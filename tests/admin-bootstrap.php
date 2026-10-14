<?php
require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/includes/Registrations.php';
require_once dirname(__DIR__) . '/includes/Admin.php';
$GLOBALS['test_admin'] = true;
function current_user_can($capability) { return $GLOBALS['test_admin'] && $capability === 'manage_options'; }
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
