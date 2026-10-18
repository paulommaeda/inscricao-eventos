<?php
/** Frontend login, registration modal and identity: fictional WordPress accounts and K8 only. */
require __DIR__ . '/admin-bootstrap.php';
use InscricaoEventos\Access;
use InscricaoEventos\Api;
use InscricaoEventos\Branding as B;
use InscricaoEventos\Dashboard;
use InscricaoEventos\DashboardLogin as L;
use InscricaoEventos\Domain as D;
use InscricaoEventos\Gateway as G;
use InscricaoEventos\RegistrationPanel as Panel;
use InscricaoEventos\Security as C;
use InscricaoEventos\Store as S;
$checks = 0;
function same($actual, $expected, string $label): void { global $checks; $checks++; if ($actual !== $expected) throw new RuntimeException($label . ': ' . var_export([$actual, $expected], true)); }
function request(int $id, array $body = [], array $query = [], string $nonce = 'valid'): WP_REST_Request { return new WP_REST_Request(['x-wp-nonce' => $nonce === 'valid' ? wp_create_nonce('wp_rest') : $nonce], $query, ['id' => $id], $body); }
function registration(int $event, string $scope, string $cpf = '52998224725'): int { return S::insert('registrations', ['event_id' => $event, 'scope' => $scope, 'lot_id' => 1, 'lot_name' => 'Lote original', 'total' => 49000, 'max_installments' => 10, 'method' => D::PHYSICAL_CARD, 'name' => 'Pessoa Exemplo', 'email' => 'pessoa@example.test', 'phone' => '41999999999', 'cpf_hash' => C::hash('cpf:' . $cpf), 'cpf_encrypted' => C::seal($cpf), 'status' => 'active', 'created_at' => current_time('mysql', true), 'terms_snapshot' => 'Condições de teste', 'terms_accepted_at' => current_time('mysql', true)]); }
update_option('ie_settings', ['environment' => 'sandbox', 'credentials' => C::seal(json_encode(['id' => 'portal-fixture', 'secret' => 'fictional', 'aes' => '1234567890abcdef']))]); $scope = (new G())->scope();
$today = new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));
$e = array_merge(S::sample(), ['status' => 'published', 'name' => 'Retiro de teste', 'event_date' => $today->modify('+120 days')->format('Y-m-d'), 'payment_deadline' => $today->modify('+120 days')->format('Y-m-d'), 'registration_start' => $today->modify('-1 day')->format('Y-m-d'), 'registration_end' => $today->modify('+10 days')->format('Y-m-d'), 'allow_card_physical' => 1, 'minimum_entry' => 100, 'brand_preset' => '']);
$eid = S::insert('events', $e); $other = S::insert('events', array_merge($e, ['brand_preset' => 'custom', 'name' => 'Outro evento']));
S::insert('lots', ['event_id' => $eid, 'name' => 'Lote vigente', 'price' => 52000, 'max_installments' => 8, 'starts_on' => $e['registration_start'], 'ends_on' => $e['registration_end'], 'note' => '']);
$rid = registration($eid, $scope); $foreign = registration($other, $scope); $otherBank = registration($eid, 'other-bank', '11144477735');
$legacyPalette = ['primary_color' => '#215c4a', 'accent_color' => '#d2a45c', 'background_color' => '#f6f5f0'];
$publishedCopy = S::insert('events', array_merge($e, $legacyPalette, ['name' => 'Fogo Santo - Origens', 'brand_preset' => 'custom', 'hero_url' => 'https://event.test/uploads/origens.jpeg']));
$customCopy = S::insert('events', array_merge($e, $legacyPalette, ['name' => 'Fogo Santo - Origens', 'brand_preset' => 'custom', 'primary_color' => '#123456', 'hero_url' => 'https://event.test/custom.jpg']));
$otherLegacy = S::insert('events', array_merge($e, $legacyPalette, ['name' => 'Outro retiro', 'brand_preset' => 'custom', 'hero_url' => 'https://event.test/other.jpg']));
$customBefore = S::event($customCopy); $otherBefore = S::event($otherLegacy); $copyBefore = S::event($publishedCopy);
update_option('ie_sample_created', $eid); $before = S::registration($rid); B::migrate(); $branded = S::event($eid);
same([S::event($publishedCopy)['brand_preset'], S::event($publishedCopy)['primary_color'], S::event($publishedCopy)['hero_url']], ['origens', '#075ea8', ''], 'Published copy with legacy palette receives bundled identity');
same(S::event($publishedCopy), array_merge($copyBefore, B::ORIGENS, ['brand_preset' => 'origens', 'hero_url' => '']), 'Repair changes only visual fields of the published copy');
same(S::event($customCopy), $customBefore, 'Already customized retreat palette is preserved'); same(S::event($otherLegacy), $otherBefore, 'Another event with old palette is preserved');
same($branded['brand_preset'], 'origens', 'Initial retreat receives Origens preset'); same($branded['primary_color'], '#075ea8', 'Identity blue applied'); same(S::registration($rid), $before, 'Brand migration cannot change registration contract'); same(S::event($other)['brand_preset'], 'custom', 'Other event identity preserved');
same([$branded['event_date'], $branded['registration_start'], $branded['registration_end']], [$e['event_date'], $e['registration_start'], $e['registration_end']], 'Poster dates never replace event dates');
same(str_ends_with(B::present($branded)['cover_url'], '/assets/origens-cover.jpg'), true, 'Bundled clean banner used');
// A previously selected theme may still keep the original poster URL. Rendering
// must use the clean cover even if migration markers were already saved earlier.
$poster = array_merge($e, B::ORIGENS, ['name' => 'Fogo Santo - Origens', 'brand_preset' => 'origens', 'hero_url' => 'https://event.test/uploads/origens.jpeg']);
same(str_ends_with(B::present($poster)['cover_url'], '/assets/origens-cover.jpg'), true, 'Selected Origens theme cannot be covered by its legacy retreat poster');
same(B::present(array_merge($poster, ['hero_url' => 'https://event.test/custom-cover.jpg']))['cover_url'], 'https://event.test/custom-cover.jpg', 'A custom cover remains available');
same(B::present(array_merge($poster, ['name' => 'Outro evento']))['cover_url'], $poster['hero_url'], 'Poster override is specific to the requested retreat');
same(B::present(array_merge($poster, ['brand_preset' => 'custom']))['cover_url'], $poster['hero_url'], 'Personalized theme still honors its chosen image');
same(B::present(['allow_card' => '0', 'allow_pix' => '1'])['allow_card'], false, 'Database string zero cannot advertise online card'); same(B::present(['allow_card_physical' => '1'])['allow_card_physical'], true, 'Database string flag normalized for banner');
S::update('events', ['primary_color' => '#123456'], ['id' => $eid]); B::migrate(); same(S::event($eid)['primary_color'], '#123456', 'Identity migration runs once and preserves later customization');
S::update('events', ['brand_preset' => 'custom', 'primary_color' => '#123456', 'hero_url' => 'https://event.test/later.jpg'], ['id' => $publishedCopy]); B::migrate(); same(S::event($publishedCopy)['hero_url'], 'https://event.test/later.jpg', 'Repair also preserves customization after upgrading');
// Repair still runs when the previous release already marked its original-model migration done.
delete_option('ie_origens_branding_repaired_161'); update_option('ie_sample_created', 9999);
$copySeed = $copyBefore; unset($copySeed['id']); $lateCopy = S::insert('events', $copySeed); B::migrate();
same(S::event($lateCopy)['brand_preset'], 'origens', 'Missing/deleted model cannot prevent repair of published copy'); update_option('ie_sample_created', $eid);
$catalog = (new ReflectionMethod(Api::class, 'catalog'))->invoke(null, new WP_REST_Request([], ['event' => $eid])); same($catalog[0]['brand_preset'], 'origens', 'Public API includes visual identity'); same($catalog[0]['active_lot'], 1, 'Public lot selected from current dates'); same($catalog[0]['lots'][0]['price'], 52000, 'Current lot distinct from frozen price');
$read = Panel::read(request($rid, [], ['event' => $eid])); same($read->get_status(), 200, 'Administrator reads modal'); $detail = $read->get_data();
same([$detail['registration']['total'], $detail['registration']['registration_status']], [49000, 'active'], 'Frozen contract and lifecycle are separate from financial status');
foreach (['cpf_encrypted', 'cpf_snapshot', 'scope', 'token_hash', 'credentials', 'payload_json'] as $key) same(str_contains(json_encode($detail), '"' . $key . '"'), false, 'Modal omits internal ' . $key);
same(Panel::read(request($foreign, [], ['event' => $eid]))->get_status(), 400, 'Cross-event detail blocked'); same(Panel::read(request($otherBank, [], ['event' => $eid]))->get_status(), 400, 'Other bank hidden by current scope'); same(Panel::read(request($otherBank, [], ['event' => $eid, 'all_scopes' => '1']))->get_status(), 200, 'Explicit all-account filter retained');
same(Panel::write(request($rid, ['action' => 'trash', 'confirmed' => true], [], 'invalid'))->get_status(), 403, 'Mutation rejects bad nonce');
$edit = ['action' => 'save', 'name' => 'Pessoa Atualizada', 'email' => 'PESSOA.NOVA@EXAMPLE.TEST', 'phone' => '41988887777', 'cpf' => '52998224725', 'method' => D::PHYSICAL_CARD, 'status' => 'active', 'admin_note' => 'Conferido no modal', 'total' => 1, 'max_installments' => 1, 'lot_name' => 'Forjado'];
same(Panel::write(request($rid, $edit))->get_status(), 200, 'Administrator edits through modal route'); $r = S::registration($rid); same([$r['email'], $r['total'], $r['max_installments'], $r['lot_name']], ['pessoa.nova@example.test', 49000, 10, 'Lote original'], 'Editable data normalized, financial contract immutable');
$input = ['action' => 'manual', 'payment_id' => 0, 'amount' => '100', 'method' => D::PHYSICAL_CARD, 'paid_on' => $today->format('Y-m-d'), 'note' => 'Maquininha conferida', 'confirmed' => true, 'request_token' => $detail['request_token']];
$received = Panel::write(request($rid, $input)); same($received->get_status(), 200, 'POS manual receipt in modal'); same($received->get_data()['registration']['balance'], 39000, 'Modal balance updated'); same(S::payments($rid), [], 'No fictitious K8 payment'); same(Panel::write(request($rid, $input))->get_data()['registration']['paid'], 10000, 'Repeated modal submission is idempotent');
$mid = S::manuals($rid)[0]['id']; same(Panel::write(request($foreign, ['action' => 'reverse_manual', 'manual_id' => $mid, 'note' => 'Forjado', 'confirmed' => true]))->get_status(), 400, 'Cannot reverse another registration receipt');
$snapshot = $wpdb->rows; $GLOBALS['test_admin'] = false; $GLOBALS['test_manager'] = true; $GLOBALS['test_logged_in'] = true;
same(Panel::read(request($rid))->get_status(), 200, 'Manager reads details'); same(Panel::read(request($rid))->get_data()['can_manage'], false, 'Manager cannot request editable interface'); same(Panel::read(request($rid))->get_data()['request_token'], '', 'Manager receives no manual receipt token');
foreach (['save', 'manual', 'reverse_manual', 'link_manual', 'sync', 'cancel_payment', 'review_cancellation', 'recover', 'trash', 'restore', 'purge'] as $action) same(Panel::write(request($rid, array_merge($edit, $input, ['action' => $action, 'can_manage' => true, 'confirmed' => true])))->get_status(), 403, 'Manager blocked on ' . $action . ' with valid nonce');
same($wpdb->rows, $snapshot, 'Manager writes cannot change data'); same($GLOBALS['http_calls'], [], 'Manager sends no K8 request');
$GLOBALS['test_manager'] = false; $GLOBALS['test_logged_in'] = false; same(Panel::read(request($rid))->get_status(), 403, 'Visitor cannot read modal'); same(Panel::write(request($rid, $input))->get_status(), 403, 'Visitor cannot mutate modal');
$GLOBALS['test_permalink'] = 'https://event.test/dashboard/'; update_option('ie_dashboard_page_id', 10); $_GET = [];
$form = Dashboard::shortcode(['evento' => $eid]); same(str_contains($form, 'name="log"'), true, 'Inline username field'); same(str_contains($form, 'type="password"'), true, 'Inline password field'); same(str_contains($form, 'origens-cover.jpg'), true, 'Login has event cover'); same(str_contains($form, 'Pessoa Atualizada'), false, 'Login leaks no registration'); same(str_contains($form, 'window.IE_DASHBOARD'), false, 'Visitor receives no dashboard credentials');
same(B::loginEvent($other)['name'], 'Outro evento', 'Login uses selected event'); S::update('events', ['status' => 'draft'], ['id' => $other]); same(B::loginEvent($other), null, 'Draft event is not exposed on login');
$_SERVER['REQUEST_METHOD'] = 'POST'; $target = $GLOBALS['test_permalink'];
$login = ['redirect_to' => $target, 'ie_login_nonce' => L::nonce($target), 'log' => 'gerente.fixture', 'pwd' => 'SenhaFicticia!'];
$cookie = $_COOKIE['ie_dashboard_login_csrf']; unset($_COOKIE['ie_dashboard_login_csrf']); same(L::authenticate($login)['error'], 'expired', 'Login requires browser-bound CSRF cookie'); $_COOKIE['ie_dashboard_login_csrf'] = str_repeat('b', 64); same(L::authenticate($login)['error'], 'expired', 'Nonce from another browser rejected'); $_COOKIE['ie_dashboard_login_csrf'] = $cookie;
same(L::authenticate(array_merge($login, ['ie_login_nonce' => 'invalid']))['error'], 'expired', 'Invalid login nonce rejected');
same(L::authenticate(array_merge($login, ['pwd' => 'senha errada']))['error'], 'credentials', 'Bad credentials return to inline form');
same(L::authenticate($login), ['url' => $target, 'error' => ''], 'Manager login returns directly to dashboard'); same(L::authenticate(array_merge($login, ['log' => 'admin.fixture']))['error'], '', 'Administrator login supported'); same(L::authenticate(array_merge($login, ['log' => 'pessoa.fixture']))['error'], 'forbidden', 'Ordinary subscriber cannot enter dashboard');
foreach (['https://evil.example/dashboard/', '//evil.example/', 'javascript:alert(1)', 'https://event.test/wp-admin/'] as $bad) same(L::target($bad), $target, 'Unsafe login redirect rejected');
$manager = (object) ['caps' => [Access::VIEW => true]]; same(L::redirect('https://event.test/wp-admin/', '', $manager), $target, 'Normal manager login also targets dashboard'); same(L::redirect('https://event.test/wp-admin/', '', new WP_Error('bad', 'bad')), 'https://event.test/wp-admin/', 'Failed authentication is not treated as a manager');
$GLOBALS['test_admin'] = true; $GLOBALS['test_logged_in'] = true; same(Panel::write(request($rid, ['action' => 'reverse_manual', 'manual_id' => $mid, 'note' => 'Correção do comprovante', 'confirmed' => true]))->get_status(), 200, 'Administrator reverses receipt in modal'); same(S::paid($rid), 0, 'Reversal restores balance');
same(Panel::write(request($rid, ['action' => 'purge', 'confirmation' => 'EXCLUIR 999', 'confirmed' => true]))->get_status(), 400, 'Permanent delete requires exact typed ID'); same(Panel::write(request($rid, ['action' => 'trash', 'confirmed' => true]))->get_status(), 200, 'Modal trash preserves history'); same(S::registration($rid)['status'], 'deleted', 'Lifecycle updated'); same(Panel::write(request($rid, ['action' => 'restore']))->get_status(), 200, 'Modal restore supported');
same($GLOBALS['http_calls'], [], 'Login, edits and manual actions need no K8 traffic');
echo 'OK: ' . $checks . " dashboard modal/login/identity checks; fictional accounts and no live payments.\n";
