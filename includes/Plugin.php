<?php
namespace InscricaoEventos;
if (!defined('ABSPATH')) { exit; }

final class Plugin
{
    public static function boot(): void
    {
        Api::boot(); Admin::boot(); Dashboard::boot();
        add_shortcode('inscricao_eventos', [self::class, 'shortcode']);
        add_shortcode('inscricao_evento', [self::class, 'shortcode']);
        add_filter('cron_schedules', static function ($s) { $s['ie_five_minutes'] = ['interval' => 300, 'display' => 'A cada cinco minutos']; return $s; });
        add_action('ie_reconcile', [Payments::class, 'cron']);
        add_action('plugins_loaded', [self::class, 'updates']);
        add_action('plugins_loaded', static function () {
            // Auto-updated code may receive a callback before an administrator opens the dashboard.
            if (get_option('ie_schema_version') !== IE_VERSION) { Store::install(); }
        }, 5);
        add_action('admin_init', static function () {
            if (get_option('ie_schema_version') !== IE_VERSION) { Store::install(); }
            self::schedule();
        });
    }
    private static function schedule(): void
    {
        if (!wp_next_scheduled('ie_reconcile')) { wp_schedule_event(time() + 300, 'ie_five_minutes', 'ie_reconcile'); }
    }
    public static function activate(): void
    {
        if (!extension_loaded('openssl')) { wp_die('Inscrição de Eventos precisa da extensão OpenSSL do PHP.'); }
        try {
            Store::install();
            if (!get_option('ie_sample_created')) {
                $id = Store::saveEvent(Store::sample());
                $page = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Inscrições do retiro', 'post_content' => '[inscricao_eventos evento="' . $id . '"]'], true);
                if (!is_wp_error($page) && $page) { Store::update('events', ['public_page_id' => $page], ['id' => $id]); }
                update_option('ie_sample_created', $id, false);
            }
            self::schedule();
        } catch (\Throwable $e) { wp_die(esc_html($e->getMessage())); }
    }
    public static function deactivate(): void { wp_clear_scheduled_hook('ie_reconcile'); }
    public static function updates(): void
    {
        require_once IE_DIR . 'plugin-update-checker/plugin-update-checker.php';
        $checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker('https://github.com/paulommaeda/inscricao-eventos/', IE_FILE, 'inscricao-eventos');
        $checker->setBranch('main');
        add_filter($checker->getUniqueName('vcs_update_detection_strategies'), static fn($strategies) => array_intersect_key($strategies, ['branch' => true]));
    }
    public static function shortcode($attributes = []): string
    {
        $a = shortcode_atts(['evento' => '0', 'id' => '0'], $attributes);
        wp_enqueue_style('ie-public', plugins_url('assets/public.css', IE_FILE), [], IE_VERSION);
        wp_enqueue_script('ie-public', plugins_url('assets/public.js', IE_FILE), [], IE_VERSION, true);
        $config = ['api' => rest_url('inscricao-eventos/v1/'), 'nonce' => is_user_logged_in() ? wp_create_nonce('wp_rest') : ''];
        wp_add_inline_script('ie-public', 'window.IE_CONFIG=' . wp_json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';', 'before');
        return '<div class="ie-app" data-event="' . (int) ($a['evento'] ?: $a['id']) . '"><div class="ie-loading" role="status">Carregando evento…</div><noscript>Ative o JavaScript do navegador para se inscrever e consultar o saldo.</noscript></div>';
    }
}
