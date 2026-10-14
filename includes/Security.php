<?php
namespace InscricaoEventos;
if (!defined('ABSPATH')) { exit; }

final class Security
{
    private static function key(): string { return hash('sha256', wp_salt('auth') . ':inscricao-eventos', true); }
    public static function seal(string $plain): string
    {
        $iv = random_bytes(12);
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) { throw new \RuntimeException('Não foi possível proteger os dados.'); }
        return base64_encode($iv . $tag . $cipher);
    }
    public static function unseal(string $value): string
    {
        $bytes = base64_decode($value, true);
        if ($bytes === false || strlen($bytes) < 28) { throw new \RuntimeException('Dados protegidos inválidos.'); }
        $plain = openssl_decrypt(substr($bytes, 28), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr($bytes, 0, 12), substr($bytes, 12, 16));
        if ($plain === false) { throw new \RuntimeException('Não foi possível abrir os dados protegidos. Verifique as chaves do WordPress.'); }
        return $plain;
    }
    public static function hash(string $value): string { return hash_hmac('sha256', $value, self::key()); }

    public static function cookie(string $name, string $value, int $expires): void
    {
        setcookie($name, $value, ['expires' => $expires, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax']);
        $_COOKIE[$name] = $value;
    }
    public static function bootstrap(): array
    {
        $client = (string) ($_COOKIE['ie_client'] ?? '');
        if (!preg_match('/^[a-f0-9]{64}$/D', $client)) { $client = bin2hex(random_bytes(32)); self::cookie('ie_client', $client, time() + DAY_IN_SECONDS); }
        return ['csrf' => self::hash('csrf:' . $client)];
    }
    public static function publicPermission(\WP_REST_Request $request)
    {
        $client = (string) ($_COOKIE['ie_client'] ?? '');
        $csrf = (string) $request->get_header('x-ie-csrf');
        $origin = (string) $request->get_header('origin');
        if ($origin && rtrim($origin, '/') !== rtrim(self::origin(home_url('/')), '/')) { return new \WP_Error('ie_origin', 'Origem inválida.', ['status' => 403]); }
        if (!preg_match('/^[a-f0-9]{64}$/D', $client) || !$csrf || !hash_equals(self::hash('csrf:' . $client), $csrf)) { return new \WP_Error('ie_csrf', 'Recarregue a página para continuar.', ['status' => 403]); }
        return true;
    }
    private static function origin(string $url): string
    {
        $p = wp_parse_url($url); return ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');
    }
    public static function ip(): string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? ''); return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : 'unknown';
    }
    /** Atomic per-window limits. Store keyed hashes instead of IP addresses / CPFs. */
    public static function limit(string $key, int $max, int $seconds): void
    {
        global $wpdb; $table = Store::table('limits');
        $bucket = self::hash($key . ':' . (int) floor(time() / $seconds));
        Store::check($wpdb->query($wpdb->prepare("INSERT INTO $table (bucket,hits,expires_at) VALUES (%s,1,%d) ON DUPLICATE KEY UPDATE hits=hits+1", $bucket, time() + $seconds * 2)));
        if ((int) $wpdb->get_var($wpdb->prepare("SELECT hits FROM $table WHERE bucket=%s", $bucket)) > $max) { throw new \RuntimeException('Muitas tentativas. Aguarde alguns minutos e tente novamente.'); }
    }
    public static function session(int $registrationId): void
    {
        $token = bin2hex(random_bytes(32));
        Store::insert('sessions', ['token_hash' => self::hash($token), 'registration_id' => $registrationId, 'expires_at' => time() + HOUR_IN_SECONDS * 2]);
        self::cookie('ie_session', $token, time() + HOUR_IN_SECONDS * 2);
    }
    public static function registration(): array
    {
        global $wpdb; $token = (string) ($_COOKIE['ie_session'] ?? '');
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) { throw new \RuntimeException('Consulte sua inscrição pelo CPF para continuar.'); }
        $s = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Store::table('sessions') . ' WHERE token_hash=%s AND expires_at>%d', self::hash($token), time()), ARRAY_A);
        if (!$s) { throw new \RuntimeException('Seu acesso expirou. Consulte novamente pelo CPF.'); }
        return Store::registration((int) $s['registration_id']);
    }
    public static function logout(): void
    {
        global $wpdb; $token = (string) ($_COOKIE['ie_session'] ?? '');
        if ($token) { $wpdb->delete(Store::table('sessions'), ['token_hash' => self::hash($token)]); }
        self::cookie('ie_session', '', time() - HOUR_IN_SECONDS);
    }
    public static function gatewayURL(string $url): string
    {
        $p = wp_parse_url($url); $host = strtolower((string) ($p['host'] ?? ''));
        $allowed = ['pagamentos.meuk8.com.br', 'sandbox.meuk8.com.br', 'pagamentos.k8bank.com.br', 'sandbox.k8bank.com.br'];
        if (($p['scheme'] ?? '') !== 'https' || !in_array($host, $allowed, true) || isset($p['user']) || isset($p['pass']) || (isset($p['port']) && (int) $p['port'] !== 443)) { throw new \RuntimeException('O K8 retornou uma URL de checkout não reconhecida. Confira com o suporte.'); }
        return $url;
    }
}
