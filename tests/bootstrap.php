<?php
/** Isolated WordPress / gateway doubles. No real payments or emails are sent. */
define('ABSPATH', __DIR__ . '/');
define('ARRAY_A', 'ARRAY_A');
define('IE_VERSION', '1.0.1');
define('DAY_IN_SECONDS', 86400); define('HOUR_IN_SECONDS', 3600);
$GLOBALS['test_options'] = []; $GLOBALS['test_transients'] = []; $GLOBALS['http_queue'] = []; $GLOBALS['http_calls'] = [];
function wp_salt($scheme) { return 'local-test-salt-never-a-production-secret'; }
function wp_json_encode($data, $flags = 0) { return json_encode($data, $flags); }
function get_option($key, $default = false) { return $GLOBALS['test_options'][$key] ?? $default; }
function update_option($key, $value, $autoload = false) { $GLOBALS['test_options'][$key] = $value; return true; }
function delete_option($key) { unset($GLOBALS['test_options'][$key]); }
function get_transient($key) { return $GLOBALS['test_transients'][$key] ?? false; }
function set_transient($key, $value, $ttl) { $GLOBALS['test_transients'][$key] = $value; }
function delete_transient($key) { unset($GLOBALS['test_transients'][$key]); }
function absint($value) { return abs((int) $value); }
function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
function sanitize_textarea_field($value) { return trim(strip_tags((string) $value)); }
function sanitize_hex_color($value) { return preg_match('/^#[a-f0-9]{6}$/i', $value) ? $value : ''; }
function wp_kses_post($value) { return $value; }
function esc_url_raw($value, $protocols = []) { return $value; }
function current_time($type, $gmt = false) { return gmdate($type === 'mysql' ? 'Y-m-d H:i:s' : $type); }
function is_email($value) { return filter_var($value, FILTER_VALIDATE_EMAIL); }
function is_ssl() { return true; }
function home_url($path = '') { return 'https://event.test' . $path; }
function rest_url($path = '') { return 'https://event.test/wp-json/' . $path; }
function get_permalink($id) { return 'https://event.test/inscricoes'; }
function get_current_user_id() { return 1; }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function add_query_arg($key, $value = null, $url = null) {
    if (is_array($key)) { $params = $key; $url = $value; } else { $params = [$key => $value]; }
    return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($params);
}
class WP_Error { public function __construct(public $code, public $message, public $data = []) {} }
function is_wp_error($result) { return $result instanceof WP_Error; }
function wp_remote_retrieve_response_code($r) { return $r['code']; }
function wp_remote_retrieve_body($r) { return $r['body']; }
function wp_remote_request($url, $args) {
    $GLOBALS['http_calls'][] = [$url, $args];
    if (!$GLOBALS['http_queue']) throw new RuntimeException('Unexpected HTTP call: ' . $url);
    return array_shift($GLOBALS['http_queue']);
}
class WP_REST_Request {
    public function __construct(private array $headers = []) {}
    public function get_header($key) { return $this->headers[strtolower($key)] ?? ''; }
}

