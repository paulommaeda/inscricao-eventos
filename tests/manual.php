<?php
require __DIR__ . '/admin-bootstrap.php';
use InscricaoEventos\Access as A;
use InscricaoEventos\Admin;
use InscricaoEventos\Dashboard;
use InscricaoEventos\Domain as D;
use InscricaoEventos\Gateway as G;
use InscricaoEventos\Manual as M;
use InscricaoEventos\Payments as P;
use InscricaoEventos\Registrations as R;
use InscricaoEventos\Reports as Q;
use InscricaoEventos\Security as C;
use InscricaoEventos\Store as S;
$checks = 0;
function same($actual, $expected, $label): void { global $checks; $checks++; if ($actual !== $expected) throw new RuntimeException($label . ': ' . var_export([$actual, $expected], true)); }
function failure(callable $fn): string { global $checks; $checks++; try { $fn(); } catch (Throwable $e) { return $e->getMessage(); } throw new RuntimeException('Expected failure'); }
function capture(callable $fn): string { ob_start(); try { $fn(); return ob_get_contents(); } finally { ob_end_clean(); } }
function queue(array $data): void { $GLOBALS['http_queue'][] = ['code' => 200, 'body' => json_encode($data)]; }
$GLOBALS['test_roles']['administrator'] = new TestRole('administrator', ['manage_options' => true]);
A::install(); $role = get_role(A::ROLE); same($role instanceof TestRole, true, 'Role created during update'); same($role->capabilities, ['read' => true, A::VIEW => true], 'Manager role has only read and event-view capabilities');
same(get_role('administrator')->capabilities[A::VIEW], true, 'Administrator also has view capability'); A::install(); same(get_role(A::ROLE), $role, 'Role installation is idempotent');
foreach (['manage_options', 'edit_users', 'edit_posts', 'upload_files', 'activate_plugins'] as $cap) same(!empty($role->capabilities[$cap]), false, 'Manager has no ' . $cap);
update_option('ie_settings', ['environment' => 'sandbox', 'credentials' => C::seal(json_encode(['id' => 'manual-test', 'secret' => 'fictional-secret', 'aes' => '1234567890abcdef']))]);
$scope = (new G())->scope(); queue(['access_token' => 'mock-token', 'expires_in' => 3600]); (new G())->authenticate();
$today = new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')); $date = $today->format('Y-m-d');
$eid = S::insert('events', array_merge(S::sample(), ['status' => 'published', 'name' => 'Evento de testes', 'event_date' => $today->modify('+1 year')->format('Y-m-d'), 'payment_deadline' => $today->modify('+1 year')->format('Y-m-d'), 'registration_start' => $today->modify('-1 week')->format('Y-m-d'), 'registration_end' => $today->modify('+1 week')->format('Y-m-d'), 'public_page_id' => 1]));
S::insert('lots', ['event_id' => $eid, 'name' => 'Lote teste', 'price' => 10000, 'max_installments' => 10, 'starts_on' => $today->modify('-1 week')->format('Y-m-d'), 'ends_on' => $today->modify('+1 week')->format('Y-m-d')]);
function registration(): array {
    global $scope, $eid;
    $rid = S::insert('registrations', ['event_id' => $eid, 'scope' => $scope, 'lot_id' => 1, 'lot_name' => 'Lote teste', 'total' => 10000, 'max_installments' => 10, 'method' => 'Pix', 'cpf_hash' => C::hash('fixture:' . random_bytes(8)), 'cpf_encrypted' => C::seal('52998224725'), 'name' => 'Pessoa Exemplo', 'email' => 'pessoa@example.test', 'phone' => '41999999999', 'terms_snapshot' => 'Condições teste', 'terms_accepted_at' => current_time('mysql', true), 'created_at' => current_time('mysql', true)]);
    return S::registration($rid);
}
function pending(array $r, string $state = 'pending'): array {
    return S::payment(S::insert('payments', ['registration_id' => $r['id'], 'scope' => $r['scope'], 'reference' => 'IEmanual' . $r['id'], 'amount' => 10000, 'method' => 'Pix', 'state' => $state, 'checkout_url' => 'https://sandbox.meuk8.com.br/PagamentosUI/checkout?id=manual-test', 'last_error' => '', 'operator_note' => '', 'cpf_snapshot' => $r['cpf_encrypted']]));
}
function input(string $amount = '100', int $pid = 0, string $method = 'Pix'): array {
    global $date;
    return ['amount' => $amount, 'payment_id' => $pid, 'method' => $method, 'paid_on' => $date, 'note' => 'Comprovante conferido pela organização', 'confirmed' => true, 'request_token' => bin2hex(random_bytes(32)), 'actor_id' => 999];
}
function remote(array $p, string $state, array $extra = []): array { return array_merge(['Retorno' => 'OK', 'Identificador' => 'tx-manual-' . $p['id'], 'Referencia' => $p['reference'], 'NumeroPedido' => $p['reference'], 'ClienteCPFCNPJ' => '52998224725', 'Tipo' => 'Pix', 'Valor' => 100, 'ValorPago' => 100, 'Estado' => $state], $extra); }
$offline = registration(); $calls = count($GLOBALS['http_calls']); $firstInput = input('60', 0, 'Dinheiro'); $m1 = M::save($offline['id'], $firstInput);
same(S::creditTotals($offline['id']), ['paid' => 6000, 'gateway_paid' => 0, 'manual_paid' => 6000], 'External manual receipt credited separately'); same(S::balance($offline)['balance'], 4000, 'Partial manual lowers remaining balance');
$report = Q::dashboard(['event' => $eid]); same([$report['metrics']['paid'], $report['metrics']['manual_paid'], $report['metrics']['gateway_paid']], [6000, 6000, 0], 'Dashboard totals include manual source separately'); same(array_column($report['receipts'], 'paid', 'label'), ['Dinheiro' => 6000], 'Dashboard receipt method uses manual tender');
same(S::manual($m1)['actor_id'], 1, 'Actor comes from authenticated WordPress user'); same(M::save($offline['id'], $firstInput), $m1, 'Duplicate request returns same receipt'); same(count(S::manuals($offline['id'])), 1, 'Duplicate does not create another credit');
failure(fn() => M::save($offline['id'], array_merge($firstInput, ['amount' => '40']))); failure(fn() => M::save($offline['id'], array_merge($firstInput, ['method' => 'Outro'])));
$m2 = M::save($offline['id'], input('40')); same(S::paid($offline['id']), 10000, 'More than one offline receipt can settle contract'); failure(fn() => M::save($offline['id'], input('1'))); failure(fn() => P::create($offline, '1', 'Cartao'));
M::reverse($m1, 'Lançamento em duplicidade identificado', true); same(S::paid($offline['id']), 4000, 'Reversal removes only selected manual receipt'); same(S::manual($m1)['reversed_by'], 1, 'Reversal preserves responsible administrator');
$auditCount = count(S::auditRows($offline['id'])); M::reverse($m1, 'Repetição do clique', true); same(count(S::auditRows($offline['id'])), $auditCount, 'Repeated reversal does not repeat audit/credit change');
same(count($GLOBALS['http_calls']), $calls, 'Manual receipt and reversal make no K8 calls');
$bad = registration();
foreach ([['amount' => '0'], ['amount' => '-1'], ['amount' => '101'], ['amount' => '0.001'], ['method' => 'Boleto'], ['note' => ''], ['paid_on' => '2027-02-30'], ['paid_on' => $today->modify('+1 day')->format('Y-m-d')], ['confirmed' => false], ['request_token' => 'invalid']] as $change) failure(fn() => M::save($bad['id'], array_merge(input(), $change)));
same(S::paid($bad['id']), 0, 'Invalid manual submissions create no credit');
$wpdb->failInsert = 'wp_ie_audit'; failure(fn() => M::save($bad['id'], input('10'))); same(S::manuals($bad['id']), [], 'Audit failure rolls back receipt insertion'); same(S::paid($bad['id']), 0, 'Failed audited receipt creates no financial credit');
$before = $wpdb->rows; $wpdb->failUpdate = 'wp_ie_registrations'; // Manual entry does not edit the registration contract.
$m = M::save($bad['id'], input('10')); same(S::registration($bad['id'])['total'], 10000, 'Manual does not change frozen price'); $wpdb->failUpdate = null; M::reverse($m, 'Correção de teste', true);
$linked = registration(); $p = pending($linked); $m = M::save($linked['id'], input('100', $p['id']));
same(S::paid($linked['id']), 10000, 'Provisional manual receipt covers pending K8 attempt'); same(S::payment($p['id'])['state'], 'pending', 'Manual never fakes K8 paid state'); same(S::transactions($p['id']), [], 'Manual creates no fake gateway transaction');
$public = S::publicRegistration($linked); same($public['balance'], 0, 'Participant sees manually settled balance'); same($public['payments'][0]['url'], '', 'Manual-covered K8 checkout is hidden'); same($public['manual_payments'][0]['status'], 'active', 'Participant sees manual source');
foreach (['note', 'request_key', 'actor_id', 'reverse_note'] as $private) same(array_key_exists($private, $public['manual_payments'][0]), false, 'Public receipt omits ' . $private);
failure(fn() => M::save($linked['id'], input('1', $p['id'])));
queue(remote($p, 'Pendente')); P::sync($p['id'], 'tx-manual-' . $p['id']); same(S::paid($linked['id']), 10000, 'Pending K8 answer preserves provisional manual'); same(empty(S::manual($m)['resolved_at']), true, 'Pending answer cannot reconcile manual');
queue(remote($p, 'Aprovada', ['ClienteCPFCNPJ' => '11144477735'])); failure(fn() => P::sync($p['id'], 'tx-manual-' . $p['id'])); same(empty(S::manual($m)['resolved_at']), true, 'Identity mismatch cannot replace manual credit');
$wpdb->failUpdate = 'wp_ie_payments'; $wpdb->failUpdateField = 'state'; queue(remote($p, 'Aprovada')); failure(fn() => P::sync($p['id'], 'tx-manual-' . $p['id']));
same(empty(S::manual($m)['resolved_at']), true, 'Failed verified commit restores provisional receipt'); same(S::creditTotals($linked['id']), ['paid' => 10000, 'gateway_paid' => 0, 'manual_paid' => 10000], 'K8 and manual changes roll back together');
queue(remote($p, 'Aprovada')); P::sync($p['id'], 'tx-manual-' . $p['id']); same(S::creditTotals($linked['id']), ['paid' => 10000, 'gateway_paid' => 10000, 'manual_paid' => 0], 'Late approval replaces manual without duplicating'); same(S::manual($m)['resolved_state'], 'Aprovada', 'Original manual history kept with resolution');
queue(remote($p, 'Aprovada')); P::sync($p['id'], 'tx-manual-' . $p['id']); same(S::paid($linked['id']), 10000, 'Repeated final callback stays idempotent'); failure(fn() => M::reverse($m, 'Pretender alterar K8', true));
queue(remote($p, 'Estornado')); P::sync($p['id'], 'tx-manual-' . $p['id']); same(S::paid($linked['id']), 0, 'Confirmed refund never resurrects reconciled manual');
foreach (['Rejeitada', 'Cancelada', 'Estornado'] as $state) {
    $r = registration(); $attempt = pending($r); $id = M::save($r['id'], input('100', $attempt['id'])); queue(remote($attempt, $state)); P::sync($attempt['id'], 'tx-manual-' . $attempt['id']); same(S::paid($r['id']), 0, 'Authoritative ' . $state . ' replaces provisional declaration'); same(S::manual($id)['resolved_state'], $state, 'Final state recorded in manual history');
}
$partial = registration(); $attempt = pending($partial); $id = M::save($partial['id'], input('100', $attempt['id'])); queue(remote($attempt, 'Aprovada', ['ValorPago' => 50])); P::sync($attempt['id'], 'tx-manual-' . $attempt['id']); same(S::paid($partial['id']), 5000, 'Verified partial PIX corrects an overstated provisional declaration');
$reverse = registration(); $attempt = pending($reverse); $id = M::save($reverse['id'], input('50', $attempt['id'])); same(S::payments($reverse['id'])[0]['manual_credit'], 5000, 'Partial linked credit accounted once'); failure(fn() => P::create($reverse, '50', 'Cartao'));
M::reverse($id, 'Correção do comprovante', true); same(S::paid($reverse['id']), 0, 'Linked manual reversal preserves pending K8 state'); same(S::payment($attempt['id'])['state'], 'pending', 'Local reversal does not cancel remote attempt');
$canceling = registration(); $attempt = pending($canceling, 'canceling'); failure(fn() => M::save($canceling['id'], input('100', $attempt['id'])));
failure(fn() => M::save($bad['id'], input('100', $p['id']))); S::update('registrations', ['status' => 'deleted'], ['id' => $bad['id']]); failure(fn() => M::save($bad['id'], input('1')));
$unreadable = registration(); $wpdb->failSum = true; failure(fn() => M::save($unreadable['id'], input('100'))); same(S::manuals($unreadable['id']), [], 'Read failure creates no manual credit');
// K8 sync outage: the real charge can be settled locally without API availability.
$outage = registration(); $attempt = pending($outage, 'review');
$GLOBALS['http_queue'][] = ['code' => 503, 'body' => '{"Retorno":"ERRO","Mensagem":"Indisponível"}']; failure(fn() => P::sync($attempt['id'], 'outage-k8'));
$_GET = ['view' => $outage['id']]; $html = capture([Admin::class, 'dashboard']);
same(str_contains($html, 'Dar baixa manual da transação K8'), true, 'K8 manual action discoverable on failed sync');
same(str_contains($html, 'id="ie-k8-manual-' . $attempt['id'] . '" open'), true, 'K8 manual panel is already open after sync failure');
same(strpos($html, 'Histórico de pagamentos K8') < strpos($html, 'Registrar recebimento fora do K8'), true, 'K8 workflow precedes offline receipts');
$calls = count($GLOBALS['http_calls']); $guid = '12345678-90ab-cdef-0123-456789abcdef';
$data = array_merge(input('100', $attempt['id']), ['remote_id' => strtoupper(str_replace('-', '', $guid))]); $id = M::save($outage['id'], $data);
same(S::manual($id)['remote_id'], $guid, 'K8 transaction ID stored canonically'); same(S::payment($attempt['id'])['remote_id_hint'], $guid, 'Manual ID becomes a hint for subsequent verified sync');
same(S::payment($attempt['id'])['state'], 'review', 'K8 state stays truthful during outage'); same(S::transactions($attempt['id']), [], 'No fictitious K8 transaction during outage');
same(S::balance($outage)['balance'], 0, 'K8 manual fallback settles balance during outage'); same(count($GLOBALS['http_calls']), $calls, 'K8 manual fallback makes no external call');
same(M::save($outage['id'], array_merge($data, ['remote_id' => $guid])), $id, 'Canonical ID replay is idempotent');
failure(fn() => M::save($outage['id'], array_merge($data, ['remote_id' => 'another-k8'])));
same(array_key_exists('remote_id', S::publicRegistration($outage)['manual_payments'][0]), false, 'Admin-entered transaction ID is private');
$_GET = ['view' => $outage['id']]; same(str_contains(capture([Admin::class, 'dashboard']), 'Baixada manualmente · aguardando sincronização K8'), true, 'Charge has explicit manual K8 badge');
queue(remote($attempt, 'Rejeitada', ['Identificador' => 'older-failed-k8'])); P::sync($attempt['id'], 'older-failed-k8'); same(S::paid($outage['id']), 10000, 'Another failed transaction of same checkout cannot erase targeted receipt');
queue(remote($attempt, 'Aprovada', ['Identificador' => $guid])); queue(remote($attempt, 'Rejeitada', ['Identificador' => 'older-failed-k8'])); P::sync($attempt['id']);
same(S::creditTotals($outage['id']), ['paid' => 10000, 'gateway_paid' => 10000, 'manual_paid' => 0], 'Late K8 sync by admin-entered ID replaces rather than doubles credit');
// A failed sibling transaction must not hide the actual paid transaction of the same checkout.
$sibling = registration(); $attempt = pending($sibling); queue(remote($attempt, 'Rejeitada')); P::sync($attempt['id'], 'tx-manual-' . $attempt['id']);
failure(fn() => M::save($sibling['id'], array_merge(input('100', $attempt['id']), ['remote_id' => 'tx-manual-' . $attempt['id']])));
S::update('payments', ['remote_id_hint' => 'new-paid-k8'], ['id' => $attempt['id']]);
$_GET = ['view' => $sibling['id']]; same(str_contains(capture([Admin::class, 'dashboard']), 'Dar baixa manual da transação K8'), true, 'Final rejected sibling does not hide pending new transaction action');
$siblingId = M::save($sibling['id'], array_merge(input('100', $attempt['id']), ['remote_id' => 'new-paid-k8'])); same(S::paid($sibling['id']), 10000, 'New K8 transaction can be recognized after rejected sibling');
foreach (['creating', 'closed'] as $state) { $r = registration(); $a = pending($r, $state); M::save($r['id'], input('100', $a['id'])); same(S::paid($r['id']), 10000, 'Local ' . $state . ' without verified terminal result permits manual K8 receipt'); }
$invalidId = registration(); $attempt = pending($invalidId);
foreach (['invalid/id', str_repeat('a', 101), ['forged']] as $value) failure(fn() => M::save($invalidId['id'], array_merge(input('100', $attempt['id']), ['remote_id' => $value])));
failure(fn() => M::save($invalidId['id'], array_merge(input('100'), ['remote_id' => 'k8-without-charge'])));
$wpdb->failUpdate = 'wp_ie_payments'; failure(fn() => M::save($invalidId['id'], array_merge(input('100', $attempt['id']), ['remote_id' => 'rollback-id']))); same(S::manuals($invalidId['id']), [], 'Failure saving hint rolls back K8 manual receipt');
// Reuse an already posted receipt of the same payment, including a fully settled registration.
$reuse = registration(); $attempt = pending($reuse, 'review'); $original = M::save($reuse['id'], input()); $originalRow = S::manual($original); $before = S::creditTotals($reuse['id']);
$_GET = ['view' => $reuse['id']]; $html = capture([Admin::class, 'dashboard']); same(str_contains($html, 'Vincular baixa existente ao K8'), true, 'Already settled registration can link existing receipt');
$calls = count($GLOBALS['http_calls']); $link = ['remote_id' => 'reused-k8', 'note' => 'Mesmo PIX com retorno em atraso', 'confirmed' => true];
M::linkK8($reuse['id'], $attempt['id'], $original, $link); same(S::creditTotals($reuse['id']), $before, 'Linking an existing receipt preserves every financial total'); same(count(S::manuals($reuse['id'])), 1, 'Linking creates no second receipt');
foreach (['amount', 'method', 'paid_on', 'note', 'actor_id', 'created_at', 'request_key'] as $field) same(S::manual($original)[$field], $originalRow[$field], 'Link preserves original receipt ' . $field);
same(S::manual($original)['payment_id'], $attempt['id'], 'Existing receipt now belongs to correct K8 charge'); same(S::manual($original)['remote_id'], 'reused-k8', 'Link stores actual transaction ID');
same(S::publicRegistration($reuse)['payments'][0]['url'], '', 'Reused receipt hides K8 checkout too'); same(count($GLOBALS['http_calls']), $calls, 'Reclassification works with K8 offline');
$audit = count(S::auditRows($reuse['id'])); M::linkK8($reuse['id'], $attempt['id'], $original, $link); same(count(S::auditRows($reuse['id'])), $audit, 'Repeated link is idempotent');
queue(remote($attempt, 'Aprovada', ['Identificador' => 'reused-k8'])); P::sync($attempt['id']); same(S::creditTotals($reuse['id']), ['paid' => 10000, 'gateway_paid' => 10000, 'manual_paid' => 0], 'Reused receipt does not duplicate on late sync');
$reuseBad = registration(); $attempt = pending($reuseBad); $existing = M::save($reuseBad['id'], input('50')); $link['remote_id'] = 'rollback-link';
failure(fn() => M::linkK8($reuseBad['id'], $attempt['id'], $existing, array_merge($link, ['confirmed' => false])));
failure(fn() => M::linkK8($reuseBad['id'], $attempt['id'], $existing, array_merge($link, ['note' => ''])));
failure(fn() => M::linkK8($reuse['id'], $attempt['id'], $existing, $link));
$wpdb->failInsert = 'wp_ie_audit'; failure(fn() => M::linkK8($reuseBad['id'], $attempt['id'], $existing, $link)); same(S::manual($existing)['payment_id'], 0, 'Audit failure rolls back reclassification'); same(S::payment($attempt['id'])['remote_id_hint'], '', 'Audit failure rolls back unverified ID hint too'); same(S::paid($reuseBad['id']), 5000, 'Failed link preserves receipt balance');
$wpdb->failSum = true; failure(fn() => M::linkK8($reuseBad['id'], $attempt['id'], $existing, $link)); same(S::manual($existing)['payment_id'], 0, 'Balance read failure prevents linking');
S::update('payments', ['method' => 'Cartao'], ['id' => $attempt['id']]); failure(fn() => M::linkK8($reuseBad['id'], $attempt['id'], $existing, $link)); S::update('payments', ['method' => 'Pix', 'state' => 'canceling'], ['id' => $attempt['id']]); failure(fn() => M::linkK8($reuseBad['id'], $attempt['id'], $existing, $link));
S::update('payments', ['state' => 'review', 'amount' => 1000], ['id' => $attempt['id']]); failure(fn() => M::linkK8($reuseBad['id'], $attempt['id'], $existing, $link));
M::reverse($existing, 'Correção de teste', true); failure(fn() => M::linkK8($reuseBad['id'], $attempt['id'], $existing, $link));
// Fully recognizing an entry must also release future payments of the remaining contract balance.
$entry = registration(); $attempt = pending($entry, 'review'); S::update('payments', ['amount' => 4000], ['id' => $attempt['id']]); $entryManual = M::save($entry['id'], array_merge(input('40', $attempt['id']), ['remote_id' => 'entry-k8']));
same(S::publicRegistration($entry)['payments'][0]['manual_settled'], true, 'Fully manual-settled entry does not block remaining payments');
same(S::publicRegistration($entry)['payments'][0]['error'], '', 'Settled entry has no participant blocking warning');
queue(['Retorno' => 'OK', 'Identificador' => 'remaining-checkout', 'URL' => 'https://sandbox.meuk8.com.br/PagamentosUI/checkout?id=remaining']);
$remaining = P::create($entry, '60', 'Cartao'); same(S::payment($remaining['payment_id'])['amount'], 6000, 'New card checkout charges only remaining balance after manually settled entry');
same(S::payment($attempt['id'])['state'], 'review', 'Allowing remaining payment does not fake gateway state'); $calls = count($GLOBALS['http_calls']);
same(P::create($entry, '60', 'Cartao')['payment_id'], $remaining['payment_id'], 'Repeated remaining payment reuses new checkout'); same(count($GLOBALS['http_calls']), $calls, 'Retry does not create duplicate checkout');
queue(remote($attempt, 'Aprovada', ['Identificador' => 'entry-k8', 'Valor' => 40, 'ValorPago' => 40])); P::sync($attempt['id']); same(S::paid($entry['id']), 4000, 'Late entry approval leaves remaining balance unchanged after next checkout'); same(S::balance($entry)['balance'], 6000, 'Remaining checkout is not credited before real approval');
$notComplete = registration(); $attempt = pending($notComplete); S::update('payments', ['amount' => 4000], ['id' => $attempt['id']]); M::save($notComplete['id'], input('20', $attempt['id']));
same(S::publicRegistration($notComplete)['payments'][0]['manual_settled'], false, 'Partial manual recognition keeps original attempt outstanding'); failure(fn() => P::create($notComplete, '80', 'Cartao'));
$snapshot = $wpdb->rows; $beforeAudit = count(S::auditRows($offline['id'])); $wpdb->failDelete = 'wp_ie_registrations'; failure(fn() => R::purge($offline['id'])); same($wpdb->rows, $snapshot, 'Failed purge rolls back manual receipt history too');
$managerState = $wpdb->rows; $managerCalls = count($GLOBALS['http_calls']); $GLOBALS['test_admin'] = false; $GLOBALS['test_manager'] = true; $GLOBALS['test_logged_in'] = true;
same(A::canView(), true, 'Manager can view'); same(A::canManage(), false, 'Manager cannot manage'); same(Dashboard::permission(), true, 'Manager can use private dashboard API');
$response = Dashboard::endpoint(new WP_REST_Request([], ['event' => $eid, 'can_manage' => true])); same($response->get_status(), 200, 'Manager reads financial dashboard'); same($response->get_data()['can_manage'], false, 'Client cannot claim write access');
same(str_contains(Dashboard::shortcode(['evento' => $eid]), 'data-event='), true, 'Manager can use existing dashboard shortcode');
$_SERVER['REQUEST_METHOD'] = 'POST';
foreach (['saveEvent' => 'ie_save_event', 'cloneEvent' => 'ie_clone_event', 'deleteEvent' => 'ie_delete_event', 'saveSettings' => 'ie_save_settings', 'testK8' => 'ie_test_k8', 'syncPayment' => 'ie_sync_payment', 'recoverPayment' => 'ie_recover_payment', 'saveRegistration' => 'ie_save_registration', 'registrationAction' => 'ie_registration_action', 'cancelPayment' => 'ie_cancel_payment', 'reviewCancellation' => 'ie_review_cancellation', 'manualPayment' => 'ie_manual_payment', 'linkManualK8' => 'ie_link_manual_k8', 'reverseManual' => 'ie_reverse_manual'] as $method => $action) {
    $_POST = ['_wpnonce' => wp_create_nonce($action), 'registration_id' => $linked['id'], 'confirmed' => '1']; same(str_contains(failure([Admin::class, $method]), 'administrador'), true, 'Manager blocked on ' . $action . ' even with valid nonce');
}
failure(fn() => M::save($linked['id'], input())); failure(fn() => M::reverse($m2, 'Tentativa de gerente', true)); failure(fn() => M::linkK8($reuse['id'], $attempt['id'], $original, $link));
$_GET = ['view' => $linked['id']]; $html = capture([Admin::class, 'dashboard']); same(str_contains($html, 'somente consulta'), true, 'Manager detail identifies read-only mode'); same(str_contains($html, 'Pessoa Exemplo'), true, 'Manager can read participant');
foreach (['ie_save_registration', 'ie_registration_action', 'ie_sync_payment', 'ie_cancel_payment', 'ie_recover_payment', 'ie_manual_payment', 'ie_reverse_manual'] as $action) same(str_contains($html, 'value="' . $action . '"'), false, 'Manager sees no ' . $action . ' form');
$_GET = ['edit_registration' => $linked['id']]; failure(fn() => capture([Admin::class, 'dashboard'])); $_GET = []; failure([Admin::class, 'settings']);
$events = capture([Admin::class, 'events']); same(str_contains($events, 'Evento de testes'), true, 'Manager sees events/lots'); same(str_contains($events, 'ie_save_event'), false, 'Manager sees no event edit form'); same(str_contains($events, 'ie_clone_event'), false, 'Manager sees no clone form');
$guard = new ReflectionMethod(Admin::class, 'guard'); $_POST = ['_wpnonce' => wp_create_nonce('ie_export')]; $guard->invoke(null, 'ie_export', true); $_POST['_wpnonce'] = 'invalid'; failure(fn() => $guard->invoke(null, 'ie_export', true));
same($wpdb->rows, $managerState, 'Read-only manager cannot change business data'); same(count($GLOBALS['http_calls']), $managerCalls, 'Read-only manager sends no K8 request');
$GLOBALS['test_manager'] = false; same(A::canView(), false, 'Ordinary user cannot view'); same(Dashboard::permission() instanceof WP_Error, true, 'Ordinary user cannot call private API'); failure(fn() => capture([Admin::class, 'dashboard']));
$GLOBALS['test_admin'] = true; $_SERVER['REQUEST_METHOD'] = 'GET'; $_POST = ['_wpnonce' => wp_create_nonce('ie_manual_payment')]; failure(fn() => $guard->invoke(null, 'ie_manual_payment')); $_SERVER['REQUEST_METHOD'] = 'POST'; $_POST['_wpnonce'] = 'invalid'; failure(fn() => $guard->invoke(null, 'ie_manual_payment'));
R::purge($offline['id']); same(S::manuals($offline['id']), [], 'Permanent registration deletion removes manual records');
R::purgeEvent($eid); same(count($wpdb->rows[S::table('manuals')] ?? []), 0, 'Event deletion removes all manual histories');
echo 'OK: ' . $checks . " manual receipt/manager permission checks; all K8 traffic mocked.\n";
