<?php
require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/includes/Registrations.php';
require_once dirname(__DIR__) . '/includes/Reports.php';
require_once dirname(__DIR__) . '/includes/Api.php';
require_once dirname(__DIR__) . '/includes/DashboardLogin.php';
require_once dirname(__DIR__) . '/includes/RegistrationPanel.php';
require_once dirname(__DIR__) . '/includes/Dashboard.php';
require_once dirname(__DIR__) . '/includes/Admin.php';
$GLOBALS['test_admin'] = true;
function current_user_can($capability) { return ($GLOBALS['test_admin'] && in_array($capability, ['manage_options', 'ie_view_events', 'read'], true)) || (!empty($GLOBALS['test_manager']) && in_array($capability, ['ie_view_events', 'read'], true)); }
function wp_die($message) { throw new RuntimeException((string) $message); }
function wp_unslash($value) { return is_array($value) ? array_map('wp_unslash', $value) : stripslashes((string) $value); }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value)); }
function esc_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function esc_attr($value) { return esc_html($value); }
function esc_textarea($value) { return esc_html($value); }
function esc_url($value) { return esc_html($value); }
function admin_url($path) { return ($GLOBALS['test_admin_base'] ?? 'https://event.test') . '/' . $path; }
function checked($left, $right, $echo = true) { $s = (string) $left === (string) $right ? 'checked' : ''; if ($echo) echo $s; return $s; }
function selected($left, $right, $echo = true) { $s = (string) $left === (string) $right ? 'selected' : ''; if ($echo) echo $s; return $s; }
function wp_nonce_field($action) { echo '<input type="hidden" name="_wpnonce" value="' . InscricaoEventos\Security::hash('nonce:' . $action) . '">'; }
function check_admin_referer($action) { if (!hash_equals(InscricaoEventos\Security::hash('nonce:' . $action), (string) ($_POST['_wpnonce'] ?? ''))) { wp_die('Nonce inválido.'); } }
function wp_safe_redirect($url) { header('Location: ' . $url, true, 303); }
function is_user_logged_in() { return $GLOBALS['test_logged_in'] ?? $GLOBALS['test_admin']; }
function wp_create_nonce($action) { return InscricaoEventos\Security::hash('nonce:' . $action); }
function wp_login_url($redirect) { return 'https://event.test/wp-login.php?redirect_to=' . urlencode($redirect); }
function shortcode_atts($defaults, $attributes) { return array_merge($defaults, array_intersect_key((array) $attributes, $defaults)); }
function plugins_url($path, $file) { return '/' . $path; }
function nocache_headers() { if (!headers_sent()) header('Cache-Control: no-store, private'); }
function wp_enqueue_style(...$args) { $GLOBALS['test_styles'][$args[0]] = $args; }
function wp_enqueue_script(...$args) { $GLOBALS['test_scripts'][$args[0]] = $args; }
function wp_add_inline_script($handle, $content, $position) { $GLOBALS['test_inline'][$handle] = $content; }
if (!defined('IE_FILE')) define('IE_FILE', dirname(__DIR__) . '/inscricao-eventos.php');

class TestRole {
    public function __construct(public string $name, public array $capabilities = []) {}
    public function add_cap($key, $value = true) { $this->capabilities[$key] = $value; }
}
function get_role($name) { return $GLOBALS['test_roles'][$name] ?? null; }
function add_role($name, $label, $capabilities) { if (get_role($name)) return null; return $GLOBALS['test_roles'][$name] = new TestRole($name, $capabilities); }

// WordPress authentication doubles. The browser fixture uses fictional users only.
function wp_verify_nonce($nonce, $action) { return is_string($nonce) && hash_equals(wp_create_nonce($action), $nonce); }
function get_post_status($id) { return $GLOBALS['test_post_status'][$id] ?? 'publish'; }
function get_queried_object_id() { return $GLOBALS['test_page_id'] ?? 0; }
function wp_logout_url($redirect) { return add_query_arg('ie_logout', '1', $redirect); }
function wp_lostpassword_url($redirect) { return home_url('/wp-login.php?action=lostpassword&redirect_to=' . urlencode($redirect)); }
function wp_validate_redirect($url, $fallback = '') { $scheme = parse_url($url, PHP_URL_SCHEME); $host = parse_url($url, PHP_URL_HOST); return (!$scheme || in_array($scheme, ['http', 'https'], true)) && (!$host || $host === parse_url(home_url('/'), PHP_URL_HOST)) ? $url : $fallback; }
function user_can($user, $cap) { return !empty($user->caps[$cap]); }
function wp_signon($credentials, $secure = false) {
    $GLOBALS['test_signon_calls'][] = $credentials;
    $name = $credentials['user_login'];
    if (!in_array($name, ['admin.fixture', 'gerente.fixture', 'pessoa.fixture'], true) || $credentials['user_password'] !== 'SenhaFicticia!') return new WP_Error('invalid_login', 'Invalid fixture credentials');
    $role = $name === 'admin.fixture' ? 'admin' : ($name === 'gerente.fixture' ? 'manager' : 'person');
    if (!headers_sent()) setcookie('ie_test_' . $role, '1', ['path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    return (object) ['ID' => $role === 'admin' ? 1 : 2, 'caps' => ['manage_options' => $role === 'admin', 'ie_view_events' => $role !== 'person']];
}
function wp_set_current_user($id) { $GLOBALS['test_logged_in'] = true; return (object) ['ID' => $id]; }
function wp_logout() { foreach (['admin','manager','person'] as $r) if (!headers_sent()) setcookie('ie_test_' . $r, '', ['path' => '/', 'expires' => 1]); $GLOBALS['test_admin'] = false; $GLOBALS['test_manager'] = false; $GLOBALS['test_logged_in'] = false; }
