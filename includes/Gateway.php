<?php
namespace InscricaoEventos;
if (!defined('ABSPATH')) { exit; }

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
    public function authenticate(): string
    {
        $cached = get_transient($this->cache);
        if (is_string($cached) && $cached) { return Security::unseal($cached); }
        $r = $this->transport('/token', ['method' => 'POST', 'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'], 'body' => ['username' => $this->credentials['id'], 'password' => $this->credentials['secret'], 'grant_type' => 'password']]);
        $code = (int) wp_remote_retrieve_response_code($r);
        if ($code !== 200) { throw new \RuntimeException('Autenticação K8 recusada (HTTP ' . $code . '). Confira as credenciais do K8 Pay e o ambiente.'); }
        $data = json_decode(wp_remote_retrieve_body($r), true);
        $token = $data['access_token'] ?? '';
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
        $allowed = ['/v2/CriaTransacaoCheckout', '/api/ConsultaTransacao', '/api/ConsultaTransacaoReferencia'];
        if (!in_array($path, $allowed, true)) { throw new \RuntimeException('Operação K8 não permitida.'); }
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $r = $this->transport($path, ['method' => 'POST', 'headers' => ['Accept' => 'application/json', 'Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $this->authenticate(), 'Aeskey' => $this->credentials['aes']], 'body' => self::encode($body, $this->credentials['aes'])]);
            $code = (int) wp_remote_retrieve_response_code($r);
            if ($code === 401 && !$attempt) { delete_transient($this->cache); continue; }
            if ($code < 200 || $code >= 300) { throw new \RuntimeException('K8 retornou HTTP ' . $code . '. Confira a tentativa no painel antes de repetir.'); }
            $data = self::decode(wp_remote_retrieve_body($r), $this->credentials['aes']);
            if (($data['Retorno'] ?? '') !== 'OK') { throw new \RuntimeException('K8 não confirmou esta operação. Confira a tentativa no painel K8.'); }
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
