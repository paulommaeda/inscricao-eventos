<?php
require __DIR__ . '/admin-bootstrap.php';
use InscricaoEventos\Domain as D;
use InscricaoEventos\Store as S;
use InscricaoEventos\Security as C;
use InscricaoEventos\Gateway as G;
use InscricaoEventos\Payments as P;
use InscricaoEventos\Reports as Q;
use InscricaoEventos\Api;
$checks = 0;
function same($actual, $expected, $label): void { global $checks; $checks++; if ($actual !== $expected) throw new RuntimeException($label . ': ' . var_export([$actual, $expected], true)); }
function failure(callable $fn): string { global $checks; $checks++; try { $fn(); } catch (Throwable $e) { return $e->getMessage(); } throw new RuntimeException('Expected failure'); }
function queue(array $body): void { $GLOBALS['http_queue'][] = ['code' => 200, 'body' => json_encode($body)]; }
function checkout($key): void { queue(['Retorno' => 'OK', 'Identificador' => 'checkout-' . $key, 'URL' => 'https://sandbox.meuk8.com.br/PagamentosUI/checkout?id=' . $key]); }
$event = array_merge(S::sample(), ['timezone' => 'America/Sao_Paulo']);
foreach ([10, 8, 6, 1] as $base) {
    $r = ['max_installments' => $base, 'created_at' => '2026-10-17 15:00:00'];
    foreach (['2026-10-17' => 0, '2026-11-01' => 0, '2026-11-16' => 0, '2026-11-17' => 1, '2026-12-16' => 1, '2026-12-17' => 2, '2027-01-16' => 2, '2027-01-17' => 3, '2027-02-17' => 4, '2027-10-17' => 12, '2028-10-17' => 24] as $day => $months) {
        $quote = D::installments($r, $event, $day);
        same($quote['months'], $months, 'Completed monthly anniversary ' . $base . ' ' . $day);
        same($quote['max'], max(1, $base - $months), 'All lot caps decrease and floor at one ' . $base . ' ' . $day);
    }
}
same(D::installments(['max_installments' => 10, 'created_at' => '2026-10-17 15:00:00'], $event, '2027-01-17')['next_reduction'], '2027-02-17', 'Next reduction shown');
same(D::installments(['max_installments' => 6, 'created_at' => '2026-10-17 15:00:00'], $event, '2027-10-17')['next_reduction'], '', 'No further reductions below one');
foreach ([['2027-01-31 15:00:00', '2027-02-27', 0, '2027-02-28'], ['2027-01-31 15:00:00', '2027-02-28', 1, '2027-03-31'], ['2027-01-31 15:00:00', '2027-03-30', 1, '2027-03-31'], ['2027-01-31 15:00:00', '2027-03-31', 2, '2027-04-30'], ['2028-01-31 15:00:00', '2028-02-28', 0, '2028-02-29'], ['2028-01-31 15:00:00', '2028-02-29', 1, '2028-03-31'], ['2027-08-30 15:00:00', '2027-09-30', 1, '2027-10-30'], ['2026-10-18 01:00:00', '2026-11-17', 1, '2026-12-17']] as [$created, $day, $months, $next]) {
    $quote = D::installments(['max_installments' => 10, 'created_at' => $created], $event, $day);
    same([$quote['months'], $quote['next_reduction']], [$months, $next], 'Short month/leap year/local day ' . $created . ' ' . $day);
}
same(D::installments(['max_installments' => 10, 'created_at' => '2026-10-18 01:00:00'], $event, '2026-11-16')['max'], 10, 'UTC registration is converted to event timezone');
same(D::installments(['max_installments' => 10, 'created_at' => '2028-10-17 15:00:00'], $event, '2026-10-17')['max'], 10, 'Future timestamp cannot increase frozen cap');
failure(fn() => D::installments(['max_installments' => 13, 'created_at' => '2026-10-17 15:00:00'], $event));
failure(fn() => D::installments(['max_installments' => 10, 'created_at' => '2026-02-30 15:00:00'], $event));
failure(fn() => D::installments(['max_installments' => 10, 'created_at' => ''], $event));
$aes = '1234567890abcdef'; update_option('ie_settings', ['environment' => 'sandbox', 'credentials' => C::seal(json_encode(['id' => 'installment-fixture', 'secret' => 'fictional-secret', 'aes' => $aes]))]);
$scope = (new G())->scope(); $today = new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));
$event = array_merge($event, ['status' => 'published', 'name' => 'Evento teste', 'payment_deadline' => $today->modify('+1 year')->format('Y-m-d'), 'event_date' => $today->modify('+1 year')->format('Y-m-d'), 'registration_start' => $today->modify('-1 year')->format('Y-m-d'), 'registration_end' => $today->modify('-1 day')->format('Y-m-d'), 'minimum_entry' => 100, 'public_page_id' => 1]);
$eid = S::insert('events', $event); $event['id'] = $eid;
$oldMonth = $today->modify('first day of this month')->modify('-3 months');
$created = $oldMonth->setDate((int) $oldMonth->format('Y'), (int) $oldMonth->format('n'), min((int) $today->format('j'), (int) $oldMonth->format('t')))->setTime(12, 0)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
function registration(int $base = 10, int $price = 49000, string $method = 'Pix'): array {
    global $eid, $scope, $created;
    $id = S::insert('registrations', ['event_id' => $eid, 'scope' => $scope, 'lot_id' => 1, 'lot_name' => 'Lote de ' . $base . 'x', 'total' => $price, 'max_installments' => $base, 'method' => $method, 'name' => 'Pessoa de teste', 'email' => 'fixture@example.test', 'phone' => '41999999999', 'cpf_hash' => C::hash('fixture:' . random_bytes(8)), 'cpf_encrypted' => C::seal('52998224725'), 'terms_snapshot' => '', 'terms_accepted_at' => $created, 'created_at' => $created]);
    return S::registration($id);
}
function deposit(array $r, int $amount = 10000, string $state = 'paid'): array {
    $id = S::insert('payments', ['registration_id' => $r['id'], 'scope' => $r['scope'], 'reference' => 'IEentry' . $r['id'], 'amount' => $amount, 'method' => 'Pix', 'state' => $state, 'checkout_url' => $state === 'pending' ? 'https://sandbox.meuk8.com.br/PagamentosUI/checkout?id=entry' : '', 'last_error' => '', 'operator_note' => '', 'cpf_snapshot' => $r['cpf_encrypted']]);
    if ($state === 'paid') S::insert('transactions', ['payment_id' => $id, 'scope' => $r['scope'], 'remote_id' => 'pix-entry-' . $r['id'], 'remote_state' => 'Aprovada', 'credit' => $amount, 'paid_at' => '', 'synced_at' => current_time('mysql', true)]);
    return S::payment($id);
}
$r = registration(); $entry = deposit($r); $a = S::publicRegistration($r);
same($a['payment_methods'], ['Pix', 'Cartao'], 'PIX participant can also pay by enabled card'); same($a['card_installments']['max'], 7, 'Three months reduce L1 to 7x'); same($a['max_installments'], 10, 'Original contracted cap retained');
same($a['balance'], 39000, 'PIX deposit deducted'); same($a['can_pay'], true, 'Balance can be paid after registrations close');
$calls = count($GLOBALS['http_calls']); failure(fn() => P::create($r, '300', 'Cartao')); failure(fn() => P::create($r, '490', 'Cartao')); failure(fn() => P::create($r, '390', 'Boleto')); failure(fn() => P::create($r, '390', 'Cartao', 10)); same(count($GLOBALS['http_calls']), $calls, 'Invalid amount/method/stale installment quote sends no checkout');
queue(['access_token' => 'mock-token', 'expires_in' => 3600]); checkout('card-balance');
$result = P::create($r, '390', 'Cartao', 7); $p = S::payment($result['payment_id']); $body = G::decode(end($GLOBALS['http_calls'])[1]['body'], $aes);
same([$body['Valor'], $body['Cartao']['QuantidadeParcelas']], [390, 7], 'Only remaining amount and reduced cap go to K8'); same(isset($body['Pix']), false, 'Balance card checkout has no PIX alternative');
same([$p['method'], $p['max_installments']], ['Cartao', 7], 'Each attempt freezes its actual method and installment cap'); same([S::registration($r['id'])['method'], S::registration($r['id'])['max_installments']], ['Pix', 10], 'Choosing card does not rewrite original registration');
$calls = count($GLOBALS['http_calls']); same(P::create($r, '390', 'Cartao', 7), $result, 'Same pending card checkout reused'); failure(fn() => P::create($r, '390', 'Pix')); same(count($GLOBALS['http_calls']), $calls, 'Cannot reuse pending card checkout as PIX or create a second charge');
$remote = ['Retorno' => 'OK', 'Identificador' => 'card-balance-tx', 'Tipo' => 'Cartao', 'Estado' => 'Pendente', 'Valor' => 390, 'ValorPago' => 0, 'Referencia' => $p['reference'], 'NumeroPedido' => $p['reference'], 'ClienteCPFCNPJ' => '52998224725'];
queue($remote); P::sync($p['id'], 'card-balance-tx'); same(S::balance($r)['balance'], 39000, 'Card return without approval never settles balance');
$remote['Estado'] = 'Aprovada'; queue($remote); P::sync($p['id'], 'card-balance-tx'); same(S::paid($r['id']), 49000, 'Approved balance card + original PIX settle once');
queue($remote); P::sync($p['id'], 'card-balance-tx'); same(S::paid($r['id']), 49000, 'Repeated mixed-payment callback is idempotent'); failure(fn() => P::create($r, '1', 'Cartao'));
$report = Q::dashboard(['event' => $eid]); $receipts = array_column($report['receipts'], 'paid', 'label'); same($receipts, ['Cartao' => 39000, 'Pix' => 10000], 'Dashboard separates actual PIX and card receipts'); same($report['metrics']['paid'], 49000, 'Mixed registration counted once');
$remote['Estado'] = 'Estornado'; queue($remote); P::sync($p['id'], 'card-balance-tx'); same(S::balance($r)['balance'], 39000, 'Refund of card leaves original PIX credit');
$receipts = array_column(Q::dashboard(['event' => $eid])['receipts'], 'paid', 'label'); same($receipts, ['Pix' => 10000], 'Refunded card not reported as received');
foreach ([[8, 52000, 5], [6, 55000, 3], [1, 49000, 1]] as [$base, $price, $limit]) {
    $item = registration($base, $price); deposit($item); checkout('lot-' . $base); $issued = S::payment(P::create($item, ($price - 10000) / 100, 'Cartao', $limit)['payment_id']); $sent = G::decode(end($GLOBALS['http_calls'])[1]['body'], $aes);
    same($sent['Cartao']['QuantidadeParcelas'], $limit, 'Reduced checkout cap for lot ' . $base); same($issued['max_installments'], $limit, 'Frozen cap for lot ' . $base);
    same($sent['Cartao']['FormaPagamento'], $limit > 1 ? ['AVista', 'ParceladoSemJuros'] : ['AVista'], 'One installment uses only cash card option');
}
$pending = registration(); $pendingPix = deposit($pending, 39000, 'pending'); $calls = count($GLOBALS['http_calls']); failure(fn() => P::create($pending, '490', 'Cartao', 7));
same(count($GLOBALS['http_calls']), $calls, 'Existing pending PIX blocks switching to card');
$sameAmount = registration(); deposit($sameAmount, 49000, 'pending'); failure(fn() => P::create($sameAmount, '490', 'Cartao', 7)); same(count($GLOBALS['http_calls']), $calls, 'Same value in another method does not reuse wrong checkout');
$restricted = registration(); deposit($restricted); S::update('events', ['allow_card' => 0], ['id' => $eid]); failure(fn() => P::create($restricted, '390', 'Cartao')); same(S::publicRegistration($restricted)['payment_methods'], ['Pix'], 'PIX-only event hides and rejects card');
S::update('events', ['allow_card' => 1, 'allow_pix' => 0], ['id' => $eid]); same(S::publicRegistration($restricted)['payment_methods'], ['Cartao'], 'PIX deposit can finish by card when PIX disabled later'); same(S::publicRegistration($restricted)['can_pay'], true, 'Card-enabled event still allows settling PIX registration');
failure(fn() => P::create($restricted, '10', 'Pix')); S::update('events', ['allow_pix' => 1], ['id' => $eid]);
$wpdb->failSum = true; failure(fn() => P::create($restricted, '390', 'Cartao')); same(count($GLOBALS['http_calls']), $calls, 'Unreadable balance cannot emit a balance card charge');
S::update('registrations', ['status' => 'cancelled'], ['id' => $restricted['id']]); failure(fn() => P::create($restricted, '390', 'Cartao')); S::update('registrations', ['status' => 'active'], ['id' => $restricted['id']]);
S::update('events', ['payment_deadline' => $today->modify('-1 day')->format('Y-m-d')], ['id' => $eid]); failure(fn() => P::create($restricted, '390', 'Cartao')); S::update('events', ['payment_deadline' => $event['payment_deadline']], ['id' => $eid]);
// Exercise the real REST payment action: no client installment field can increase the server cap.
$_COOKIE['ie_session'] = str_repeat('a', 64); S::insert('sessions', ['token_hash' => C::hash($_COOKIE['ie_session']), 'registration_id' => $restricted['id'], 'expires_at' => time() + 300]);
$action = new ReflectionMethod(Api::class, 'action');
foreach ([['method' => ['Cartao']], ['method' => 'Cartao', 'expected_installments' => 99], ['method' => 'Cartao', 'expected_installments' => '7'], ['method' => 'Cartao', 'expected_installments' => 10]] as $bad) failure(fn() => $action->invoke(null, 'payment', new WP_REST_Request([], [], [], array_merge(['amount' => '390'], $bad))));
checkout('api-balance'); $apiResult = $action->invoke(null, 'payment', new WP_REST_Request([], [], [], ['amount' => '390', 'method' => 'Cartao', 'max_installments' => 12, 'installments' => 12, 'expected_installments' => 7]));
same(S::payment($apiResult['payment_id'])['max_installments'], 7, 'Posted arbitrary installment fields do not override policy');
same(count($GLOBALS['http_queue']), 0, 'All and only expected gateway requests used');
echo 'OK: ' . $checks . " monthly installment/mixed-payment checks; no real K8 charges.\n";
