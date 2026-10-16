<?php
/** Read-only dashboard fixture: synthetic records and the real PHP report/controller code. */
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (in_array($path, ['/assets/dashboard.css', '/assets/dashboard.js'], true)) { header('Content-Type: ' . (str_ends_with($path, '.css') ? 'text/css' : 'application/javascript')); readfile(dirname(__DIR__) . $path); return; }
require __DIR__ . '/admin-bootstrap.php';
use InscricaoEventos\Store as S;
use InscricaoEventos\Security as C;
use InscricaoEventos\Gateway as G;
use InscricaoEventos\Dashboard as D;
use InscricaoEventos\Admin as A;
$GLOBALS['test_admin_base'] = 'http://' . $_SERVER['HTTP_HOST'];
$GLOBALS['test_admin'] = ($_COOKIE['ie_test_admin'] ?? '') === '1'; $GLOBALS['test_manager'] = ($_COOKIE['ie_test_manager'] ?? '') === '1'; $GLOBALS['test_logged_in'] = $GLOBALS['test_admin'] || $GLOBALS['test_manager'];
update_option('ie_settings', ['environment' => 'sandbox', 'credentials' => C::seal(json_encode(['id' => 'dashboard-fixture', 'secret' => 'fictional', 'aes' => '1234567890abcdef']))]);
$scope = (new G())->scope(); $today = new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));
$event = array_merge(S::sample(), ['name' => 'Encontro da Comunidade', 'location' => 'Recanto das Oliveiras', 'status' => 'published', 'event_date' => $today->modify('+120 days')->format('Y-m-d'), 'payment_deadline' => $today->modify('+115 days')->format('Y-m-d'), 'registration_start' => $today->modify('-15 days')->format('Y-m-d'), 'registration_end' => $today->modify('+30 days')->format('Y-m-d')]);
$eid = S::insert('events', $event);
S::insert('lots', ['event_id' => $eid, 'name' => 'Lote 1', 'price' => 49000, 'max_installments' => 10, 'starts_on' => $event['registration_start'], 'ends_on' => $today->modify('+10 days')->format('Y-m-d')]);
S::insert('lots', ['event_id' => $eid, 'name' => 'Lote 2', 'price' => 52000, 'max_installments' => 8, 'starts_on' => $today->modify('+11 days')->format('Y-m-d'), 'ends_on' => $event['registration_end']]);
$eid2 = S::insert('events', array_merge($event, ['name' => 'Segundo encontro']));
for ($i = 0; $i < 34; $i++) {
    $method = $i % 3 === 0 ? 'Cartao' : 'Pix'; $credit = $i % 4 === 1 ? 10000 : ($i % 4 === 2 ? 0 : 49000);
    $created = $today->modify('-' . (15 - $i % 15) . ' days')->setTime(12, 0)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $rid = S::insert('registrations', ['event_id' => $i === 33 ? $eid2 : $eid, 'scope' => $i === 32 ? 'other-account' : $scope, 'lot_id' => $i < 15 ? 1 : 2, 'lot_name' => $i < 15 ? 'Lote 1' : 'Lote 2', 'total' => 49000, 'max_installments' => 10, 'method' => $method, 'status' => $i === 30 ? 'cancelled' : ($i === 31 ? 'deleted' : 'active'), 'name' => $i === 0 ? 'João da Silva' : 'Participante ' . str_pad($i + 1, 2, '0', STR_PAD_LEFT), 'email' => 'pessoa' . $i . '@example.test', 'phone' => '41999999999', 'cpf_hash' => C::hash('cpf:' . ($i === 0 ? '52998224725' : 'fixture' . $i)), 'cpf_encrypted' => C::seal('52998224725'), 'created_at' => $created, 'terms_snapshot' => 'Teste', 'terms_accepted_at' => $created]);
    $parts = $credit > 10000 ? [$credit - 10000, 10000] : [$credit];
    foreach ($parts as $j => $part) {
        $pid = S::insert('payments', ['registration_id' => $rid, 'scope' => $i === 32 ? 'other-account' : $scope, 'reference' => 'IEfixture' . $i . '-' . $j, 'amount' => max(100, $part), 'method' => $method, 'state' => $i === 2 ? 'review' : ($part ? 'paid' : 'pending'), 'last_error' => $i === 2 ? 'Consulta aguardando conferência' : '', 'webhook_error' => '', 'checkout_url' => '', 'operator_note' => '']);
        S::insert('transactions', ['payment_id' => $pid, 'scope' => $scope, 'remote_id' => 'synthetic-' . $pid, 'remote_state' => $part ? 'Aprovada' : 'Pendente', 'credit' => $part, 'synced_at' => $created]);
    }
}
if (($_COOKIE['ie_manual_fixture'] ?? '') === '1') {
    S::insert('manuals', ['registration_id' => 3, 'payment_id' => 0, 'amount' => 10000, 'method' => 'Dinheiro', 'paid_on' => $today->format('Y-m-d'), 'note' => 'Comprovante privado da organização', 'request_key' => str_repeat('f', 64), 'actor_id' => 1, 'created_at' => current_time('mysql', true), 'reversed_at' => null, 'reversed_by' => 0, 'reverse_note' => '', 'resolved_at' => null, 'resolved_state' => '']);
}
try {
    if ($path === '/api/dashboard') {
        // Mimic WordPress cookie + wp_rest nonce authentication before calling the real route.
        if (($_SERVER['HTTP_X_WP_NONCE'] ?? '') !== wp_create_nonce('wp_rest')) { $GLOBALS['test_admin'] = false; $GLOBALS['test_manager'] = false; }
        $response = D::endpoint(new WP_REST_Request([], $_GET)); http_response_code($response->get_status());
        foreach ($response->headers as $name => $value) header($name . ': ' . $value);
        header('Content-Type: application/json'); echo json_encode($response->get_data()); return;
    }
    if ($path === '/admin-post.php') { if (($_POST['action'] ?? '') !== 'ie_export') throw new RuntimeException('Unsupported action'); A::export(); return; }
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="pt-BR"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Dashboard do evento</title><style>body{margin:0;padding:22px;background:#fff;color:#213d36;font:14px system-ui}@media(max-width:520px){body{padding:8px}}</style>';
    echo D::shortcode($path === '/all' ? [] : ['evento' => $eid]);
    if (InscricaoEventos\Access::canView()) {
        $inline = $GLOBALS['test_inline']['ie-dashboard'] . 'window.IE_DASHBOARD.api=' . json_encode($GLOBALS['test_admin_base'] . '/api/dashboard') . ';';
        echo '<link rel="stylesheet" href="/assets/dashboard.css"><script>' . $inline . '</script><script src="/assets/dashboard.js"></script>';
    }
    echo '</html>';
} catch (Throwable $e) { http_response_code(403); echo htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'); }
