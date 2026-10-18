<?php
namespace InscricaoEventos;
if (!defined('ABSPATH')) { exit; }

final class Domain
{
    public const PHYSICAL_CARD = 'CartaoPOS';
    public const PHYSICAL_MESSAGE = 'Procure a secretaria para finalizar a sua inscrição e validar seu pagamento.';
    public static function methodLabel(string $method): string { return ['Pix' => 'PIX', 'Cartao' => 'Cartão', self::PHYSICAL_CARD => 'Cartão físico (maquininha)'][$method] ?? $method; }
    public static function physicalMessage(array $event): string { return trim((string) ($event['physical_card_message'] ?? '')) ?: self::PHYSICAL_MESSAGE; }
    public static function methods(array $event, ?array $registration = null): array
    {
        $methods = [];
        if (!empty($event['allow_pix']) && (!$registration || in_array($registration['method'], ['Pix', self::PHYSICAL_CARD], true))) { $methods[] = 'Pix'; }
        if (!empty($event['allow_card'])) { $methods[] = 'Cartao'; }
        if (!empty($event['allow_card_physical'])) { $methods[] = self::PHYSICAL_CARD; }
        return $methods;
    }
    public static function cents($value): int
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) { throw new \RuntimeException('Informe um valor monetário válido.'); }
        if (is_float($value)) { $value = number_format($value, 2, '.', ''); }
        $v = trim((string) $value);
        if (strpos($v, ',') !== false) { $v = str_replace('.', '', $v); $v = str_replace(',', '.', $v); }
        if (!preg_match('/^\d{1,9}(?:\.\d{1,2})?$/D', $v)) { throw new \RuntimeException('Informe um valor válido com até duas casas decimais.'); }
        [$whole, $fraction] = array_pad(explode('.', $v, 2), 2, '');
        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    public static function money(int $cents): string { return 'R$ ' . number_format($cents / 100, 2, ',', '.'); }

    /** K8 can send the same GUID as 32 hex characters or with hyphens and different casing. */
    public static function remoteId(string $id): string
    {
        $id = trim($id);
        if (!preg_match('/^[A-Za-z0-9-]{1,100}$/D', $id)) { throw new \RuntimeException('Identificador de transação K8 inválido ou ausente.'); }
        if (preg_match('/^(?:[a-f0-9]{32}|[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})$/iD', $id)) {
            $hex = strtolower(str_replace('-', '', $id));
            return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
        }
        return $id; // Preserve opaque, non-GUID identifiers exactly.
    }

    public static function cpf(string $input): string
    {
        $cpf = preg_replace('/\D/', '', $input);
        if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/D', $cpf)) { throw new \RuntimeException('Informe um CPF válido.'); }
        for ($j = 9; $j < 11; $j++) {
            $sum = 0;
            for ($i = 0; $i < $j; $i++) { $sum += (int) $cpf[$i] * ($j + 1 - $i); }
            $digit = (10 * $sum) % 11;
            if ($digit === 10) { $digit = 0; }
            if ($digit !== (int) $cpf[$j]) { throw new \RuntimeException('Informe um CPF válido.'); }
        }
        return $cpf;
    }

    public static function date(string $value, bool $optional = false): string
    {
        if ($optional && $value === '') { return ''; }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) { throw new \RuntimeException('Informe uma data válida.'); }
        return $value;
    }

    public static function today(array $event): string { return (new \DateTimeImmutable('now', new \DateTimeZone($event['timezone'])))->format('Y-m-d'); }

    /** One installment less per completed calendar month, anchored to the local registration day. */
    public static function installments(array $registration, array $event, ?string $day = null): array
    {
        $base = (int) ($registration['max_installments'] ?? 0);
        if ($base < 1 || $base > 12) { throw new \RuntimeException('Limite de parcelas da inscrição inválido. Procure a organização.'); }
        $raw = (string) ($registration['created_at'] ?? '');
        $created = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $raw, new \DateTimeZone('UTC'));
        if (!$created || $created->format('Y-m-d H:i:s') !== $raw) { throw new \RuntimeException('Não foi possível conferir a data da inscrição para o parcelamento. Procure a organização.'); }
        $zone = new \DateTimeZone($event['timezone']); $created = $created->setTimezone($zone);
        $today = new \DateTimeImmutable(self::date($day ?? self::today($event)), $zone);
        $months = ((int) $today->format('Y') - (int) $created->format('Y')) * 12 + (int) $today->format('n') - (int) $created->format('n');
        // A registration on the 29th/30th/31st reaches its anniversary on a short month's last day.
        if ((int) $today->format('j') < min((int) $created->format('j'), (int) $today->format('t'))) { $months--; }
        $months = max(0, $months); $max = max(1, $base - $months); $next = '';
        if ($max > 1) {
            $nextMonth = $created->modify('first day of this month')->modify('+' . ($months + 1) . ' months');
            $next = $nextMonth->setDate((int) $nextMonth->format('Y'), (int) $nextMonth->format('n'), min((int) $created->format('j'), (int) $nextMonth->format('t')))->format('Y-m-d');
        }
        return ['base' => $base, 'max' => $max, 'months' => $months, 'registered_on' => $created->format('Y-m-d'), 'next_reduction' => $next];
    }

    public static function quote(array $event, array $lots, string $day): ?array
    {
        if ($event['status'] !== 'published' || $day < $event['registration_start'] || $day > $event['registration_end'] || ($event['payment_deadline'] && $day > $event['payment_deadline'])) { return null; }
        $active = array_values(array_filter($lots, static fn($lot) => $day >= $lot['starts_on'] && $day <= $lot['ends_on']));
        if (count($active) !== 1) { return null; }
        return $active[0];
    }

    public static function balance(int $total, int $paid): array
    {
        return ['total' => $total, 'paid' => $paid, 'balance' => max(0, $total - $paid), 'overpaid' => max(0, $paid - $total), 'status' => $paid >= $total ? 'paid' : ($paid > 0 ? 'partial' : 'pending')];
    }

    /** Only authenticated gateway responses reach this function. Non-final states preserve an existing credit. */
    public static function credit(array $remote, int $amount, string $method, int $previous): int
    {
        if (self::cents($remote['Valor'] ?? '') !== $amount) { throw new \RuntimeException('O valor da transação K8 difere do valor desta tentativa.'); }
        if (($remote['Tipo'] ?? '') !== $method) { throw new \RuntimeException('A forma de pagamento K8 difere da forma desta tentativa.'); }
        $state = $remote['Estado'] ?? '';
        if (in_array($state, ['Aprovada', 'Liquidado'], true)) {
            if ($method === 'Cartao') { return $amount; } // Bank installments are owed to the card issuer, not to the event.
            $paid = self::cents($remote['ValorPago'] ?? '');
            if ($paid < 1 || $paid > $amount) { throw new \RuntimeException('Valor PIX confirmado inconsistente. Confira no K8.'); }
            return $paid;
        }
        if (in_array($state, ['Cancelada', 'Estornado', 'Rejeitada'], true)) { return 0; }
        $pending = ['Pendente', 'ACancelar', 'pendenteCobranca', 'acancelarCNAB', 'PendenteAntifraude', 'AnaliseAntifraude', 'ProcessarInstrucao', 'EmProcessamento', 'PagoeAberto', 'EstornarPIX', 'ErroEstorno', 'FalhaCancelamento', 'CartaoPreAutorizado', 'CartaoCancelametoFuturo', 'ErroGerarPixBH'];
        if (!in_array($state, $pending, true)) { throw new \RuntimeException('Estado K8 não reconhecido; crédito anterior preservado.'); }
        return $previous;
    }

    public static function terminal(string $state): bool { return in_array($state, ['Aprovada', 'Liquidado', 'Cancelada', 'Estornado', 'Rejeitada'], true); }
    public static function firstName(string $name): string { return explode(' ', trim($name))[0]; }
    public static function dateBR(string $day): string { return $day ? implode('/', array_reverse(explode('-', $day))) : 'a definir'; }
    public static function cut(string $text, int $length): string { return function_exists('mb_substr') ? mb_substr($text, 0, $length) : implode('', array_slice(preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, $length)); }
}
