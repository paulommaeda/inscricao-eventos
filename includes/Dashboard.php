<?php
namespace InscricaoEventos;
if (!defined('ABSPATH')) { exit; }

final class Dashboard
{
    public static function boot(): void
    {
        DashboardLogin::boot();
        add_shortcode('inscricao_eventos_dashboard', [self::class, 'shortcode']);
        add_shortcode('dashboard_evento', [self::class, 'shortcode']);
        add_action('rest_api_init', static function () {
            register_rest_route('inscricao-eventos/v1', '/dashboard', ['methods' => 'GET', 'permission_callback' => [self::class, 'permission'], 'callback' => [self::class, 'endpoint']]);
            RegistrationPanel::routes();
        });
    }
    public static function permission()
    {
        return Access::canView() ? true : new \WP_Error('ie_dashboard_forbidden', 'Entre com uma conta administradora ou gerente de eventos para acessar o dashboard.', ['status' => 403]);
    }
    public static function endpoint(\WP_REST_Request $request): \WP_REST_Response
    {
        // WordPress validates the authenticated cookie and wp_rest nonce before the route.
        if (!Access::canView()) {
            $response = new \WP_REST_Response(['message' => 'Acesso restrito a administradores e gerentes de eventos.'], 403);
            $response->header('Cache-Control', 'no-store, private, max-age=0'); return $response;
        }
        return Api::respond(static fn() => Reports::dashboard($request->get_query_params()));
    }
    public static function shortcode($attributes = []): string
    {
        if (!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE', true); }
        if (!headers_sent()) { nocache_headers(); }
        $a = shortcode_atts(['evento' => '0', 'id' => '0'], $attributes);
        Branding::enqueue(); wp_enqueue_style('ie-dashboard', plugins_url('assets/dashboard.css', IE_FILE), ['ie-brand'], IE_VERSION);
        $eventId = absint($a['evento'] ?: $a['id']);
        if (Access::canManage() && function_exists('get_queried_object_id') && get_queried_object_id()) { update_option('ie_dashboard_page_id', (int) get_queried_object_id(), false); }
        if (!Access::canView()) { return DashboardLogin::form($eventId); }
        wp_enqueue_script('ie-registration-modal', plugins_url('assets/registration-modal.js', IE_FILE), [], IE_VERSION, true);
        wp_enqueue_script('ie-dashboard', plugins_url('assets/dashboard.js', IE_FILE), ['ie-registration-modal', 'ie-brand'], IE_VERSION, true);
        $config = ['api' => rest_url('inscricao-eventos/v1/dashboard'), 'registrationApi' => rest_url('inscricao-eventos/v1/dashboard/registration/'), 'nonce' => wp_create_nonce('wp_rest'), 'exportUrl' => admin_url('admin-post.php'), 'exportNonce' => wp_create_nonce('ie_export'), 'loginUrl' => get_permalink(), 'logoutUrl' => wp_logout_url(get_permalink())];
        wp_add_inline_script('ie-dashboard', 'window.IE_DASHBOARD=' . wp_json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';', 'before');
        return '<section class="ie-dashboard" data-event="' . absint($a['evento'] ?: $a['id']) . '" aria-label="Dashboard do evento"><div class="ied-loading" role="status">Carregando dashboard…</div><noscript>Ative o JavaScript para acompanhar as inscrições e o financeiro.</noscript></section>';
    }
}
