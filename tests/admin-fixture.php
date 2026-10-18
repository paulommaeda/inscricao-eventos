<?php
/** Local fixture: K8 calls use bootstrap's mock; no real network payments. */
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (in_array($path, ['/assets/admin.css', '/assets/admin.js'], true)) { header('Content-Type: ' . (str_ends_with($path, '.css') ? 'text/css' : 'application/javascript')); readfile(dirname(__DIR__) . $path); return; }
require __DIR__ . '/admin-bootstrap.php';
use InscricaoEventos\Security as C;
use InscricaoEventos\Store as S;
use InscricaoEventos\Gateway as G;
use InscricaoEventos\Payments as P;
use InscricaoEventos\Admin as A;
$stateFile = getenv('IE_ADMIN_STATE'); if (!$stateFile) { throw new RuntimeException('Fixture state missing.'); }
$GLOBALS['test_admin_base'] = 'http://' . $_SERVER['HTTP_HOST'];
$GLOBALS['test_manager'] = ($_COOKIE['ie_test_manager'] ?? '') === '1'; if ($GLOBALS['test_manager']) $GLOBALS['test_admin'] = false;
$saved = file_exists($stateFile) ? json_decode(file_get_contents($stateFile), true) : null;
if ($saved) {
    $wpdb->hydrate($saved['rows']); $GLOBALS['test_options'] = $saved['options']; $GLOBALS['test_transients'] = $saved['transients']; $GLOBALS['http_calls'] = $saved['calls'];
} else {
    update_option('ie_settings', ['environment' => 'sandbox', 'credentials' => C::seal(json_encode(['id' => 'ui-fixture-client', 'secret' => 'ui-fixture-secret', 'aes' => '1234567890abcdef']))]);
    $g = new G(); $scope = $g->scope(); $GLOBALS['http_queue'][] = ['code' => 200, 'body' => '{"access_token":"ui-fixture-token","expires_in":3600}']; $g->authenticate(); $GLOBALS['http_calls'] = [];
    $e = array_merge(S::sample(), ['event_date' => '2028-02-29', 'payment_deadline' => '2028-02-29', 'status' => 'published', 'minimum_entry' => 100]); $eid = S::insert('events', $e);
    foreach ($e['lots'] as $lot) { $lot['event_id'] = $eid; $lot['price'] = InscricaoEventos\Domain::cents($lot['price']); S::insert('lots', $lot); }
    $rid = S::insert('registrations', ['event_id' => $eid, 'scope' => $scope, 'lot_id' => 1, 'lot_name' => 'Lote 1', 'total' => 49000, 'max_installments' => 10, 'method' => 'Pix', 'name' => 'João Teste', 'email' => 'joao@example.test', 'phone' => '41999999999', 'cpf_hash' => C::hash('cpf:52998224725'), 'cpf_encrypted' => C::seal('52998224725'), 'terms_snapshot' => 'Condições de teste', 'terms_accepted_at' => current_time('mysql', true), 'created_at' => current_time('mysql', true)]);
    $pid = S::insert('payments', ['registration_id' => $rid, 'scope' => $scope, 'reference' => 'IE-uifixture', 'cpf_snapshot' => C::seal('52998224725'), 'amount' => 5000, 'method' => 'Pix', 'checkout_id' => 'checkout-uifixture', 'checkout_url' => 'https://sandbox.meuk8.com.br/PagamentosUI/checkout?id=mock', 'state' => 'pending', 'last_error' => '', 'operator_note' => '']);
    $payload = ['Retorno' => 'OK', 'Identificador' => 'ui-transaction', 'Referencia' => 'IE-uifixture', 'NumeroPedido' => 'IE-uifixture', 'ClienteCPFCNPJ' => '52998224725', 'Tipo' => 'Pix', 'Valor' => 50, 'ValorPago' => 50, 'Estado' => 'Aprovada'];
    if (getenv('IE_ADMIN_K8_MANUAL') === '1') {
        S::update('payments', ['state' => 'review', 'last_error' => 'K8 indisponível (HTTP 503). Falha na sincronização.', 'webhook_error' => 'Retorno não conciliado.'], ['id' => $pid]);
    } else { P::applyVerified(S::payment($pid), S::registration($rid), $payload, 'ui-transaction'); }
    S::update('payments', ['remote_id_hint' => 'ui-transaction'], ['id' => $pid]);
}
register_shutdown_function(static function () use ($stateFile, $wpdb) { file_put_contents($stateFile, json_encode(['rows' => $wpdb->rows, 'options' => $GLOBALS['test_options'], 'transients' => $GLOBALS['test_transients'], 'calls' => $GLOBALS['http_calls']])); });
if ($path === '/_state') { header('Content-Type: application/json'); echo json_encode(['rows' => $wpdb->rows, 'calls' => $GLOBALS['http_calls']]); return; }
try {
    if ($path === '/admin-post.php') {
        $action = $_POST['action'] ?? '';
        if (in_array($action, ['ie_cancel_payment', 'ie_sync_payment'], true)) {
            $p = S::payment((int) ($_POST['payment_id'] ?? 1));
            $data = ['Retorno' => 'OK', 'Identificador' => 'ui-transaction', 'Referencia' => $p['reference'], 'NumeroPedido' => $p['reference'], 'ClienteCPFCNPJ' => C::unseal($p['cpf_snapshot']), 'Tipo' => 'Pix', 'Valor' => 50, 'ValorPago' => 50, 'Estado' => 'Aprovada'];
            $GLOBALS['http_queue'][] = ['code' => 200, 'body' => json_encode($data)];
            if ($action === 'ie_cancel_payment') { $GLOBALS['http_queue'][] = ['code' => 200, 'body' => '{"Retorno":"OK","Posterior":false}']; $data['Estado'] = 'Estornado'; $GLOBALS['http_queue'][] = ['code' => 200, 'body' => json_encode($data)]; }
        }
        $handlers = ['ie_save_event' => 'saveEvent', 'ie_save_registration' => 'saveRegistration', 'ie_registration_action' => 'registrationAction', 'ie_cancel_payment' => 'cancelPayment', 'ie_sync_payment' => 'syncPayment', 'ie_review_cancellation' => 'reviewCancellation', 'ie_delete_event' => 'deleteEvent', 'ie_manual_payment' => 'manualPayment', 'ie_link_manual_k8' => 'linkManualK8', 'ie_reverse_manual' => 'reverseManual', 'ie_export' => 'export'];
        if (!isset($handlers[$action])) throw new RuntimeException('Unsupported fixture action');
        A::{$handlers[$action]}(); return;
    }
    ob_start(); header('Content-Type: text/html; charset=utf-8'); echo '<!doctype html><html lang="pt-BR"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/admin.css"><style>body{margin:0;padding:18px;background:#f0f0f1;font:14px system-ui;color:#1d2327}*{box-sizing:border-box}.button{display:inline-block;padding:8px 12px;color:#215c4a;border:1px solid #a5b5a5;border-radius:4px;background:white;text-decoration:none;cursor:pointer}input,select,textarea{font:inherit;padding:8px;border:1px solid #aab4a7;border-radius:4px}.widefat{width:100%;border-collapse:collapse}.widefat th{text-align:left;padding:12px;background:#e7ece2}.notice{padding:12px;background:#fff;border-left:4px solid #215c4a}code{font-size:12px}</style>';
    if (($_GET['page'] ?? '') === 'ie-settings') { A::settings(); }
    elseif (($_GET['page'] ?? '') === 'ie-events') { A::events(); }
    else { if (!isset($_GET['view']) && !isset($_GET['edit_registration']) && isset($wpdb->rows['wp_ie_registrations'][1])) { $_GET['view'] = 1; } A::dashboard(); }
    echo '<script src="/assets/admin.js"></script></html>'; ob_end_flush();
} catch (Throwable $e) { if (ob_get_level()) ob_end_clean(); http_response_code(403); echo htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'); }
