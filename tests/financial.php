<?php
require __DIR__ . '/bootstrap.php';
use InscricaoEventos\Domain as D;
use InscricaoEventos\Store as S;
use InscricaoEventos\Gateway as G;
use InscricaoEventos\Payments as P;
use InscricaoEventos\Security as C;
$checks = 0;
function same($a, $b, $label) { global $checks; $checks++; if ($a !== $b) throw new RuntimeException($label . ': ' . var_export([$a, $b], true)); }
function fails(callable $fn, string $label) { global $checks; $checks++; try { $fn(); } catch (Throwable $e) { return; } throw new RuntimeException($label . ' should fail'); }
function remote(array $p, string $id, string $state = 'Aprovada', array $extra = []): array {
    return array_merge(['Retorno' => 'OK', 'Identificador' => $id, 'Tipo' => $p['method'], 'Estado' => $state, 'Valor' => $p['amount'] / 100, 'ValorPago' => $p['amount'] / 100, 'Referencia' => $p['reference'], 'NumeroPedido' => $p['reference'], 'ClienteCPFCNPJ' => '52998224725', 'DataPagamento' => '2026-10-20T12:00:00-03:00'], $extra);
}
function queue(array $data, int $code = 200) { $GLOBALS['http_queue'][] = ['code' => $code, 'body' => json_encode($data)]; }

