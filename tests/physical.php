<?php
/** Physical POS receipts are local, audited and never a synthetic K8 payment. */
require __DIR__ . '/admin-bootstrap.php';
use InscricaoEventos\Api;
use InscricaoEventos\Admin;
use InscricaoEventos\Domain as D;
use InscricaoEventos\Gateway as G;
use InscricaoEventos\Manual as M;
use InscricaoEventos\Payments as P;
use InscricaoEventos\Reports as Q;
use InscricaoEventos\Security as C;
use InscricaoEventos\Store as S;
$checks = 0;
function same($actual, $expected, string $label): void { global $checks; $checks++; if ($actual !== $expected) throw new RuntimeException($label . ': ' . var_export([$actual, $expected], true)); }
function failure(callable $fn): string { global $checks; $checks++; try { $fn(); } catch (Throwable $e) { return $e->getMessage(); } throw new RuntimeException('Expected failure'); }
function capture(callable $fn): string { ob_start(); try { $fn(); return ob_get_contents(); } finally { ob_end_clean(); } }
function queue(array $data): void { $GLOBALS['http_queue'][] = ['code' => 200, 'body' => json_encode($data)]; }
function api(string $action, array $input = []): array { return (new ReflectionMethod(Api::class, 'action'))->invoke(null, $action, new WP_REST_Request([], [], [], $input)); }
function sessionFor(int $id): void { $token = bin2hex(random_bytes(32)); $_COOKIE['ie_session'] = $token; S::insert('sessions', ['token_hash' => C::hash($token), 'registration_id' => $id, 'expires_at' => time() + 3600]); }
function receipt(string $amount): array { return ['amount' => $amount, 'payment_id' => 0, 'method' => D::PHYSICAL_CARD, 'paid_on' => date('Y-m-d'), 'note' => 'Maquininha: comprovante NSU TESTE conferido', 'confirmed' => true, 'request_token' => bin2hex(random_bytes(32))]; }
$today = new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));
$input = array_merge(S::sample(), ['name' => 'Retiro de teste POS', 'status' => 'published', 'event_date' => $today->modify('+1 year')->format('Y-m-d'), 'payment_deadline' => $today->modify('+1 year')->format('Y-m-d'), 'registration_start' => $today->modify('-1 day')->format('Y-m-d'), 'registration_end' => $today->modify('+1 week')->format('Y-m-d'), 'allow_pix' => 0, 'allow_card' => 0, 'allow_card_physical' => 1, 'lots' => [['name' => 'Lote POS', 'starts_on' => $today->modify('-1 day')->format('Y-m-d'), 'ends_on' => $today->modify('+1 week')->format('Y-m-d'), 'price' => '490', 'max_installments' => 10]]]);
$eid = S::saveEvent($input); $e = S::event($eid);
same(D::methods($e), [D::PHYSICAL_CARD], 'Physical-only event configuration'); same(strlen(D::PHYSICAL_CARD) <= 10, true, 'Method fits existing schema');
same(S::sample()['allow_card'], 1, 'Existing sample online mode preserved'); same(S::sample()['allow_card_physical'], 0, 'Physical mode is opt-in');
same(D::physicalMessage($e), D::PHYSICAL_MESSAGE, 'Default secretaria instruction');
same(D::methods(['allow_pix' => 1, 'allow_card' => 1]), ['Pix', 'Cartao'], 'Older event rows retain their modes');
failure(fn() => S::saveEvent(array_merge($input, ['allow_card_physical' => 0])));
$custom = array_merge($input, ['physical_card_message' => '<b>Procure a secretaria de teste.</b>']); S::saveEvent($custom, $eid); same(S::event($eid)['physical_card_message'], 'Procure a secretaria de teste.', 'Instruction is configurable plain text'); S::saveEvent($input, $eid);
$lot = S::lots($eid)[0];
$registrationInput = ['event_id' => $eid, 'lot_id' => $lot['id'], 'expected_price' => 49000, 'method' => D::PHYSICAL_CARD, 'cpf' => '52998224725', 'name' => 'Pessoa Exemplo', 'email' => 'pessoa@example.test', 'phone' => '41999999999', 'accepted_terms' => true];
$a = P::register($registrationInput); $rid = $a['id']; $r = S::registration($rid); sessionFor($rid);
same($r['scope'], G::offlineScope(), 'No credentials needed for physical card'); same($r['method'], D::PHYSICAL_CARD, 'Choice retained on registration');
same([$a['paid'], $a['balance'], $a['status']], [0, 49000, 'pending'], 'Choosing physical card does not credit registration');
same([$r['total'], $r['max_installments']], [49000, 10], 'Frozen lot price and installment limit');
same(str_contains($r['terms_snapshot'], D::PHYSICAL_MESSAGE), true, 'Accepted physical instructions frozen'); same(S::byCPF($eid, '52998224725')['id'], $rid, 'CPF lookup without K8 credentials');
same(api('account')['id'], $rid, 'Local account session accepted'); same(api('lookup', ['event_id' => $eid, 'cpf' => '52998224725'])['balance'], 49000, 'Physical CPF consultation leaves balance unpaid'); sessionFor($rid);
$instructions = api('payment', ['method' => D::PHYSICAL_CARD, 'amount' => 'invalid']);
same($instructions['physical_card'], true, 'Physical payment returns instruction instead of checkout'); same($instructions['message'], D::PHYSICAL_MESSAGE, 'Requested secretaria message'); same(isset($instructions['url']), false, 'No K8 checkout URL');
same(P::create($r, '490', D::PHYSICAL_CARD)['registration']['balance'], 49000, 'Repeated choice remains unpaid');
same(S::payments($rid), [], 'No gateway payment rows for physical choice'); same($wpdb->rows['wp_ie_transactions'] ?? [], [], 'No fake bank transactions'); same($GLOBALS['http_calls'], [], 'No K8 auth or API traffic');
failure(fn() => P::register($registrationInput)); failure(fn() => P::create($r, '490', 'Cartao')); failure(fn() => P::create($r, '10', 'Pix'));
failure(fn() => G::payload(['method' => D::PHYSICAL_CARD], $r, $e, '', '', '52998224725'));
$_GET = ['view' => $rid]; $html = capture([Admin::class, 'dashboard']); same(str_contains($html, 'Cartão físico (maquininha)'), true, 'Admin identifies POS choice'); same(str_contains($html, 'value="CartaoPOS" selected'), true, 'POS preselected for manual receipt');
$partial = receipt('100'); $mid = M::save($rid, $partial); same(S::creditTotals($rid), ['paid' => 10000, 'gateway_paid' => 0, 'manual_paid' => 10000], 'POS receipt is independent manual credit'); same(S::manual($mid)['payment_id'], 0, 'POS receipt not linked to a K8 attempt');
same(M::save($rid, $partial), $mid, 'Double click idempotent'); same(S::publicRegistration($r)['balance'], 39000, 'Partial POS receipt lowers balance');
$q = Q::dashboard(['event' => $eid, 'method' => D::PHYSICAL_CARD]); same([$q['metrics']['count'], $q['metrics']['paid'], $q['metrics']['balance']], [1, 10000, 39000], 'Local registration included in filtered finance'); same(array_column($q['receipts'], 'paid', 'label'), [D::PHYSICAL_CARD => 10000], 'Actual receipt method shown as POS');
$GLOBALS['test_admin'] = false; $GLOBALS['test_manager'] = true; $before = $wpdb->rows;
failure(fn() => M::save($rid, receipt('390'))); failure(fn() => M::reverse($mid, 'Tentativa do gerente', true));
$_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = ['_wpnonce' => wp_create_nonce('ie_manual_payment'), 'registration_id' => $rid, 'method' => D::PHYSICAL_CARD, 'confirmed' => '1']; failure([Admin::class, 'manualPayment']);
$_GET = ['view' => $rid]; $manager = capture([Admin::class, 'dashboard']); same(str_contains($manager, 'Cartão físico (maquininha)'), true, 'Manager reads POS history'); same(str_contains($manager, 'value="ie_manual_payment"'), false, 'Manager sees no receipt action'); same($wpdb->rows, $before, 'Manager cannot change POS receipts'); $GLOBALS['test_admin'] = true; $GLOBALS['test_manager'] = false;
$wpdb->failInsert = 'wp_ie_audit'; failure(fn() => M::save($rid, receipt('390'))); same(S::paid($rid), 10000, 'Audit failure rolls back receipt and credit');
$full = M::save($rid, receipt('390')); same(S::publicRegistration($r)['status'], 'paid', 'Only administrator receipt settles registration'); failure(fn() => P::create($r, '', D::PHYSICAL_CARD));
M::reverse($full, 'Correção do lançamento da maquininha', true); same(S::balance($r)['balance'], 39000, 'Audited reversal restores remaining balance'); same($GLOBALS['http_calls'], [], 'Manual POS administration never contacts K8');
// Adding online modes later retains the local registration and migrates only on its first authenticated checkout.
update_option('ie_settings', ['environment' => 'sandbox', 'credentials' => C::seal(json_encode(['id' => 'pos-fixture', 'secret' => 'fictional-secret', 'aes' => '1234567890abcdef']))]); $scope = (new G())->scope();
S::update('events', ['allow_pix' => 1, 'allow_card' => 1], ['id' => $eid]); same(S::byCPF($eid, '52998224725')['id'], $rid, 'Adding credentials preserves CPF lookup'); same(api('account')['id'], $rid, 'Local session survives adding K8 credentials');
queue(['access_token' => 'fictional-token', 'expires_in' => 3600]); $wpdb->failInsert = 'wp_ie_audit'; failure(fn() => P::create($r, '390', 'Cartao', 10)); same(S::registration($rid)['scope'], G::offlineScope(), 'Scope migration audit failure rolls back'); same(S::payments($rid), [], 'Failed migration creates no checkout');
queue(['Retorno' => 'OK', 'Identificador' => 'pos-online-balance', 'URL' => 'https://sandbox.meuk8.com.br/PagamentosUI/checkout?id=pos-online-balance']);
$checkout = P::create($r, '390', 'Cartao', 10); $r = S::registration($rid); $p = S::payment($checkout['payment_id']);
same($r['scope'], $scope, 'Only local scope migrated into configured bank account'); same([$p['amount'], $p['method'], $p['max_installments']], [39000, 'Cartao', 10], 'Online card charges exact remaining balance with frozen cap'); same(S::paid($rid), 10000, 'Migration preserves POS receipt'); same(S::byCPF($eid, '52998224725')['id'], $rid, 'Migration creates no duplicate registration');
$sent = G::decode(end($GLOBALS['http_calls'])[1]['body'], '1234567890abcdef'); same($sent['Valor'], 390, 'Checkout payload excludes physical amount already received');
S::update('events', ['allow_card' => 0], ['id' => $eid]); $calls = count($GLOBALS['http_calls']);
same(S::publicRegistration($r)['payments'][0]['url'], '', 'Online card disabled hides prior checkout'); failure(fn() => P::create($r, '390', 'Cartao'));
same(P::create($r, '', D::PHYSICAL_CARD)['physical_card'], true, 'Physical instruction accessible with old bank attempt pending'); same(S::payment($p['id'])['state'], 'pending', 'Physical choice never closes or modifies bank attempt'); same(count(S::payments($rid)), 1, 'No second bank charge'); same(count($GLOBALS['http_calls']), $calls, 'No K8 request after online card disabled');
// Neither bank isolation nor event/registration state guards are relaxed by physical card.
$oldScope = $r['scope']; S::update('registrations', ['scope' => 'other-bank-account'], ['id' => $rid]); failure(fn() => P::create($r, '', D::PHYSICAL_CARD)); failure(fn() => api('account')); S::update('registrations', ['scope' => $oldScope], ['id' => $rid]);
foreach (['cancelled', 'deleted'] as $status) { S::update('registrations', ['status' => $status], ['id' => $rid]); failure(fn() => P::create($r, '', D::PHYSICAL_CARD)); }
S::update('registrations', ['status' => 'active'], ['id' => $rid]); S::update('events', ['payment_deadline' => $today->modify('-1 day')->format('Y-m-d')], ['id' => $eid]); failure(fn() => P::create($r, '', D::PHYSICAL_CARD));
S::update('events', ['payment_deadline' => $input['payment_deadline'], 'allow_card_physical' => 0], ['id' => $eid]); failure(fn() => P::create($r, '', D::PHYSICAL_CARD));
same(count($GLOBALS['http_calls']), $calls, 'All rejected actions are local'); same(count($GLOBALS['http_queue']), 0, 'Mocked checkout responses consumed');
echo 'OK: ' . $checks . " physical card/configuration/manual receipt checks; no live payments.\n";
