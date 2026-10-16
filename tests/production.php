<?php
/** Production protocol fixtures: synthetic participants, encrypted mocked transport, no live payments. */
require __DIR__ . '/admin-bootstrap.php';
use InscricaoEventos\Api as A;
use InscricaoEventos\Domain as D;
use InscricaoEventos\Gateway as G;
use InscricaoEventos\Payments as P;
use InscricaoEventos\Security as C;
use InscricaoEventos\Store as S;
$checks = 0;
function same($actual, $expected, string $label): void { global $checks; $checks++; if ($actual !== $expected) throw new RuntimeException($label . ': ' . var_export([$actual, $expected], true)); }
function failure(callable $fn): string { global $checks; $checks++; try { $fn(); } catch (Throwable $e) { return $e->getMessage(); } throw new RuntimeException('Expected failure'); }
function queue(array $data, int $code = 200): void { $GLOBALS['http_queue'][] = ['code' => $code, 'body' => G::encode($data, '1234567890abcdef')]; }
update_option('ie_settings', ['environment' => 'production', 'credentials' => C::seal(json_encode(['id' => 'production-fixture', 'secret' => 'fictional-secret', 'aes' => '1234567890abcdef']))]);
$scope = (new G())->scope(); $today = new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));
$eid = S::insert('events', array_merge(S::sample(), ['status' => 'published', 'event_date' => $today->modify('+1 year')->format('Y-m-d'), 'payment_deadline' => $today->modify('+1 year')->format('Y-m-d'), 'registration_start' => $today->modify('-1 day')->format('Y-m-d'), 'registration_end' => $today->modify('+1 week')->format('Y-m-d'), 'public_page_id' => 1]));
S::insert('lots', ['event_id' => $eid, 'name' => 'Lote 1', 'price' => 49000, 'max_installments' => 10, 'starts_on' => $today->modify('-1 day')->format('Y-m-d'), 'ends_on' => $today->modify('+1 week')->format('Y-m-d')]);
function registration(string $method = 'Pix'): array {
    global $scope, $eid;
    $id = S::insert('registrations', ['event_id' => $eid, 'scope' => $scope, 'lot_id' => 1, 'lot_name' => 'Lote 1', 'total' => 49000, 'max_installments' => 10, 'method' => $method, 'cpf_hash' => C::hash('test:' . random_bytes(8)), 'cpf_encrypted' => C::seal('52998224725'), 'name' => 'Pessoa Exemplo', 'email' => 'pessoa@example.test', 'phone' => '41999999999', 'terms_snapshot' => 'Condições teste', 'terms_accepted_at' => current_time('mysql', true), 'created_at' => current_time('mysql', true)]);
    return S::registration($id);
}
function payment(array $r): array {
    $sequence = count(S::payments($r['id'])) + 1;
    return S::payment(S::insert('payments', ['registration_id' => $r['id'], 'scope' => $r['scope'], 'reference' => 'IEproduction' . $r['id'] . '-' . $sequence, 'amount' => 10000, 'method' => 'Pix', 'cpf_snapshot' => $r['cpf_encrypted'], 'state' => 'pending', 'checkout_id' => 'checkout-' . $r['id'] . '-' . $sequence, 'checkout_url' => 'https://pagamentos.meuk8.com.br/PagamentosUI/checkout?id=fixture', 'last_error' => '', 'operator_note' => '']));
}
function remote(array $p, string $id, array $extra = []): array { return array_merge(['Retorno' => 'OK', 'Identificador' => $id, 'Referencia' => $p['reference'], 'NumeroPedido' => $p['reference'], 'ClienteCPFCNPJ' => '52998224725', 'Tipo' => $p['method'], 'Valor' => $p['amount'] / 100, 'ValorPago' => $p['amount'] / 100, 'Estado' => 'Aprovada'], $extra); }
function sessionFor(array $r): void {
    $token = bin2hex(random_bytes(32)); $_COOKIE['ie_session'] = $token;
    S::insert('sessions', ['token_hash' => C::hash($token), 'registration_id' => $r['id'], 'expires_at' => time() + 3600]);
}
function refresh(array $r, array $data): array {
    sessionFor($r); $method = new ReflectionMethod(A::class, 'action'); return $method->invoke(null, 'refresh', new WP_REST_Request([], [], [], $data));
}
$GLOBALS['http_queue'][] = ['code' => 200, 'body' => '{"access_token":"fictional-production-token","expires_in":3600}'];
$card = registration('Cartao'); queue(['Retorno' => 'OK', 'Identificador' => 'card-checkout', 'URL' => 'https://pagamentos.meuk8.com.br/PagamentosUI/checkout?id=card-fixture']);
$created = P::create($card, '490', 'Cartao', 10); $p = S::payment($created['payment_id']);
same($GLOBALS['http_calls'][0][0], 'https://pagamentos.meuk8.com.br/PagamentosAPI/token', 'Production OAuth host');
same($GLOBALS['http_calls'][1][0], 'https://pagamentos.meuk8.com.br/PagamentosAPI/v2/CriaTransacaoCheckout', 'Production checkout v2');
$sent = G::decode($GLOBALS['http_calls'][1][1]['body'], '1234567890abcdef');
same($sent['Cartao']['FormaPagamento'], ['AVista', 'ParceladoSemJuros'], 'Documented v2 card array preserved'); same($sent['Cartao']['QuantidadeParcelas'], 10, 'Production card installment cap'); same(isset($sent['Pix']), false, 'Card-only checkout'); same($sent['Valor'], 490, 'Production card amount');
parse_str(parse_url($sent['URLRetorno'], PHP_URL_QUERY), $return); same((int) $return['ie_payment'], $p['id'], 'Return URL binds exact local payment'); same((int) $return['ie_event'], $eid, 'Return URL binds event');
same(str_starts_with($created['url'], 'https://pagamentos.meuk8.com.br/'), true, 'Production checkout URL accepted');
$calls = count($GLOBALS['http_calls']); same(P::create($card, '490', 'Cartao')['payment_id'], $p['id'], 'Production retry reuses pending checkout'); same(count($GLOBALS['http_calls']), $calls, 'Production retry creates no duplicate');
// A bank rejection is visible to the participant with its attempt ID and no credentials/documents.
$rejected = registration('Cartao'); queue(['Retorno' => 'ERRO', 'DetalhesErro' => 'Cartão não habilitado. fictional-secret 1234567890abcdef 52998224725']);
$error = failure(fn() => P::create($rejected, '490', 'Cartao')); same(str_contains($error, 'Cartão não habilitado'), true, 'Card rejection reason is actionable'); same(str_contains($error, 'Tentativa #'), true, 'Card error identifies review attempt');
foreach (['fictional-secret', '1234567890abcdef', '52998224725'] as $private) same(str_contains($error, $private), false, 'Card error redacts private field');
same(S::payments($rejected['id'])[0]['state'], 'review', 'Rejected/uncertain operation remains protected against automatic duplicates');
// Emission search misses the checkout; the documented checkout filter finds the exact order.
$pix = registration(); $p = payment($pix); queue(['Retorno' => 'ERRO', 'DetalhesErro' => 'Referencia da Transação não localizada']);
queue(['Retorno' => 'OK', 'Transacoes' => []]); queue(['Retorno' => 'OK', 'Transacoes' => [remote($p, 'pix-from-checkout', ['Referencia' => null])]]); queue(remote($p, 'pix-from-checkout', ['Referencia' => null]));
$calls = count($GLOBALS['http_calls']); P::sync($p['id']); same(S::paid($pix['id']), 10000, 'Checkout fallback independently confirms PIX');
$first = G::decode($GLOBALS['http_calls'][$calls + 1][1]['body'], '1234567890abcdef'); $second = G::decode($GLOBALS['http_calls'][$calls + 2][1]['body'], '1234567890abcdef');
same([$first['TipoFiltro'], $second['TipoFiltro']], ['dataemissao', 'checkout'], 'Both documented search modes');
same(strtotime($first['DataHoraInicial']), strtotime($p['created_at'] . ' UTC') - 300, 'Search preserves original creation instant');
same(substr($first['DataHoraInicial'], -6), $today->format('P'), 'Search timestamps use event local offset'); same(str_contains($GLOBALS['http_calls'][$calls + 3][0], '/api/ConsultaTransacao'), true, 'List result never credits without ID lookup');
// An empty/mismatched search records only query counts and local identity, never unrelated people.
$missing = registration(); $p = payment($missing); $other = remote($p, 'foreign-id', ['Referencia' => 'foreign', 'NumeroPedido' => 'foreign', 'ClienteNome' => 'DO NOT STORE OTHER PERSON', 'ClienteCPFCNPJ' => '11144477735']);
queue(['Retorno' => 'ERRO', 'DetalhesErro' => 'Referencia da Transação não localizada']); queue(['Retorno' => 'OK', 'Transacoes' => [$other]]); queue(['Retorno' => 'OK', 'Transacoes' => []]); failure(fn() => P::sync($p['id']));
$diag = S::payment($p['id'])['diagnostic_json']; $parsed = json_decode($diag, true); same(array_column($parsed['search'], 'returned'), [1, 0], 'Search diagnostics distinguish unmatched from empty results');
same(str_contains($diag, 'DO NOT STORE') || str_contains($diag, '11144477735') || str_contains($diag, 'foreign-id'), false, 'Discovery diagnostics retain no unrelated transaction data'); same(S::paid($missing['id']), 0, 'No exact match creates no credit');
// Participant return IDs are hints; only their own payment and a fully verified bank answer may credit.
$returned = registration(); $p = payment($returned); $calls = count($GLOBALS['http_calls']); queue(remote($p, 'pix-return-id'));
$public = refresh($returned, ['payment_id' => $p['id'], 'remote_id' => 'pix-return-id']); same($public['paid'], 10000, 'Return ID allows verified reconciliation without reference lookup'); same(count($GLOBALS['http_calls']), $calls + 1, 'Return ID needs one independent query');
same(S::payment($p['id'])['remote_id_hint'], 'pix-return-id', 'Return hint retained for future retries'); queue(remote($p, 'pix-return-id')); same(refresh($returned, ['payment_id' => $p['id'], 'remote_id' => 'pix-return-id'])['paid'], 10000, 'Repeated browser return never duplicates credit');
$calls = count($GLOBALS['http_calls']); failure(fn() => refresh($missing, ['payment_id' => $p['id'], 'remote_id' => 'pix-return-id'])); same(count($GLOBALS['http_calls']), $calls, 'Cross-registration return is rejected before contacting K8');
foreach ([['remote_id' => ['forged']], ['remote_id' => 'invalid/id'], ['remote_id' => 'valid-id', 'payment_id' => ['forged']], ['remote_id' => 'valid-id', 'payment_id' => -1]] as $bad) failure(fn() => refresh($missing, $bad));
$legacy = registration(); $p = payment($legacy); queue(remote($p, 'legacy-return-id')); same(refresh($legacy, ['remote_id' => 'legacy-return-id'])['paid'], 10000, 'Old return URL can identify its sole outstanding payment');
$invalid = registration(); $p = payment($invalid); queue(remote($p, 'wrong-return-id', ['ClienteCPFCNPJ' => '11144477735'])); same(refresh($invalid, ['payment_id' => $p['id'], 'remote_id' => 'wrong-return-id'])['paid'], 0, 'Forged return cannot bypass CPF check'); same(S::payment($p['id'])['remote_id_hint'], '', 'Invalid browser return cannot poison canonical retry ID');
$waiting = registration(); $p = payment($waiting); $GLOBALS['http_queue'][] = ['code' => 503, 'body' => 'unavailable']; $response = refresh($waiting, ['payment_id' => $p['id'], 'remote_id' => 'waiting-return-id']); same($response['paid'], 0, 'Outage on browser return preserves account without false approval'); same($response['return_retry'], true, 'Outage asks browser to retry the same hint'); same(S::payment($p['id'])['remote_id_hint'], '', 'Unverified browser hint cannot replace server reconciliation ID');
S::update('payments', ['remote_id_hint' => 'known-valid-id'], ['id' => $p['id']]); queue(remote($p, 'wrong-browser-id', ['ClienteCPFCNPJ' => '11144477735'])); refresh($waiting, ['payment_id' => $p['id'], 'remote_id' => 'wrong-browser-id']); same(S::payment($p['id'])['remote_id_hint'], 'known-valid-id', 'A forged return cannot overwrite an existing trusted hint');
queue(remote($p, 'known-valid-id')); P::sync($p['id']); same(S::paid($waiting['id']), 10000, 'Regular reconciliation still succeeds after a forged browser return');
$ambiguous = registration(); $one = payment($ambiguous); $two = payment($ambiguous); $calls = count($GLOBALS['http_calls']);
queue(remote($one, 'real-open-one', ['Estado' => 'Pendente', 'ValorPago' => 0])); queue(remote($two, 'real-open-two', ['Estado' => 'Pendente', 'ValorPago' => 0]));
same(refresh($ambiguous, ['remote_id' => 'untrusted-ambiguous-hint'])['paid'], 0, 'Legacy return never chooses between two open checkouts');
foreach (array_slice($GLOBALS['http_calls'], $calls) as $call) { same(str_ends_with($call[0], '/api/ConsultaTransacaoReferencia'), true, 'Ambiguous return uses original references instead of foreign hint'); }
same(S::payment($one['id'])['remote_id_hint'] === 'untrusted-ambiguous-hint' || S::payment($two['id'])['remote_id_hint'] === 'untrusted-ambiguous-hint', false, 'Ambiguous browser hint is not attached to either attempt');
same(count($GLOBALS['http_queue']), 0, 'All mocked responses consumed by intended calls');
echo 'OK: ' . $checks . " production checkout/PIX return checks; all transport mocked, no live payments.\n";
