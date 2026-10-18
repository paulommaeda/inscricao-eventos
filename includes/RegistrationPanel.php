<?php
namespace InscricaoEventos;
if (!defined('ABSPATH')) { exit; }

/** Private dashboard detail and actions. Reuses the existing transactional domain services. */
final class RegistrationPanel
{
    public static function routes(): void
    {
        register_rest_route('inscricao-eventos/v1', '/dashboard/registration/(?P<id>\d+)', [
            ['methods' => 'GET', 'permission_callback' => [Dashboard::class, 'permission'], 'callback' => [self::class, 'read']],
            ['methods' => 'POST', 'permission_callback' => [self::class, 'permission'], 'callback' => [self::class, 'write']],
        ]);
    }
    public static function permission() { return Access::canManage() ? true : new \WP_Error('ie_manager_readonly', 'Esta ação exige administrador. Gerentes têm somente consulta.', ['status' => 403]); }
    private static function denied(string $message): \WP_REST_Response
    {
        $response = new \WP_REST_Response(['message' => $message], 403); $response->header('Cache-Control', 'no-store, private, max-age=0'); return $response;
    }
    private static function registration(\WP_REST_Request $req): array
    {
        $route = $req->get_url_params(); $r = Store::registration(absint($route['id'] ?? 0));
        $filters = Reports::filters($req->get_query_params()); $event = $filters['event']; $all = (bool) $filters['all_scopes'];
        if (($event && (int) $r['event_id'] !== $event) || (!$all && !in_array($r['scope'], [Gateway::formScope(), Gateway::offlineScope()], true))) { throw new \RuntimeException('Inscrição indisponível neste evento ou conta. Atualize os filtros.'); }
        return $r;
    }
    public static function read(\WP_REST_Request $req): \WP_REST_Response
    {
        if (!Access::canView()) { return self::denied('Entre como administrador ou gerente de eventos.'); }
        return Api::respond(static fn() => self::data(self::registration($req)));
    }
    public static function data(array $r): array
    {
        if (!Access::canView()) { throw new \RuntimeException('Acesso negado.'); }
        $event = Store::event((int) $r['event_id']); $b = Store::balance($r);
        $fields = array_intersect_key($r, array_flip(['id', 'event_id', 'name', 'email', 'phone', 'lot_name', 'method', 'status', 'created_at', 'terms_accepted_at', 'terms_snapshot', 'admin_note', 'max_installments']));
        $fields['registration_status'] = $r['status'] ?? 'active'; unset($fields['status']);
        $fields['id'] = (int) $r['id']; $fields['event_id'] = (int) $r['event_id']; $fields['cpf'] = Security::unseal($r['cpf_encrypted']);
        $payments = array_map(static function ($p) {
            $row = array_intersect_key($p, array_flip(['id', 'reference', 'amount', 'method', 'max_installments', 'state', 'created_at', 'synced_at', 'last_error', 'webhook_error', 'operator_note', 'remote_id_hint', 'credit', 'gateway_credit', 'manual_credit']));
            $row['id'] = (int) $p['id']; $row['amount'] = (int) $p['amount']; $row['manual_settled'] = Manual::settled($p);
            $row['transactions'] = array_map(static fn($t) => array_intersect_key($t, array_flip(['remote_id', 'remote_state', 'credit', 'synced_at', 'cancel_status', 'cancel_operation', 'cancel_error'])), Store::transactions((int) $p['id']));
            return $row;
        }, Store::payments((int) $r['id']));
        $manuals = array_map(static fn($m) => array_intersect_key($m, array_flip(['id', 'payment_id', 'amount', 'method', 'paid_on', 'note', 'actor_id', 'created_at', 'remote_id', 'reversed_at', 'reversed_by', 'reverse_note', 'resolved_at', 'resolved_state'])), Store::manuals((int) $r['id']));
        $audit = array_map(static fn($a) => array_intersect_key($a, array_flip(['id', 'action', 'note', 'actor_id', 'created_at', 'payment_id'])), Store::auditRows((int) $r['id']));
        return ['registration' => array_merge($fields, $b), 'event' => array_merge(array_intersect_key(Branding::present($event), array_flip(['id', 'name', 'payment_deadline', 'timezone', 'primary_color', 'accent_color', 'brand_preset'])), ['payment_methods' => Domain::methods($event)]), 'card_installments' => Domain::installments($r, $event), 'payments' => $payments, 'manuals' => $manuals, 'audit' => $audit, 'can_manage' => Access::canManage(), 'today' => Domain::today($event), 'request_token' => Access::canManage() ? bin2hex(random_bytes(32)) : ''];
    }
    public static function write(\WP_REST_Request $req): \WP_REST_Response
    {
        if (!Access::canManage()) { return self::denied('Esta ação exige administrador. Gerentes têm somente consulta.'); }
        // Cookie authentication checks this nonce in WordPress as well; enforce it on direct handler use too.
        if (!wp_verify_nonce((string) $req->get_header('x-wp-nonce'), 'wp_rest')) { return self::denied('Sua sessão expirou. Entre novamente no dashboard.'); }
        return Api::respond(static function () use ($req) {
            $r = self::registration($req); $id = (int) $r['id']; $input = $req->get_json_params();
            if (!is_array($input) || !is_string($input['action'] ?? null)) { throw new \RuntimeException('Ação inválida.'); }
            $action = $input['action']; $confirmed = ($input['confirmed'] ?? false) === true;
            $text = static fn($key, $default = '') => is_string($input[$key] ?? $default) ? (string) ($input[$key] ?? $default) : throw new \RuntimeException('Confira os campos enviados.');
            $payment = null;
            if (in_array($action, ['sync', 'cancel_payment', 'review_cancellation', 'recover', 'link_manual'], true) || ($action === 'manual' && !empty($input['payment_id']))) {
                $payment = Store::payment(absint($input['payment_id'] ?? 0));
                if ((int) $payment['registration_id'] !== $id) { throw new \RuntimeException('Esta cobrança não pertence à inscrição.'); }
            }
            switch ($action) {
                case 'save': Registrations::save($id, $input); break;
                case 'manual': Manual::save($id, $input); break;
                case 'reverse_manual':
                    $manual = Store::manual(absint($input['manual_id'] ?? 0));
                    if ((int) $manual['registration_id'] !== $id) { throw new \RuntimeException('Esta baixa não pertence à inscrição.'); }
                    Manual::reverse((int) $manual['id'], $text('note'), $confirmed); break;
                case 'link_manual': Manual::linkK8($id, (int) $payment['id'], absint($input['manual_id'] ?? 0), $input); break;
                case 'sync': Payments::sync((int) $payment['id'], $text('remote_id') ?: null); break;
                case 'cancel_payment': Payments::cancel((int) $payment['id'], $text('remote_id'), $text('operation'), $text('note'), $confirmed); break;
                case 'review_cancellation': Payments::reviewCancellation((int) $payment['id'], $text('remote_id'), $text('note'), $confirmed); break;
                case 'recover':
                    if ($text('mode') === 'close' && !$confirmed) { throw new \RuntimeException('Confirme a conferência no K8 antes de liberar outra tentativa.'); }
                    if (!in_array($text('mode'), ['close', 'attach'], true)) { throw new \RuntimeException('Recuperação inválida.'); }
                    Payments::recover((int) $payment['id'], $text('checkout_id'), $text('checkout_url'), $text('mode') === 'close'); break;
                case 'trash':
                    if (!$confirmed) { throw new \RuntimeException('Confirme a exclusão da inscrição.'); }
                    Registrations::trash($id); break;
                case 'restore': Registrations::restore($id); break;
                case 'purge':
                    if (!$confirmed || $text('confirmation') !== 'EXCLUIR ' . $id) { throw new \RuntimeException('Digite exatamente EXCLUIR ' . $id . ' e confirme a exclusão permanente.'); }
                    Registrations::purge($id); return ['deleted' => true];
                default: throw new \RuntimeException('Ação inválida.');
            }
            return self::data(Store::registration($id));
        });
    }
}
