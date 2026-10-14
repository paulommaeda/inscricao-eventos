<?php
require __DIR__ . '/bootstrap.php';
use InscricaoEventos\Gateway;
use InscricaoEventos\Security;

$checks = 0;
function verify(bool $ok, string $label): void { global $checks; $checks++; if (!$ok) { throw new RuntimeException($label); } }
function response(array $data, int $code = 200): void { $GLOBALS['http_queue'][] = ['code' => $code, 'body' => json_encode($data)]; }
function errorMessage(callable $fn): string { try { $fn(); } catch (Throwable $e) { return $e->getMessage(); } throw new RuntimeException('Expected authentication failure.'); }

$id = 'client+name&=id'; $secret = 'secret+with&percent%=and/slash'; $aes = '1234567890abcdef';
update_option('ie_settings', ['environment' => 'sandbox', 'credentials' => Security::seal(json_encode(['id' => "\xEF\xBB\xBF " . $id . "\r\n", 'secret' => "\xC2\xA0" . $secret . "\n", 'aes' => "\xE2\x80\x8B" . $aes . ' ']))]);
$gateway = new Gateway();
response(['access_token' => 'test-token-one', 'expires_in' => 120]);
verify($gateway->authenticate() === 'test-token-one', 'Valid token accepted');
$call = $GLOBALS['http_calls'][0]; $form = []; parse_str($call[1]['body'], $form);
verify($call[0] === 'https://sandbox.meuk8.com.br/PagamentosAPI/token', 'Sandbox endpoint');
verify(is_string($call[1]['body']), 'Explicit encoded body');
verify($form === ['username' => $id, 'password' => $secret, 'grant_type' => 'password'], 'Special characters survive URL encoding and copied edge characters are removed');
verify(str_starts_with($call[1]['headers']['Content-Type'], 'application/x-www-form-urlencoded'), 'OAuth password grant format');
verify(!isset($form['aes']) && !isset($form['Aeskey']), 'AES not sent in authentication payload');
verify(!str_contains($call[1]['body'], $secret), 'Credentials encoded in transport');
verify($gateway->authenticate() === 'test-token-one' && count($GLOBALS['http_calls']) === 1, 'Ordinary API calls reuse cache');
foreach ($GLOBALS['test_transients'] as $cached) verify(!str_contains($cached, 'test-token-one'), 'Token protected in cache');

// The administrator's test bypasses cache and cannot report an old success.
response(['error' => 'acesso inválido', 'error_description' => 'As credenciais do usuário não conferem.'], 400);
$message = errorMessage(fn() => $gateway->authenticate(true));
verify(count($GLOBALS['http_calls']) === 2, 'Fresh test contacts K8');
verify(str_contains($message, 'HTTP 400') && str_contains($message, 'Sandbox (testes)'), 'HTTP and environment in diagnostics');
verify(str_contains($message, 'As credenciais do usuário não conferem.'), 'Localized K8 detail survives');
verify(str_contains($message, 'K8 Pagamentos') && str_contains($message, 'IP'), 'Actionable API / environment guidance');

// Only structured error fields are displayed; echoed secrets and bearer values are redacted.
response(['error' => 'invalid_grant', 'error_description' => '<b>Erro</b> ' . $id . ' ' . $secret . ' ' . urlencode($secret) . ' ' . $aes . ' Bearer dont-show-this-token', 'access_token' => 'dont-show-top-level-token'], 400);
$message = errorMessage(fn() => $gateway->authenticate(true));
foreach ([$id, $secret, urlencode($secret), $aes, 'dont-show-this-token', 'dont-show-top-level-token', '<b>'] as $sensitive) verify(!str_contains($message, $sensitive), 'No leaked value: ' . substr(hash('sha256', $sensitive), 0, 6));
verify(str_contains($message, '[oculto]'), 'Redaction visible');
response(['error' => 'unsupported_grant_type'], 400);
verify(str_contains(errorMessage(fn() => $gateway->authenticate(true)), 'grant_type=password'), 'Protocol failure distinguished from rejected credentials');
$GLOBALS['http_queue'][] = ['code' => 502, 'body' => '<html>Proxy upstream error ' . $secret . '</html>'];
$message = errorMessage(fn() => $gateway->authenticate(true));
verify(!str_contains($message, $secret) && !str_contains($message, 'Proxy upstream'), 'Unexpected HTML not dumped');
response(['token' => 'legacy-token-alias']); verify($gateway->authenticate(true) === 'legacy-token-alias', 'Compatible token alias');
response(['access_token' => '']); verify(str_contains(errorMessage(fn() => $gateway->authenticate(true)), 'token válido'), 'Empty success rejected');

$s = get_option('ie_settings'); $s['environment'] = 'production'; update_option('ie_settings', $s); $production = new Gateway();
verify($production->scope() !== $gateway->scope(), 'Account/environment separation preserved');
response(['error' => 'acesso inválido', 'error_description' => 'As credenciais do usuário não conferem.'], 400);
$message = errorMessage(fn() => $production->authenticate(true));
verify(str_contains($message, 'Produção') && !str_contains($message, 'Sandbox'), 'Production diagnostics');
verify(end($GLOBALS['http_calls'])[0] === 'https://pagamentos.meuk8.com.br/PagamentosAPI/token', 'Production endpoint');
verify(Gateway::cleanCredential('  keep+&=% punctuation  ') === 'keep+&=% punctuation', 'Internal credential characters preserved');
echo 'OK: ' . $checks . " authentication checks; no real credentials or transactions.\n";
