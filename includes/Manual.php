<?php
namespace InscricaoEventos;
if (!defined('ABSPATH')) { exit; }

/** Explicit administrator receipts, independent of the gateway transaction ledger. */
final class Manual
{
    public const METHODS = ['Pix' => 'PIX', 'Cartao' => 'Cartão', 'Dinheiro' => 'Dinheiro', 'Transferencia' => 'Transferência', 'Outro' => 'Outro'];
    public static function active(array $row): bool { return empty($row['reversed_at']) && empty($row['resolved_at']); }
    public static function save(int $registrationId, array $input): int
    {
        Access::requireManage(); $lock = Store::lock('registration:' . $registrationId);
        try {
            $r = Store::registration($registrationId); $event = Store::event((int) $r['event_id']);
            if (($r['status'] ?? 'active') !== 'active') { throw new \RuntimeException('Restaure/ative a inscrição antes de registrar uma baixa.'); }
            if (empty($input['confirmed'])) { throw new \RuntimeException('Confirme o recebimento antes de registrar a baixa manual.'); }
            $token = is_string($input['request_token'] ?? null) ? $input['request_token'] : '';
            if (!preg_match('/^[a-f0-9]{64}$/D', $token)) { throw new \RuntimeException('Atualize o formulário de baixa manual.'); }
            $requestKey = Security::hash('manual:' . $registrationId . ':' . $token); $rows = Store::manuals($registrationId);
            foreach ($rows as $row) {
                if (!hash_equals($row['request_key'], $requestKey)) { continue; }
                if ((int) $row['amount'] !== Domain::cents($input['amount'] ?? '') || $row['paid_on'] !== (string) ($input['paid_on'] ?? '') || (int) $row['payment_id'] !== absint($input['payment_id'] ?? 0) || (!(int) $row['payment_id'] && $row['method'] !== (string) ($input['method'] ?? '')) || $row['note'] !== Domain::cut(sanitize_textarea_field($input['note'] ?? ''), 1000)) { throw new \RuntimeException('Este envio já foi utilizado com outros dados. Atualize o formulário para uma nova baixa.'); }
                return (int) $row['id'];
            }
            $note = Domain::cut(sanitize_textarea_field($input['note'] ?? ''), 1000);
            if (strlen(trim($note)) < 3) { throw new \RuntimeException('Informe o motivo/comprovante da baixa manual.'); }
            $date = Domain::date((string) ($input['paid_on'] ?? ''));
            if ($date > Domain::today($event)) { throw new \RuntimeException('A data do pagamento não pode estar no futuro.'); }
            $amount = Domain::cents($input['amount'] ?? ''); $paymentId = absint($input['payment_id'] ?? 0); $gatewayCredit = 0;
            $method = (string) ($input['method'] ?? '');
            if ($paymentId) {
                $p = Store::payment($paymentId);
                if ((int) $p['registration_id'] !== $registrationId) { throw new \RuntimeException('Esta tentativa não pertence à inscrição.'); }
                if ($p['state'] === 'canceling') { throw new \RuntimeException('Aguarde o resultado do cancelamento/estorno desta tentativa.'); }
                foreach ($rows as $row) { if ((int) $row['payment_id'] === $paymentId && self::active($row)) { throw new \RuntimeException('Esta tentativa já possui baixa manual ativa. Reverta a anterior para corrigir.'); } }
                foreach (Store::transactions($paymentId) as $tx) {
                    if (Domain::terminal($tx['remote_state'])) { throw new \RuntimeException('O K8 já devolveu um estado final. Use a conciliação K8 ou registre um recebimento separado fora do K8.'); }
                    $gatewayCredit += (int) $tx['credit'];
                }
                if ($amount > (int) $p['amount']) { throw new \RuntimeException('O valor reconhecido não pode exceder o valor desta tentativa K8.'); }
                $method = $p['method'];
            }
            if (!isset(self::METHODS[$method])) { throw new \RuntimeException('Forma de recebimento manual inválida.'); }
            $increment = $paymentId ? max(0, $amount - $gatewayCredit) : $amount;
            $balance = Store::balance($r);
            if ($amount < 1 || $increment < 1 || $increment > $balance['balance']) { throw new \RuntimeException('O crédito da baixa deve ser positivo e não pode exceder o saldo a receber.'); }
            Store::begin();
            try {
                $id = Store::insert('manuals', ['registration_id' => $registrationId, 'payment_id' => $paymentId, 'amount' => $amount, 'method' => $method, 'paid_on' => $date, 'note' => $note, 'request_key' => $requestKey, 'actor_id' => get_current_user_id(), 'created_at' => current_time('mysql', true), 'reversed_at' => null, 'reversed_by' => 0, 'reverse_note' => '', 'resolved_at' => null, 'resolved_state' => '']);
                Store::audit($registrationId, 'manual_received', 'Baixa manual #' . $id . ': ' . Domain::money($amount) . ' · ' . self::METHODS[$method] . ' · ' . Domain::dateBR($date) . ' · ' . $note, $paymentId);
                Store::commit(); return $id;
            } catch (\Throwable $e) { Store::rollback(); throw $e; }
        } finally { Store::unlock($lock); }
    }
    public static function reverse(int $id, string $note, bool $confirmed): void
    {
        Access::requireManage(); $note = Domain::cut(sanitize_textarea_field($note), 1000); $m = Store::manual($id); $lock = Store::lock('registration:' . $m['registration_id']);
        try {
            $m = Store::manual($id);
            if (!$confirmed || strlen(trim($note)) < 3) { throw new \RuntimeException('Confirme a reversão e informe o motivo.'); }
            if (!empty($m['reversed_at'])) { return; }
            if (!empty($m['resolved_at'])) { throw new \RuntimeException('Esta baixa já foi substituída pela conciliação K8. Cancelamento/estorno do pagamento deve usar a ação K8.'); }
            Store::begin();
            try {
                Store::update('manuals', ['reversed_at' => current_time('mysql', true), 'reversed_by' => get_current_user_id(), 'reverse_note' => Domain::cut(sanitize_textarea_field($note), 1000)], ['id' => $id]);
                Store::audit((int) $m['registration_id'], 'manual_reversed', 'Baixa manual #' . $id . ' revertida: ' . $note . '. Sem solicitação de estorno ao K8.', (int) $m['payment_id']);
                Store::commit();
            } catch (\Throwable $e) { Store::rollback(); throw $e; }
        } finally { Store::unlock($lock); }
    }
    /** Inside the verified K8 transaction and registration lock; never called from unverified callbacks. */
    public static function resolve(array $p, string $state): void
    {
        if (!Domain::terminal($state)) { return; }
        foreach (Store::manuals((int) $p['registration_id']) as $m) {
            if ((int) $m['payment_id'] !== (int) $p['id'] || !self::active($m)) { continue; }
            Store::update('manuals', ['resolved_at' => current_time('mysql', true), 'resolved_state' => $state], ['id' => $m['id']]);
            Store::audit((int) $p['registration_id'], 'manual_reconciled', 'Baixa manual #' . $m['id'] . ' substituída pelo resultado conferido na API K8: ' . $state . '.', (int) $p['id']);
        }
    }
}
