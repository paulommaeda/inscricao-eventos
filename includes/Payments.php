<?php
namespace InscricaoEventos;
if (!defined('ABSPATH')) { exit; }

final class Payments
{
    public static function register(array $input): array
    {
        $eventId = absint($input['event_id'] ?? 0);
        $cpf = Domain::cpf((string) ($input['cpf'] ?? ''));
        Security::limit('register-ip:' . Security::ip(), 15, 900);
        $scope = (new Gateway())->scope();
        $lock = Store::lock('event:' . $eventId);
        try {
            $event = Store::event($eventId);
            $lot = Domain::quote($event, Store::lots($eventId), Domain::today($event));
            if (!$lot) { throw new \RuntimeException('As inscrições não estão abertas neste momento.'); }
            if ((int) ($input['lot_id'] ?? 0) !== (int) $lot['id'] || (int) ($input['expected_price'] ?? -1) !== (int) $lot['price']) { throw new \RuntimeException('O lote ou valor mudou. Atualize a página para conferir as condições atuais.'); }
            if (Store::byCPF($eventId, $cpf)) { throw new \RuntimeException('Este CPF já possui inscrição neste evento. Use a opção Minha inscrição.'); }
            $method = (string) ($input['method'] ?? '');
            if (!in_array($method, ['Pix', 'Cartao'], true) || ($method === 'Pix' && !$event['allow_pix']) || ($method === 'Cartao' && !$event['allow_card'])) { throw new \RuntimeException('Forma de pagamento indisponível para este evento.'); }
            $name = trim(sanitize_text_field($input['name'] ?? ''));
            $email = strtolower(trim((string) ($input['email'] ?? '')));
            $phone = preg_replace('/\D/', '', (string) ($input['phone'] ?? ''));
            if (strlen($name) < 3 || strlen($name) > 200 || !is_email($email) || strlen($email) > 254 || !preg_match('/^\d{10,15}$/D', $phone)) { throw new \RuntimeException('Confira nome, e-mail e telefone com DDD.'); }
            if (($input['accepted_terms'] ?? false) !== true) { throw new \RuntimeException('Leia e aceite as condições de inscrição.'); }
            $id = Store::insert('registrations', ['event_id' => $eventId, 'scope' => $scope, 'lot_id' => $lot['id'], 'lot_name' => $lot['name'], 'total' => $lot['price'], 'max_installments' => $lot['max_installments'], 'method' => $method, 'name' => $name, 'email' => $email, 'phone' => $phone, 'cpf_hash' => Security::hash('cpf:' . $cpf), 'cpf_encrypted' => Security::seal($cpf), 'terms_snapshot' => $event['terms'] . "\n" . str_replace('{data}', Domain::dateBR($event['payment_deadline']), $event['payment_message']), 'terms_accepted_at' => current_time('mysql', true), 'created_at' => current_time('mysql', true)]);
            Security::session($id);
            return Store::publicRegistration(Store::registration($id));
        } finally { Store::unlock($lock); }
    }

