<?php
require __DIR__ . '/admin-bootstrap.php';
use InscricaoEventos\Domain as D;
use InscricaoEventos\Store as S;
use InscricaoEventos\Security as C;
use InscricaoEventos\Gateway as G;
use InscricaoEventos\Payments as P;
use InscricaoEventos\Registrations as R;
use InscricaoEventos\Admin as A;
$checks = 0;
function same($a, $b, string $label): void { global $checks; $checks++; if ($a !== $b) throw new RuntimeException($label . ': ' . var_export([$a, $b], true)); }
function failure(callable $fn): string { global $checks; $checks++; try { $fn(); } catch (Throwable $e) { return $e->getMessage(); } throw new RuntimeException('Expected failure'); }
function queue(array $data): void { $GLOBALS['http_queue'][] = ['code' => 200, 'body' => json_encode($data)]; }
function remote(array $p, string $id, string $state = 'Aprovada', array $extra = []): array { return array_merge(['Retorno' => 'OK', 'Identificador' => $id, 'Referencia' => $p['reference'], 'NumeroPedido' => $p['reference'], 'Tipo' => $p['method'], 'Estado' => $state, 'Valor' => $p['amount'] / 100, 'ValorPago' => $p['amount'] / 100, 'ClienteCPFCNPJ' => '52998224725'], $extra); }
$aes = '1234567890abcdef';
update_option('ie_settings', ['environment' => 'sandbox', 'credentials' => C::seal(json_encode(['id' => 'test-client', 'secret' => 'test-secret', 'aes' => $aes]))]);
$g = new G(); $scope = $g->scope(); queue(['access_token' => 'test-token', 'expires_in' => 3600]); $g->authenticate();
$today = new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));
$e = array_merge(S::sample(), ['status' => 'published', 'terms' => '', 'event_date' => $today->modify('+1 year')->format('Y-m-d'), 'payment_deadline' => $today->modify('+1 year')->format('Y-m-d'), 'registration_start' => $today->modify('-1 day')->format('Y-m-d'), 'registration_end' => $today->modify('+1 week')->format('Y-m-d'), 'minimum_entry' => 100, 'public_page_id' => 1]);
$eventId = S::insert('events', $e);
$lotId = S::insert('lots', ['event_id' => $eventId, 'name' => 'Lote 1', 'price' => 49000, 'max_installments' => 10, 'starts_on' => $e['registration_start'], 'ends_on' => $e['registration_end'], 'note' => '']);
$input = ['event_id' => $eventId, 'lot_id' => $lotId, 'expected_price' => 49000, 'method' => 'Pix', 'name' => 'João Teste', 'email' => 'joao@example.test', 'phone' => '41999999999', 'cpf' => '52998224725', 'accepted_terms' => true];
$a = P::register($input); $r = S::registration($a['id']);
queue(['Retorno' => 'OK', 'Identificador' => 'checkout-first', 'URL' => 'https://sandbox.meuk8.com.br/PagamentosUI/checkout?id=first']); $p = S::payment(P::create($r, '100')['payment_id']);
same(C::unseal($p['cpf_snapshot']), '52998224725', 'New attempt freezes its customer CPF');
$legacyId = S::insert('payments', ['registration_id' => $r['id'], 'scope' => $scope, 'reference' => 'IElegacy', 'amount' => 100, 'method' => 'Pix', 'state' => 'closed', 'last_error' => '', 'operator_note' => '', 'checkout_url' => '']);
$edit = ['name' => 'João Corrigido', 'email' => 'JOAO.NOVO@EXAMPLE.TEST', 'phone' => '(41) 98888-7777', 'cpf' => '11144477735', 'method' => 'Pix', 'status' => 'active', 'admin_note' => 'Conferido pela organização', 'total' => 1, 'lot_id' => 999, 'max_installments' => 1];
R::save($r['id'], $edit); $new = S::registration($r['id']);
same($new['email'], 'joao.novo@example.test', 'Email normalized after edit'); same($new['phone'], '41988887777', 'Edited phone normalized');
same(C::unseal($new['cpf_encrypted']), '11144477735', 'Registration CPF updated');
same([$new['total'], $new['lot_id'], $new['max_installments']], [49000, $lotId, 10], 'Contracted price, lot and installments not overwritten');
same(C::unseal(S::payment($legacyId)['cpf_snapshot']), '52998224725', 'Legacy attempt frozen before CPF edit');
same(C::unseal(S::payment($p['id'])['cpf_snapshot']), '52998224725', 'Issued attempt keeps original identity');
same(count($wpdb->rows['wp_ie_sessions'] ?? []), 0, 'Old public session revoked by administrative edit');
same(count(S::auditRows($r['id'])), 1, 'Edit logged with actor and time');
same(str_contains(failure(fn() => R::save($r['id'], array_merge($edit, ['method' => 'Cartao']))), 'andamento'), true, 'Cannot change future method while checkout active');
queue(remote($p, 'tx-original')); P::sync($p['id'], 'tx-original');
same(S::paid($r['id']), 10000, 'Old payment still verifies after registration CPF edit');
$diag = S::payment($p['id'])['diagnostic_json'];
same(str_contains($diag, '52998224725') || str_contains($diag, '11144477735') || str_contains($diag, 'test-secret'), false, 'Diagnostic omits full documents and keys');
same(json_decode($diag, true)['rows'][4]['result'], 'Confere', 'Diagnostic compares immutable customer identity');
R::save($r['id'], array_merge($edit, ['method' => 'Cartao'])); same(S::registration($r['id'])['method'], 'Cartao', 'Future method can change after attempt settles');
same(S::payment($p['id'])['method'], 'Pix', 'Historic method preserved');
queue(['Retorno' => 'OK', 'Identificador' => 'checkout-new', 'URL' => 'https://sandbox.meuk8.com.br/PagamentosUI/checkout?id=new']);
$next = S::payment(P::create(S::registration($r['id']), '390')['payment_id']); $sent = G::decode(end($GLOBALS['http_calls'])[1]['body'], $aes);
same($sent['ClienteCPFCNPJ'], '11144477735', 'Future checkout uses corrected CPF'); same($sent['Cartao']['QuantidadeParcelas'], 10, 'Future checkout keeps original installment limit');
$balance = S::paid($r['id']); $paymentCount = count(S::payments($r['id'])); $calls = count($GLOBALS['http_calls']);
R::trash($r['id']); same(S::registration($r['id'])['status'], 'deleted', 'Delete moves registration to trash');
same(S::paid($r['id']), $balance, 'Delete preserves confirmed credit'); same(count(S::payments($r['id'])), $paymentCount, 'Delete preserves all attempts');
$public = S::publicRegistration(S::registration($r['id'])); same($public['can_pay'], false, 'Trashed registration cannot pay'); same(array_filter(array_column($public['payments'], 'url')), [], 'Trashed registration exposes no checkout links');
failure(fn() => P::create($new, '10')); same(count($GLOBALS['http_calls']), $calls, 'Stale public session cannot create charge after trash');
same(S::paid($r['id']), $balance, 'Trash keeps credit until an explicit permanent deletion');
R::restore($r['id']); same(S::registration($r['id'])['status'], 'active', 'Trash can be restored');
R::save($r['id'], array_merge($edit, ['method' => 'Cartao', 'status' => 'cancelled']));
same(S::publicRegistration(S::registration($r['id']))['can_pay'], false, 'Cancelled registration cannot pay'); failure(fn() => P::create($r, '10'));
$other = P::register(array_merge($input, ['cpf' => '12345678909']));
same(str_contains(failure(fn() => R::save($r['id'], array_merge($edit, ['cpf' => '12345678909', 'method' => 'Cartao']))), 'outra inscrição'), true, 'Editing refuses duplicate CPF in same event/scope');
R::trash($other['id']); R::purge($other['id']); failure(fn() => S::registration($other['id']));
same(count(S::payments($r['id'])), $paymentCount, 'Purging a clean registration preserves others');
$before = S::registration($r['id']); $wpdb->failUpdate = 'wp_ie_registrations';
failure(fn() => R::save($r['id'], array_merge($edit, ['method' => 'Cartao']))); same(S::registration($r['id']), $before, 'Edit storage failure rolls back registration and snapshots');

