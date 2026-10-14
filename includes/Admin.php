<?php
namespace InscricaoEventos;
if (!defined('ABSPATH')) { exit; }

final class Admin
{
    public static function boot(): void
    {
        add_action('admin_menu', static function () {
            add_menu_page('Inscrições de eventos', 'Inscrições', 'manage_options', 'ie-dashboard', [self::class, 'dashboard'], 'dashicons-tickets-alt', 30);
            add_submenu_page('ie-dashboard', 'Inscrições', 'Acompanhamento', 'manage_options', 'ie-dashboard', [self::class, 'dashboard']);
            add_submenu_page('ie-dashboard', 'Eventos e lotes', 'Eventos e lotes', 'manage_options', 'ie-events', [self::class, 'events']);
            add_submenu_page('ie-dashboard', 'Configurações', 'Configurações K8', 'manage_options', 'ie-settings', [self::class, 'settings']);
        });
        add_action('admin_post_ie_save_event', [self::class, 'saveEvent']);
        add_action('admin_post_ie_clone_event', [self::class, 'cloneEvent']);
        add_action('admin_post_ie_delete_event', [self::class, 'deleteEvent']);
        add_action('admin_post_ie_save_settings', [self::class, 'saveSettings']);
        add_action('admin_post_ie_test_k8', [self::class, 'testK8']);
        add_action('admin_post_ie_sync_payment', [self::class, 'syncPayment']);
        add_action('admin_post_ie_recover_payment', [self::class, 'recoverPayment']);
        add_action('admin_post_ie_export', [self::class, 'export']);
        add_action('admin_enqueue_scripts', static function ($hook) {
            if (strpos($hook, 'ie-') === false) { return; }
            wp_enqueue_media();
            wp_enqueue_style('ie-admin', plugins_url('assets/admin.css', IE_FILE), [], IE_VERSION);
            wp_enqueue_script('ie-admin', plugins_url('assets/admin.js', IE_FILE), [], IE_VERSION, true);
        });
        add_filter('auto_update_plugin', static function ($update, $item) {
            if (($item->plugin ?? '') === plugin_basename(IE_FILE)) { return (bool) (get_option('ie_settings', [])['auto_update'] ?? true); }
            return $update;
        }, 10, 2);
    }
    private static function guard(string $action): void
    {
        if (!current_user_can('manage_options')) { wp_die('Acesso negado.'); }
        check_admin_referer($action);
    }
    private static function notice(): void
    {
        $key = 'ie_notice_' . get_current_user_id(); $n = get_transient($key); delete_transient($key);
        if ($n) { echo '<div class="notice notice-' . ($n['error'] ? 'error' : 'success') . '"><p>' . esc_html($n['message']) . '</p></div>'; }
    }
    private static function redirect(string $page, string $message, bool $error = false, array $args = []): void
    {
        set_transient('ie_notice_' . get_current_user_id(), ['message' => $message, 'error' => $error], 120);
        wp_safe_redirect(add_query_arg(array_merge(['page' => $page], $args), admin_url('admin.php'))); exit;
    }
    private static function form(string $action, array $hidden = []): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="ie-admin-form"><input type="hidden" name="action" value="' . esc_attr($action) . '">';
        wp_nonce_field($action);
        foreach ($hidden as $key => $value) { echo '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '">'; }
    }
    private static function input(string $key, string $label, $value, string $type = 'text', string $extra = ''): void
    {
        echo '<label class="ie-field"><span>' . esc_html($label) . '</span><input type="' . esc_attr($type) . '" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '" ' . $extra . '></label>';
    }
    private static function textarea(string $key, string $label, string $value): void
    {
        echo '<label class="ie-field ie-full"><span>' . esc_html($label) . '</span><textarea rows="4" name="' . esc_attr($key) . '">' . esc_textarea($value) . '</textarea></label>';
    }
    private static function check(string $key, string $label, bool $checked): void
    {
        echo '<label class="ie-checkbox"><input type="checkbox" name="' . esc_attr($key) . '" value="1" ' . checked($checked, true, false) . '> ' . esc_html($label) . '</label>';
    }
    public static function events(): void
    {
        echo '<div class="wrap ie-admin"><h1>Eventos e lotes</h1>'; self::notice();
        $edit = isset($_GET['edit']); $id = absint($_GET['edit'] ?? 0);
        if (!$edit) {
            echo '<p><a class="button button-primary" href="' . esc_url(admin_url('admin.php?page=ie-events&edit=0')) . '">Novo evento</a></p><div class="ie-event-grid">';
            foreach (Store::events() as $e) {
                echo '<article class="ie-panel"><span class="ie-badge">' . esc_html(['draft' => 'Rascunho', 'published' => 'Publicado', 'archived' => 'Arquivado'][$e['status']] ?? $e['status']) . '</span><h2>' . esc_html($e['name']) . '</h2><p>' . esc_html(Domain::dateBR($e['event_date'])) . '</p><p>Inscrições: ' . esc_html(Domain::dateBR($e['registration_start']) . ' a ' . Domain::dateBR($e['registration_end'])) . '</p><code>[inscricao_eventos evento="' . (int) $e['id'] . '"]</code><p><a class="button" href="' . esc_url(admin_url('admin.php?page=ie-events&edit=' . $e['id'])) . '">Editar evento</a> ';
                if ($e['public_page_id']) { echo '<a class="button" target="_blank" rel="noopener" href="' . esc_url(get_permalink($e['public_page_id'])) . '">Ver página</a>'; }
                echo '</p>'; self::form('ie_clone_event', ['event_id' => $e['id']]); echo '<button class="button">Duplicar como rascunho</button></form></article>';
            }
            echo '</div></div>'; return;
        }
        try { $e = $id ? Store::event($id) : array_merge(Store::sample(), ['name' => '', 'description' => '', 'lots' => []]); $lots = $id ? Store::lots($id) : []; }
        catch (\Throwable $error) { echo '<p>' . esc_html($error->getMessage()) . '</p></div>'; return; }
        echo '<p><a href="' . esc_url(admin_url('admin.php?page=ie-events')) . '">← Todos os eventos</a></p>';
        if ($id === (int) get_option('ie_sample_created')) { echo '<div class="notice notice-info inline"><p>Modelo do retiro: falta definir a data exata do evento. A imagem usa janeiro de 2027 e você informou Carnaval de 2028. O segundo lote está em R$ 520,00, a confirmar.</p></div>'; }
        self::form('ie_save_event', ['event_id' => $id]);
        echo '<div class="ie-panel"><h2>Informações do evento</h2><div class="ie-form-grid">';
        self::input('name', 'Nome do evento', $e['name'], 'text', 'required maxlength="200"');
        self::input('location', 'Local', $e['location'] ?? '');
        echo '<label class="ie-field"><span>Situação</span><select name="status">';
        foreach (['draft' => 'Rascunho', 'published' => 'Publicado', 'archived' => 'Arquivado'] as $v => $text) { echo '<option value="' . esc_attr($v) . '" ' . selected($e['status'], $v, false) . '>' . esc_html($text) . '</option>'; } echo '</select></label>';
        self::input('event_date', 'Data do evento', $e['event_date'], 'date');
        self::input('registration_start', 'Início das inscrições', $e['registration_start'], 'date', 'required');
        self::input('registration_end', 'Fim das inscrições', $e['registration_end'], 'date', 'required');
        self::input('payment_deadline', 'Quitação até (vazio = dia do evento)', $e['payment_deadline'], 'date');
        echo '<label class="ie-field"><span>Fuso horário</span><select name="timezone">';
        foreach (['America/Sao_Paulo', 'America/Manaus', 'America/Rio_Branco', 'America/Recife', 'America/Bahia', 'America/Fortaleza', 'America/Belem', 'America/Cuiaba', 'UTC'] as $tz) { echo '<option ' . selected($e['timezone'], $tz, false) . '>' . esc_html($tz) . '</option>'; } echo '</select></label>';
        self::input('public_page_id', 'ID da página de inscrições (retorno do K8)', $e['public_page_id'] ?? 0, 'number', 'min="0"');
        self::textarea('description', 'Apresentação (HTML básico permitido)', $e['description']);
        echo '</div></div><div class="ie-panel"><h2>Pagamento</h2><div class="ie-form-grid">';
        self::check('allow_card', 'Aceitar cartão', (bool) $e['allow_card']); self::check('allow_pix', 'Aceitar PIX com entrada e complementos', (bool) $e['allow_pix']);
        self::input('minimum_entry', 'Entrada mínima por PIX (R$)', $id ? number_format((int) $e['minimum_entry'] / 100, 2, '.', '') : '1.00', 'number', 'min="0.01" step="0.01" required');
        self::textarea('payment_message', 'Mensagem sobre quitação — use {data}', $e['payment_message']);
        echo '</div><p>O valor e o parcelamento do lote são guardados na inscrição. Edições futuras dos lotes não alteram inscrições existentes. As datas incluem o dia inteiro no fuso escolhido.</p></div>';
        echo '<div class="ie-panel"><h2>Lotes</h2><p>Cadastre períodos sem sobreposição. O limite de parcelas do cartão deve estar entre 1 e 12.</p><div id="ie-lots">';
        foreach ($lots as $i => $l) { self::lot($i, $l); }
        if (!$lots) { self::lot(0, []); }
        echo '</div><button type="button" class="button" id="ie-add-lot">Adicionar lote</button><template id="ie-lot-template">'; self::lot('__INDEX__', []); echo '</template></div>';
        echo '<div class="ie-panel"><h2>Identidade visual</h2><div class="ie-form-grid">';
        foreach (['logo_url' => 'Logo', 'hero_url' => 'Imagem de capa'] as $key => $label) { echo '<div>'; self::input($key, $label . ' — URL', $e[$key] ?? '', 'url'); echo '<button type="button" class="button ie-media" data-field="' . esc_attr($key) . '">Escolher imagem</button></div>'; }
        self::input('primary_color', 'Cor principal', $e['primary_color'] ?? '#215c4a', 'color');
        self::input('accent_color', 'Cor de destaque', $e['accent_color'] ?? '#d2a45c', 'color');
        self::input('background_color', 'Cor de fundo', $e['background_color'] ?? '#f6f5f0', 'color');
        echo '</div></div><div class="ie-panel"><h2>Condições de inscrição</h2><div class="ie-form-grid">';
        self::textarea('terms', 'Condições e política do evento', $e['terms'] ?? '');
        self::input('privacy_url', 'URL da política de privacidade', $e['privacy_url'] ?? '', 'url');
        echo '</div><p>Na consulta por CPF são exibidos primeiro nome, lote e informações de pagamento. E-mail, telefone e CPF completo ficam no painel administrativo.</p></div><p><button class="button button-primary button-hero">Salvar evento</button></p></form>';
        if ($id) { self::form('ie_delete_event', ['event_id' => $id]); echo '<button class="button ie-confirm" data-confirm="Excluir este evento? Só é permitido quando não há inscrições.">Excluir evento sem inscrições</button></form>'; }
        echo '</div>';
    }
    private static function lot($index, array $l): void
    {
        echo '<div class="ie-lot-row"><div class="ie-form-grid">';
        self::input('lots[' . $index . '][name]', 'Nome', $l['name'] ?? '', 'text', 'required maxlength="100"');
        self::input('lots[' . $index . '][starts_on]', 'Início', $l['starts_on'] ?? '', 'date', 'required');
        self::input('lots[' . $index . '][ends_on]', 'Fim', $l['ends_on'] ?? '', 'date', 'required');
        self::input('lots[' . $index . '][price]', 'Valor (R$)', isset($l['price']) ? number_format((int) $l['price'] / 100, 2, '.', '') : '', 'number', 'required min="0.01" step="0.01"');
        self::input('lots[' . $index . '][max_installments]', 'Máximo de parcelas', $l['max_installments'] ?? 1, 'number', 'required min="1" max="12"');
        self::input('lots[' . $index . '][note]', 'Observação', $l['note'] ?? '', 'text', 'maxlength="200"');
        echo '</div><button type="button" class="button-link-delete ie-remove-lot">Remover lote</button></div>';
    }
    public static function saveEvent(): void
    {
        self::guard('ie_save_event'); $id = absint($_POST['event_id'] ?? 0);
        try { $id = Store::saveEvent(wp_unslash($_POST), $id); self::redirect('ie-events', 'Evento e lotes salvos.', false, ['edit' => $id]); }
        catch (\Throwable $e) { self::redirect('ie-events', $e->getMessage(), true, ['edit' => $id]); }
    }
    public static function cloneEvent(): void
    {
        self::guard('ie_clone_event');
        try {
            $old = Store::event(absint($_POST['event_id'] ?? 0)); $lots = Store::lots((int) $old['id']);
            foreach ($lots as &$lot) { $lot['price'] = number_format((int) $lot['price'] / 100, 2, '.', ''); } unset($lot);
            $old['name'] = Domain::cut($old['name'], 180) . ' (cópia)'; $old['status'] = 'draft'; $old['lots'] = $lots; $old['minimum_entry'] = number_format((int) $old['minimum_entry'] / 100, 2, '.', ''); $old['public_page_id'] = 0;
            $id = Store::saveEvent($old); self::redirect('ie-events', 'Cópia criada. Ajuste datas, valores e identidade visual.', false, ['edit' => $id]);
        } catch (\Throwable $e) { self::redirect('ie-events', $e->getMessage(), true); }
    }
    public static function deleteEvent(): void
    {
        self::guard('ie_delete_event'); global $wpdb; $id = absint($_POST['event_id'] ?? 0); $lock = '';
        try {
            $lock = Store::lock('event:' . $id); Store::event($id);
            if ($wpdb->get_var($wpdb->prepare('SELECT id FROM ' . Store::table('registrations') . ' WHERE event_id=%d LIMIT 1', $id))) { throw new \RuntimeException('Este evento possui inscrições. Use a opção Arquivado para preservá-las.'); }
            Store::begin(); Store::check($wpdb->delete(Store::table('lots'), ['event_id' => $id])); Store::check($wpdb->delete(Store::table('events'), ['id' => $id])); Store::commit(); Store::unlock($lock); $lock = '';
            self::redirect('ie-events', 'Evento excluído.');
        } catch (\Throwable $e) { Store::rollback(); if ($lock) { Store::unlock($lock); } self::redirect('ie-events', $e->getMessage(), true); }
    }
    public static function settings(): void
    {
        $s = (array) get_option('ie_settings', []);
        echo '<div class="wrap ie-admin"><h1>Configurações K8</h1>'; self::notice();
        echo '<div class="ie-panel"><p>Use as credenciais da <strong>API K8 Pay (PagamentosAPI)</strong>. O checkout hospedado recebe os dados do cartão e oferece o parcelamento do lote.</p>';
        self::form('ie_save_settings'); echo '<div class="ie-form-grid"><label class="ie-field"><span>Ambiente</span><select name="environment"><option value="sandbox" ' . selected($s['environment'] ?? 'sandbox', 'sandbox', false) . '>Testes (sandbox)</option><option value="production" ' . selected($s['environment'] ?? '', 'production', false) . '>Produção</option></select></label>';
        foreach (['client_id' => 'Client ID', 'client_secret' => 'Client Secret', 'aes_key' => 'Chave AES (16 caracteres)'] as $key => $label) { self::input($key, $label, '', 'password', 'autocomplete="new-password"'); }
        self::check('auto_update', 'Instalar automaticamente novas versões do GitHub', (bool) ($s['auto_update'] ?? true));
        echo '</div><p>' . (empty($s['credentials']) ? 'Credenciais ainda não cadastradas.' : 'Credenciais protegidas já cadastradas. Deixe os três campos vazios para mantê-las.') . ' Ao substituir credenciais, preencha os três campos. Testes e produção possuem inscrições e pagamentos separados.</p><button class="button button-primary">Salvar configurações</button></form></div><div class="ie-panel">';
        self::form('ie_test_k8'); echo '<button class="button">Testar autenticação K8</button><p>Este teste autentica na API; a homologação do checkout exige uma inscrição de teste no sandbox.</p></form></div><div class="ie-panel"><h2>Como colocar no site</h2><p>Use <code>[inscricao_eventos evento="1"]</code> em uma página. Sem o atributo evento, o visitante pode escolher entre os eventos publicados.</p><p>Configure essa página no cadastro do evento para receber o retorno do K8. O endereço do webhook é enviado automaticamente em cada checkout.</p><p>Atualizações: <a href="https://github.com/paulommaeda/inscricao-eventos" target="_blank" rel="noopener">paulommaeda/inscricao-eventos</a>, branch <code>main</code>. O número da versão deve aumentar em cada entrega.</p><p>A conciliação usa WP-Cron a cada cinco minutos. Em sites com pouco tráfego, configure um cron do servidor para chamar <code>wp-cron.php</code> regularmente.</p></div></div>';
    }
    public static function saveSettings(): void
    {
        self::guard('ie_save_settings');
        try {
            $s = (array) get_option('ie_settings', []); $input = wp_unslash($_POST);
            $id = trim((string) ($input['client_id'] ?? '')); $secret = (string) ($input['client_secret'] ?? ''); $aes = (string) ($input['aes_key'] ?? '');
            if ($id || $secret || $aes) {
                if (!$id || !$secret || strlen($aes) !== 16) { throw new \RuntimeException('Preencha Client ID, Client Secret e uma chave AES de 16 caracteres.'); }
                $s['credentials'] = Security::seal(wp_json_encode(['id' => $id, 'secret' => $secret, 'aes' => $aes]));
            }
            $s['environment'] = ($input['environment'] ?? '') === 'production' ? 'production' : 'sandbox'; $s['auto_update'] = empty($input['auto_update']) ? 0 : 1;
            update_option('ie_settings', $s, false); self::redirect('ie-settings', 'Configurações salvas.');
        } catch (\Throwable $e) { self::redirect('ie-settings', $e->getMessage(), true); }
    }
    public static function testK8(): void
    {
        self::guard('ie_test_k8');
        try { (new Gateway())->authenticate(); self::redirect('ie-settings', 'Autenticação K8 confirmada. Faça uma inscrição de teste para homologar o checkout e a baixa.'); }
        catch (\Throwable $e) { self::redirect('ie-settings', $e->getMessage(), true); }
    }
    private static function filters(): array
    {
        return ['event' => absint($_GET['event'] ?? $_POST['event'] ?? 0), 'status' => sanitize_key(wp_unslash($_GET['status'] ?? $_POST['status'] ?? '')), 'q' => Domain::cut(sanitize_text_field(wp_unslash($_GET['q'] ?? $_POST['q'] ?? '')), 200), 'method' => sanitize_text_field(wp_unslash($_GET['method'] ?? $_POST['method'] ?? '')), 'all_scopes' => absint($_GET['all_scopes'] ?? $_POST['all_scopes'] ?? 0)];
    }
    private static function query(array $f): array
    {
        global $wpdb;
        $r = Store::table('registrations'); $p = Store::table('payments'); $t = Store::table('transactions'); $e = Store::table('events');
        $where = ['1=1']; $args = [];
        if (!$f['all_scopes']) {
            try { $scope = (new Gateway())->scope(); } catch (\Throwable $error) { $scope = ''; }
            $where[] = 'r.scope=%s'; $args[] = $scope;
        }
        if ($f['event']) { $where[] = 'r.event_id=%d'; $args[] = $f['event']; }
        if (in_array($f['method'], ['Pix', 'Cartao'], true)) { $where[] = 'r.method=%s'; $args[] = $f['method']; }
        if ($f['q']) {
            $q = '%' . $wpdb->esc_like($f['q']) . '%'; $where[] = '(r.name LIKE %s OR r.email LIKE %s OR r.cpf_hash=%s)';
            array_push($args, $q, $q, Security::hash('cpf:' . preg_replace('/\D/', '', $f['q'])));
        }
        $paid = 'COALESCE(b.paid,0)';
        foreach (['pending' => "$paid=0", 'partial' => "$paid>0 AND $paid<r.total", 'paid' => "$paid>=r.total", 'overpaid' => "$paid>r.total"] as $status => $condition) { if ($f['status'] === $status) { $where[] = '(' . $condition . ')'; } }
        $from = "FROM $r r JOIN $e e ON e.id=r.event_id LEFT JOIN (SELECT p.registration_id,SUM(t.credit) paid FROM $p p JOIN $t t ON t.payment_id=p.id GROUP BY p.registration_id) b ON b.registration_id=r.id WHERE " . implode(' AND ', $where);
        return [$from, $args];
    }
    private static function rows(array $f, int $limit, int $offset): array
    {
        global $wpdb; [$from, $args] = self::query($f);
        return $wpdb->get_results($wpdb->prepare('SELECT r.*,e.name event_name,COALESCE(b.paid,0) paid ' . $from . ' ORDER BY r.id DESC LIMIT %d OFFSET %d', array_merge($args, [$limit, $offset])), ARRAY_A) ?: [];
    }
    public static function dashboard(): void
    {
        echo '<div class="wrap ie-admin"><h1>Acompanhamento de inscrições</h1>'; self::notice();
        if (!empty($_GET['view'])) { self::detail(absint($_GET['view'])); echo '</div>'; return; }
        global $wpdb; $f = self::filters(); [$from, $args] = self::query($f);
        $sql = 'SELECT COUNT(*) count,COALESCE(SUM(r.total),0) total,COALESCE(SUM(COALESCE(b.paid,0)),0) paid,COALESCE(SUM(GREATEST(r.total-COALESCE(b.paid,0),0)),0) balance,COALESCE(SUM(GREATEST(COALESCE(b.paid,0)-r.total,0)),0) overpaid ' . $from;
        $metrics = $wpdb->get_row($args ? $wpdb->prepare($sql, $args) : $sql, ARRAY_A) ?: ['count' => 0, 'total' => 0, 'paid' => 0, 'balance' => 0, 'overpaid' => 0];
        echo '<div class="ie-metrics">';
        foreach (['count' => 'Inscrições', 'total' => 'Valor inscrito', 'paid' => 'Confirmado pelo K8', 'balance' => 'Saldo a receber', 'overpaid' => 'Pagamentos excedentes'] as $key => $label) { echo '<div class="ie-panel"><span>' . esc_html($label) . '</span><strong>' . esc_html($key === 'count' ? $metrics[$key] : Domain::money((int) $metrics[$key])) . '</strong></div>'; }
        echo '</div><form method="get" class="ie-filters"><input type="hidden" name="page" value="ie-dashboard"><select name="event"><option value="0">Todos os eventos</option>';
        foreach (Store::events() as $e) { echo '<option value="' . (int) $e['id'] . '" ' . selected($f['event'], (int) $e['id'], false) . '>' . esc_html($e['name']) . '</option>'; } echo '</select><select name="status">';
        foreach (['' => 'Todos os saldos', 'pending' => 'Sem pagamento', 'partial' => 'Pagamento parcial', 'paid' => 'Quitadas', 'overpaid' => 'Pagamento excedente'] as $v => $label) { echo '<option value="' . esc_attr($v) . '" ' . selected($f['status'], $v, false) . '>' . esc_html($label) . '</option>'; }
        echo '</select><select name="method">';
        foreach (['' => 'Todos os meios', 'Pix' => 'PIX', 'Cartao' => 'Cartão'] as $v => $label) { echo '<option value="' . esc_attr($v) . '" ' . selected($f['method'], $v, false) . '>' . esc_html($label) . '</option>'; }
        echo '</select><input name="q" placeholder="Nome, e-mail ou CPF completo" value="' . esc_attr($f['q']) . '"><label><input type="checkbox" name="all_scopes" value="1" ' . checked($f['all_scopes'], 1, false) . '> Incluir outras contas / ambientes</label><button class="button">Filtrar</button></form>';
        echo '<p>O filtro padrão usa a conta e o ambiente K8 configurados atualmente. “Confirmado” inclui cartão aprovado, mesmo quando o banco ainda liquidará parcelas.</p>';
        $page = max(1, absint($_GET['paged'] ?? 1)); $size = 25; $rows = self::rows($f, $size, ($page - 1) * $size);
        echo '<div class="ie-table-wrap"><table class="widefat striped"><thead><tr><th>Inscrição</th><th>Pessoa</th><th>Evento / lote</th><th>Forma</th><th>Total</th><th>Confirmado</th><th>Saldo</th><th>Situação</th><th></th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $b = Domain::balance((int) $row['total'], (int) $row['paid']);
            echo '<tr><td>#' . (int) $row['id'] . '</td><td><strong>' . esc_html($row['name']) . '</strong><br>' . esc_html($row['email']) . '</td><td>' . esc_html($row['event_name']) . '<br>' . esc_html($row['lot_name']) . '</td><td>' . ($row['method'] === 'Pix' ? 'PIX' : 'Cartão') . '</td>';
            foreach (['total', 'paid', 'balance'] as $key) { echo '<td>' . esc_html(Domain::money($b[$key])) . '</td>'; }
            echo '<td>' . esc_html($b['overpaid'] ? 'Excedente' : ['pending' => 'Sem pagamento', 'partial' => 'Parcial', 'paid' => 'Quitada'][$b['status']]) . '</td><td><a class="button" href="' . esc_url(admin_url('admin.php?page=ie-dashboard&view=' . $row['id'])) . '">Detalhes</a></td></tr>';
        }
        if (!$rows) { echo '<tr><td colspan="9">Nenhuma inscrição para estes filtros.</td></tr>'; }
        echo '</tbody></table></div><div class="ie-pagination">';
        $pages = (int) ceil((int) $metrics['count'] / $size);
        if ($page > 1) { echo '<a class="button" href="' . esc_url(add_query_arg(array_merge($f, ['page' => 'ie-dashboard', 'paged' => $page - 1]), admin_url('admin.php'))) . '">Anterior</a>'; }
        echo '<span>Página ' . $page . ' de ' . max(1, $pages) . '</span>';
        if ($page < $pages) { echo '<a class="button" href="' . esc_url(add_query_arg(array_merge($f, ['page' => 'ie-dashboard', 'paged' => $page + 1]), admin_url('admin.php'))) . '">Próxima</a>'; }
        echo '</div>'; self::form('ie_export', $f); echo '<button class="button">Exportar inscrições filtradas (CSV)</button></form>';
        $error = get_option('ie_cron_error'); if ($error) { echo '<div class="notice notice-warning inline"><p>Conciliação automática: ' . esc_html($error) . '</p></div>'; }
        echo '</div>';
    }
    private static function detail(int $id): void
    {
        try {
            $r = Store::registration($id); $b = Store::balance($r); $event = Store::event((int) $r['event_id']);
            echo '<p><a href="' . esc_url(admin_url('admin.php?page=ie-dashboard')) . '">← Inscrições</a></p><div class="ie-panel"><h2>' . esc_html($r['name']) . ' — #' . $id . '</h2><div class="ie-form-grid">';
            foreach (['CPF' => Security::unseal($r['cpf_encrypted']), 'E-mail' => $r['email'], 'Telefone' => $r['phone'], 'Evento' => $event['name'], 'Lote' => $r['lot_name'], 'Conta / ambiente (referência)' => substr($r['scope'], 0, 12), 'Forma' => $r['method'] === 'Pix' ? 'PIX' : 'Cartão até ' . $r['max_installments'] . 'x', 'Total' => Domain::money($b['total']), 'Confirmado' => Domain::money($b['paid']), 'Saldo' => Domain::money($b['balance']), 'Excedente' => Domain::money($b['overpaid']), 'Aceite (UTC)' => $r['terms_accepted_at']] as $label => $value) { echo '<div><b>' . esc_html($label) . '</b><p>' . esc_html($value) . '</p></div>'; }
            echo '</div><details><summary>Condições aceitas</summary><div>' . wp_kses_post(nl2br($r['terms_snapshot'])) . '</div></details></div><h2>Histórico de pagamentos</h2>';
            foreach (Store::payments($id) as $p) {
                echo '<div class="ie-panel"><h3>Tentativa #' . (int) $p['id'] . ' — ' . esc_html(Domain::money((int) $p['amount'])) . '</h3><p>Estado: <strong>' . esc_html($p['state']) . '</strong> · Crédito: ' . esc_html(Domain::money((int) $p['credit'])) . '</p><p>Referência: <code>' . esc_html($p['reference']) . '</code><br>Checkout K8: <code>' . esc_html($p['checkout_id'] ?? 'Não retornado') . '</code><br>Conferido em (UTC): ' . esc_html($p['synced_at'] ?? 'Ainda não conferido') . '</p>';
                if ($p['last_error']) { echo '<p class="ie-error">' . esc_html($p['last_error']) . '</p>'; }
                if ($p['operator_note']) { echo '<p>' . esc_html($p['operator_note']) . '</p>'; }
                self::form('ie_sync_payment', ['payment_id' => $p['id'], 'registration_id' => $id]);
                self::input('remote_id', 'ID da transação K8 (opcional; vazio consulta a referência)', '', 'text', 'pattern="[A-Za-z0-9-]{1,100}"');
                echo '<p><button class="button">Consultar e conciliar no K8</button></p></form><p>Uma tentativa em conferência não gera outra cobrança automaticamente. Localize a referência no K8 e informe o ID da transação acima.</p></div>';
                if (in_array($p['state'], ['review', 'creating', 'pending'], true)) {
                    echo '<details class="ie-panel"><summary>Recuperar tentativa após conferir no K8</summary><p>Para continuar um checkout já criado, copie seu identificador e sua URL. Para liberar uma nova tentativa, primeiro confirme no K8 que não há checkout ativo nem pagamento; desative qualquer link antigo. Pagamentos posteriores de tentativas antigas continuam sendo conciliados.</p>';
                    self::form('ie_recover_payment', ['payment_id' => $p['id'], 'registration_id' => $id]);
                    self::input('checkout_id', 'Identificador do checkout existente', ''); self::input('checkout_url', 'URL do checkout existente', '', 'url');
                    echo '<p><button class="button" name="mode" value="attach">Vincular checkout existente</button></p></form>';
                    self::form('ie_recover_payment', ['payment_id' => $p['id'], 'registration_id' => $id]);
                    self::check('confirmed', 'Conferi no K8: não há cobrança ativa nem pagamento nesta tentativa.', false);
                    echo '<p><button class="button ie-confirm" name="mode" value="close" data-confirm="A conferência no K8 foi concluída e os links antigos foram desativados?">Liberar nova tentativa</button></p></form></details>';
                }
            }
        } catch (\Throwable $e) { echo '<p>' . esc_html($e->getMessage()) . '</p>'; }
    }
    public static function syncPayment(): void
    {
        self::guard('ie_sync_payment'); $id = absint($_POST['registration_id'] ?? 0);
        try { $remote = trim((string) wp_unslash($_POST['remote_id'] ?? '')); Payments::sync(absint($_POST['payment_id'] ?? 0), $remote ?: null); self::redirect('ie-dashboard', 'Pagamento conciliado com a API K8.', false, ['view' => $id]); }
        catch (\Throwable $e) { self::redirect('ie-dashboard', $e->getMessage(), true, ['view' => $id]); }
    }
    private static function csv($value): string
    {
        $v = (string) $value; if (preg_match('/^[\s]*[=+@\-]/u', $v)) { $v = "'" . $v; } return $v;
    }
    public static function recoverPayment(): void
    {
        self::guard('ie_recover_payment'); $id = absint($_POST['registration_id'] ?? 0);
        try {
            $close = ($_POST['mode'] ?? '') === 'close';
            if ($close && empty($_POST['confirmed'])) { throw new \RuntimeException('Confirme a conferência no K8 antes de liberar a tentativa.'); }
            Payments::recover(absint($_POST['payment_id'] ?? 0), trim((string) wp_unslash($_POST['checkout_id'] ?? '')), trim((string) wp_unslash($_POST['checkout_url'] ?? '')), $close);
            self::redirect('ie-dashboard', 'Tentativa recuperada. Créditos financeiros continuam sujeitos à confirmação K8.', false, ['view' => $id]);
        } catch (\Throwable $e) { self::redirect('ie-dashboard', $e->getMessage(), true, ['view' => $id]); }
    }
    public static function export(): void
    {
        self::guard('ie_export'); $f = self::filters(); nocache_headers();
        header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="inscricoes-' . gmdate('Y-m-d') . '.csv"');
        $stream = fopen('php://output', 'w'); fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, ['Inscrição', 'Evento', 'Lote', 'Nome', 'E-mail', 'Telefone', 'CPF', 'Forma', 'Valor', 'Confirmado', 'Saldo', 'Excedente', 'Conta/Ambiente', 'Cadastro UTC'], ';', '"', '');
        $offset = 0;
        do {
            $rows = self::rows($f, 250, $offset);
            foreach ($rows as $r) {
                $b = Domain::balance((int) $r['total'], (int) $r['paid']); $values = [$r['id'], $r['event_name'], $r['lot_name'], $r['name'], $r['email'], $r['phone'], Security::unseal($r['cpf_encrypted']), $r['method']];
                foreach (['total', 'paid', 'balance', 'overpaid'] as $key) { $values[] = number_format($b[$key] / 100, 2, ',', ''); }
                $values[] = substr($r['scope'], 0, 12); $values[] = $r['created_at']; fputcsv($stream, array_map([self::class, 'csv'], $values), ';', '"', '');
            }
            $offset += count($rows);
        } while (count($rows) === 250);
        fclose($stream); exit;
    }
}
