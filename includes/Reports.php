<?php
namespace InscricaoEventos;
if (!defined('ABSPATH')) { exit; }

/** Read-only reports. Money is always the frozen contract and the verified transaction ledger. */
final class Reports
{
    public static function filters(array $input): array
    {
        $text = static fn($key, $default = '') => is_scalar($input[$key] ?? $default) ? (string) ($input[$key] ?? $default) : $default;
        $choice = static function ($key, array $allowed, $default = '') use ($text) { $v = $text($key, $default); return in_array($v, $allowed, true) ? $v : $default; };
        $f = ['event' => absint($text('event', '0')), 'status' => $choice('status', ['', 'pending', 'partial', 'paid', 'overpaid']), 'q' => Domain::cut(sanitize_text_field($text('q')), 200), 'method' => $choice('method', ['', 'Pix', 'Cartao']), 'all_scopes' => $text('all_scopes') === '1' ? 1 : 0, 'registration_status' => $choice('registration_status', ['active', 'cancelled', 'deleted', 'all'], 'active'), 'lot' => Domain::cut(sanitize_text_field($text('lot')), 100), 'start' => Domain::date($text('start'), true), 'end' => Domain::date($text('end'), true)];
        if ($f['start'] && $f['end'] && $f['start'] > $f['end']) { throw new \RuntimeException('A data inicial do filtro deve ser anterior ou igual à data final.'); }
        return $f;
    }
    public static function query(array $f): array
    {
        global $wpdb; $r = Store::table('registrations'); $p = Store::table('payments'); $t = Store::table('transactions'); $e = Store::table('events');
        $where = ['1=1']; $args = [];
        if (in_array($f['registration_status'], ['active', 'cancelled', 'deleted'], true)) { $where[] = 'r.status=%s'; $args[] = $f['registration_status']; }
        if (!$f['all_scopes']) {
            try { $scope = (new Gateway())->scope(); } catch (\Throwable $error) { $scope = ''; }
            $where[] = 'r.scope=%s'; $args[] = $scope;
        }
        if ($f['event']) { $where[] = 'r.event_id=%d'; $args[] = $f['event']; }
        if ($f['method']) { $where[] = 'r.method=%s'; $args[] = $f['method']; }
        if ($f['lot']) { $where[] = 'r.lot_name=%s'; $args[] = $f['lot']; }
        if ($f['q']) {
            $q = '%' . $wpdb->esc_like($f['q']) . '%'; $where[] = '(r.name LIKE %s OR r.email LIKE %s OR r.cpf_hash=%s)';
            array_push($args, $q, $q, Security::hash('cpf:' . preg_replace('/\D/', '', $f['q'])));
        }
        // Dates filter registration creation, including the complete end day in the event timezone.
        $timezone = $f['event'] ? Store::event($f['event'])['timezone'] : 'UTC';
        $zone = new \DateTimeZone($timezone); $utc = new \DateTimeZone('UTC');
        if ($f['start']) { $where[] = 'r.created_at>=%s'; $args[] = (new \DateTimeImmutable($f['start'], $zone))->setTimezone($utc)->format('Y-m-d H:i:s'); }
        if ($f['end']) { $where[] = 'r.created_at<%s'; $args[] = (new \DateTimeImmutable($f['end'], $zone))->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s'); }
        $paid = 'COALESCE(b.paid,0)';
        $states = ['pending' => "$paid=0", 'partial' => "$paid>0 AND $paid<r.total", 'paid' => "$paid>=r.total", 'overpaid' => "$paid>r.total"];
        if ($f['status']) { $where[] = '(' . $states[$f['status']] . ')'; }
        $from = "FROM $r r JOIN $e e ON e.id=r.event_id LEFT JOIN (SELECT p.registration_id,SUM(t.credit) paid FROM $p p JOIN $t t ON t.payment_id=p.id GROUP BY p.registration_id) b ON b.registration_id=r.id LEFT JOIN (SELECT registration_id,SUM(CASE WHEN state IN ('creating','pending') THEN 1 ELSE 0 END) open_attempts,SUM(CASE WHEN state='review' THEN 1 ELSE 0 END) review_attempts,SUM(CASE WHEN state='canceling' THEN 1 ELSE 0 END) cancel_attempts,SUM(CASE WHEN last_error<>'' OR webhook_error<>'' THEN 1 ELSE 0 END) error_attempts FROM $p GROUP BY registration_id) a ON a.registration_id=r.id WHERE " . implode(' AND ', $where);
        return [$from, $args];
    }
    private static function read(string $sql, array $args = []): array
    {
        global $wpdb; $sql = '/* ie-report */ ' . $sql;
        $rows = $wpdb->get_results($args ? $wpdb->prepare($sql, $args) : $sql, ARRAY_A);
        if ($rows === null || !empty($wpdb->last_error)) { throw new \RuntimeException('Não foi possível conferir os dados do relatório. Tente atualizar novamente.'); }
        return $rows;
    }
    public static function rows(array $f, int $limit, int $offset): array
    {
        [$from, $args] = self::query($f);
        return self::read('SELECT r.*,e.name event_name,COALESCE(b.paid,0) paid,COALESCE(a.review_attempts,0) review_attempts,COALESCE(a.cancel_attempts,0) cancel_attempts,COALESCE(a.error_attempts,0) error_attempts ' . $from . ' ORDER BY r.id DESC LIMIT %d OFFSET %d', array_merge($args, [max(1, min(250, $limit)), max(0, $offset)]));
    }
    private static function sums(): string
    {
        return 'COUNT(*) count,COALESCE(SUM(r.total),0) total,COALESCE(SUM(COALESCE(b.paid,0)),0) paid,COALESCE(SUM(GREATEST(r.total-COALESCE(b.paid,0),0)),0) balance,COALESCE(SUM(GREATEST(COALESCE(b.paid,0)-r.total,0)),0) overpaid,COALESCE(SUM(CASE WHEN COALESCE(b.paid,0)>=r.total THEN r.total ELSE COALESCE(b.paid,0) END),0) covered';
    }
    public static function metrics(array $f): array
    {
        [$from, $args] = self::query($f);
        $sql = 'SELECT ' . self::sums();
        foreach (['pending' => 'COALESCE(b.paid,0)=0', 'partial' => 'COALESCE(b.paid,0)>0 AND COALESCE(b.paid,0)<r.total', 'paid_count' => 'COALESCE(b.paid,0)>=r.total', 'overpaid_count' => 'COALESCE(b.paid,0)>r.total', 'active' => "r.status='active'", 'cancelled' => "r.status='cancelled'", 'deleted' => "r.status='deleted'"] as $key => $condition) { $sql .= ',COALESCE(SUM(CASE WHEN ' . $condition . ' THEN 1 ELSE 0 END),0) ' . $key; }
        foreach (['open_attempts', 'review_attempts', 'cancel_attempts', 'error_attempts'] as $key) { $sql .= ',COALESCE(SUM(a.' . $key . '),0) ' . $key; }
        $row = self::read($sql . ' ' . $from, $args)[0] ?? null;
        if ($row === null) { throw new \RuntimeException('O relatório não retornou os totais.'); }
        $m = array_map('intval', $row); $m['progress'] = $m['total'] ? round(100 * $m['covered'] / $m['total'], 1) : 0.0;
        return $m;
    }
    /** Signed calendar-day countdown; no assumption about an event without a configured date. */
    public static function calendar(array $event, ?string $today = null): array
    {
        $today = $today ?? Domain::today($event); $zone = new \DateTimeZone($event['timezone']);
        $days = static fn($date) => $date ? (int) (new \DateTimeImmutable($today, $zone))->diff(new \DateTimeImmutable($date, $zone))->format('%r%a') : null;
        $lots = Store::lots((int) $event['id']); $active = Domain::quote($event, $lots, $today);
        return ['today' => $today, 'days' => $days($event['event_date']), 'deadline_days' => $days($event['payment_deadline'] ?: $event['event_date']), 'deadline' => $event['payment_deadline'] ?: $event['event_date'], 'registration_days' => $days($event['registration_end']), 'open' => $active !== null, 'active_lot' => $active ? ['name' => $active['name'], 'price' => (int) $active['price'], 'installments' => (int) $active['max_installments'], 'ends_on' => $active['ends_on']] : null];
    }
    public static function dashboard(array $input): array
    {
        $f = self::filters($input); $event = $f['event'] ? Store::event($f['event']) : null;
        [$from, $args] = self::query($f); $metrics = self::metrics($f);
        $pages = max(1, (int) ceil($metrics['count'] / 25)); $page = min($pages, max(1, absint(is_scalar($input['page'] ?? 1) ? ($input['page'] ?? 1) : 1)));
        $rows = self::rows($f, 25, ($page - 1) * 25);
        $registrations = array_map(static function ($r) {
            $b = Domain::balance((int) $r['total'], (int) $r['paid']);
            return array_merge(array_intersect_key($r, array_flip(['name', 'email', 'phone', 'event_name', 'lot_name', 'method', 'created_at'])), $b, ['id' => (int) $r['id'], 'event_id' => (int) $r['event_id'], 'registration_status' => $r['status'], 'alerts' => (int) $r['review_attempts'] + (int) $r['cancel_attempts'] + (int) $r['error_attempts'], 'detail_url' => admin_url('admin.php?page=ie-dashboard&view=' . (int) $r['id'])]);
        }, $rows);
        $lots = self::read('SELECT r.event_id,e.name event_name,r.lot_name label,' . self::sums() . ' ' . $from . ' GROUP BY r.event_id,e.name,r.lot_name ORDER BY r.event_id,r.lot_name', $args);
        $methods = self::read('SELECT r.method label,' . self::sums() . ' ' . $from . ' GROUP BY r.method ORDER BY r.method', $args);
        $timeline = array_reverse(self::read('SELECT DATE(r.created_at) label,COUNT(*) count ' . $from . ' GROUP BY DATE(r.created_at) ORDER BY label DESC LIMIT 30', $args));
        $normalize = static function ($row) { foreach (['count', 'total', 'paid', 'balance', 'overpaid', 'covered', 'event_id'] as $key) { if (isset($row[$key])) { $row[$key] = (int) $row[$key]; } } return $row; };
        // Lot choices retain the names frozen in existing registrations, even after lot edits.
        $choices = $f; $choices['q'] = ''; $choices['lot'] = ''; $choices['status'] = ''; $choices['method'] = ''; $choices['start'] = ''; $choices['end'] = ''; $choices['registration_status'] = 'all';
        [$choiceFrom, $choiceArgs] = self::query($choices);
        $lotNames = array_column(self::read('SELECT DISTINCT r.lot_name label ' . $choiceFrom . ' ORDER BY r.lot_name', $choiceArgs), 'label');
        if ($event) { $lotNames = array_values(array_unique(array_merge($lotNames, array_column(Store::lots((int) $event['id']), 'name')))); }
        return ['filters' => $f, 'event' => $event ? array_intersect_key($event, array_flip(['id', 'name', 'location', 'event_date', 'registration_start', 'registration_end', 'payment_deadline', 'timezone', 'status', 'primary_color', 'accent_color', 'logo_url'])) : null, 'calendar' => $event ? self::calendar($event) : null, 'events' => array_map(static fn($e) => ['id' => (int) $e['id'], 'name' => $e['name']], Store::events()), 'lot_options' => $lotNames, 'metrics' => $metrics, 'lots' => array_map($normalize, $lots), 'methods' => array_map($normalize, $methods), 'timeline' => array_map($normalize, $timeline), 'registrations' => $registrations, 'pagination' => ['page' => $page, 'pages' => $pages, 'total' => $metrics['count'], 'per_page' => 25], 'scope_notice' => $f['all_scopes'] ? 'Todas as contas e ambientes K8' : 'Conta e ambiente K8 atuais', 'date_timezone' => $event['timezone'] ?? 'UTC', 'cron_error' => Domain::cut(sanitize_text_field((string) get_option('ie_cron_error', '')), 500), 'updated_at' => gmdate('c')];
    }
}