// Cancellation fixtures reserve local attempts, and every HTTP request is mocked.
function attempt(string $reference, string $method = 'Pix'): array {
    global $scope, $eventId, $lotId;
    $rid = S::insert('registrations', ['event_id' => $eventId, 'scope' => $scope, 'cpf_hash' => C::hash('fixture:' . $reference), 'cpf_encrypted' => C::seal('52998224725'), 'name' => 'Pessoa Teste', 'email' => 'fixture@example.test', 'phone' => '41999999999', 'lot_id' => $lotId, 'lot_name' => 'Lote 1', 'total' => 49000, 'max_installments' => 10, 'method' => $method]);
    $pid = S::insert('payments', ['registration_id' => $rid, 'scope' => $scope, 'cpf_snapshot' => C::seal('52998224725'), 'reference' => $reference, 'amount' => $method === 'Pix' ? 10000 : 49000, 'method' => $method, 'state' => 'pending', 'checkout_id' => 'checkout-' . $reference, 'checkout_url' => '', 'last_error' => '', 'operator_note' => '']); return S::payment($pid);
}
$pix = attempt('IErefund');
foreach ([fn() => P::cancel($pix['id'], 'tx-refund', 'refund', 'Pedido do participante'), fn() => P::cancel($pix['id'], 'tx-refund', 'unknown', 'Pedido', true), fn() => P::cancel($pix['id'], 'tx-refund', 'refund', '', true)] as $fn) { failure($fn); }
$calls = count($GLOBALS['http_calls']);
queue(remote($pix, 'tx-refund')); queue(['Retorno' => 'OK', 'Posterior' => true]); queue(remote($pix, 'tx-refund', 'EstornarPIX'));
$message = P::cancel($pix['id'], 'tx-refund', 'refund', 'Pedido do participante', true);
same(str_contains($message, 'quando'), true, 'Accepted asynchronous refund waits for confirmation');
same(S::paid($pix['registration_id']), 10000, 'Accepted refund never removes credit prematurely');
same(S::payment($pix['id'])['state'], 'canceling', 'Pending refund blocks additional payments');
same(str_ends_with($GLOBALS['http_calls'][$calls + 1][0], '/api/EstornaTransacaoPIx'), true, 'Paid PIX uses official refund endpoint');
same(G::decode($GLOBALS['http_calls'][$calls + 1][1]['body'], $aes), ['Identificador' => 'tx-refund'], 'Cancellation body contains transaction ID only');
$calls = count($GLOBALS['http_calls']); failure(fn() => P::cancel($pix['id'], 'tx-refund', 'refund', 'Repeated click', true));
same(count($GLOBALS['http_calls']), $calls, 'Repeated refund click sends no second request');
failure(fn() => P::create(S::registration($pix['registration_id']), '50')); same(count($GLOBALS['http_calls']), $calls, 'Cannot charge during refund');
queue(remote($pix, 'tx-refund', 'Estornado')); P::sync($pix['id'], 'tx-refund');
same(S::paid($pix['registration_id']), 0, 'Authoritative refund confirmation restores debt');
same(S::transactions($pix['id'])[0]['cancel_status'], 'confirmed', 'Cancellation tracking becomes confirmed');
same(S::payment($pix['id'])['state'], 'closed', 'Verified refunded attempt closes');
$card = attempt('IEcardrefund', 'Cartao'); queue(remote($card, 'tx-card')); queue(['Retorno' => 'OK', 'Posterior' => false]); queue(remote($card, 'tx-card', 'Cancelada'));
same(str_contains(P::cancel($card['id'], 'tx-card', 'refund', 'Cancelamento do cartão', true), 'confirmado'), true, 'Immediate card cancellation confirmed through separate query');
same(str_contains($GLOBALS['http_calls'][count($GLOBALS['http_calls']) - 2][0], '/api/CancelaTransacao'), true, 'Card refund uses cancellation endpoint');
same(S::paid($card['registration_id']), 0, 'Card credit removed only after terminal API state');

