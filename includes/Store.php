<?php
namespace InscricaoEventos;
if (!defined('ABSPATH')) { exit; }

final class Store
{
    public static function table(string $name): string { global $wpdb; return $wpdb->prefix . 'ie_' . $name; }
    public static function check($ok): void { if ($ok === false) { throw new \RuntimeException('Não foi possível salvar os dados. Tente novamente.'); } }
    public static function insert(string $table, array $data): int
    {
        global $wpdb; self::check($wpdb->insert(self::table($table), $data)); return (int) $wpdb->insert_id;
    }
    public static function update(string $table, array $data, array $where): void { global $wpdb; self::check($wpdb->update(self::table($table), $data, $where)); }
    public static function lock(string $key): string
    {
        global $wpdb; $name = 'ie:' . substr(hash('sha256', $wpdb->prefix . ':' . $key), 0, 60);
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,3)', $name)) !== 1) { throw new \RuntimeException('Outra operação está em andamento. Aguarde e tente novamente.'); }
        return $name;
    }
    public static function unlock(string $lock): void { global $wpdb; $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock)); }
    public static function begin(): void { global $wpdb; self::check($wpdb->query('START TRANSACTION')); }
    public static function commit(): void { global $wpdb; self::check($wpdb->query('COMMIT')); }
    public static function rollback(): void { global $wpdb; $wpdb->query('ROLLBACK'); }

    public static function install(): void
    {
        global $wpdb; require_once ABSPATH . 'wp-admin/includes/upgrade.php'; $c = $wpdb->get_charset_collate();
        $schemas = [
            'events' => "id bigint unsigned NOT NULL AUTO_INCREMENT,
                name varchar(200) NOT NULL,
                description longtext NOT NULL,
                location varchar(255) NOT NULL DEFAULT '',
                status varchar(20) NOT NULL DEFAULT 'draft',
                event_date varchar(10) NOT NULL DEFAULT '',
                registration_start varchar(10) NOT NULL,
                registration_end varchar(10) NOT NULL,
                payment_deadline varchar(10) NOT NULL DEFAULT '',
                timezone varchar(64) NOT NULL DEFAULT 'America/Sao_Paulo',
                allow_pix tinyint NOT NULL DEFAULT 1,
                allow_card tinyint NOT NULL DEFAULT 1,
                minimum_entry bigint NOT NULL DEFAULT 100,
                logo_url text NOT NULL,
                hero_url text NOT NULL,
                primary_color varchar(7) NOT NULL DEFAULT '#215c4a',
                accent_color varchar(7) NOT NULL DEFAULT '#d2a45c',
                background_color varchar(7) NOT NULL DEFAULT '#f6f5f0',
                payment_message text NOT NULL,
                terms longtext NOT NULL,
                privacy_url text NOT NULL,
                public_page_id bigint unsigned NOT NULL DEFAULT 0,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY status (status)",
            'lots' => "id bigint unsigned NOT NULL AUTO_INCREMENT,
                event_id bigint unsigned NOT NULL,
                name varchar(100) NOT NULL,
                starts_on varchar(10) NOT NULL,
                ends_on varchar(10) NOT NULL,
                price bigint NOT NULL,
                max_installments int NOT NULL DEFAULT 1,
                note varchar(200) NOT NULL DEFAULT '',
                PRIMARY KEY  (id),
                KEY event_dates (event_id,starts_on,ends_on)",
            'registrations' => "id bigint unsigned NOT NULL AUTO_INCREMENT,
                event_id bigint unsigned NOT NULL,
                scope char(64) NOT NULL,
                lot_id bigint unsigned NOT NULL,
                lot_name varchar(100) NOT NULL,
                total bigint NOT NULL,
                max_installments int NOT NULL,
                method varchar(10) NOT NULL,
                status varchar(20) NOT NULL DEFAULT 'active',
                admin_note text DEFAULT NULL,
                updated_at datetime DEFAULT NULL,
                deleted_at datetime DEFAULT NULL,
                name varchar(200) NOT NULL,
                email varchar(254) NOT NULL,
                phone varchar(20) NOT NULL,
                cpf_hash char(64) NOT NULL,
                cpf_encrypted text NOT NULL,
                terms_snapshot longtext NOT NULL,
                terms_accepted_at datetime NOT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY event_cpf (event_id,scope,cpf_hash),
                KEY event_created (event_id,created_at)",
            'payments' => "id bigint unsigned NOT NULL AUTO_INCREMENT,
                registration_id bigint unsigned NOT NULL,
                reference varchar(32) NOT NULL,
                scope char(64) NOT NULL,
                amount bigint NOT NULL,
                method varchar(10) NOT NULL,
                max_installments int DEFAULT NULL,
                cpf_snapshot text DEFAULT NULL,
                diagnostic_json longtext DEFAULT NULL,
                checkout_id varchar(100) DEFAULT NULL,
                checkout_url text NOT NULL,
                remote_id_hint varchar(100) NOT NULL DEFAULT '',
                webhook_at datetime DEFAULT NULL,
                webhook_error varchar(500) NOT NULL DEFAULT '',
                state varchar(20) NOT NULL DEFAULT 'creating',
                last_error text NOT NULL,
                operator_note text NOT NULL,
                created_at datetime NOT NULL,
                synced_at datetime DEFAULT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY reference (reference),
                UNIQUE KEY scope_checkout (scope,checkout_id),
                KEY registration_state (registration_id,state),
                KEY scope_synced (scope,synced_at)",
            'transactions' => "id bigint unsigned NOT NULL AUTO_INCREMENT,
                payment_id bigint unsigned NOT NULL,
                scope char(64) NOT NULL,
                remote_id varchar(100) NOT NULL,
                remote_state varchar(50) NOT NULL,
                credit bigint NOT NULL DEFAULT 0,
                paid_at varchar(40) NOT NULL DEFAULT '',
                synced_at datetime NOT NULL,
                cancel_status varchar(20) NOT NULL DEFAULT '',
                cancel_operation varchar(20) NOT NULL DEFAULT '',
                cancel_requested_at datetime DEFAULT NULL,
                cancel_error varchar(500) NOT NULL DEFAULT '',
                cancel_actor bigint unsigned NOT NULL DEFAULT 0,
                PRIMARY KEY  (id),
                UNIQUE KEY scope_remote (scope,remote_id),
                KEY payment_id (payment_id)",
            'audit' => "id bigint unsigned NOT NULL AUTO_INCREMENT,
                registration_id bigint unsigned NOT NULL,
                payment_id bigint unsigned NOT NULL DEFAULT 0,
                actor_id bigint unsigned NOT NULL DEFAULT 0,
                action varchar(40) NOT NULL,
                note text NOT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY registration_created (registration_id,created_at)",
            'sessions' => "token_hash char(64) NOT NULL,
                registration_id bigint unsigned NOT NULL,
                expires_at bigint NOT NULL,
                PRIMARY KEY  (token_hash),
                KEY expires_at (expires_at)",
            'limits' => "bucket char(64) NOT NULL,
                hits int NOT NULL DEFAULT 1,
                expires_at bigint NOT NULL,
                PRIMARY KEY  (bucket),
                KEY expires_at (expires_at)",
        ];
        foreach ($schemas as $name => $fields) {
            $table = self::table($name); dbDelta("CREATE TABLE $table ($fields) ENGINE=InnoDB $c;");
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== $table) { throw new \RuntimeException('Falha criando as tabelas de inscrições.'); }
            $engine = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS WHERE Name=%s', $table), ARRAY_A);
            if (strtolower($engine['Engine'] ?? '') !== 'innodb') { throw new \RuntimeException('O plugin precisa de tabelas InnoDB para proteger as operações financeiras.'); }
        }
        foreach (['registrations' => ['status', 'admin_note', 'updated_at', 'deleted_at'], 'payments' => ['max_installments', 'cpf_snapshot', 'diagnostic_json', 'remote_id_hint', 'webhook_at', 'webhook_error'], 'transactions' => ['cancel_status', 'cancel_operation', 'cancel_requested_at', 'cancel_error', 'cancel_actor']] as $name => $required) {
            $columns = $wpdb->get_col('SHOW COLUMNS FROM ' . self::table($name));
            if (!is_array($columns) || array_diff($required, $columns)) { throw new \RuntimeException('Não foi possível atualizar as colunas da tabela de ' . $name . '. Confira as permissões do banco.'); }
        }
        update_option('ie_schema_version', IE_VERSION, false);
    }

    public static function events(bool $public = false): array
    {
        global $wpdb; return $wpdb->get_results('SELECT * FROM ' . self::table('events') . ($public ? " WHERE status='published'" : '') . ' ORDER BY created_at DESC,id DESC', ARRAY_A) ?: [];
    }
    public static function event(int $id): array
    {
        global $wpdb; $e = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table('events') . ' WHERE id=%d', $id), ARRAY_A);
        if (!$e) { throw new \RuntimeException('Evento não encontrado.'); } return $e;
    }
    public static function lots(int $eventId): array
    {
        global $wpdb; return $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table('lots') . ' WHERE event_id=%d ORDER BY starts_on,id', $eventId), ARRAY_A) ?: [];
    }
    public static function registration(int $id): array
    {
        global $wpdb; $r = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table('registrations') . ' WHERE id=%d', $id), ARRAY_A);
        if (!$r) { throw new \RuntimeException('Inscrição não encontrada.'); } return $r;
    }
    public static function byCPF(int $eventId, string $cpf, ?string $scope = null): ?array
    {
        global $wpdb; return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table('registrations') . ' WHERE event_id=%d AND scope=%s AND cpf_hash=%s', $eventId, $scope ?? (new Gateway())->scope(), Security::hash('cpf:' . $cpf)), ARRAY_A) ?: null;
    }
    public static function payment(int $id): array
    {
        global $wpdb; $p = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table('payments') . ' WHERE id=%d', $id), ARRAY_A);
        if (!$p) { throw new \RuntimeException('Pagamento não encontrado.'); } return $p;
    }
    public static function paid(int $id): int
    {
        global $wpdb; $value = $wpdb->get_var($wpdb->prepare('SELECT COALESCE(SUM(t.credit),0) FROM ' . self::table('transactions') . ' t JOIN ' . self::table('payments') . ' p ON p.id=t.payment_id WHERE p.registration_id=%d', $id));
        if ($value === null || !empty($wpdb->last_error)) { throw new \RuntimeException('Não foi possível conferir o saldo. Nenhuma nova cobrança foi criada.'); }
        return (int) $value;
    }
    public static function balance(array $r): array { return Domain::balance((int) $r['total'], self::paid((int) $r['id'])); }
    public static function payments(int $id): array
    {
        global $wpdb; $rows = $wpdb->get_results($wpdb->prepare('SELECT p.*,COALESCE((SELECT SUM(t.credit) FROM ' . self::table('transactions') . ' t WHERE t.payment_id=p.id),0) credit FROM ' . self::table('payments') . ' p WHERE p.registration_id=%d ORDER BY p.id DESC', $id), ARRAY_A);
        if ($rows === null || !empty($wpdb->last_error)) { throw new \RuntimeException('Não foi possível conferir as tentativas de pagamento. Tente novamente.'); }
        return $rows;
    }
    public static function transactions(int $paymentId): array
    {
        global $wpdb; $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table('transactions') . ' WHERE payment_id=%d ORDER BY id ASC', $paymentId), ARRAY_A);
        if ($rows === null || !empty($wpdb->last_error)) { throw new \RuntimeException('Não foi possível conferir as transações.'); }
        return $rows;
    }
    public static function audit(int $registrationId, string $action, string $note, int $paymentId = 0): void
    {
        self::insert('audit', ['registration_id' => $registrationId, 'payment_id' => $paymentId, 'actor_id' => get_current_user_id(), 'action' => $action, 'note' => Domain::cut(sanitize_textarea_field($note), 1000), 'created_at' => current_time('mysql', true)]);
    }
    public static function auditRows(int $registrationId): array
    {
        global $wpdb; return $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table('audit') . ' WHERE registration_id=%d ORDER BY id DESC LIMIT 50', $registrationId), ARRAY_A) ?: [];
    }
    public static function publicRegistration(array $r): array
    {
        $e = self::event((int) $r['event_id']); $today = Domain::today($e);
        $b = self::balance($r);
        $active = ($r['status'] ?? 'active') === 'active';
        $card = Domain::installments($r, $e); $methods = [];
        if ($e['allow_pix'] && $r['method'] === 'Pix') { $methods[] = 'Pix'; }
        if ($e['allow_card']) { $methods[] = 'Cartao'; }
        $payments = array_map(static function ($p) use ($active) {
            return ['id' => (int) $p['id'], 'amount' => (int) $p['amount'], 'credit' => (int) $p['credit'], 'method' => $p['method'], 'max_installments' => isset($p['max_installments']) ? (int) $p['max_installments'] : null, 'state' => $p['state'], 'created_at' => $p['created_at'], 'url' => $active && $p['state'] === 'pending' ? $p['checkout_url'] : '', 'error' => $p['state'] === 'review' ? 'Pagamento em conferência. Procure a organização antes de tentar novamente.' : ''];
        }, self::payments((int) $r['id']));
        return array_merge($b, ['id' => (int) $r['id'], 'event_id' => (int) $r['event_id'], 'event_name' => $e['name'], 'name' => Domain::firstName($r['name']), 'lot' => $r['lot_name'], 'method' => $r['method'], 'max_installments' => (int) $r['max_installments'], 'card_installments' => $card, 'payment_methods' => $methods, 'deadline' => $e['payment_deadline'], 'message' => str_replace('{data}', Domain::dateBR($e['payment_deadline']), $e['payment_message']), 'registration_status' => $r['status'] ?? 'active', 'payments' => $payments, 'can_pay' => $active && $b['balance'] > 0 && $e['status'] === 'published' && !empty($e['payment_deadline']) && $today <= $e['payment_deadline'] && (bool) $methods]);
    }
    public static function sample(): array
    {
        return ['name' => 'Retiro Carnaval 2028', 'description' => 'Um tempo para descansar, fortalecer a fé e viver momentos especiais em comunidade.', 'status' => 'draft', 'registration_start' => '2026-10-17', 'registration_end' => '2027-01-30', 'event_date' => '', 'payment_deadline' => '', 'timezone' => 'America/Sao_Paulo', 'allow_pix' => 1, 'allow_card' => 1, 'minimum_entry' => '1,00', 'payment_message' => 'O saldo devedor deve ser quitado até o dia do evento: {data}.', 'lots' => [
            ['name' => 'Lote 1', 'starts_on' => '2026-10-17', 'ends_on' => '2026-11-15', 'price' => '490,00', 'max_installments' => 10, 'note' => ''],
            ['name' => 'Lote 2', 'starts_on' => '2026-11-16', 'ends_on' => '2026-12-31', 'price' => '520,00', 'max_installments' => 8, 'note' => 'Valor a confirmar'],
            ['name' => 'Lote 3', 'starts_on' => '2027-01-01', 'ends_on' => '2027-01-30', 'price' => '550,00', 'max_installments' => 6, 'note' => ''],
        ]];
    }
    public static function saveEvent(array $input, int $id = 0): int
    {
        $status = in_array($input['status'] ?? '', ['draft', 'published', 'archived'], true) ? $input['status'] : 'draft';
        $name = Domain::cut(sanitize_text_field($input['name'] ?? ''), 200);
        if (!$name) { throw new \RuntimeException('Informe o nome do evento.'); }
        $start = Domain::date((string) ($input['registration_start'] ?? ''));
        $end = Domain::date((string) ($input['registration_end'] ?? ''));
        $date = Domain::date((string) ($input['event_date'] ?? ''), $status !== 'published');
        $deadline = Domain::date((string) ($input['payment_deadline'] ?? '') ?: $date, $status !== 'published');
        if (!$deadline) { $deadline = $date; }
        if ($start > $end || ($date && $end > $date) || ($deadline && ($deadline < $end || ($date && $deadline > $date)))) { throw new \RuntimeException('Confira a ordem das datas: início, encerramento, quitação e evento.'); }
        $tz = (string) ($input['timezone'] ?? 'America/Sao_Paulo');
        if (!in_array($tz, \DateTimeZone::listIdentifiers(), true)) { throw new \RuntimeException('Fuso horário inválido.'); }
        $pix = empty($input['allow_pix']) ? 0 : 1; $card = empty($input['allow_card']) ? 0 : 1;
        if (!$pix && !$card) { throw new \RuntimeException('Habilite PIX ou cartão para o evento.'); }
        $lots = []; $rows = $input['lots'] ?? [];
        if (!is_array($rows) || count($rows) > 100) { throw new \RuntimeException('Lotes inválidos.'); }
        foreach ($rows as $lot) {
            if (!is_array($lot)) { throw new \RuntimeException('Lote inválido.'); }
            if (empty($lot['name']) && empty($lot['price'])) { continue; }
            $l = ['name' => Domain::cut(sanitize_text_field($lot['name'] ?? ''), 100), 'starts_on' => Domain::date((string) ($lot['starts_on'] ?? '')), 'ends_on' => Domain::date((string) ($lot['ends_on'] ?? '')), 'price' => Domain::cents($lot['price'] ?? ''), 'max_installments' => (int) ($lot['max_installments'] ?? 1), 'note' => Domain::cut(sanitize_text_field($lot['note'] ?? ''), 200)];
            if (!$l['name'] || $l['price'] < 1 || $l['starts_on'] < $start || $l['ends_on'] > $end || $l['starts_on'] > $l['ends_on'] || $l['max_installments'] < 1 || $l['max_installments'] > 12) { throw new \RuntimeException('Confira nome, datas, valor e parcelas dos lotes.'); }
            $lots[] = $l;
        }
        usort($lots, static fn($a, $b) => strcmp($a['starts_on'], $b['starts_on']));
        foreach ($lots as $i => $l) { if ($i && $l['starts_on'] <= $lots[$i - 1]['ends_on']) { throw new \RuntimeException('Os períodos dos lotes não podem se sobrepor.'); } }
        if (!$lots) { throw new \RuntimeException('Cadastre ao menos um lote.'); }
        $minimum = Domain::cents($input['minimum_entry'] ?? '1');
        if ($minimum < 1 || $minimum > min(array_column($lots, 'price'))) { throw new \RuntimeException('A entrada mínima deve ser positiva e não pode exceder o menor lote.'); }
        $fields = ['name' => $name, 'description' => wp_kses_post($input['description'] ?? ''), 'location' => Domain::cut(sanitize_text_field($input['location'] ?? ''), 255), 'status' => $status, 'event_date' => $date, 'registration_start' => $start, 'registration_end' => $end, 'payment_deadline' => $deadline, 'timezone' => $tz, 'allow_pix' => $pix, 'allow_card' => $card, 'minimum_entry' => $minimum, 'logo_url' => esc_url_raw($input['logo_url'] ?? '', ['https', 'http']), 'hero_url' => esc_url_raw($input['hero_url'] ?? '', ['https', 'http']), 'terms' => wp_kses_post($input['terms'] ?? ''), 'privacy_url' => esc_url_raw($input['privacy_url'] ?? '', ['https', 'http']), 'payment_message' => sanitize_textarea_field($input['payment_message'] ?? '') ?: 'O saldo devedor deve ser quitado até o dia do evento: {data}.', 'public_page_id' => absint($input['public_page_id'] ?? 0), 'updated_at' => current_time('mysql', true)];
        foreach (['primary_color' => '#215c4a', 'accent_color' => '#d2a45c', 'background_color' => '#f6f5f0'] as $key => $default) { $fields[$key] = sanitize_hex_color($input[$key] ?? '') ?: $default; }
        $lock = self::lock('event:' . $id);
        try {
            if ($id) { self::event($id); }
            self::begin();
            if ($id) { self::update('events', $fields, ['id' => $id]); }
            else { $fields['created_at'] = current_time('mysql', true); $id = self::insert('events', $fields); }
            global $wpdb; self::check($wpdb->delete(self::table('lots'), ['event_id' => $id]));
            foreach ($lots as $l) { self::insert('lots', array_merge($l, ['event_id' => $id])); }
            self::commit(); return $id;
        } catch (\Throwable $e) { self::rollback(); throw $e; }
        finally { self::unlock($lock); }
    }
}
