<?php
require __DIR__ . '/admin-bootstrap.php';
use InscricaoEventos\Store as S;
use InscricaoEventos\Security as C;
use InscricaoEventos\Gateway as G;
use InscricaoEventos\Reports as Q;
use InscricaoEventos\Dashboard as D;
use InscricaoEventos\Registrations as R;
use InscricaoEventos\Admin as A;
use InscricaoEventos\Api;
use InscricaoEventos\Payments as P;
$checks = 0;
function same($actual, $expected, $label) { global $checks; $checks++; if ($actual !== $expected) throw new RuntimeException($label . ': ' . var_export([$actual, $expected], true)); }
function failure(callable $fn) { global $checks; $checks++; try { $fn(); } catch (Throwable $e) { return $e->getMessage(); } throw new RuntimeException('Expected failure'); }
update_option('ie_settings', ['environment' => 'sandbox', 'credentials' => C::seal(json_encode(['id' => 'report-test', 'secret' => 'fictional', 'aes' => '1234567890abcdef']))]);
$scope = (new G())->scope();
$eid = S::insert('events', array_merge(S::sample(), ['name' => 'Evento de teste', 'status' => 'published', 'event_date' => '2028-03-01', 'payment_deadline' => '2028-02-29']));
$eid2 = S::insert('events', array_merge(S::sample(), ['name' => 'Outro evento', 'status' => 'published']));
S::insert('lots', ['event_id' => $eid, 'name' => 'Lote renomeado', 'starts_on' => '2026-10-17', 'ends_on' => '2027-01-30', 'price' => 49000, 'max_installments' => 10]);
S::insert('lots', ['event_id' => $eid2, 'name' => 'Lote de outro evento', 'starts_on' => '2026-10-17', 'ends_on' => '2027-01-30', 'price' => 49000, 'max_installments' => 10]);
function participant($name, $total, $method = 'Pix', $status = 'active', $otherScope = false, $otherEvent = false, $created = '2026-10-14 12:00:00', $lot = 'Lote original'): int {
    global $scope, $eid, $eid2;
    return S::insert('registrations', ['event_id' => $otherEvent ? $eid2 : $eid, 'scope' => $otherScope ? 'other-account' : $scope, 'lot_id' => 90, 'lot_name' => $lot, 'total' => $total, 'max_installments' => 10, 'method' => $method, 'status' => $status, 'name' => $name, 'email' => strtolower($name) . '@example.test', 'phone' => '41999999999', 'cpf_hash' => C::hash('cpf:' . ($name === 'Ana' ? '52998224725' : $name)), 'cpf_encrypted' => C::seal('52998224725'), 'terms_snapshot' => 'Teste', 'terms_accepted_at' => $created, 'created_at' => $created]);
}
function payment($rid, array $credits, $state = 'paid', $error = ''): int {
    $r = S::registration($rid);
    $pid = S::insert('payments', ['registration_id' => $rid, 'reference' => 'IEtest' . $rid . '-' . count(S::payments($rid)), 'scope' => $r['scope'], 'amount' => max(100, array_sum($credits)), 'method' => $r['method'], 'state' => $state, 'last_error' => $error, 'operator_note' => '', 'checkout_url' => '', 'cpf_snapshot' => $r['cpf_encrypted']]);
    foreach ($credits as $i => $credit) { S::insert('transactions', ['payment_id' => $pid, 'scope' => $r['scope'], 'remote_id' => 'report-transaction-' . $pid . '-' . $i, 'remote_state' => $credit ? 'Aprovada' : 'Pendente', 'credit' => $credit, 'synced_at' => current_time('mysql', true)]); }
    S::insert('sessions', ['token_hash' => C::hash('session:' . $pid), 'registration_id' => $rid, 'expires_at' => time() + 300]); S::audit($rid, 'fixture', 'Anotação de teste', $pid);
    return $pid;
}
$ana = participant('Ana', 10000, 'Pix', 'active', false, false, '2026-10-14 02:59:59'); $anaP = payment($ana, [3000, 1000]); payment($ana, [1000]); payment($ana, [0], 'pending');
$bruno = participant('Bruno', 20000, 'Cartao', 'active', false, false, '2026-10-14 03:00:00', 'Lote 2'); payment($bruno, [10000]); payment($bruno, [10000]);
$carla = participant('Carla', 10000, 'Pix', 'active', false, false, '2026-10-15 02:59:59', 'Lote 2'); payment($carla, [12000], 'canceling');
$diego = participant('Diego', 15000, 'Pix', 'active', false, false, '2026-10-15 03:00:00'); payment($diego, [0], 'review', 'Conferir retorno');
$elisa = participant('Elisa', 10000, 'Pix', 'cancelled'); payment($elisa, [5000]);
$fabio = participant('Fabio', 10000, 'Pix', 'deleted'); payment($fabio, [10000]);
$gabi = participant('Gabriela', 99000, 'Pix', 'active', true); payment($gabi, [9000]);
$helena = participant('Helena', 5000, 'Pix', 'active', false, true); payment($helena, [5000]);
$f = Q::filters(['event' => $eid]); $m = Q::metrics($f);
foreach (['count' => 4, 'total' => 55000, 'paid' => 37000, 'balance' => 20000, 'overpaid' => 2000, 'covered' => 35000, 'pending' => 1, 'partial' => 1, 'paid_count' => 2, 'overpaid_count' => 1, 'open_attempts' => 1, 'review_attempts' => 1, 'cancel_attempts' => 1, 'error_attempts' => 1, 'active' => 4, 'cancelled' => 0, 'deleted' => 0] as $key => $value) same($m[$key], $value, 'Correct aggregate ' . $key);
same($m['progress'], 63.6, 'Excess on one registration does not cover another balance');
$data = Q::dashboard(['event' => $eid]); same(count($data['registrations']), 4, 'Only active current-account registrations');
same(array_sum(array_column($data['lots'], 'paid')), 37000, 'Lot totals agree with ledger summary'); same(array_sum(array_column($data['methods'], 'balance')), 20000, 'Method totals agree with due summary'); same(array_sum(array_column($data['timeline'], 'count')), 4, 'Timeline counts registrations once');
same(array_column($data['receipts'], 'paid', 'label'), ['Cartao' => 20000, 'Pix' => 17000], 'Actual receipt methods agree with verified credits');
same(array_column($data['receipts'], 'count', 'label'), ['Cartao' => 1, 'Pix' => 2], 'Many attempts/transactions count each receiving registration once per method');
same(in_array('Lote original', $data['lot_options']), true, 'Frozen old lot retained in filter'); same(in_array('Lote renomeado', $data['lot_options']), true, 'Current lot included in filter');
foreach (['cpf_encrypted', 'cpf_hash', 'cpf_snapshot', 'terms_snapshot', 'admin_note', 'scope'] as $field) same(array_key_exists($field, $data['registrations'][0]), false, 'Dashboard rows omit ' . $field);
same(str_contains(json_encode($data), '52998224725'), false, 'No full document in dashboard listing');
foreach ([['method' => 'Cartao', 'count' => 1, 'paid' => 20000], ['status' => 'partial', 'count' => 1, 'paid' => 5000], ['status' => 'pending', 'count' => 1, 'paid' => 0], ['status' => 'paid', 'count' => 2, 'paid' => 32000], ['status' => 'overpaid', 'count' => 1, 'paid' => 12000], ['lot' => 'Lote original', 'count' => 2, 'paid' => 5000], ['q' => '529.982.247-25', 'count' => 1, 'paid' => 5000], ['q' => 'bruno@example.test', 'count' => 1, 'paid' => 20000], ['q' => 'Diego', 'count' => 1, 'paid' => 0], ['registration_status' => 'cancelled', 'count' => 1, 'paid' => 5000], ['registration_status' => 'deleted', 'count' => 1, 'paid' => 10000], ['registration_status' => 'all', 'count' => 6, 'paid' => 52000], ['all_scopes' => 1, 'count' => 5, 'paid' => 46000]] as $case) {
    $actual = Q::metrics(Q::filters(array_merge(['event' => $eid], array_diff_key($case, ['count' => 1, 'paid' => 1])))); same([$actual['count'], $actual['paid']], [$case['count'], $case['paid']], 'Filter ' . json_encode($case));
}
$dates = Q::metrics(Q::filters(['event' => $eid, 'start' => '2026-10-14', 'end' => '2026-10-14'])); same([$dates['count'], $dates['paid']], [2, 32000], 'Whole-day filtering respects Sao Paulo midnight in UTC');
same(Q::metrics(Q::filters(['event' => $eid, 'q' => "' OR 1=1 --"]))['count'], 0, 'Search does not execute SQL');
participant('Teste% literal', 100); participant('TesteX outro', 100); same(Q::metrics(Q::filters(['event' => $eid, 'q' => 'Teste%']))['count'], 1, 'Percent sign in search is literal');
same(Q::metrics(Q::filters(['event' => $eid, 'q' => 'inexistente']))['balance'], 0, 'Empty results have zero amounts');
failure(fn() => Q::filters(['start' => '2026-02-30'])); failure(fn() => Q::filters(['start' => '2026-10-15', 'end' => '2026-10-14']));
same(Q::filters(['status' => ['paid'], 'method' => 'sql'])['status'], '', 'Malformed choices normalized safely');
$calendar = Q::calendar(S::event($eid), '2028-02-28'); same([$calendar['days'], $calendar['deadline_days']], [2, 1], 'Countdown respects leap-day calendar');
same(Q::calendar(S::event($eid), '2028-03-02')['days'], -1, 'Past event has signed countdown'); same(Q::calendar(S::event($eid2), '2028-02-28')['days'], null, 'Unset date never invented');
$GLOBALS['test_admin'] = false; same(D::permission() instanceof WP_Error, true, 'Anonymous endpoint permission denied'); same(D::endpoint(new WP_REST_Request())->get_status(), 403, 'Callback also rejects unauthorized users');
$GLOBALS['test_logged_in'] = false; $html = D::shortcode(['evento' => $eid]); same(str_contains($html, 'ied-login-form'), true, 'Visitor gets inline login form'); same(str_contains($html, 'Ana'), false, 'Visitor receives no participant data'); same(isset($GLOBALS['test_inline']['ie-dashboard']), false, 'Visitor receives no dashboard nonce');
$GLOBALS['test_logged_in'] = true; same(str_contains(D::shortcode(), 'ied-login-form'), false, 'Logged-in nonadministrator is still denied');
$GLOBALS['test_admin'] = true; same(D::permission(), true, 'Administrator allowed'); same(str_contains(D::shortcode(['evento' => $eid]), 'data-event="' . $eid . '"'), true, 'Event shortcode shell rendered');
same(isset($GLOBALS['test_inline']['ie-dashboard']), true, 'Authorized shell has authenticated API and export configuration');
$response = D::endpoint(new WP_REST_Request([], ['event' => $eid])); same($response->get_status(), 200, 'Authorized API works'); same(str_contains($response->headers['Cache-Control'], 'no-store'), true, 'Financial response not cached');
$wpdb->failReport = true; $failed = D::endpoint(new WP_REST_Request([], ['event' => $eid])); same($failed->get_status(), 400, 'Database read failure is not a false zero report'); same(isset($failed->get_data()['metrics']), false, 'No misleading metrics on storage failure');
$confirm = new ReflectionMethod(A::class, 'confirmDeletion'); $_POST = ['confirmation' => 'EXCLUIR 999']; failure(fn() => $confirm->invoke(null, 'EXCLUIR ' . $ana)); $_POST = ['confirmation' => 'EXCLUIR ' . $ana]; $confirm->invoke(null, 'EXCLUIR ' . $ana);
for ($i = 0; $i < 27; $i++) participant('Paginado' . $i, 100, 'Pix', 'active', false, true);
$paging = Q::dashboard(['event' => $eid2, 'page' => 2]); same(count($paging['registrations']), 3, 'Second page contains remaining rows'); same($paging['pagination']['pages'], 2, 'Correct number of pages'); same(Q::dashboard(['event' => $eid2, 'page' => 999])['pagination']['page'], 2, 'Out-of-range page clamped');
$p = S::payment($anaP); $key = P::callbackKey($p); $allBefore = $wpdb->rows; $wpdb->failDelete = 'wp_ie_payments';
failure(fn() => R::purge($ana)); same($wpdb->rows, $allBefore, 'Failed purge rolls back transactions, payments, sessions, audit and registration');
$otherBefore = S::payments($bruno); R::purge($ana); failure(fn() => S::registration($ana)); failure(fn() => S::payment($anaP));
foreach (['payments', 'sessions', 'audit'] as $table) same(count(array_filter($wpdb->rows[S::table($table)] ?? [], fn($row) => $row['registration_id'] == $ana)), 0, 'Purge removes linked ' . $table);
same(count(array_filter($wpdb->rows[S::table('transactions')], fn($row) => $row['payment_id'] == $anaP)), 0, 'Purge removes old ledger'); same(S::payments($bruno), $otherBefore, 'Purge preserves other registrations');
same(S::byCPF($eid, '52998224725', $scope), null, 'Purged CPF can register again'); same(Api::webhookPermission(new WP_REST_Request([], ['key' => $key], ['payment_id' => $anaP])) instanceof WP_Error, true, 'Late callback for purged attempt denied');
$beforeEvent = $wpdb->rows; $wpdb->failDelete = 'wp_ie_events'; failure(fn() => R::purgeEvent($eid)); same($wpdb->rows, $beforeEvent, 'Event purge is atomic even if final event delete fails');
$wpdb->failLock = true; failure(fn() => R::purgeEvent($eid)); same($wpdb->rows, $beforeEvent, 'Busy lock prevents any deletion'); $wpdb->failLock = false;
R::purgeEvent($eid); failure(fn() => S::event($eid)); same(S::lots($eid), [], 'Event lots permanently removed');
same(count(array_filter($wpdb->rows[S::table('registrations')], fn($row) => $row['event_id'] == $eid)), 0, 'All statuses and environments removed with event');
same(count($wpdb->rows[S::table('registrations')]), 28, 'Other event registrations retained'); same(S::paid($helena), 5000, 'Other event finance retained');
foreach ($wpdb->rows[S::table('payments')] as $p) same(isset($wpdb->rows[S::table('registrations')][$p['registration_id']]), true, 'No orphan payment');
foreach ($wpdb->rows[S::table('transactions')] as $t) same(isset($wpdb->rows[S::table('payments')][$t['payment_id']]), true, 'No orphan transaction');
foreach (['sessions', 'audit'] as $table) foreach ($wpdb->rows[S::table($table)] as $row) same(isset($wpdb->rows[S::table('registrations')][$row['registration_id']]), true, 'No orphan ' . $table);
same(count($GLOBALS['http_calls']), 0, 'Deletion and reporting never request a K8 payment or refund');
echo 'OK: ' . $checks . " dashboard/deletion checks with real SELECT execution; no real K8 operations.\n";
