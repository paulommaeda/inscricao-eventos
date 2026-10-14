<?php
require __DIR__ . '/bootstrap.php';
require dirname(__DIR__) . '/includes/Api.php';
use InscricaoEventos\Api as A;
use InscricaoEventos\Gateway as G;
use InscricaoEventos\Payments as P;
use InscricaoEventos\Security as C;
use InscricaoEventos\Store as S;

$checks = 0;
function same($actual, $expected, string $label): void { global $checks; $checks++; if ($actual !== $expected) { throw new RuntimeException($label . ': ' . var_export([$actual, $expected], true)); } }
function fails(callable $fn, string $label): void { global $checks; $checks++; try { $fn(); } catch (Throwable $e) { return; } throw new RuntimeException($label . ' should fail'); }
function queue(array $data, int $code = 200): void { $GLOBALS['http_queue'][] = ['code' => $code, 'body' => json_encode($data)]; }
function notification(array $p, array $query = [], ?array $json = null, array $form = [], string $raw = ''): WP_REST_Request {
    return new WP_REST_Request([], array_merge(['key' => P::callbackKey($p)], $query), ['payment_id' => $p['id']], $json, $form, $raw);
}
function dispatch(WP_REST_Request $req) {
    $permission = A::webhookPermission($req);
    return $permission === true ? A::webhook($req) : $permission;
}
function transaction(array $p, string $id, array $extra = []): array {
    return array_merge(['Retorno' => 'OK', 'Identificador' => $id, 'Tipo' => $p['method'], 'Estado' => 'Aprovada', 'Valor' => $p['amount'] / 100, 'ValorPago' => $p['amount'] / 100, 'Referencia' => $p['reference'], 'NumeroPedido' => $p['reference'], 'ClienteCPFCNPJ' => '52998224725', 'DataPagamento' => '2026-10-20T12:00:00-03:00'], $extra);
}

$aes = '1234567890abcdef';
update_option('ie_settings', ['environment' => 'sandbox', 'credentials' => C::seal(json_encode(['id' => 'mock-client', 'secret' => 'mock-secret', 'aes' => $aes]))]);
$scope = (new G())->scope();
$registrationId = S::insert('registrations', ['event_id' => 1, 'scope' => $scope, 'total' => 49000, 'cpf_encrypted' => C::seal('52998224725')]);
function payment(string $reference): array {
    global $registrationId, $scope;
    $id = S::insert('payments', ['registration_id' => $registrationId, 'scope' => $scope, 'reference' => $reference, 'amount' => 10000, 'method' => 'Pix', 'state' => 'pending', 'checkout_id' => 'checkout-' . $reference, 'checkout_url' => 'https://sandbox.meuk8.com.br/PagamentosUI/checkout?id=mock', 'last_error' => '', 'operator_note' => '']);
    return S::payment($id);
}

A::routes();
$route = $GLOBALS['test_routes']['inscricao-eventos/v1/webhook/(?P<payment_id>\d+)'];
same($route['methods'], ['POST', 'GET'], 'POST and recovery GET remain supported on the same URL');
same($route['permission_callback'], [A::class, 'webhookPermission'], 'Signed callback required');

foreach ([
    new WP_REST_Request([], ['ID_BknBank' => 'tx-query']),
    new WP_REST_Request([], [], [], ['Identificador' => 'tx-json']),
    new WP_REST_Request([], [], [], null, ['IdTransacao' => 'tx-form']),
    new WP_REST_Request([], [], [], null, [], '{"Identificador":"tx-raw"}'),
    new WP_REST_Request([], ['id' => 'tx-generic']),
    new WP_REST_Request([], ['id_bknbank' => 'tx-case'])
] as $i => $req) { same(A::webhookRemoteId($req), ['tx-query', 'tx-json', 'tx-form', 'tx-raw', 'tx-generic', 'tx-case'][$i], 'K8 ID location/alias ' . $i); }
same(A::webhookRemoteId(new WP_REST_Request([], ['ID_BknBank' => 'tx-primary', 'id' => 'other'], [], ['Identificador' => 'other'])), 'tx-primary', 'Explicit K8 query ID wins over generic fields');
same(A::webhookRemoteId(new WP_REST_Request([], ['key' => 'mock-key'], ['payment_id' => 1])), null, 'Path payment ID is never a remote transaction ID');
foreach ([['ID_BknBank' => ['tx']], ['Identificador' => 'bad/id'], ['id' => str_repeat('x', 101)]] as $input) { fails(fn() => A::webhookRemoteId(new WP_REST_Request([], $input)), 'Invalid remote identifier rejected'); }