final class TestDB
{
    public string $prefix = 'wp_'; public int $insert_id = 0;
    public array $rows = []; private array $seq = []; private ?array $snapshot = null;
    public bool $failLock = false; public ?string $failUpdate = null;
    public bool $failSum = false;
    public function prepare($sql, ...$values) {
        if (isset($values[0]) && is_array($values[0])) $values = $values[0];
        return preg_replace_callback('/%[ds]/', static function ($m) use (&$values) { $v = array_shift($values); return $m[0] === '%d' ? (string) (int) $v : "'" . str_replace("'", "''", (string) $v) . "'"; }, $sql);
    }
    public function insert($table, $data) {
        if ($table === 'wp_ie_transactions') foreach ($this->rows[$table] ?? [] as $r) if ($r['scope'] === $data['scope'] && $r['remote_id'] === $data['remote_id']) return false;
        if ($table === 'wp_ie_registrations') foreach ($this->rows[$table] ?? [] as $r) if ($r['event_id'] === $data['event_id'] && $r['scope'] === $data['scope'] && $r['cpf_hash'] === $data['cpf_hash']) return false;
        $id = ($this->seq[$table] ?? 0) + 1; $this->seq[$table] = $id; $this->insert_id = $id;
        $data = array_merge(['id' => $id], $data);
        if ($table === 'wp_ie_payments') $data = array_merge(['checkout_id' => null, 'synced_at' => null], $data);
        $this->rows[$table][$id] = $data; return 1;
    }
    public function update($table, $data, $where) {
        if ($this->failUpdate === $table) { $this->failUpdate = null; return false; }
        $n = 0; foreach ($this->rows[$table] ?? [] as $id => $r) if ($this->matches($r, $where)) { $this->rows[$table][$id] = array_merge($r, $data); $n++; } return $n;
    }
    public function delete($table, $where) { $n = 0; foreach ($this->rows[$table] ?? [] as $id => $r) if ($this->matches($r, $where)) { unset($this->rows[$table][$id]); $n++; } return $n; }
    private function matches($row, $where) { foreach ($where as $key => $value) if ((string) ($row[$key] ?? '') !== (string) $value) return false; return true; }
    private function where($sql) {
        $w = []; foreach (['id', 'event_id', 'registration_id', 'payment_id', 'scope', 'cpf_hash', 'remote_id', 'token_hash', 'bucket'] as $field) {
            if (preg_match('/(?:\b|\.)' . $field . "=(?:'([^']*)'|(\d+))/", $sql, $m)) $w[$field] = ($m[1] ?? '') !== '' ? $m[1] : ($m[2] ?? '');
        } return $w;
    }
    public function get_var($sql) {
        if ($this->failSum && str_contains($sql, 'SUM(')) { $this->failSum = false; return null; }
        if (str_contains($sql, 'GET_LOCK')) return $this->failLock ? 0 : 1;
        if (str_contains($sql, 'RELEASE_LOCK')) return 1;
        if (str_contains($sql, 'SUM(t.credit)')) {
            preg_match('/p.registration_id=(\d+)/', $sql, $m); $sum = 0;
            foreach ($this->rows['wp_ie_transactions'] ?? [] as $t) if (($this->rows['wp_ie_payments'][$t['payment_id']]['registration_id'] ?? 0) == $m[1]) $sum += $t['credit']; return $sum;
        }
        if (str_contains($sql, 'SUM(credit)')) { $where = $this->where($sql); $sum = 0; foreach ($this->rows['wp_ie_transactions'] ?? [] as $t) if ($this->matches($t, $where)) $sum += $t['credit']; return $sum; }
        $r = $this->get_row($sql); return $r ? ($r['hits'] ?? $r['id']) : null;
    }
    public function get_row($sql, $output = null) { return $this->get_results($sql, $output)[0] ?? null; }
    public function get_results($sql, $output = null) {
        if (!preg_match_all('/FROM (wp_ie_\w+)/', $sql, $m)) throw new RuntimeException('Unexpected SQL: ' . $sql);
        $table = end($m[1]); $where = $this->where($sql); $rows = array_values(array_filter($this->rows[$table] ?? [], fn($r) => $this->matches($r, $where)));
        if ($table === 'wp_ie_payments') {
            usort($rows, static fn($a, $b) => $b['id'] <=> $a['id']);
            foreach ($rows as &$p) { $p['credit'] = 0; foreach ($this->rows['wp_ie_transactions'] ?? [] as $t) if ($t['payment_id'] == $p['id']) $p['credit'] += $t['credit']; } unset($p);
        }
        return $rows;
    }
    public function query($sql) {
        if ($sql === 'START TRANSACTION') { $this->snapshot = $this->rows; return 1; }
        if ($sql === 'COMMIT') { $this->snapshot = null; return 1; }
        if ($sql === 'ROLLBACK') { if ($this->snapshot !== null) $this->rows = $this->snapshot; $this->snapshot = null; return 1; }
        if (preg_match("/INSERT INTO wp_ie_limits .*VALUES \('([^']+)',1,(\d+)\)/", $sql, $m)) {
            foreach ($this->rows['wp_ie_limits'] ?? [] as $id => $r) if ($r['bucket'] === $m[1]) { $this->rows['wp_ie_limits'][$id]['hits']++; return 1; }
            return $this->insert('wp_ie_limits', ['bucket' => $m[1], 'hits' => 1, 'expires_at' => $m[2]]);
        }
        throw new RuntimeException('Unexpected mutation SQL: ' . $sql);
    }
}
$GLOBALS['wpdb'] = new TestDB();
foreach (['Domain', 'Security', 'Store', 'Gateway', 'Payments'] as $class) require_once dirname(__DIR__) . '/includes/' . $class . '.php';
