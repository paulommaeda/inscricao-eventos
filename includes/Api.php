<?php
namespace InscricaoEventos;
if (!defined('ABSPATH')) { exit; }

final class Api
{
    public static function boot(): void { add_action('rest_api_init', [self::class, 'routes']); }
    public static function routes(): void
    {
        register_rest_route('inscricao-eventos/v1', '/bootstrap', ['methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => static fn() => self::respond(static fn() => Security::bootstrap())]);
        register_rest_route('inscricao-eventos/v1', '/events', ['methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => static fn($req) => self::respond(static fn() => self::catalog($req))]);
        foreach (['register', 'lookup', 'account', 'payment', 'refresh', 'logout'] as $action) {
            register_rest_route('inscricao-eventos/v1', '/' . $action, ['methods' => 'POST', 'permission_callback' => [Security::class, 'publicPermission'], 'callback' => static fn($req) => self::respond(static fn() => self::action($action, $req))]);
        }
        register_rest_route('inscricao-eventos/v1', '/webhook/(?P<payment_id>\d+)', ['methods' => ['POST', 'GET'], 'permission_callback' => [self::class, 'webhookPermission'], 'callback' => [self::class, 'webhook']]);
    }
    public static function respond(callable $fn): \WP_REST_Response
    {
        try { $response = new \WP_REST_Response($fn(), 200); }
        catch (\Throwable $e) { $response = new \WP_REST_Response(['message' => $e->getMessage()], 400); }
        $response->header('Cache-Control', 'no-store, private, max-age=0'); $response->header('X-Robots-Tag', 'noindex');
        return $response;
    }
    private static function catalog(\WP_REST_Request $req): array
    {
        $id = absint($req->get_param('event')); $admin = current_user_can('manage_options');
        if ($id) { $e = Store::event($id); $rows = $admin || in_array($e['status'], ['published', 'archived'], true) ? [$e] : []; }
        else { $rows = Store::events(true); }
        return array_map(static function ($e) {
            $lots = Store::lots((int) $e['id']); $today = Domain::today($e); $lot = Domain::quote($e, $lots, $today);
            $info = array_intersect_key($e, array_flip(['name', 'description', 'location', 'event_date', 'registration_start', 'registration_end', 'payment_deadline', 'logo_url', 'hero_url', 'primary_color', 'accent_color', 'background_color', 'terms', 'privacy_url', 'payment_message', 'timezone']));
            $info['id'] = (int) $e['id']; $info['minimum_entry'] = (int) $e['minimum_entry']; $info['allow_pix'] = (bool) $e['allow_pix']; $info['allow_card'] = (bool) $e['allow_card'];
            $info['lots'] = array_map(static fn($l) => ['id' => (int) $l['id'], 'name' => $l['name'], 'starts_on' => $l['starts_on'], 'ends_on' => $l['ends_on'], 'price' => (int) $l['price'], 'max_installments' => (int) $l['max_installments'], 'note' => $l['note']], $lots);
            $info['active_lot'] = $lot ? (int) $lot['id'] : null; $info['open'] = $lot !== null;
            $info['notice'] = $e['status'] === 'draft' ? 'Prévia: defina a data do evento e publique nas configurações.' : ($today < $e['registration_start'] ? 'As inscrições começam em ' . Domain::dateBR($e['registration_start']) . '.' : (!$lot ? 'Novas inscrições encerradas ou indisponíveis. Quem já se inscreveu pode consultar o saldo.' : ''));
            return $info;
        }, $rows);
    }
    private static function action(string $action, \WP_REST_Request $req): array
    {
        $input = $req->get_json_params() ?: [];
        if (!is_array($input)) { throw new \RuntimeException('Requisição inválida.'); }
        if ($action === 'register') { return Payments::register($input); }
        if ($action === 'lookup') {
            Security::limit('lookup-ip:' . Security::ip(), 25, 900);
            $cpf = Domain::cpf((string) ($input['cpf'] ?? '')); Security::limit('lookup-cpf:' . $cpf, 10, 900);
            $r = Store::byCPF(absint($input['event_id'] ?? 0), $cpf);
            if (!$r) { throw new \RuntimeException('Nenhuma inscrição encontrada para este CPF neste evento.'); }
            Security::session((int) $r['id']); Payments::refreshRegistration($r); return Store::publicRegistration($r);
        }
        if ($action === 'logout') { Security::logout(); return ['ok' => true]; }
        $r = Security::registration();
        if (!hash_equals($r['scope'], (new Gateway())->scope())) { throw new \RuntimeException('O ambiente de pagamentos mudou. Consulte novamente sua inscrição.'); }
        if ($action === 'payment') { Security::limit('pay:' . $r['id'], 10, 300); return Payments::create($r, $input['amount'] ?? ''); }
        if ($action === 'refresh') { Security::limit('refresh:' . $r['id'], 15, 300); Payments::refreshRegistration($r); }
        return Store::publicRegistration($r);
    }
    public static function webhookPermission(\WP_REST_Request $req)
    {
        try {
            $p = Store::payment(self::webhookPaymentId($req));
            $query = $req->get_query_params(); $key = $query['key'] ?? '';
            if (!is_string($key)) { throw new \RuntimeException('Token inválido.'); }
            if (!$key || !hash_equals(Payments::callbackKey($p), $key)) { throw new \RuntimeException('Token inválido.'); }
            return true;
        } catch (\Throwable $e) { return new \WP_Error('ie_webhook', 'Webhook não autorizado.', ['status' => 403]); }
    }
    private static function webhookPaymentId(\WP_REST_Request $req): int
    {
        // URL parameters cannot be replaced by a remote "id" in the POST body or query string.
        $url = $req->get_url_params();
        return absint($url['payment_id'] ?? $url['id'] ?? 0);
    }
    public static function webhookRemoteId(\WP_REST_Request $req): ?string
    {
        $json = $req->get_json_params();
        $raw = json_decode($req->get_body(), true);
        $inputs = [$req->get_query_params(), $req->get_body_params(), is_array($json) ? $json : [], is_array($raw) ? $raw : []];
        // The K8 fallback uses ID_BknBank. Prefer this transaction ID over other generic ID fields.
        foreach (['id_bknbank', 'identificador', 'idtransacao', 'id'] as $alias) {
            foreach ($inputs as $input) {
                foreach ($input as $name => $value) {
                    if (strtolower((string) $name) !== $alias || $value === null || $value === '') { continue; }
                    if (!is_string($value) && !is_int($value)) { throw new \RuntimeException('Identificador da notificação K8 inválido.'); }
                    $id = trim((string) $value);
                    if (!preg_match('/^[A-Za-z0-9-]{1,100}$/D', $id)) { throw new \RuntimeException('Identificador da notificação K8 inválido.'); }
                    return $id;
                }
            }
        }
        return null;
    }
    public static function webhook(\WP_REST_Request $req): \WP_REST_Response
    {
        $id = self::webhookPaymentId($req);
        try {
            $remote = self::webhookRemoteId($req);
            Store::update('payments', ['webhook_at' => current_time('mysql', true)], ['id' => $id]);
            Payments::sync($id, $remote);
            Store::update('payments', ['webhook_error' => ''], ['id' => $id]);
            $response = new \WP_REST_Response(['Retorno' => 'OK', 'ok' => true], 200);
        } catch (\Throwable $e) {
            try { Store::update('payments', ['webhook_at' => current_time('mysql', true), 'webhook_error' => Domain::cut($e->getMessage(), 500)], ['id' => $id]); } catch (\Throwable $ignored) { /* Still return a retryable failure. */ }
            $response = new \WP_REST_Response(['Retorno' => 'ERRO', 'message' => 'A conciliação será tentada novamente.'], 503);
        }
        $response->header('Cache-Control', 'no-store, private, max-age=0');
        return $response;
    }
}