same(D::cents('490,00'), 49000, 'Brazilian amount'); same(D::cents('1.234,56'), 123456, 'Thousands'); same(D::cents('0.01'), 1, 'One cent');
foreach (['0.001', '-1', 'NaN', '1e5', '', '490xyz'] as $bad) fails(fn() => D::cents($bad), 'Invalid amount');
same(D::cpf('529.982.247-25'), '52998224725', 'CPF digit verification'); fails(fn() => D::cpf('11111111111'), 'Repeated CPF'); fails(fn() => D::cpf('52998224726'), 'Invalid CPF digit');
fails(fn() => D::date('2027-02-29'), 'Invalid date'); same(D::date('2028-02-29'), '2028-02-29', 'Leap date');
$sample = S::sample(); $sample['status'] = 'published'; $sample['payment_deadline'] = '2028-02-29';
foreach (['2026-10-17' => 10, '2026-11-15' => 10, '2026-11-16' => 8, '2026-12-31' => 8, '2027-01-01' => 6, '2027-01-30' => 6] as $day => $max) same(D::quote($sample, $sample['lots'], $day)['max_installments'], $max, 'Inclusive lot boundary');
same(D::quote($sample, $sample['lots'], '2026-10-16'), null, 'Before opening'); same(D::quote($sample, $sample['lots'], '2027-01-31'), null, 'After closure');
same(D::balance(49000, 10000)['balance'], 39000, 'Deposit balance'); same(D::balance(49000, 50000)['overpaid'], 1000, 'Overpayment visible');
$secret = C::seal('sensitive-test-value'); same(C::unseal($secret), 'sensitive-test-value', 'Protected value roundtrip'); fails(fn() => C::unseal(substr($secret, 4)), 'Tamper detection');
$key = '1234567890abcdef'; $cipher = G::encode(['Valor' => 100, 'Cartao' => ['QuantidadeParcelas' => 10]], $key); same(G::decode($cipher, $key)['Valor'], 100, 'K8 AES protocol'); same(G::decode(json_encode($cipher), $key)['Valor'], 100, 'Quoted encrypted response'); fails(fn() => G::decode($cipher, 'wrong-secret-key!'), 'Wrong key');
same(C::gatewayURL('https://pagamentos.meuk8.com.br/PagamentosUI/checkout?id=123'), 'https://pagamentos.meuk8.com.br/PagamentosUI/checkout?id=123', 'K8 redirect');
foreach (['https://evil.test/checkout', 'https://pagamentos.meuk8.com.br.evil.test', 'https://evil@pagamentos.meuk8.com.br/x', 'javascript:alert(1)', 'http://pagamentos.meuk8.com.br/x'] as $url) fails(fn() => C::gatewayURL($url), 'Untrusted redirect');
$GLOBALS['test_options']['ie_settings'] = ['environment' => 'sandbox', 'credentials' => C::seal(json_encode(['id' => 'test-client', 'secret' => 'test-secret', 'aes' => $key]))];
$gateway = new G(); $scope = $gateway->scope();
$today = new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));
$event = array_merge($sample, ['status' => 'published', 'terms' => '', 'event_date' => $today->modify('+1 year')->format('Y-m-d'), 'payment_deadline' => $today->modify('+1 year')->format('Y-m-d'), 'registration_start' => $today->modify('-1 day')->format('Y-m-d'), 'registration_end' => $today->modify('+10 days')->format('Y-m-d'), 'public_page_id' => 1]);
$eventId = S::insert('events', $event); $event['id'] = $eventId;
$lotId = S::insert('lots', ['event_id' => $eventId, 'name' => 'Lote 1', 'starts_on' => $event['registration_start'], 'ends_on' => $event['registration_end'], 'price' => 49000, 'max_installments' => 10, 'note' => '']);
// Exercise real registration service, including duplicate and stale quote protection.
$input = ['event_id' => $eventId, 'lot_id' => $lotId, 'expected_price' => 49000, 'method' => 'Pix', 'name' => 'João Exemplo', 'email' => 'JOAO@EXAMPLE.TEST', 'phone' => '41999999999', 'cpf' => '52998224725', 'accepted_terms' => true];
fails(fn() => P::register(array_merge($input, ['expected_price' => 1])), 'Stale quote rejected');
fails(fn() => P::register(array_merge($input, ['accepted_terms' => false])), 'Acceptance required');
$account = P::register($input); $r = S::registration($account['id']); same($r['email'], 'joao@example.test', 'Lowercase email'); same($account['name'], 'João', 'Public first name');
same(isset($account['email']) || isset($account['phone']) || isset($account['cpf']), false, 'Public financial view omits contact and CPF');
fails(fn() => P::register($input), 'Duplicate CPF/event');
queue(['access_token' => 'sample-token', 'expires_in' => 3600]); queue(['Retorno' => 'OK', 'Identificador' => 'checkout-one', 'URL' => 'https://sandbox.meuk8.com.br/PagamentosUI/checkout?id=one']);
$payment = P::create($r, '100,00'); same(count($GLOBALS['http_calls']), 2, 'Auth and checkout'); $p = S::payment($payment['payment_id']);
$sent = G::decode($GLOBALS['http_calls'][1][1]['body'], $key); same($sent['Valor'], 100, 'Entry submitted'); same(isset($sent['Cartao']) || isset($sent['Boleto']), false, 'PIX-only checkout'); same($sent['Pix']['Tipo'], 'D', 'Dynamic PIX'); same(str_contains($sent['URLConfirmacao'], '/webhook/' . $p['id']), true, 'Per-payment webhook URL');
same(P::create($r, '100.00'), $payment, 'Pending checkout reused'); same(count($GLOBALS['http_calls']), 2, 'No duplicated remote creation'); fails(fn() => P::create($r, '50'), 'Different amount while pending');
$payload = G::payload(['amount' => 49000, 'method' => 'Cartao', 'reference' => 'IE01'], $r, $event, 'https://event.test/return', 'https://event.test/callback', '52998224725'); same($payload['Cartao']['QuantidadeParcelas'], 10, 'Lot installment cap'); same(isset($payload['Pix']) || isset($payload['Boleto']), false, 'Card-only checkout');
queue(remote($p, 'tx-one')); P::sync($p['id'], 'tx-one'); same(S::balance($r)['balance'], 39000, 'Confirmed entry');
queue(remote($p, 'tx-one')); P::sync($p['id'], 'tx-one'); same(S::balance($r)['balance'], 39000, 'Repeated callback does not duplicate credit'); same(count($wpdb->rows['wp_ie_transactions']), 1, 'Unique remote ledger');
foreach ([['Valor' => 99.99], ['ClienteCPFCNPJ' => '00000000000'], ['Referencia' => 'wrong'], ['NumeroPedido' => 'wrong'], ['Tipo' => 'Cartao']] as $extra) {
    queue(remote($p, 'tx-one', 'Aprovada', $extra)); fails(fn() => P::sync($p['id'], 'tx-one'), 'Mismatch never credits'); same(S::paid($r['id']), 10000, 'Credit unchanged after mismatch');
}
queue(remote($p, 'tx-one', 'EstornarPIX')); P::sync($p['id'], 'tx-one'); same(S::paid($r['id']), 10000, 'Pending refund preserves credit');
queue(remote($p, 'tx-one', 'Estornado')); P::sync($p['id'], 'tx-one'); same(S::paid($r['id']), 0, 'Confirmed refund restores debt');
queue(remote($p, 'tx-one')); P::sync($p['id'], 'tx-one');
// Simulate storage failure: transaction and payment state roll back together.
$wpdb->failUpdate = 'wp_ie_payments'; queue(remote($p, 'tx-one', 'Estornado')); fails(fn() => P::sync($p['id'], 'tx-one'), 'Storage failure'); same(S::paid($r['id']), 10000, 'Credit rolled back');
S::update('lots', ['price' => 55000, 'max_installments' => 6], ['id' => $lotId]); same(S::registration($r['id'])['total'], 49000, 'Existing lot price frozen'); same(S::registration($r['id'])['max_installments'], 10, 'Existing installments frozen');
queue(['Retorno' => 'OK', 'Identificador' => 'checkout-two', 'URL' => 'https://sandbox.meuk8.com.br/PagamentosUI/checkout?id=two']);
$second = P::create($r, '390.00'); $p2 = S::payment($second['payment_id']); queue(remote($p2, 'tx-two')); P::sync($p2['id'], 'tx-two'); same(S::balance($r)['balance'], 0, 'Monthly payments settle exact balance'); fails(fn() => P::create($r, '1'), 'Cannot charge after settlement');
same(D::credit(['Valor' => 490, 'Tipo' => 'Cartao', 'Estado' => 'Aprovada', 'ValorPago' => 0], 49000, 'Cartao', 0), 49000, 'Card approval settles full registration before bank installments');
same(D::credit(['Valor' => 490, 'Tipo' => 'Cartao', 'Estado' => 'CartaoPreAutorizado'], 49000, 'Cartao', 0), 0, 'Preauthorization does not settle');
fails(fn() => D::credit(['Valor' => 100, 'Tipo' => 'Pix', 'Estado' => 'Aprovada', 'ValorPago' => 101], 10000, 'Pix', 0), 'Invalid paid amount');
// Simulate network ambiguity and verify no second charge is emitted.
queue(remote($p2, 'tx-two', 'Estornado')); P::sync($p2['id'], 'tx-two');
$GLOBALS['http_queue'][] = new WP_Error('timeout', 'timeout'); fails(fn() => P::create($r, '50'), 'Creation timeout');
$payments = S::payments($r['id']); $review = $payments[0]; same($review['state'], 'review', 'Timeout saved for review'); $count = count($GLOBALS['http_calls']); fails(fn() => P::create($r, '50'), 'Unknown operation blocks duplicate'); same(count($GLOBALS['http_calls']), $count, 'No extra HTTP');
P::recover($review['id'], '', '', true); same(S::paid($r['id']), 10000, 'Recovery cannot create credit');
$wpdb->failLock = true; fails(fn() => P::create($r, '50'), 'Concurrent registration lock'); $wpdb->failLock = false;
$wpdb->failSum = true; fails(fn() => P::create($r, '50'), 'Unreadable balance cannot become zero'); same(count($GLOBALS['http_calls']), $count, 'Balance read failure creates no checkout');
// A callback token is scoped to its payment, and CSRF rejects a hostile origin.
same(P::callbackKey($p) === P::callbackKey($p2), false, 'Independent callback secrets');
$_COOKIE['ie_client'] = str_repeat('a', 64); $csrf = C::hash('csrf:' . $_COOKIE['ie_client']);
same(C::publicPermission(new WP_REST_Request(['x-ie-csrf' => $csrf, 'origin' => 'https://event.test'])), true, 'CSRF cookie binding'); same(C::publicPermission(new WP_REST_Request(['x-ie-csrf' => $csrf, 'origin' => 'https://evil.test'])) instanceof WP_Error, true, 'Cross-origin rejected');
$settings = get_option('ie_settings'); $settings['environment'] = 'production'; update_option('ie_settings', $settings); same((new G())->scope() !== $scope, true, 'Sandbox and production isolated'); fails(fn() => P::create($r, '50'), 'Cross-environment charge blocked');
echo 'OK: ' . $checks . " financial/security checks, mocked WordPress and K8.\n";