    public static function create(array $registration, $amount): array
    {
        $lock = Store::lock('registration:' . $registration['id']);
        try {
            $registration = Store::registration((int) $registration['id']);
            $event = Store::event((int) $registration['event_id']);
            $gateway = new Gateway();
            if (!hash_equals($registration['scope'], $gateway->scope())) { throw new \RuntimeException('A inscrição pertence a outra conta ou ambiente K8. Procure a organização.'); }
            if ($event['status'] !== 'published' || !$event['payment_deadline'] || Domain::today($event) > $event['payment_deadline']) { throw new \RuntimeException('O prazo para novos pagamentos deste evento terminou. Procure a organização.'); }
            $method = $registration['method'];
            if (($method === 'Pix' && !$event['allow_pix']) || ($method === 'Cartao' && !$event['allow_card'])) { throw new \RuntimeException('Esta forma de pagamento foi desabilitada. Procure a organização.'); }
            $balance = Store::balance($registration);
            if (!$balance['balance']) { throw new \RuntimeException('Sua inscrição já está quitada.'); }
            $cents = Domain::cents($amount);
            if ($cents < 1 || $cents > $balance['balance']) { throw new \RuntimeException('O pagamento deve ser positivo e não pode exceder o saldo devedor.'); }
            if ($balance['paid'] === 0 && $method === 'Pix' && $cents < min((int) $event['minimum_entry'], $balance['balance'])) { throw new \RuntimeException('A entrada mínima é ' . Domain::money((int) $event['minimum_entry']) . '.'); }
            if ($method === 'Cartao' && $cents !== $balance['balance']) { throw new \RuntimeException('O cartão deve pagar o saldo completo; o parcelamento será escolhido no K8.'); }
            foreach (Store::payments((int) $registration['id']) as $old) {
                if (!in_array($old['state'], ['creating', 'pending', 'review'], true)) { continue; }
                if ($old['state'] === 'pending' && $old['checkout_url'] && (int) $old['amount'] === $cents) { return ['payment_id' => (int) $old['id'], 'url' => Security::gatewayURL($old['checkout_url'])]; }
                throw new \RuntimeException('Há um pagamento em andamento. Confira ou conclua essa tentativa antes de gerar outra.');
            }
            $settings = (array) get_option('ie_settings', []);
            if (($settings['environment'] ?? 'sandbox') === 'production' && wp_parse_url(home_url('/'), PHP_URL_SCHEME) !== 'https') { throw new \RuntimeException('A organização precisa habilitar HTTPS antes de receber pagamentos.'); }
            $gateway->authenticate(); // Avoid reserving a payment with invalid credentials.
            $reference = 'IE' . bin2hex(random_bytes(9));
            $id = Store::insert('payments', ['registration_id' => $registration['id'], 'reference' => $reference, 'scope' => $gateway->scope(), 'amount' => $cents, 'method' => $method, 'checkout_url' => '', 'state' => 'creating', 'last_error' => '', 'operator_note' => '', 'created_at' => current_time('mysql', true)]);
            $payment = Store::payment($id);
            $return = add_query_arg(['ie_return' => 1, 'ie_event' => $event['id']], $event['public_page_id'] ? get_permalink((int) $event['public_page_id']) : home_url('/'));
            $callback = add_query_arg('key', self::callbackKey($payment), rest_url('inscricao-eventos/v1/webhook/' . $id));
            try {
                $result = $gateway->request('/v2/CriaTransacaoCheckout', Gateway::payload($payment, $registration, $event, $return, $callback, Security::unseal($registration['cpf_encrypted'])));
                $checkout = (string) ($result['Identificador'] ?? '');
                if (!preg_match('/^[A-Za-z0-9-]{1,100}$/D', $checkout)) { throw new \RuntimeException('Identificador de checkout K8 ausente.'); }
                $url = Security::gatewayURL((string) ($result['URL'] ?? ''));
                Store::update('payments', ['checkout_id' => $checkout, 'checkout_url' => $url, 'state' => 'pending'], ['id' => $id]);
                return ['payment_id' => $id, 'url' => $url];
            } catch (\Throwable $e) {
                Store::update('payments', ['state' => 'review', 'last_error' => Domain::cut($e->getMessage(), 500)], ['id' => $id]);
                throw new \RuntimeException('Tentativa #' . $id . ' registrada para conferência. Procure a organização antes de tentar novamente.');
            }
        } finally { Store::unlock($lock); }
    }

    public static function callbackKey(array $p): string { return Security::hash('webhook:' . $p['id'] . ':' . $p['reference']); }

    /** Administrator recovery never creates financial credit. Final credit still requires the gateway API. */
    public static function recover(int $id, string $checkout, string $url, bool $close): void
    {
        $p = Store::payment($id); $lock = Store::lock('registration:' . $p['registration_id']);
        try {
            $p = Store::payment($id);
            if (!hash_equals($p['scope'], (new Gateway())->scope())) { throw new \RuntimeException('Selecione a conta e o ambiente K8 desta tentativa antes de recuperá-la.'); }
            if (!in_array($p['state'], ['review', 'creating', 'pending'], true)) { throw new \RuntimeException('Esta tentativa já foi encerrada ou confirmada.'); }
            $note = 'Conferência manual por usuário WP #' . get_current_user_id() . ' em ' . current_time('mysql', true) . ' UTC: ';
            if ($close) {
                foreach (Store::payments((int) $p['registration_id']) as $payment) { if ((int) $payment['id'] === $id && (int) $payment['credit'] > 0) { throw new \RuntimeException('Não é possível liberar uma tentativa com crédito financeiro.'); } }
                Store::update('payments', ['state' => 'closed', 'operator_note' => $note . 'organização declarou ter conferido a inexistência de cobrança ativa ou pagamento no K8.'], ['id' => $id]);
            } else {
                if (!preg_match('/^[A-Za-z0-9-]{1,100}$/D', $checkout)) { throw new \RuntimeException('Informe o identificador do checkout existente.'); }
                Store::update('payments', ['checkout_id' => $checkout, 'checkout_url' => Security::gatewayURL($url), 'state' => 'pending', 'operator_note' => $note . 'checkout existente vinculado; nenhum crédito incluído.'], ['id' => $id]);
            }
        } finally { Store::unlock($lock); }
    }