// Existing URLs retain their signature. Body fields must not shadow the route or signed query.
$p = payment('IElegacy'); $other = payment('IEother');
same(P::callbackKey($p), C::hash('webhook:' . $p['id'] . ':' . $p['reference']), 'Old checkout token remains valid');
$shadow = notification($p, ['ID_BknBank' => 'tx-one', 'id' => 'remote-query-id'], ['id' => 999, 'payment_id' => 999, 'key' => 'body-key', 'Estado' => 'Aprovada', 'ValorPago' => 100000]);
same($shadow->get_param('payment_id'), 999, 'Test includes the WordPress body/URL parameter collision');
same(A::webhookPermission($shadow), true, 'Path ID and query token are authoritative');
foreach ([notification($p, ['key' => P::callbackKey($other)]), notification($p, ['key' => ['bad']]), notification($p, ['key' => ''], ['key' => P::callbackKey($p)])] as $unauthorized) { same(dispatch($unauthorized) instanceof WP_Error, true, 'Invalid token rejected before callback'); }
same(count($GLOBALS['http_calls']), 0, 'Unauthorized notifications never query K8');

queue(['access_token' => 'mock-token', 'expires_in' => 3600]);
queue(transaction($p, 'tx-one', ['Referencia' => null, 'IdentificadorCheckout' => null]));
$response = dispatch($shadow);
same($response->get_status(), 200, 'Empty-body POST with ID_BknBank can confirm');
same($response->get_data(), ['Retorno' => 'OK', 'ok' => true], 'Confirmation acknowledgment');
same(S::paid($registrationId), 10000, 'API-verified legacy entry credited');
same(S::payment($other['id'])['state'], 'pending', 'Body ID cannot update another payment');
same(S::payment($p['id'])['remote_id_hint'], 'tx-one', 'Transaction ID retained for reconciliation');
same(S::payment($p['id'])['webhook_at'] !== null, true, 'Receipt timestamp retained');
same(str_contains($response->headers['Cache-Control'], 'no-store'), true, 'Webhook responses cannot be cached');
$call = end($GLOBALS['http_calls']);
same(str_ends_with($call[0], '/api/ConsultaTransacao'), true, 'Queries transaction ID instead of checkout ID');
same(G::decode($call[1]['body'], $aes), ['Identificador' => 'tx-one'], 'Full remote transaction ID sent to K8');

queue(transaction($p, 'tx-one'));
$response = dispatch(notification($p, [], ['Identificador' => 'tx-one', 'Estado' => 'Aprovada']));
same($response->get_status(), 200, 'Documented JSON callback accepted');
same(S::paid($registrationId), 10000, 'Repeated callbacks never duplicate entry');
same(count($wpdb->rows['wp_ie_transactions']), 1, 'One ledger row for repeated transaction');

queue(transaction($other, 'tx-two', ['Estado' => 'Pendente', 'ValorPago' => 0]));
$response = dispatch(notification($other, [], ['Identificador' => 'tx-two', 'Estado' => 'Aprovada', 'ValorPago' => 100]));
same($response->get_status(), 200, 'Pending API status is processed successfully');
same(S::paid($registrationId), 10000, 'Forged callback approval never credits pending transaction');
queue(transaction($other, 'tx-two'));
same(dispatch(notification($other, ['ID_BknBank' => 'tx-two']))->get_status(), 200, 'GET recovery with signed URL supported');
same(S::paid($registrationId), 20000, 'Later API approval credits next payment');

