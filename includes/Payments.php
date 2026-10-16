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
            $existing = Store::byCPF($eventId, $cpf);
            if ($existing) { throw new \RuntimeException(($existing['status'] ?? 'active') === 'deleted' ? 'Este CPF possui inscrição na lixeira. Solicite a restauração à organização.' : 'Este CPF já possui inscrição neste evento. Use a opção Minha inscrição.'); }
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

    public static function create(array $registration, $amount, ?string $selectedMethod = null, ?int $expectedInstallments = null): array
    {
        $lock = Store::lock('registration:' . $registration['id']);
        try {
            $registration = Store::registration((int) $registration['id']);
            if (($registration['status'] ?? 'active') !== 'active') { throw new \RuntimeException('Esta inscrição está cancelada ou na lixeira. Procure a organização.'); }
            $event = Store::event((int) $registration['event_id']);
            $gateway = new Gateway();
            if (!hash_equals($registration['scope'], $gateway->scope())) { throw new \RuntimeException('A inscrição pertence a outra conta ou ambiente K8. Procure a organização.'); }
            if ($event['status'] !== 'published' || !$event['payment_deadline'] || Domain::today($event) > $event['payment_deadline']) { throw new \RuntimeException('O prazo para novos pagamentos deste evento terminou. Procure a organização.'); }
            $method = $selectedMethod ?? $registration['method'];
            if (!in_array($method, ['Pix', 'Cartao'], true)) { throw new \RuntimeException('Forma de pagamento inválida.'); }
            if (($method === 'Pix' && (!$event['allow_pix'] || $registration['method'] !== 'Pix')) || ($method === 'Cartao' && !$event['allow_card'])) { throw new \RuntimeException('Esta forma de pagamento foi desabilitada. Procure a organização.'); }
            $balance = Store::balance($registration);
            if (!$balance['balance']) { throw new \RuntimeException('Sua inscrição já está quitada.'); }
            $cents = Domain::cents($amount);
            if ($cents < 1 || $cents > $balance['balance']) { throw new \RuntimeException('O pagamento deve ser positivo e não pode exceder o saldo devedor.'); }
            if ($balance['paid'] === 0 && $method === 'Pix' && $cents < min((int) $event['minimum_entry'], $balance['balance'])) { throw new \RuntimeException('A entrada mínima é ' . Domain::money((int) $event['minimum_entry']) . '.'); }
            if ($method === 'Cartao' && $cents !== $balance['balance']) { throw new \RuntimeException('O cartão deve pagar o saldo completo; o parcelamento será escolhido no K8.'); }
            foreach (Store::payments((int) $registration['id']) as $old) {
                if (!in_array($old['state'], ['creating', 'pending', 'review', 'canceling'], true)) { continue; }
                if ($old['state'] === 'pending' && $old['method'] === $method && $old['checkout_url'] && (int) $old['amount'] === $cents) { return ['payment_id' => (int) $old['id'], 'url' => Security::gatewayURL($old['checkout_url'])]; }
                throw new \RuntimeException('Há um pagamento em andamento. Confira ou conclua essa tentativa antes de gerar outra.');
            }
            $installments = $method === 'Cartao' ? Domain::installments($registration, $event)['max'] : 1;
            if ($method === 'Cartao' && $expectedInstallments !== null && $expectedInstallments !== $installments) { throw new \RuntimeException('O limite de parcelas mudou com a data. Atualize o saldo e confira as condições antes de continuar.'); }
            $settings = (array) get_option('ie_settings', []);
            if (($settings['environment'] ?? 'sandbox') === 'production' && wp_parse_url(home_url('/'), PHP_URL_SCHEME) !== 'https') { throw new \RuntimeException('A organização precisa habilitar HTTPS antes de receber pagamentos.'); }
            $gateway->authenticate(); // Avoid reserving a payment with invalid credentials.
            $reference = 'IE' . bin2hex(random_bytes(9));
            $id = Store::insert('payments', ['registration_id' => $registration['id'], 'reference' => $reference, 'scope' => $gateway->scope(), 'amount' => $cents, 'method' => $method, 'max_installments' => $installments, 'cpf_snapshot' => $registration['cpf_encrypted'], 'checkout_url' => '', 'state' => 'creating', 'last_error' => '', 'operator_note' => '', 'created_at' => current_time('mysql', true)]);
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

    /** Cancellation is an explicit administrator action, never an automatic retry. */
    public static function cancel(int $paymentId, string $remoteId, string $operation, string $reason, bool $confirmed = false): string
    {
        if (!$confirmed) { throw new \RuntimeException('Confirme o cancelamento ou estorno antes de enviar ao K8.'); }
        if (!in_array($operation, ['cancel', 'refund'], true)) { throw new \RuntimeException('Operação de cancelamento inválida.'); }
        $reason = Domain::cut(sanitize_textarea_field($reason), 1000);
        if (strlen($reason) < 3) { throw new \RuntimeException('Informe o motivo do cancelamento ou estorno.'); }
        $p = Store::payment($paymentId); $lock = Store::lock('registration:' . $p['registration_id']);
        try {
            $p = Store::payment($paymentId); $r = Store::registration((int) $p['registration_id']); $api = new Gateway();
            if (!hash_equals($p['scope'], $api->scope())) { throw new \RuntimeException('Selecione a conta e o ambiente K8 desta tentativa para cancelar.'); }
            $remoteId = Domain::remoteId($remoteId ?: (string) ($p['remote_id_hint'] ?? ''));
            $existing = self::ledger($p, $remoteId);
            if ($existing && in_array($existing['cancel_status'] ?? '', ['sending', 'requested', 'review', 'confirmed'], true)) { throw new \RuntimeException('Já existe cancelamento ou estorno registrado para esta transação. Consulte o K8 antes de uma nova solicitação.'); }
            $data = $api->request('/api/ConsultaTransacao', ['Identificador' => $remoteId]);
            self::applyVerified($p, $r, $data, $remoteId); // Full identity, CPF snapshot, method and amount are checked first.
            $tx = self::ledger($p, $remoteId);
            if (!$tx) { throw new \RuntimeException('Transação não conferida para cancelamento.'); }
            $state = (string) $data['Estado'];
            if (in_array($state, ['Cancelada', 'Estornado', 'Rejeitada'], true)) { return 'O K8 já confirmou que esta transação está encerrada.'; }
            if (self::cancellationState($state) || in_array($tx['cancel_status'] ?? '', ['sending', 'requested', 'review'], true)) { throw new \RuntimeException('O K8 já mostra cancelamento ou estorno em andamento. Aguarde ou confira no painel K8.'); }
            $paid = in_array($state, ['Aprovada', 'Liquidado'], true);
            if ($operation === 'refund' && !$paid) { throw new \RuntimeException('O K8 ainda não aprovou este pagamento para estorno.'); }
            if ($operation === 'cancel' && $p['method'] === 'Pix' && $paid) { throw new \RuntimeException('Este PIX já foi pago. Selecione Estornar pagamento aprovado para solicitar a devolução.'); }
            $path = $operation === 'refund' && $p['method'] === 'Pix' ? '/api/EstornaTransacaoPIx' : '/api/CancelaTransacao';
            Store::begin();
            try {
                Store::update('transactions', ['cancel_status' => 'sending', 'cancel_operation' => $operation, 'cancel_requested_at' => current_time('mysql', true), 'cancel_error' => '', 'cancel_actor' => get_current_user_id()], ['id' => $tx['id']]);
                Store::update('payments', ['state' => 'canceling'], ['id' => $paymentId]);
                Store::audit((int) $r['id'], $operation . '_sending', $reason . ' · Transação ' . $remoteId, $paymentId);
                Store::commit();
            } catch (\Throwable $e) { Store::rollback(); throw $e; }
            try { $api->request($path, ['Identificador' => $remoteId]); }
            catch (\Throwable $e) {
                $rejected = $e instanceof GatewayRejected;
                $message = ($rejected ? 'Solicitação recusada pelo K8. ' : 'Não foi possível confirmar o envio ao K8. Confira a transação antes de reenviar. ') . $e->getMessage();
                Store::update('transactions', ['cancel_status' => $rejected ? 'failed' : 'review', 'cancel_error' => Domain::cut($message, 500)], ['id' => $tx['id']]);
                if ($rejected) { self::applyVerified($p, $r, $data, $remoteId); }
                Store::update('payments', ['last_error' => Domain::cut($message, 500)], ['id' => $paymentId]);
                Store::audit((int) $r['id'], $operation . ($rejected ? '_failed' : '_review'), $message, $paymentId);
                throw new \RuntimeException($message);
            }
            Store::update('transactions', ['cancel_status' => 'requested', 'cancel_error' => ''], ['id' => $tx['id']]);
            Store::audit((int) $r['id'], $operation . '_requested', 'Solicitação aceita pelo K8 para a transação ' . $remoteId . '. Aguardando conferência do estado.', $paymentId);
            try {
                $after = $api->request('/api/ConsultaTransacao', ['Identificador' => $remoteId]);
                self::applyVerified($p, $r, $after, $remoteId);
                if (in_array($after['Estado'], ['Cancelada', 'Estornado', 'Rejeitada'], true)) { return 'Cancelamento/estorno confirmado pela consulta K8. Saldo atualizado.'; }
            } catch (\Throwable $e) { Store::update('payments', ['last_error' => Domain::cut('Solicitação aceita pelo K8; a conferência será repetida. ' . $e->getMessage(), 500)], ['id' => $paymentId]); }
            return 'Solicitação enviada ao K8. O saldo será atualizado quando o cancelamento/estorno for confirmado.';
        } finally { Store::unlock($lock); }
    }

    /** An ambiguous request is unlocked only after explicit operator review and a fresh K8 query. */
    public static function reviewCancellation(int $paymentId, string $remoteId, string $reason, bool $confirmed = false): void
    {
        if (!$confirmed || strlen(trim($reason)) < 3) { throw new \RuntimeException('Confirme a conferência no K8 e registre o motivo.'); }
        $p = Store::payment($paymentId); $lock = Store::lock('registration:' . $p['registration_id']);
        try {
            $p = Store::payment($paymentId); $r = Store::registration((int) $p['registration_id']); $api = new Gateway();
            if (!hash_equals($p['scope'], $api->scope())) { throw new \RuntimeException('Selecione a conta e o ambiente K8 desta tentativa.'); }
            $remoteId = Domain::remoteId($remoteId); $tx = self::ledger($p, $remoteId);
            if (!$tx || !in_array($tx['cancel_status'] ?? '', ['review', 'sending', 'requested'], true)) { throw new \RuntimeException('Não há solicitação de cancelamento pendente de conferência.'); }
            $data = $api->request('/api/ConsultaTransacao', ['Identificador' => $remoteId]); self::applyVerified($p, $r, $data, $remoteId);
            if (in_array($data['Estado'], ['Cancelada', 'Estornado', 'Rejeitada'], true)) { return; }
            if (self::cancellationState($data['Estado'])) { throw new \RuntimeException('O K8 ainda informa cancelamento/estorno em processamento ou com erro. Regularize no K8 antes de liberar.'); }
            Store::update('transactions', ['cancel_status' => 'failed', 'cancel_error' => 'Operador conferiu no K8 a inexistência de cancelamento/estorno em andamento.'], ['id' => $tx['id']]);
            self::applyVerified($p, $r, $data, $remoteId);
            Store::audit((int) $r['id'], 'cancel_reviewed', $reason, $paymentId);
        } finally { Store::unlock($lock); }
    }
    private static function cancellationState(string $state): bool
    {
        return in_array($state, ['ACancelar', 'acancelarCNAB', 'EstornarPIX', 'CartaoCancelametoFuturo', 'ErroEstorno', 'FalhaCancelamento'], true);
    }
    private static function ledger(array $p, string $id): ?array
    {
        global $wpdb; $table = Store::table('transactions'); $id = Domain::remoteId($id);
        $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE scope=%s AND remote_id=%s", $p['scope'], $id), ARRAY_A);
        if (!empty($wpdb->last_error)) { throw new \RuntimeException('Não foi possível conferir o histórico da transação.'); }
        if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $id)) {
            $aliases = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE scope=%s AND REPLACE(LOWER(remote_id),'-','')=%s", $p['scope'], str_replace('-', '', $id)), ARRAY_A);
            if ($aliases === null || !empty($wpdb->last_error)) { throw new \RuntimeException('Não foi possível conferir o histórico da transação.'); }
            $aliases = array_values(array_filter($aliases, static fn($row) => Domain::remoteId($row['remote_id']) === $id));
            if (count($aliases) > 1) { throw new \RuntimeException('Há dois registros com formatos diferentes do mesmo ID K8. Procure a organização para conferir o histórico.'); }
            $existing = $aliases[0] ?? $existing;
        }
        if ($existing && (int) $existing['payment_id'] !== (int) $p['id']) { throw new \RuntimeException('Transação K8 já vinculada a outro pagamento.'); }
        return $existing;
    }

    private static function diagnose(array $p, array $r, array $data, ?string $expectedId): void
    {
        $text = static fn($v) => is_scalar($v) ? Domain::cut(sanitize_text_field((string) $v), 100) : '[ausente/inválido]';
        $id = static function ($v) use ($text) { try { return Domain::remoteId($text($v)); } catch (\Throwable $e) { return '[ausente/inválido]'; } };
        $expectedCPF = Security::unseal(($p['cpf_snapshot'] ?? '') ?: $r['cpf_encrypted']);
        $receivedCPF = is_scalar($data['ClienteCPFCNPJ'] ?? null) ? preg_replace('/\D/', '', (string) $data['ClienteCPFCNPJ']) : '';
        $mask = static fn($v) => strlen($v) === 11 ? '***.***.***-' . substr($v, -2) : '[ausente/inválido]';
        $rows = [];
        $add = static function ($field, $local, $remote, $match) use (&$rows) { $rows[] = ['field' => $field, 'local' => $local, 'k8' => $remote, 'result' => $match]; };
        $add('ID da transação', $expectedId ? $id($expectedId) : 'Consulta por referência', $id($data['Identificador'] ?? ''), $expectedId ? ($id($expectedId) === $id($data['Identificador'] ?? '') ? 'Confere' : 'Divergente') : 'Localizado pelo K8');
        foreach (['Referencia' => 'Referência', 'NumeroPedido' => 'Número do pedido'] as $field => $label) { $v = $data[$field] ?? null; $add($label, $p['reference'], $text($v), $v === null || $v === '' ? 'Não informado' : ($v === $p['reference'] ? 'Confere' : 'Divergente')); }
        $checkout = $data['IdentificadorCheckout'] ?? $data['CheckoutId'] ?? '';
        $add('Checkout', $p['checkout_id'] ? $id($p['checkout_id']) : 'Não guardado', $checkout ? $id($checkout) : 'Não informado', $checkout ? (self::checkoutMatches($p, $checkout) ? 'Confere' : 'Divergente') : 'Não informado');
        $add('CPF da cobrança original', $mask($expectedCPF), $mask($receivedCPF), $expectedCPF === $receivedCPF ? 'Confere' : 'Divergente');
        $add('Forma', $p['method'], $text($data['Tipo'] ?? null), ($data['Tipo'] ?? '') === $p['method'] ? 'Confere' : 'Divergente');
        try { $amount = Domain::cents($data['Valor'] ?? ''); $add('Valor da transação', Domain::money((int) $p['amount']), Domain::money($amount), $amount === (int) $p['amount'] ? 'Confere' : 'Divergente'); }
        catch (\Throwable $e) { $add('Valor da transação', Domain::money((int) $p['amount']), '[ausente/inválido]', 'Divergente'); }
        $add('Estado', 'Confirmação pela API', $text($data['Estado'] ?? null), 'Informativo');
        Store::update('payments', ['diagnostic_json' => wp_json_encode(['checked_at' => current_time('mysql', true), 'rows' => $rows])], ['id' => $p['id']]);
    }

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
            if ($remoteId !== null) { $remoteId = Domain::remoteId($remoteId); }
            if ($remoteId) {
                // Only a retry hint: no transaction or credit is inserted until the API confirms the match.
                Store::update('payments', ['remote_id_hint' => $remoteId], ['id' => $paymentId]);
                $ids = [$remoteId];
            } else {
                $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . Store::table('transactions') . ' WHERE payment_id=%d', $paymentId), ARRAY_A);
                if ($rows === null || !empty($wpdb->last_error)) { throw new \RuntimeException('Não foi possível conferir as transações já vinculadas.'); }
                $ids = array_column($rows, 'remote_id');
                if (!empty($p['remote_id_hint'])) { $ids[] = $p['remote_id_hint']; }
                $ids = array_values(array_unique(array_map([Domain::class, 'remoteId'], $ids)));
            }
            if ($ids) {
                foreach ($ids as $candidate) {
                    $data = $api->request('/api/ConsultaTransacao', ['Identificador' => $candidate]);
                    self::applyVerified($p, $r, $data, $candidate);
                }
            } else {
                try {
                    $data = $api->request('/api/ConsultaTransacaoReferencia', ['Referencia' => $p['reference']]);
                    self::applyVerified($p, $r, $data);
                } catch (ReferenceNotFound $e) {
                    // Some checkouts do not expose their reference through the transaction lookup.
                    // Discover only exact order/reference/checkout matches, then re-query each ID.
                    $ids = self::discover($api, $p);
                    if (!$ids) { throw new \RuntimeException('O K8 ainda não devolveu uma transação vinculada a esta tentativa. Aguarde a confirmação ou informe o ID completo da transação no painel, sem gerar outra cobrança.'); }
                    foreach ($ids as $candidate) {
                        Store::update('payments', ['remote_id_hint' => $candidate], ['id' => $paymentId]);
                        $data = $api->request('/api/ConsultaTransacao', ['Identificador' => $candidate]);
                        self::applyVerified($p, $r, $data, $candidate);
                    }
                }
            }
        } catch (\Throwable $e) {
            Store::update('payments', ['synced_at' => current_time('mysql', true), 'last_error' => Domain::cut($e->getMessage(), 500)], ['id' => $paymentId]);
            throw $e;
        } finally { Store::unlock($lock); }
    }

    private static function checkoutMatches(array $p, $checkout): bool
    {
        if (!is_string($checkout) || $checkout === '' || empty($p['checkout_id'])) { return false; }
        try { return Domain::remoteId($checkout) === Domain::remoteId($p['checkout_id']); }
        catch (\Throwable $e) { return false; }
    }

    /** This API result only supplies candidate IDs. Credit always requires a separate ID query. */
    private static function discover(Gateway $api, array $p): array
    {
        $created = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', (string) ($p['created_at'] ?? ''), new \DateTimeZone('UTC'));
        if (!$created) { throw new \RuntimeException('Data da tentativa inválida para consultar o K8. Informe o ID completo da transação.'); }
        $data = $api->request('/api/ConsultaTransacoes', ['DataHoraInicial' => $created->modify('-5 minutes')->format(DATE_ATOM), 'DataHoraFinal' => gmdate(DATE_ATOM, time() + 60), 'Tipo' => $p['method'], 'Estado' => 'Todos', 'TipoFiltro' => 'dataemissao']);
        if (!isset($data['Transacoes']) || !is_array($data['Transacoes'])) { throw new \RuntimeException('Lista de transações K8 inválida. Informe o ID completo da transação para consultar.'); }
        $ids = [];
        foreach ($data['Transacoes'] as $row) {
            if (!is_array($row)) { throw new \RuntimeException('Lista de transações K8 inválida.'); }
            if (($row['Referencia'] ?? '') !== $p['reference'] && ($row['NumeroPedido'] ?? '') !== $p['reference'] && !self::checkoutMatches($p, $row['IdentificadorCheckout'] ?? $row['CheckoutId'] ?? '')) { continue; }
            if (!is_string($row['Identificador'] ?? null) && !is_int($row['Identificador'] ?? null)) { throw new \RuntimeException('Identificador inválido na lista de transações K8.'); }
            $ids[] = Domain::remoteId((string) ($row['Identificador'] ?? ''));
        }
        return array_values(array_unique($ids));
    }

    /** Caller holds the registration lock. Callback payloads never call this directly. */
    public static function applyVerified(array $p, array $r, array $data, ?string $expectedId = null): void
    {
        global $wpdb;
        self::diagnose($p, $r, $data, $expectedId);
        foreach (['Identificador', 'Referencia', 'NumeroPedido', 'IdentificadorCheckout', 'CheckoutId', 'ClienteCPFCNPJ'] as $field) {
            if (isset($data[$field]) && !is_string($data[$field]) && !is_int($data[$field])) { throw new \RuntimeException('Campo ' . $field . ' inválido na resposta K8.'); }
        }
        $id = Domain::remoteId((string) ($data['Identificador'] ?? ''));
        if ($expectedId !== null) { $expectedId = Domain::remoteId($expectedId); }
        $reference = (string) ($data['Referencia'] ?? ''); $order = (string) ($data['NumeroPedido'] ?? '');
        $checkout = (string) ($data['IdentificadorCheckout'] ?? $data['CheckoutId'] ?? '');
        $matches = $reference === $p['reference'] || $order === $p['reference'] || self::checkoutMatches($p, $checkout);
        $problems = [];
        if ($expectedId && $id !== $expectedId) { $problems[] = 'ID retornado diferente do ID consultado'; }
        if (!$matches) { $problems[] = 'nenhum pedido, referência ou checkout corresponde à tentativa'; }
        if ($reference !== '' && $reference !== $p['reference']) { $problems[] = 'referência diferente'; }
        if ($order !== '' && $order !== $p['reference']) { $problems[] = 'número do pedido diferente'; }
        if ($checkout !== '' && !empty($p['checkout_id']) && !self::checkoutMatches($p, $checkout)) { $problems[] = 'checkout diferente'; }
        if (preg_replace('/\D/', '', (string) ($data['ClienteCPFCNPJ'] ?? '')) !== Security::unseal(($p['cpf_snapshot'] ?? '') ?: $r['cpf_encrypted'])) { $problems[] = 'CPF diferente ou ausente'; }
        if ($problems) { throw new \RuntimeException('A transação K8 não corresponde à inscrição: ' . implode('; ', $problems) . '.'); }
        $table = Store::table('transactions');
        $existing = self::ledger($p, $id);
        $credit = Domain::credit($data, (int) $p['amount'], $p['method'], (int) ($existing['credit'] ?? 0));
        $row = ['payment_id' => $p['id'], 'scope' => $p['scope'], 'remote_id' => $id, 'remote_state' => $data['Estado'], 'credit' => $credit, 'paid_at' => Domain::cut((string) ($data['DataPagamento'] ?? ''), 40), 'synced_at' => current_time('mysql', true)];
        if (in_array($data['Estado'], ['Cancelada', 'Estornado', 'Rejeitada'], true) && in_array($existing['cancel_status'] ?? '', ['sending', 'requested', 'review'], true)) { $row['cancel_status'] = 'confirmed'; $row['cancel_error'] = ''; }
        elseif (self::cancellationState($data['Estado'])) { $row['cancel_status'] = in_array($data['Estado'], ['ErroEstorno', 'FalhaCancelamento'], true) ? 'review' : 'requested'; }
        Store::begin();
        try {
            if ($existing) { Store::update('transactions', $row, ['id' => $existing['id']]); } else { Store::insert('transactions', $row); }
            $sum = $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(credit),0) FROM $table WHERE payment_id=%d", $p['id']));
            if ($sum === null || !empty($wpdb->last_error)) { throw new \RuntimeException('Falha ao conferir o crédito financeiro. A conciliação será repetida.'); }
            $sum = (int) $sum;
            $state = $sum > 0 ? 'paid' : (Domain::terminal($data['Estado']) ? 'closed' : 'pending');
            foreach (Store::transactions((int) $p['id']) as $tx) { if (in_array($tx['cancel_status'] ?? '', ['sending', 'requested', 'review'], true)) { $state = 'canceling'; break; } }
            Store::update('payments', ['state' => $state, 'synced_at' => current_time('mysql', true), 'last_error' => '', 'webhook_error' => ''], ['id' => $p['id']]);
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
