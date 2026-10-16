<?php
require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/includes/Registrations.php';
require_once dirname(__DIR__) . '/includes/Reports.php';
require_once dirname(__DIR__) . '/includes/Api.php';
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