    public static function sync(int $paymentId, ?string $remoteId = null): void
    {
        global $wpdb; $p = Store::payment($paymentId); $lock = Store::lock('registration:' . $p['registration_id']);
        try {
            $p = Store::payment($paymentId); $r = Store::registration((int) $p['registration_id']); $api = new Gateway();
            if (!hash_equals($p['scope'], $api->scope())) { throw new \RuntimeException('Pagamento pertence a outra conta ou ambiente K8.'); }
            if ($remoteId !== null && !preg_match('/^[A-Za-z0-9-]{1,100}$/D', $remoteId)) { throw new \RuntimeException('Identificador de transação inválido.'); }
            $data = $remoteId ? $api->request('/api/ConsultaTransacao', ['Identificador' => $remoteId]) : $api->request('/api/ConsultaTransacaoReferencia', ['Referencia' => $p['reference']]);
            self::applyVerified($p, $r, $data, $remoteId);
        } catch (\Throwable $e) {
            Store::update('payments', ['synced_at' => current_time('mysql', true), 'last_error' => Domain::cut($e->getMessage(), 500)], ['id' => $paymentId]);
            throw $e;
        } finally { Store::unlock($lock); }
    }

    /** Caller holds the registration lock. Callback payloads never call this directly. */
    public static function applyVerified(array $p, array $r, array $data, ?string $expectedId = null): void
    {
        global $wpdb;
        $id = (string) ($data['Identificador'] ?? '');
        if (!preg_match('/^[A-Za-z0-9-]{1,100}$/D', $id) || ($expectedId && $id !== $expectedId) || ($data['Referencia'] ?? '') !== $p['reference'] || ($data['NumeroPedido'] ?? '') !== $p['reference'] || preg_replace('/\D/', '', (string) ($data['ClienteCPFCNPJ'] ?? '')) !== Security::unseal($r['cpf_encrypted'])) { throw new \RuntimeException('A identidade da transação K8 não confere com a inscrição.'); }
        $table = Store::table('transactions');
        $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE scope=%s AND remote_id=%s", $p['scope'], $id), ARRAY_A);
        if ($existing && (int) $existing['payment_id'] !== (int) $p['id']) { throw new \RuntimeException('Transação K8 já vinculada a outro pagamento.'); }
        $credit = Domain::credit($data, (int) $p['amount'], $p['method'], (int) ($existing['credit'] ?? 0));
        $row = ['payment_id' => $p['id'], 'scope' => $p['scope'], 'remote_id' => $id, 'remote_state' => $data['Estado'], 'credit' => $credit, 'paid_at' => Domain::cut((string) ($data['DataPagamento'] ?? ''), 40), 'synced_at' => current_time('mysql', true)];
        Store::begin();
        try {
            if ($existing) { Store::update('transactions', $row, ['id' => $existing['id']]); } else { Store::insert('transactions', $row); }
            $sum = $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(credit),0) FROM $table WHERE payment_id=%d", $p['id']));
            if ($sum === null || !empty($wpdb->last_error)) { throw new \RuntimeException('Falha ao conferir o crédito financeiro. A conciliação será repetida.'); }
            $sum = (int) $sum;
            $state = $sum > 0 ? 'paid' : (Domain::terminal($data['Estado']) ? 'closed' : 'pending');
            Store::update('payments', ['state' => $state, 'synced_at' => current_time('mysql', true), 'last_error' => ''], ['id' => $p['id']]);
            Store::commit();
        } catch (\Throwable $e) { Store::rollback(); throw $e; }
    }

    public static function refreshRegistration(array $r): void
    {
        $count = 0;
        foreach (Store::payments((int) $r['id']) as $p) {
            if ($count >= 3) { break; }
            if ($p['synced_at'] && strtotime($p['synced_at'] . ' UTC') > time() - 20) { continue; }
            $count++;
            try { self::sync((int) $p['id']); } catch (\Throwable $e) { /* No credit until the authoritative query succeeds; cron retries. */ }
        }
    }
    public static function cron(): void
    {
        global $wpdb;
        try {
            $scope = (new Gateway())->scope();
            $ids = $wpdb->get_col($wpdb->prepare('SELECT id FROM ' . Store::table('payments') . " WHERE scope=%s AND state<>'creating' ORDER BY COALESCE(synced_at,'1970-01-01') ASC,id ASC LIMIT 10", $scope));
            foreach ($ids as $id) {
                try { self::sync((int) $id); } catch (\Throwable $e) { /* Visible on the admin payment detail; retried on the next round. */ }
            }
            delete_option('ie_cron_error');
        } catch (\Throwable $e) { update_option('ie_cron_error', Domain::cut($e->getMessage(), 500), false); }
        foreach (['sessions', 'limits'] as $name) { $wpdb->query($wpdb->prepare('DELETE FROM ' . Store::table($name) . ' WHERE expires_at<%d', time())); }
        // A process killed during creation becomes a review item, never a new charge automatically.
        $wpdb->query($wpdb->prepare('UPDATE ' . Store::table('payments') . " SET state='review',last_error=%s WHERE state='creating' AND created_at<%s", 'A criação foi interrompida. Confira a referência no K8 antes de repetir.', gmdate('Y-m-d H:i:s', time() - 120)));
    }
}