$pending = attempt('IEpending'); queue(remote($pending, 'tx-pending', 'Pendente', ['ValorPago' => 0])); queue(['Retorno' => 'ERRO', 'DetalhesErro' => 'Estado da transação não permite cancelar.']);
same(str_contains(failure(fn() => P::cancel($pending['id'], 'tx-pending', 'cancel', 'Cancelamento solicitado', true)), 'recusada'), true, 'Provider rejection reported');
same(S::payment($pending['id'])['state'], 'pending', 'Rejected cancellation does not falsely close checkout');
same(S::transactions($pending['id'])[0]['cancel_status'], 'failed', 'Provider rejection distinguished from ambiguous timeout');
$wrong = attempt('IEwrong');
foreach ([['ClienteCPFCNPJ' => '00000000000'], ['Referencia' => 'foreign'], ['Valor' => 99], ['Tipo' => 'Cartao'], ['Identificador' => 'foreign-id']] as $mismatch) {
    $calls = count($GLOBALS['http_calls']); queue(remote($wrong, 'tx-wrong', 'Aprovada', $mismatch)); failure(fn() => P::cancel($wrong['id'], 'tx-wrong', 'refund', 'Pedido do participante', true));
    same(count($GLOBALS['http_calls']) - $calls, 1, 'Identity mismatch sends only query, never cancellation'); same(S::paid($wrong['registration_id']), 0, 'Mismatch cannot credit or refund another transaction');
}
$unknown = attempt('IEunknown'); queue(remote($unknown, 'tx-unknown')); $GLOBALS['http_queue'][] = new WP_Error('timeout', 'mock timeout');
failure(fn() => P::cancel($unknown['id'], 'tx-unknown', 'refund', 'Pedido do participante', true));
same(S::transactions($unknown['id'])[0]['cancel_status'], 'review', 'Ambiguous request saved for operator review');
same(S::paid($unknown['registration_id']), 10000, 'Timeout cannot invent a refund');
$calls = count($GLOBALS['http_calls']); failure(fn() => P::cancel($unknown['id'], 'tx-unknown', 'refund', 'Repeated', true)); same(count($GLOBALS['http_calls']), $calls, 'Unknown request is never automatically resent');
failure(fn() => P::reviewCancellation($unknown['id'], 'tx-unknown', 'Checked')); same(count($GLOBALS['http_calls']), $calls, 'Review requires explicit confirmation');
queue(remote($unknown, 'tx-unknown', 'EstornarPIX')); failure(fn() => P::reviewCancellation($unknown['id'], 'tx-unknown', 'Checked in K8', true));
same(S::payment($unknown['id'])['state'], 'canceling', 'Operator cannot override K8 cancellation-in-progress state');
queue(remote($unknown, 'tx-unknown')); P::reviewCancellation($unknown['id'], 'tx-unknown', 'Conferido com K8: solicitação anterior não recebida', true);
same(S::transactions($unknown['id'])[0]['cancel_status'], 'failed', 'Explicit review unlocks verified non-pending request');
same(S::paid($unknown['registration_id']), 10000, 'Operator review never creates financial refund');
$accepted = attempt('IEaccepted'); queue(remote($accepted, 'tx-accepted')); queue(['Retorno' => 'OK', 'Posterior' => false]); $GLOBALS['http_queue'][] = new WP_Error('timeout', 'mock timeout');
same(str_contains(P::cancel($accepted['id'], 'tx-accepted', 'refund', 'Pedido do participante', true), 'Solicitação enviada'), true, 'Post-request query failure preserves accepted request');
same(S::transactions($accepted['id'])[0]['cancel_status'], 'requested', 'Accepted request remains pending confirmation');
same(S::paid($accepted['registration_id']), 10000, 'Posterior=false alone cannot remove credit');
$storage = attempt('IEstorage'); queue(remote($storage, 'tx-storage')); $wpdb->failUpdate = 'wp_ie_transactions'; $wpdb->failUpdateField = 'cancel_status'; $calls = count($GLOBALS['http_calls']);
failure(fn() => P::cancel($storage['id'], 'tx-storage', 'refund', 'Pedido', true)); same(count($GLOBALS['http_calls']) - $calls, 1, 'Storage failure prevents remote mutation');
same(S::transactions($storage['id'])[0]['cancel_status'], '', 'Cancellation state rolled back before sending');
same(S::payment($storage['id'])['state'], 'paid', 'Authoritative pre-request credit preserved');
$s = get_option('ie_settings'); $s['environment'] = 'production'; update_option('ie_settings', $s);
$calls = count($GLOBALS['http_calls']); failure(fn() => P::cancel($storage['id'], 'tx-storage', 'refund', 'Pedido', true)); same(count($GLOBALS['http_calls']), $calls, 'Cross-environment cancellation blocked');
$s['environment'] = 'sandbox'; update_option('ie_settings', $s);

// Controllers reject unauthorized users, invalid nonces and non-POST requests before any mutation.
$_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = ['registration_id' => $r['id'], 'payment_id' => $pix['id']];
$calls = count($GLOBALS['http_calls']); $GLOBALS['test_admin'] = false;
foreach ([A::class . '::cancelPayment', A::class . '::saveRegistration', A::class . '::registrationAction', A::class . '::reviewCancellation'] as $handler) { same(str_contains(failure($handler), 'Acesso negado'), true, 'Administrative capability required'); }
$GLOBALS['test_admin'] = true; same(str_contains(failure([A::class, 'cancelPayment']), 'Nonce'), true, 'Cancellation nonce required');
$_SERVER['REQUEST_METHOD'] = 'GET'; same(str_contains(failure([A::class, 'cancelPayment']), 'formulário'), true, 'GET cannot cancel transaction');
same(count($GLOBALS['http_calls']), $calls, 'Rejected controllers send no K8 requests');
same(count($GLOBALS['http_queue']), 0, 'All mocked responses consumed');
echo 'OK: ' . $checks . " management/cancellation checks; no real changes in K8.\n";
