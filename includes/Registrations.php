<?php
namespace InscricaoEventos;
if (!defined('ABSPATH')) { exit; }

final class Registrations
{
    /** Administrative edits preserve immutable prices, installments and prior payment identities. */
    public static function save(int $id, array $input): void
    {
        global $wpdb;
        $r = Store::registration($id); $eventLock = Store::lock('event:' . $r['event_id']); $lock = null;
        try {
            $lock = Store::lock('registration:' . $id); $r = Store::registration($id);
            if (($r['status'] ?? 'active') === 'deleted') { throw new \RuntimeException('Restaure a inscrição da lixeira antes de editar.'); }
            $name = trim(sanitize_text_field($input['name'] ?? ''));
            $email = strtolower(trim((string) ($input['email'] ?? '')));
            $phone = preg_replace('/\D/', '', (string) ($input['phone'] ?? ''));
            $cpf = Domain::cpf((string) ($input['cpf'] ?? ''));
            if (strlen($name) < 3 || strlen($name) > 200 || !is_email($email) || strlen($email) > 254 || !preg_match('/^\d{10,15}$/D', $phone)) { throw new \RuntimeException('Confira nome, e-mail e telefone com DDD.'); }
            $duplicate = Store::byCPF((int) $r['event_id'], $cpf, $r['scope']);
            if ($duplicate && (int) $duplicate['id'] !== $id) { throw new \RuntimeException('Este CPF já possui outra inscrição neste evento e conta K8, inclusive na lixeira.'); }
            $status = (string) ($input['status'] ?? 'active');
            if (!in_array($status, ['active', 'cancelled'], true)) { throw new \RuntimeException('Situação da inscrição inválida.'); }
            $method = (string) ($input['method'] ?? $r['method']); $e = Store::event((int) $r['event_id']);
            if (!in_array($method, ['Pix', 'Cartao'], true)) { throw new \RuntimeException('Forma de pagamento inválida.'); }
            $payments = Store::payments($id);
            if ($method !== $r['method']) {
                if (($method === 'Pix' && !$e['allow_pix']) || ($method === 'Cartao' && !$e['allow_card'])) { throw new \RuntimeException('Esta forma de pagamento está desabilitada no evento.'); }
                foreach ($payments as $p) { if (in_array($p['state'], ['creating', 'pending', 'review', 'canceling'], true)) { throw new \RuntimeException('Conclua ou cancele a tentativa em andamento antes de trocar a forma de pagamento.'); } }
            }
            $fields = ['name' => $name, 'email' => $email, 'phone' => $phone, 'cpf_hash' => Security::hash('cpf:' . $cpf), 'cpf_encrypted' => Security::seal($cpf), 'method' => $method, 'status' => $status, 'admin_note' => Domain::cut(sanitize_textarea_field($input['admin_note'] ?? ''), 2000), 'updated_at' => current_time('mysql', true)];
            Store::begin();
            try {
                // Legacy attempts need their original CPF saved before the registration CPF changes.
                foreach ($payments as $p) { if (empty($p['cpf_snapshot'])) { Store::update('payments', ['cpf_snapshot' => $r['cpf_encrypted']], ['id' => $p['id']]); } }
                Store::update('registrations', $fields, ['id' => $id]);
                Store::check($wpdb->delete(Store::table('sessions'), ['registration_id' => $id]));
                Store::audit($id, 'edit', 'Dados cadastrais, situação e forma de futuros pagamentos conferidos. Lote, valor e parcelas preservados.');
                Store::commit();
            } catch (\Throwable $e) { Store::rollback(); throw $e; }
        } finally { if ($lock) { Store::unlock($lock); } Store::unlock($eventLock); }
    }

    public static function trash(int $id): void { self::status($id, 'deleted'); }
    public static function restore(int $id): void { self::status($id, 'active'); }
    private static function status(int $id, string $status): void
    {
        global $wpdb; $lock = Store::lock('registration:' . $id);
        try {
            $r = Store::registration($id);
            if ($status === 'active' && ($r['status'] ?? 'active') !== 'deleted') { throw new \RuntimeException('A inscrição não está na lixeira.'); }
            if ($status === 'deleted' && ($r['status'] ?? 'active') === 'deleted') { return; }
            Store::begin();
            try {
                Store::update('registrations', ['status' => $status, 'deleted_at' => $status === 'deleted' ? current_time('mysql', true) : null, 'updated_at' => current_time('mysql', true)], ['id' => $id]);
                Store::check($wpdb->delete(Store::table('sessions'), ['registration_id' => $id]));
                Store::audit($id, $status === 'deleted' ? 'trash' : 'restore', $status === 'deleted' ? 'Inscrição movida para a lixeira; histórico financeiro preservado.' : 'Inscrição restaurada.');
                Store::commit();
            } catch (\Throwable $e) { Store::rollback(); throw $e; }
        } finally { Store::unlock($lock); }
    }
    public static function purge(int $id): void
    {
        $r = Store::registration($id); $eventLock = Store::lock('event:' . $r['event_id']); $lock = null;
        try {
            $lock = Store::lock('registration:' . $id); Store::registration($id);
            Store::begin();
            try { self::erase($id); Store::commit(); }
            catch (\Throwable $e) { Store::rollback(); throw $e; }
        } finally { if ($lock) { Store::unlock($lock); } Store::unlock($eventLock); }
    }
    /** Remove a complete event locally. This never calls the K8 cancellation/refund API. */
    public static function purgeEvent(int $id): void
    {
        global $wpdb; $eventLock = Store::lock('event:' . $id); $locks = [];
        try {
            Store::event($id);
            $rows = $wpdb->get_results($wpdb->prepare('SELECT id FROM ' . Store::table('registrations') . ' WHERE event_id=%d ORDER BY id', $id), ARRAY_A);
            if ($rows === null || !empty($wpdb->last_error)) { throw new \RuntimeException('Não foi possível conferir as inscrições do evento.'); }
            // Same event -> registration lock order as registration edits; serialize callbacks and cron.
            foreach ($rows as $r) { $locks[] = Store::lock('registration:' . $r['id']); }
            Store::begin();
            try {
                foreach ($rows as $r) { self::erase((int) $r['id']); }
                Store::check($wpdb->delete(Store::table('lots'), ['event_id' => $id]));
                Store::check($wpdb->delete(Store::table('events'), ['id' => $id]));
                Store::commit();
            } catch (\Throwable $e) { Store::rollback(); throw $e; }
        } finally {
            foreach (array_reverse($locks) as $lock) { Store::unlock($lock); }
            Store::unlock($eventLock);
        }
    }
    /** Caller holds the event/registration locks and the enclosing database transaction. */
    private static function erase(int $id): void
    {
        global $wpdb;
        foreach (Store::payments($id) as $p) { Store::check($wpdb->delete(Store::table('transactions'), ['payment_id' => $p['id']])); }
        foreach (['manuals', 'payments', 'sessions', 'audit'] as $table) { Store::check($wpdb->delete(Store::table($table), ['registration_id' => $id])); }
        Store::check($wpdb->delete(Store::table('registrations'), ['id' => $id]));
    }
}