// Keep the ID even when the first authoritative query times out, so cron/refresh can retry it.
$retry = payment('IEretry');
$GLOBALS['http_queue'][] = new WP_Error('timeout', 'mock timeout');
$response = dispatch(notification($retry, ['ID_BknBank' => 'tx-retry']));
same($response->get_status(), 503, 'Transient failure is retryable');
same(S::payment($retry['id'])['remote_id_hint'], 'tx-retry', 'Failed API query preserves received ID');
same(S::payment($retry['id'])['webhook_error'] !== '', true, 'Admin can inspect reconciliation failure');
same(S::paid($registrationId), 20000, 'No credit from unavailable API');
queue(transaction($retry, 'tx-retry'));
P::sync($retry['id']);
$call = end($GLOBALS['http_calls']);
same(str_ends_with($call[0], '/api/ConsultaTransacao'), true, 'Background/manual retry uses preserved transaction ID');
same(G::decode($call[1]['body'], $aes), ['Identificador' => 'tx-retry'], 'Retry does not fall back to checkout reference');
same(S::paid($registrationId), 30000, 'Successful retry credits once');
queue(transaction($retry, 'tx-retry'));
same(dispatch(notification($retry, [], null, ['ID_BknBank' => 'tx-retry']))->get_status(), 200, 'Form callback succeeds after retry');
same(S::payment($retry['id'])['webhook_error'], '', 'Successful redelivery clears callback failure');
same(S::paid($registrationId), 30000, 'Redelivery after retry stays idempotent');

// A verified checkout/order/reference match is required, and every supplied correlation must agree.
$bad = payment('IEmismatch');
foreach ([
    ['Referencia' => null, 'NumeroPedido' => null, 'IdentificadorCheckout' => null],
    ['Referencia' => 'foreign', 'IdentificadorCheckout' => $bad['checkout_id']],
    ['NumeroPedido' => 'foreign', 'IdentificadorCheckout' => $bad['checkout_id']],
    ['IdentificadorCheckout' => 'foreign-checkout'],
    ['Identificador' => 'foreign-id'],
    ['ClienteCPFCNPJ' => '00000000000'],
    ['Valor' => 99.99],
    ['Tipo' => 'Cartao']
] as $extra) {
    queue(transaction($bad, 'tx-mismatch', $extra));
    same(dispatch(notification($bad, ['ID_BknBank' => 'tx-mismatch']))->get_status(), 503, 'Mismatched authoritative identity/value/method rejected');
    same(S::paid($registrationId), 30000, 'Mismatches do not change balance');
}
queue(transaction($bad, 'tx-mismatch', ['Referencia' => null, 'NumeroPedido' => null, 'IdentificadorCheckout' => $bad['checkout_id']]));
same(dispatch(notification($bad, [], null, [], '{"Identificador":"tx-mismatch"}'))->get_status(), 200, 'Verified checkout correlation supports responses without order/reference');
same(S::paid($registrationId), 40000, 'Checkout-correlated payment credited after all checks');

// If no ID arrives, retain the old reference lookup. After confirmation, use the verified ledger ID.
$byReference = payment('IEreference');
queue(transaction($byReference, 'tx-reference'));
same(dispatch(notification($byReference))->get_status(), 200, 'Old reference-only callback remains supported');
same(str_ends_with(end($GLOBALS['http_calls'])[0], '/api/ConsultaTransacaoReferencia'), true, 'Reference is queried only when no transaction ID is known');
queue(transaction($byReference, 'tx-reference', ['Estado' => 'Estornado']));
P::sync($byReference['id']);
same(str_ends_with(end($GLOBALS['http_calls'])[0], '/api/ConsultaTransacao'), true, 'Existing confirmed ledger can reconcile without new hint');
same(S::paid($registrationId), 40000, 'Refund reconciliation restores balance');

$calls = count($GLOBALS['http_calls']);
same(dispatch(notification($byReference, ['ID_BknBank' => ['bad']]))->get_status(), 503, 'Malformed ID never reaches gateway');
same(count($GLOBALS['http_calls']), $calls, 'No HTTP with invalid identifier');
queue(['Retorno' => 'ERRO', 'DetalhesErro' => '<b>Transação não encontrada</b> mock-secret ' . $aes . ' Bearer mock-private-token 52998224725']);
$response = dispatch(notification($byReference, ['ID_BknBank' => 'tx-reference']));
same($response->get_status(), 503, 'Structured provider rejection remains retryable');
$error = S::payment($byReference['id'])['webhook_error'];
same(str_contains($error, 'Transação não encontrada'), true, 'Useful provider detail visible to admin');
foreach (['mock-secret', $aes, 'mock-private-token', '52998224725', '<b>'] as $secret) { same(str_contains($error, $secret), false, 'Provider diagnostics omit secrets/document/HTML'); }
same($response->get_data()['message'], 'A conciliação será tentada novamente.', 'Provider details stay out of public response');
same(count($GLOBALS['http_queue']), 0, 'Every mock response consumed');
echo 'OK: ' . $checks . " webhook/reconciliation checks; no real notifications or charges sent.\n";
