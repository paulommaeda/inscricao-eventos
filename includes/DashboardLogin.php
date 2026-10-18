<?php
namespace InscricaoEventos;
if (!defined('ABSPATH')) { exit; }

final class DashboardLogin
{
    public static function boot(): void
    {
        add_action('admin_post_nopriv_ie_dashboard_login', [self::class, 'handle']);
        add_action('admin_post_ie_dashboard_login', [self::class, 'handle']);
        add_action('template_redirect', static function () {
            if (!is_user_logged_in() && is_singular()) { $post = get_post(); if ($post && (has_shortcode($post->post_content, 'inscricao_eventos_dashboard') || has_shortcode($post->post_content, 'dashboard_evento'))) { self::csrf(); } }
        });
        add_filter('show_admin_bar', static fn($show) => Access::canView() && !Access::canManage() ? false : $show);
        add_filter('login_redirect', [self::class, 'redirect'], 10, 3);
    }
    public static function pageURL(): string
    {
        $id = absint(get_option('ie_dashboard_page_id', 0));
        return $id && get_post_status($id) === 'publish' ? (string) get_permalink($id) : home_url('/');
    }
    public static function redirect($redirect, $requested, $user): string
    {
        return !is_wp_error($user) && is_object($user) && user_can($user, Access::VIEW) && !user_can($user, 'manage_options') ? self::pageURL() : (string) $redirect;
    }
    /** Same-site, nonce-bound return URL; never accept a host added by an unrelated redirect filter. */
    public static function target(string $url): string
    {
        $fallback = self::pageURL(); $url = wp_validate_redirect($url, $fallback); if ($url === '') { return $fallback; }
        $host = wp_parse_url($url, PHP_URL_HOST); $home = wp_parse_url(home_url('/'), PHP_URL_HOST);
        return (!$host || strcasecmp((string) $host, (string) $home) === 0) && !preg_match('~(?:^|/)wp-admin(?:/|$)~', (string) wp_parse_url($url, PHP_URL_PATH)) ? $url : $fallback;
    }
    private static function csrf(): string
    {
        $token = $_COOKIE['ie_dashboard_login_csrf'] ?? '';
        if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D', $token)) {
            $token = bin2hex(random_bytes(32));
            if (!headers_sent()) { setcookie('ie_dashboard_login_csrf', $token, ['expires' => time() + HOUR_IN_SECONDS, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax']); }
            $_COOKIE['ie_dashboard_login_csrf'] = $token;
        }
        return $token;
    }
    public static function nonce(string $redirect): string { return wp_create_nonce('ie_dashboard_login:' . $redirect . ':' . Security::hash(self::csrf())); }
    public static function authenticate(array $input): array
    {
        $redirect = self::target(is_string($input['redirect_to'] ?? null) ? $input['redirect_to'] : self::pageURL());
        $token = $_COOKIE['ie_dashboard_login_csrf'] ?? '';
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !is_string($token) || !preg_match('/^[a-f0-9]{64}$/D', $token) || !is_string($input['ie_login_nonce'] ?? null) || !wp_verify_nonce($input['ie_login_nonce'], 'ie_dashboard_login:' . $redirect . ':' . Security::hash($token))) { return ['url' => $redirect, 'error' => 'expired']; }
        try { Security::limit('dashboard-login:' . Security::ip(), 10, 900); }
        catch (\Throwable $e) { return ['url' => $redirect, 'error' => 'limited']; }
        if (!is_string($input['log'] ?? null) || !is_string($input['pwd'] ?? null) || !$input['log'] || !$input['pwd'] || strlen($input['log']) > 254 || strlen($input['pwd']) > 4096) { return ['url' => $redirect, 'error' => 'credentials']; }
        $user = wp_signon(['user_login' => trim($input['log']), 'user_password' => $input['pwd'], 'remember' => !empty($input['rememberme'])], is_ssl());
        if (is_wp_error($user)) { return ['url' => $redirect, 'error' => 'credentials']; }
        if (!user_can($user, 'manage_options') && !user_can($user, Access::VIEW)) { wp_logout(); return ['url' => $redirect, 'error' => 'forbidden']; }
        wp_set_current_user((int) $user->ID);
        return ['url' => $redirect, 'error' => ''];
    }
    public static function handle(): void
    {
        nocache_headers(); $result = self::authenticate((array) wp_unslash($_POST));
        wp_safe_redirect($result['error'] ? add_query_arg('ie_login', $result['error'], $result['url']) : $result['url']); exit;
    }
    public static function form(int $eventId): string
    {
        $event = Branding::loginEvent($eventId); $redirect = self::target((string) get_permalink());
        $messages = ['credentials' => 'Não foi possível entrar. Confira seu usuário ou e-mail e sua senha.', 'expired' => 'Sua sessão de login expirou. Tente novamente.', 'limited' => 'Aguarde alguns minutos antes de tentar entrar novamente.', 'forbidden' => 'Esta conta não tem acesso ao dashboard. Solicite à organização o perfil Gerente de Eventos.'];
        $error = is_string($_GET['ie_login'] ?? null) ? ($messages[$_GET['ie_login']] ?? '') : '';
        if (is_user_logged_in()) { return '<section class="ied-login"><div class="ied-login-body"><h2>Acesso ao dashboard</h2><p>Dashboard restrito a administradores e gerentes de eventos. Solicite à organização o acesso para sua conta.</p><a href="' . esc_url(wp_logout_url($redirect)) . '">Sair e entrar com outra conta</a></div></section>'; }
        $cover = $event && $event['cover_url'] ? '<img class="ied-login-cover" src="' . esc_url($event['cover_url']) . '" alt="' . esc_attr($event['brand_preset'] === 'origens' ? 'Origens — O fogo permanece' : $event['name']) . '">' : '';
        $form = '<section class="ied-login' . ($event && $event['brand_preset'] === 'origens' ? ' ie-origens' : '') . '">' . $cover . '<div class="ied-login-body"><div class="ied-login-intro"><span class="ied-eyebrow">ÁREA DA ORGANIZAÇÃO</span><h2>Entre no dashboard</h2><p>' . esc_html($event['name'] ?? 'Inscrições de Eventos') . '</p><p>Dashboard restrito a administradores e gerentes de eventos. Acompanhe inscrições, recebimentos e saldos.</p></div><form class="ied-login-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="ie_dashboard_login"><input type="hidden" name="redirect_to" value="' . esc_attr($redirect) . '"><input type="hidden" name="ie_login_nonce" value="' . esc_attr(self::nonce($redirect)) . '">';
        if ($error) { $form .= '<p class="ied-login-error" role="alert">' . esc_html($error) . '</p>'; }
        return $form . '<label>Usuário ou e-mail<input name="log" autocomplete="username" maxlength="254" required></label><label>Senha<input name="pwd" type="password" autocomplete="current-password" required></label><label class="ied-login-remember"><input name="rememberme" type="checkbox" value="1"> Manter conectado</label><button type="submit">Entrar no dashboard</button><a href="' . esc_url(wp_lostpassword_url($redirect)) . '">Esqueci minha senha</a></form></div></section>';
    }
}
