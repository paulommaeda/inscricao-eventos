<?php
namespace InscricaoEventos;
if (!defined('ABSPATH')) { exit; }

final class ReferenceNotFound extends \RuntimeException {}

final class Gateway
{
    private array $credentials;
    private string $base;
    private string $cache;
    public function __construct()
    {
        if (!function_exists('openssl_encrypt')) { throw new \RuntimeException('Ative a extensão OpenSSL do PHP.'); }
        $settings = (array) get_option('ie_settings', []);
        $encoded = (string) ($settings['credentials'] ?? '');
        if (!$encoded) { throw new \RuntimeException('Os pagamentos ainda não foram configurados pela organização.'); }
        $this->credentials = json_decode(Security::unseal($encoded), true) ?: [];
        foreach (['id', 'secret', 'aes'] as $field) { $this->credentials[$field] = self::cleanCredential((string) ($this->credentials[$field] ?? '')); }
        if (empty($this->credentials['id']) || empty($this->credentials['secret']) || strlen($this->credentials['aes'] ?? '') !== 16) { throw new \RuntimeException('Confira Client ID, Client Secret e chave AES do K8 Pay.'); }
        $this->base = ($settings['environment'] ?? 'sandbox') === 'production' ? 'https://pagamentos.meuk8.com.br/PagamentosAPI' : 'https://sandbox.meuk8.com.br/PagamentosAPI';
        $this->cache = 'ie_k8_token_' . hash('sha256', $this->base . $encoded);
    }
    public function scope(): string { return hash('sha256', $this->base . ':' . $this->credentials['id']); }
    private function transport(string $path, array $args): array
    {
        $r = wp_remote_request($this->base . $path, array_merge(['timeout' => 20, 'redirection' => 0, 'sslverify' => true, 'limit_response_size' => 1048576], $args));
        if (is_wp_error($r)) { throw new \RuntimeException('Não foi possível comunicar com o K8. A tentativa será conferida antes de gerar outra cobrança.'); }
        return $r;
    }
    public static function cleanCredential(string $value): string
    {
        // API keys copied from dashboards can carry a newline, NBSP, BOM or zero-width space at the edges.
        $value = preg_replace('/^[\s\x{00A0}\x{FEFF}\x{200B}]+|[\s\x{00A0}\x{FEFF}\x{200B}]+$/u', '', $value);
        if ($value === null) { throw new \RuntimeException('Há caracteres inválidos nas credenciais K8. Copie novamente do painel.'); }
        return $value;
    }
    private function authError(int $code, string $body): string
    {
        $environment = str_contains($this->base, 'sandbox.meuk8.com.br') ? 'Sandbox (testes)' : 'Produção';
        $message = 'Autenticação K8 recusada (HTTP ' . $code . '). Ambiente: ' . $environment . '. API: K8 Pagamentos.';
        $data = json_decode($body, true);
        if (is_array($data)) {
            $parts = [];
            foreach (['error', 'error_description'] as $field) {
                if (!isset($data[$field]) || !is_string($data[$field])) { continue; }
                $part = html_entity_decode(strip_tags($data[$field]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                foreach ($this->credentials as $secret) {
                    if ($secret !== '') { $part = str_replace(array_unique([$secret, urlencode($secret), rawurlencode($secret)]), '[oculto]', $part); }
                }
                $part = preg_replace('/\b(Bearer|access_token|client_secret|password|aeskey)\s*[:=]?\s*["\']?[^\s,"\';]+/i', '$1 [oculto]', $part);
                $parts[] = Domain::cut(sanitize_text_field($part), 240);
            }
            if ($parts) { $message .= ' Retorno K8: ' . implode(' — ', $parts) . '.'; }
            if (($data['error'] ?? '') === 'unsupported_grant_type' || ($data['error'] ?? '') === 'invalid_request') {
                return $message . ' O K8 não reconheceu o formato de autenticação. A requisição deve usar formulário com grant_type=password.';
            }
        }
        return $message . ' Confira se Client ID e Client Secret foram gerados no K8 Pagamentos deste ambiente, se a API está habilitada e se o IP do servidor está autorizado.';
    }
    public function authenticate(bool $fresh = false): string
    {
        if ($fresh) { delete_transient($this->cache); }
        $cached = get_transient($this->cache);
        if (is_string($cached) && $cached) { return Security::unseal($cached); }
        $form = http_build_query(['username' => $this->credentials['id'], 'password' => $this->credentials['secret'], 'grant_type' => 'password'], '', '&', PHP_QUERY_RFC1738);
        $r = $this->transport('/token', ['method' => 'POST', 'headers' => ['Accept' => 'application/json', 'Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8'], 'body' => $form]);
        $code = (int) wp_remote_retrieve_response_code($r);
        if ($code !== 200) { throw new \RuntimeException($this->authError($code, wp_remote_retrieve_body($r))); }
        $data = json_decode(wp_remote_retrieve_body($r), true);
        $token = $data['access_token'] ?? $data['token'] ?? '';
        if (!is_string($token) || !$token) { throw new \RuntimeException('K8 não retornou um token válido.'); }
        set_transient($this->cache, Security::seal($token), max(1, min(3600, (int) ($data['expires_in'] ?? 300) - 30)));
        return $token;
    }
    public static function encode(array $data, string $key): string
    {
        $bytes = openssl_encrypt(wp_json_encode($data), 'AES-128-CBC', $key, OPENSSL_RAW_DATA, str_repeat("\0", 16));
        if ($bytes === false) { throw new \RuntimeException('Falha preparando a requisição K8.'); } return base64_encode($bytes);
    }
    public static function decode(string $body, string $key): array
    {
        $json = json_decode(trim($body), true);
        if (is_array($json)) { return $json; }
        $bytes = base64_decode(is_string($json) ? $json : trim($body), true);
        $plain = $bytes === false ? false : openssl_decrypt($bytes, 'AES-128-CBC', $key, OPENSSL_RAW_DATA, str_repeat("\0", 16));
        $data = $plain === false ? null : json_decode($plain, true);
        if (!is_array($data)) { throw new \RuntimeException('Resposta K8 inválida. Confira a chave AES.'); } return $data;
    }
    public function request(string $path, array $body): array
    {
        $allowed = ['/v2/CriaTransacaoCheckout', '/api/ConsultaTransacao', '/api/ConsultaTransacaoReferencia', '/api/ConsultaTransacoes'];
        if (!in_array($path, $allowed, true)) { throw new \RuntimeException('Operação K8 não permitida.'); }
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $r = $this->transport($path, ['method' => 'POST', 'headers' => ['Accept' => 'application/json', 'Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $this->authenticate(), 'Aeskey' => $this->credentials['aes']], 'body' => self::encode($body, $this->credentials['aes'])]);
            $code = (int) wp_remote_retrieve_response_code($r);
            if ($code === 401 && !$attempt) { delete_transient($this->cache); continue; }
            if ($code < 200 || $code >= 300) { throw new \RuntimeException('K8 retornou HTTP ' . $code . '. Confira a tentativa no painel antes de repetir.'); }
            $data = self::decode(wp_remote_retrieve_body($r), $this->credentials['aes']);
            if (($data['Retorno'] ?? '') !== 'OK') {
                $detail = is_string($data['DetalhesErro'] ?? null) ? strip_tags($data['DetalhesErro']) : '';
                foreach ($this->credentials as $secret) { if ($secret !== '') { $detail = str_replace([$secret, urlencode($secret), rawurlencode($secret)], '[oculto]', $detail); } }
                $detail = preg_replace('/\b(Bearer|access_token|client_secret|password|aeskey)\s*[:=]?\s*["\']?[^\s,"\';]+/i', '$1 [oculto]', $detail);
                $detail = preg_replace('/\b\d{11,14}\b/', '[documento oculto]', $detail);
                $message = 'K8 não confirmou esta operação.' . ($detail ? ' Retorno K8: ' . Domain::cut(sanitize_text_field($detail), 300) . '.' : '') . ' Confira a tentativa no painel K8.';
                if ($path === '/api/ConsultaTransacaoReferencia' && preg_match('/refer[eê]ncia\b.*n[aã]o\s+localizad[ao]/iu', $detail)) { throw new ReferenceNotFound($message); }
                throw new \RuntimeException($message);
            }
            return $data;
        }
        throw new \RuntimeException('Não foi possível renovar a autenticação K8.');
    }
    public static function payload(array $payment, array $registration, array $event, string $return, string $callback, string $cpf): array
    {
        $phone = preg_replace('/\D/', '', $registration['phone']);
        if (strlen($phone) > 11 && substr($phone, 0, 2) === '55') { $phone = substr($phone, 2); }
        $payload = ['Valor' => (int) $payment['amount'] / 100, 'ClienteCPFCNPJ' => $cpf, 'ClienteNome' => Domain::cut($registration['name'], 100), 'ClienteEmail' => $registration['email'], 'ClienteDDD' => substr($phone, 0, 2), 'ClienteNumeroCelular' => substr($phone, 2), 'ClienteDescricao' => Domain::cut($event['name'] . ' / ' . $registration['lot_name'] . ' / Inscrição #' . $registration['id'], 100), 'ClienteIP' => Security::ip(), 'URLRetorno' => $return, 'URLConfirmacao' => $callback, 'NumeroPedido' => $payment['reference'], 'Referencia' => $payment['reference']];
        if ($payment['method'] === 'Cartao') {
            $max = (int) $registration['max_installments'];
            $payload['Cartao'] = ['FormaPagamento' => $max > 1 ? ['AVista', 'ParceladoSemJuros'] : ['AVista'], 'QuantidadeParcelas' => $max];
        } else {
            $now = new \DateTimeImmutable('now', new \DateTimeZone($event['timezone']));
            $due = min($now->modify('+1 day')->format('Y-m-d'), $event['payment_deadline']);
            $payload['Pix'] = ['Tipo' => 'D', 'DataVencimento' => $due . 'T23:59:59' . $now->format('P'), 'Desconto' => 0, 'Pdv' => false];
        }
        return $payload;
    }
}
