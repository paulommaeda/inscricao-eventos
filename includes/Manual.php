<?php
namespace InscricaoEventos;
if (!defined('ABSPATH')) { exit; }

/** Explicit administrator receipts, independent of the gateway transaction ledger. */
final class Manual
{
    public const METHODS = ['Pix' => 'PIX', 'Cartao' => 'Cartão', Domain::PHYSICAL_CARD => 'Cartão físico (maquininha)', 'Dinheiro' => 'Dinheiro', 'Transferencia' => 'Transferência', 'Outro' => 'Outro'];
    public static function active(array $row): bool { return empty($row['reversed_at']) && empty($row['resolved_at']); }
    public static function settled(array $payment): bool
    {
        return $payment['state'] !== 'canceling' && (int) ($payment['manual_credit'] ?? 0) > 0 && (int) ($payment['credit'] ?? 0) >= (int) $payment['amount'];
    }
    /** Local checks only: manual fallback must also work when K8 is unavailable. */
    public static function k8Block(array $p, array $rows, array $transactions, string $remoteId = ''): string
    {
        if ($p['state'] === 'canceling') { return 'Aguarde o resultado do cancelamento/estorno desta cobrança.'; }
        foreach ($rows as $row) { if ((int) $row['payment_id'] === (int) $p['id'] && self::active($row)) { return 'Esta cobrança já possui baixa manual ativa. Consulte o histórico ou reverta a baixa para corrigir.'; } }
        foreach ($transactions as $tx) {
            if (in_array($tx['cancel_status'] ?? '', ['sending', 'requested', 'review'], true)) { return 'Aguarde o resultado do cancelamento/estorno desta cobrança.'; }
            if (Domain::terminal($tx['remote_state']) && ($remoteId === '' || Domain::remoteId($tx['remote_id']) === $remoteId)) { return 'Esta transação já possui resultado final conferido no K8: ' . $tx['remote_state'] . '. Não cabe outra baixa para o mesmo pagamento.'; }
        }
        return '';
    }
    private static function remoteInput(array $input): string
    {
        if (!is_string($input['remote_id'] ?? '')) { throw new \RuntimeException('Identificador de transação K8 inválido.'); }
        $value = trim($input['remote_id'] ?? ''); return $value === '' ? '' : Domain::remoteId($value);
    }
    public static function save(int $registrationId, array $input): int
    {
        Access::requireManage(); $lock = Store::lock('registration:' . $registrationId);
        try {
            $r = Store::registration($registrationId); $event = Store::event((int) $r['event_id']);
            if (($r['status'] ?? 'active') !== 'active') { throw new \RuntimeException('Restaure/ative a inscrição antes de registrar uma baixa.'); }
            if (empty($input['confirmed'])) { throw new \RuntimeException('Confirme o recebimento antes de registrar a baixa manual.'); }
            $token = is_string($input['request_token'] ?? null) ? $input['request_token'] : '';
            if (!preg_match('/^[a-f0-9]{64}$/D', $token)) { throw new \RuntimeException('Atualize o formulário de baixa manual.'); }
            $requestKey = Security::hash('manual:' . $registrationId . ':' . $token); $rows = Store::manuals($registrationId); $remoteId = self::remoteInput($input);
            foreach ($rows as $row) {
                if (!hash_equals($row['request_key'], $requestKey)) { continue; }
                if ((int) $row['amount'] !== Domain::cents($input['amount'] ?? '') || $row['paid_on'] !== (string) ($input['paid_on'] ?? '') || (int) $row['payment_id'] !== absint($input['payment_id'] ?? 0) || ($row['remote_id'] ?? '') !== $remoteId || (!(int) $row['payment_id'] && $row['method'] !== (string) ($input['method'] ?? '')) || $row['note'] !== Domain::cut(sanitize_textarea_field($input['note'] ?? ''), 1000)) { throw new \RuntimeException('Este envio já foi utilizado com outros dados. Atualize o formulário para uma nova baixa.'); }
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
                $transactions = Store::transactions($paymentId); $block = self::k8Block($p, $rows, $transactions, $remoteId);
                if ($block !== '') { throw new \RuntimeException($block); }
                foreach ($transactions as $tx) {
                    $gatewayCredit += (int) $tx['credit'];
                }
                if ($amount > (int) $p['amount']) { throw new \RuntimeException('O valor reconhecido não pode exceder o valor desta tentativa K8.'); }
                $method = $p['method'];
            } elseif ($remoteId !== '') { throw new \RuntimeException('Selecione a cobrança K8 para vincular uma transação.'); }
            if (!isset(self::METHODS[$method])) { throw new \RuntimeException('Forma de recebimento manual inválida.'); }
            $increment = $paymentId ? max(0, $amount - $gatewayCredit) : $amount;
            $balance = Store::balance($r);
            if ($amount < 1 || $increment < 1 || $increment > $balance['balance']) { throw new \RuntimeException('O crédito da baixa deve ser positivo e não pode exceder o saldo a receber.'); }
            Store::begin();
            try {
                $id = Store::insert('manuals', ['registration_id' => $registrationId, 'payment_id' => $paymentId, 'remote_id' => $remoteId, 'amount' => $amount, 'method' => $method, 'paid_on' => $date, 'note' => $note, 'request_key' => $requestKey, 'actor_id' => get_current_user_id(), 'created_at' => current_time('mysql', true), 'reversed_at' => null, 'reversed_by' => 0, 'reverse_note' => '', 'resolved_at' => null, 'resolved_state' => '']);
                if ($paymentId && $remoteId !== '') { Store::update('payments', ['remote_id_hint' => $remoteId], ['id' => $paymentId]); }
                Store::audit($registrationId, 'manual_received', 'Baixa manual #' . $id . ': ' . Domain::money($amount) . ' · ' . self::METHODS[$method] . ' · ' . Domain::dateBR($date) . ($paymentId ? ' · Cobrança K8 #' . $paymentId . ' · Transação: ' . ($remoteId ?: 'ID ainda não informado') : '') . ' · ' . $note, $paymentId);
                Store::commit(); return $id;
            } catch (\Throwable $e) { Store::rollback(); throw $e; }
        } finally { Store::unlock($lock); }
    }
    /** Reclassify an existing receipt of the same payment; never add a second credit. */
    public static function linkK8(int $registrationId, int $paymentId, int $manualId, array $input): void
    {
        Access::requireManage(); $lock = Store::lock('registration:' . $registrationId);
        try {
            $r = Store::registration($registrationId); $p = Store::payment($paymentId); $m = Store::manual($manualId); $remoteId = self::remoteInput($input);
            if ((int) $p['registration_id'] !== $registrationId || (int) $m['registration_id'] !== $registrationId) { throw new \RuntimeException('A cobrança e a baixa devem pertencer à mesma inscrição.'); }
            $note = Domain::cut(sanitize_textarea_field($input['note'] ?? ''), 1000);
            if (empty($input['confirmed']) || strlen(trim($note)) < 3) { throw new \RuntimeException('Confirme que é o mesmo pagamento e informe o motivo/comprovante do vínculo.'); }
            if ((int) $m['payment_id'] === $paymentId && ($m['remote_id'] ?? '') === $remoteId) { return; }
            if (($r['status'] ?? 'active') !== 'active' || !self::active($m) || (int) $m['payment_id']) { throw new \RuntimeException('Selecione uma baixa externa ativa de uma inscrição ativa.'); }
            $transactions = Store::transactions($paymentId); $block = self::k8Block($p, Store::manuals($registrationId), $transactions, $remoteId);
            if ($block !== '') { throw new \RuntimeException($block); }
            if ($m['method'] !== $p['method'] || (int) $m['amount'] > (int) $p['amount']) { throw new \RuntimeException('O meio e o valor da baixa devem ser compatíveis com esta cobrança K8.'); }
            foreach ($transactions as $tx) { if ((int) $tx['credit'] > 0) { throw new \RuntimeException('Esta cobrança já possui crédito K8. Confira e corrija a baixa externa antes de vincular.'); } }
            $before = Store::creditTotals($registrationId); Store::begin();
            try {
                Store::update('manuals', ['payment_id' => $paymentId, 'remote_id' => $remoteId], ['id' => $manualId]);
                if ($remoteId !== '') { Store::update('payments', ['remote_id_hint' => $remoteId], ['id' => $paymentId]); }
                if (Store::creditTotals($registrationId) !== $before) { throw new \RuntimeException('O vínculo alteraria o saldo. Nenhuma alteração foi salva.'); }
                Store::audit($registrationId, 'manual_linked_k8', 'Baixa externa #' . $manualId . ' vinculada à cobrança K8 #' . $paymentId . ' · Transação: ' . ($remoteId ?: 'ID ainda não informado') . ' · Mesmo recebimento, sem novo crédito · ' . $note, $paymentId);
                Store::commit();
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
    public static function resolve(array $p, string $state, string $remoteId): void
    {
        if (!Domain::terminal($state)) { return; }
        foreach (Store::manuals((int) $p['registration_id']) as $m) {
            if ((int) $m['payment_id'] !== (int) $p['id'] || !self::active($m)) { continue; }
            if (!empty($m['remote_id']) && Domain::remoteId($m['remote_id']) !== $remoteId) { continue; }
            Store::update('manuals', ['resolved_at' => current_time('mysql', true), 'resolved_state' => $state], ['id' => $m['id']]);
            Store::audit((int) $p['registration_id'], 'manual_reconciled', 'Baixa manual #' . $m['id'] . ' substituída pelo resultado conferido na API K8: ' . $state . '.', (int) $p['id']);
        }
    }
}
