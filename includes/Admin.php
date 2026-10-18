<?php
namespace InscricaoEventos;
if (!defined('ABSPATH')) { exit; }

final class Admin
{
    public static function boot(): void
    {
        add_action('admin_menu', static function () {
            add_menu_page('Inscrições de eventos', 'Inscrições', Access::canManage() ? 'manage_options' : Access::VIEW, 'ie-dashboard', [self::class, 'dashboard'], 'dashicons-tickets-alt', 30);
            add_submenu_page('ie-dashboard', 'Inscrições', 'Acompanhamento', Access::canManage() ? 'manage_options' : Access::VIEW, 'ie-dashboard', [self::class, 'dashboard']);
            add_submenu_page('ie-dashboard', 'Eventos e lotes', 'Eventos e lotes', Access::canManage() ? 'manage_options' : Access::VIEW, 'ie-events', [self::class, 'events']);
            if (Access::canManage()) add_submenu_page('ie-dashboard', 'Configurações', 'Configurações K8', 'manage_options', 'ie-settings', [self::class, 'settings']);
        });
        add_action('admin_post_ie_save_event', [self::class, 'saveEvent']);
        add_action('admin_post_ie_clone_event', [self::class, 'cloneEvent']);
        add_action('admin_post_ie_delete_event', [self::class, 'deleteEvent']);
        add_action('admin_post_ie_save_settings', [self::class, 'saveSettings']);
        add_action('admin_post_ie_test_k8', [self::class, 'testK8']);
        add_action('admin_post_ie_sync_payment', [self::class, 'syncPayment']);
        add_action('admin_post_ie_recover_payment', [self::class, 'recoverPayment']);
        add_action('admin_post_ie_save_registration', [self::class, 'saveRegistration']);
        add_action('admin_post_ie_registration_action', [self::class, 'registrationAction']);
        add_action('admin_post_ie_cancel_payment', [self::class, 'cancelPayment']);
        add_action('admin_post_ie_review_cancellation', [self::class, 'reviewCancellation']);
        add_action('admin_post_ie_manual_payment', [self::class, 'manualPayment']);
        add_action('admin_post_ie_link_manual_k8', [self::class, 'linkManualK8']);
        add_action('admin_post_ie_reverse_manual', [self::class, 'reverseManual']);
        add_action('admin_post_ie_export', [self::class, 'export']);
        add_action('admin_enqueue_scripts', static function ($hook) {
            if (strpos($hook, 'ie-') === false) { return; }
            if (Access::canManage()) { wp_enqueue_media(); }
            wp_enqueue_style('ie-admin', plugins_url('assets/admin.css', IE_FILE), [], IE_VERSION);
            wp_enqueue_script('ie-admin', plugins_url('assets/admin.js', IE_FILE), [], IE_VERSION, true);
        });
        add_filter('auto_update_plugin', static function ($update, $item) {
            if (($item->plugin ?? '') === plugin_basename(IE_FILE)) { return (bool) (get_option('ie_settings', [])['auto_update'] ?? true); }
            return $update;
        }, 10, 2);
    }
    private static function guard(string $action, bool $readOnly = false): void
    {
        if ($readOnly) { Access::requireView(); } else { Access::requireManage(); }
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { wp_die('Esta ação exige envio pelo formulário.'); }
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
        Access::requireView();
        if (!Access::canManage()) { self::eventsReadOnly(); return; }
        echo '<div class="wrap ie-admin"><h1>Eventos e lotes</h1>'; self::notice();
        $edit = isset($_GET['edit']); $id = absint($_GET['edit'] ?? 0);
        if (!$edit) {
            echo '<p><a class="button button-primary" href="' . esc_url(admin_url('admin.php?page=ie-events&edit=0')) . '">Novo evento</a></p><div class="ie-event-grid">';
            foreach (Store::events() as $e) {
                echo '<article class="ie-panel"><span class="ie-badge">' . esc_html(['draft' => 'Rascunho', 'published' => 'Publicado', 'archived' => 'Arquivado'][$e['status']] ?? $e['status']) . '</span><h2>' . esc_html($e['name']) . '</h2><p>' . esc_html(Domain::dateBR($e['event_date'])) . '</p><p>Inscrições: ' . esc_html(Domain::dateBR($e['registration_start']) . ' a ' . Domain::dateBR($e['registration_end'])) . '</p><code>[inscricao_eventos evento="' . (int) $e['id'] . '"]</code><p><code>[inscricao_eventos_dashboard evento="' . (int) $e['id'] . '"]</code></p><p><a class="button" href="' . esc_url(admin_url('admin.php?page=ie-events&edit=' . $e['id'])) . '">Editar evento</a> ';
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
        self::check('allow_pix', 'PIX online — entrada e complementos pelo K8', (bool) $e['allow_pix']);
        self::check('allow_card', 'Cartão online — checkout K8', (bool) $e['allow_card']);
        self::check('allow_card_physical', 'Cartão físico — maquininha na secretaria e baixa manual', !empty($e['allow_card_physical']));
        self::input('minimum_entry', 'Entrada mínima por PIX (R$)', $id ? number_format((int) $e['minimum_entry'] / 100, 2, '.', '') : '1.00', 'number', 'min="0.01" step="0.01" required');
        self::textarea('payment_message', 'Mensagem sobre quitação — use {data}', $e['payment_message']);
        self::textarea('physical_card_message', 'Orientação para pagamento no cartão físico', Domain::physicalMessage($e));
        echo '<p class="ie-full">Habilite as formas desejadas para este evento. Cartão físico registra a inscrição e orienta procurar a secretaria; o recebimento só é confirmado por baixa manual de um administrador.</p>';
        echo '</div><p>O valor e o parcelamento do lote são guardados na inscrição. Edições futuras dos lotes não alteram inscrições existentes. As datas incluem o dia inteiro no fuso escolhido.</p></div>';
        echo '<div class="ie-panel"><h2>Lotes</h2><p>Cadastre períodos sem sobreposição. O limite de parcelas do cartão deve estar entre 1 e 12.</p><div id="ie-lots">';
        foreach ($lots as $i => $l) { self::lot($i, $l); }
        if (!$lots) { self::lot(0, []); }
        echo '</div><button type="button" class="button" id="ie-add-lot">Adicionar lote</button><template id="ie-lot-template">'; self::lot('__INDEX__', []); echo '</template></div>';
        echo '<div class="ie-panel"><h2>Identidade visual</h2><div class="ie-form-grid">';
        echo '<label class="ie-field"><span>Identidade do evento</span><select name="brand_preset"><option value="custom" ' . selected($e['brand_preset'] ?? 'custom', 'custom', false) . '>Personalizada</option><option value="origens" ' . selected($e['brand_preset'] ?? '', 'origens', false) . '>Origens — azul e preto</option></select></label><p>Origens inclui a capa sem QR Code. Datas e lote são exibidos dinamicamente. Você pode substituir a capa e editar as cores abaixo.</p>';
        foreach (['logo_url' => 'Logo', 'hero_url' => 'Imagem de capa'] as $key => $label) { echo '<div>'; self::input($key, $label . ' — URL', $e[$key] ?? '', 'url'); echo '<button type="button" class="button ie-media" data-field="' . esc_attr($key) . '">Escolher imagem</button></div>'; }
        self::input('primary_color', 'Cor principal', $e['primary_color'] ?? '#215c4a', 'color');
        self::input('accent_color', 'Cor de destaque', $e['accent_color'] ?? '#d2a45c', 'color');
        self::input('background_color', 'Cor de fundo', $e['background_color'] ?? '#f6f5f0', 'color');
        echo '</div></div><div class="ie-panel"><h2>Condições de inscrição</h2><div class="ie-form-grid">';
        self::textarea('terms', 'Condições e política do evento', $e['terms'] ?? '');
        self::input('privacy_url', 'URL da política de privacidade', $e['privacy_url'] ?? '', 'url');
        echo '</div><p>Na consulta por CPF são exibidos primeiro nome, lote e informações de pagamento. E-mail, telefone e CPF completo ficam no painel administrativo.</p></div><p><button class="button button-primary button-hero">Salvar evento</button></p></form>';
        if ($id) {
            echo '<div class="ie-panel ie-danger"><h2>Excluir evento permanentemente</h2><p>Remove este evento, todos os lotes, inscrições, pagamentos, créditos locais, sessões e histórico administrativo, incluindo outras contas/ambientes. A página WordPress permanece. Esta ação não cancela nem estorna transações no K8.</p>';
            self::form('ie_delete_event', ['event_id' => $id]);
            self::input('confirmation', 'Digite EXCLUIR EVENTO ' . $id . ' para confirmar', '', 'text', 'required autocomplete="off" pattern="EXCLUIR EVENTO ' . $id . '"');
            echo '<p><button class="button ie-confirm" data-confirm="Excluir permanentemente este evento e TODOS os registros vinculados? Esta ação não pode ser desfeita e não altera transações no K8.">Excluir evento permanentemente</button></p></form></div>';
        }
        echo '</div>';
    }
    private static function eventsReadOnly(): void
    {
        echo '<div class="wrap ie-admin"><h1>Eventos e lotes</h1><p class="ie-readonly">Gerente de Eventos · acesso somente para consulta.</p><div class="ie-event-grid">';
        foreach (Store::events() as $e) {
            echo '<article class="ie-panel"><h2>' . esc_html($e['name']) . '</h2><p>' . esc_html(['draft' => 'Rascunho', 'published' => 'Publicado', 'archived' => 'Arquivado'][$e['status']] ?? $e['status']) . '</p><p>Evento: ' . esc_html(Domain::dateBR($e['event_date'])) . '<br>Local: ' . esc_html($e['location'] ?? '') . '<br>Inscrições: ' . esc_html(Domain::dateBR($e['registration_start']) . ' a ' . Domain::dateBR($e['registration_end'])) . '<br>Quitação: ' . esc_html(Domain::dateBR($e['payment_deadline'])) . '</p><ul>';
            foreach (Store::lots((int) $e['id']) as $l) { echo '<li><b>' . esc_html($l['name']) . '</b>: ' . esc_html(Domain::money((int) $l['price']) . ' · até ' . $l['max_installments'] . 'x · ' . Domain::dateBR($l['starts_on']) . ' a ' . Domain::dateBR($l['ends_on'])) . '</li>'; }
            echo '</ul><p><a class="button" href="' . esc_url(admin_url('admin.php?page=ie-dashboard&event=' . (int) $e['id'])) . '">Consultar inscrições</a></p></article>';
        }
        echo '</div></div>';
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
        self::guard('ie_delete_event'); $id = absint($_POST['event_id'] ?? 0);
        try {
            self::confirmDeletion('EXCLUIR EVENTO ' . $id); Registrations::purgeEvent($id);
            self::redirect('ie-events', 'Evento e todos os registros vinculados excluídos permanentemente do WordPress.');
        } catch (\Throwable $e) { self::redirect('ie-events', $e->getMessage(), true, ['edit' => $id]); }
    }
    public static function settings(): void
    {
        Access::requireManage();
        $s = (array) get_option('ie_settings', []);
        echo '<div class="wrap ie-admin"><h1>Configurações K8</h1>'; self::notice();
        echo '<div class="ie-panel"><h2>Integração: K8 Pay / Pagamentos</h2><p>Use as credenciais da <strong>API K8 Pay (PagamentosAPI)</strong>. O checkout hospedado recebe os dados do cartão e oferece o parcelamento do lote.</p><p>API deste plugin: <code>' . esc_html(($s['environment'] ?? 'sandbox') === 'production' ? 'https://pagamentos.meuk8.com.br/PagamentosAPI' : 'https://sandbox.meuk8.com.br/PagamentosAPI') . '</code></p>';
        echo '<p><strong>Ambiente atual: ' . (($s['environment'] ?? 'sandbox') === 'production' ? 'Produção' : 'Sandbox (testes)') . '.</strong> Se você gerou as chaves no painel de produção, selecione Produção abaixo. Credenciais do K8 Cobranças e do K8 Pagamentos devem ser conferidas no respectivo painel.</p>';
        self::form('ie_save_settings'); echo '<div class="ie-form-grid"><label class="ie-field"><span>Ambiente</span><select name="environment"><option value="sandbox" ' . selected($s['environment'] ?? 'sandbox', 'sandbox', false) . '>Testes (sandbox)</option><option value="production" ' . selected($s['environment'] ?? '', 'production', false) . '>Produção</option></select></label>';
        foreach (['client_id' => 'Client ID', 'client_secret' => 'Client Secret', 'aes_key' => 'Chave AES (16 caracteres)'] as $key => $label) { self::input($key, $label, '', 'password', 'autocomplete="new-password"'); }
        self::check('auto_update', 'Instalar automaticamente novas versões do GitHub', (bool) ($s['auto_update'] ?? true));
        echo '</div><p>' . (empty($s['credentials']) ? 'Credenciais ainda não cadastradas.' : 'Credenciais protegidas já cadastradas. Deixe os três campos vazios para mantê-las.') . ' Ao substituir credenciais, preencha os três campos. Testes e produção possuem inscrições e pagamentos separados.</p><button class="button button-primary">Salvar configurações</button></form></div><div class="ie-panel">';
        self::form('ie_test_k8'); echo '<button class="button">Testar autenticação K8</button><p>O teste utiliza as configurações já salvas e faz uma nova autenticação, sem reutilizar token em cache. Salve alterações antes de testar. Se houver HTTP 400, será mostrado o motivo devolvido pelo K8, sem exibir suas chaves.</p><p>A homologação do checkout exige uma inscrição de teste no sandbox.</p></form></div><div class="ie-panel"><h2>Como colocar no site</h2><p>Use <code>[inscricao_eventos evento="1"]</code> em uma página. Sem o atributo evento, o visitante pode escolher entre os eventos publicados.</p><p>Dashboard privado: <code>[inscricao_eventos_dashboard evento="1"]</code>. Somente administradores conectados acessam os dados. Sem o atributo evento, o painel permite escolher o evento.</p><p>Configure essa página no cadastro do evento para receber o retorno do K8. O endereço do webhook é enviado automaticamente em cada checkout.</p><p>Atualizações: <a href="https://github.com/paulommaeda/inscricao-eventos" target="_blank" rel="noopener">paulommaeda/inscricao-eventos</a>, branch <code>main</code>. O número da versão deve aumentar em cada entrega.</p><p>A conciliação usa WP-Cron a cada cinco minutos. Em sites com pouco tráfego, configure um cron do servidor para chamar <code>wp-cron.php</code> regularmente.</p></div></div>';
    }
    public static function saveSettings(): void
    {
        self::guard('ie_save_settings');
        try {
            $s = (array) get_option('ie_settings', []); $input = wp_unslash($_POST);
            $id = Gateway::cleanCredential((string) ($input['client_id'] ?? '')); $secret = Gateway::cleanCredential((string) ($input['client_secret'] ?? '')); $aes = Gateway::cleanCredential((string) ($input['aes_key'] ?? ''));
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
        try { (new Gateway())->authenticate(true); self::redirect('ie-settings', 'Autenticação K8 confirmada. Faça uma inscrição de teste para homologar o checkout e a baixa.'); }
        catch (\Throwable $e) { self::redirect('ie-settings', $e->getMessage(), true); }
    }
    private static function filters(): array
    {
        return Reports::filters((array) wp_unslash(array_merge($_GET, $_POST)));
    }
    private static function rows(array $f, int $limit, int $offset): array { return Reports::rows($f, $limit, $offset); }
    public static function dashboard(): void
    {
        Access::requireView();
        if (!empty($_GET['edit_registration'])) { Access::requireManage(); }
        echo '<div class="wrap ie-admin"><h1>Acompanhamento de inscrições</h1>'; self::notice();
        if (!empty($_GET['edit_registration'])) { self::editRegistration(absint($_GET['edit_registration'])); echo '</div>'; return; }
        if (!empty($_GET['view'])) { self::detail(absint($_GET['view'])); echo '</div>'; return; }
        try { $f = self::filters(); $metrics = Reports::metrics($f); }
        catch (\Throwable $e) { echo '<p class="ie-error">' . esc_html($e->getMessage()) . '</p></div>'; return; }
        echo '<div class="ie-metrics">';
        foreach (['count' => 'Inscrições', 'total' => 'Valor inscrito', 'paid' => 'Recebido (K8 + manual)', 'balance' => 'Saldo a receber', 'overpaid' => 'Pagamentos excedentes'] as $key => $label) { echo '<div class="ie-panel"><span>' . esc_html($label) . '</span><strong>' . esc_html($key === 'count' ? $metrics[$key] : Domain::money((int) $metrics[$key])) . '</strong></div>'; }
        echo '</div><form method="get" class="ie-filters"><input type="hidden" name="page" value="ie-dashboard"><select name="event"><option value="0">Todos os eventos</option>';
        foreach (Store::events() as $e) { echo '<option value="' . (int) $e['id'] . '" ' . selected($f['event'], (int) $e['id'], false) . '>' . esc_html($e['name']) . '</option>'; } echo '</select><select name="status">';
        foreach (['' => 'Todos os saldos', 'pending' => 'Sem pagamento', 'partial' => 'Pagamento parcial', 'paid' => 'Quitadas', 'overpaid' => 'Pagamento excedente'] as $v => $label) { echo '<option value="' . esc_attr($v) . '" ' . selected($f['status'], $v, false) . '>' . esc_html($label) . '</option>'; }
        echo '</select><select name="method">';
        foreach (['' => 'Todos os meios', 'Pix' => 'PIX', 'Cartao' => 'Cartão online', Domain::PHYSICAL_CARD => 'Cartão físico'] as $v => $label) { echo '<option value="' . esc_attr($v) . '" ' . selected($f['method'], $v, false) . '>' . esc_html($label) . '</option>'; }
        echo '</select><select name="registration_status">';
        foreach (['active' => 'Inscrições ativas', 'cancelled' => 'Inscrições canceladas', 'deleted' => 'Lixeira', 'all' => 'Todas as inscrições'] as $v => $label) { echo '<option value="' . esc_attr($v) . '" ' . selected($f['registration_status'], $v, false) . '>' . esc_html($label) . '</option>'; }
        echo '</select><input name="q" placeholder="Nome, e-mail ou CPF completo" value="' . esc_attr($f['q']) . '"><label><input type="checkbox" name="all_scopes" value="1" ' . checked($f['all_scopes'], 1, false) . '> Incluir outras contas / ambientes</label><button class="button">Filtrar</button></form>';
        echo '<p>O filtro padrão usa a conta e o ambiente K8 configurados atualmente. “Recebido” soma créditos K8 e baixas manuais ativas, sem duplicar tentativas conciliadas. Cartão aprovado entra integralmente.</p>';
        $page = max(1, absint($_GET['paged'] ?? 1)); $size = 25; $rows = self::rows($f, $size, ($page - 1) * $size);
        echo '<div class="ie-table-wrap"><table class="widefat striped"><thead><tr><th>Inscrição</th><th>Pessoa</th><th>Evento / lote</th><th>Forma do cadastro</th><th>Total</th><th>Recebido</th><th>Saldo</th><th>Situação</th><th></th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $b = Domain::balance((int) $row['total'], (int) $row['paid']);
            echo '<tr><td>#' . (int) $row['id'] . '</td><td><strong>' . esc_html($row['name']) . '</strong><br>' . esc_html($row['email']) . '</td><td>' . esc_html($row['event_name']) . '<br>' . esc_html($row['lot_name']) . '</td><td>' . esc_html(Domain::methodLabel($row['method'])) . '</td>';
            foreach (['total', 'paid', 'balance'] as $key) { echo '<td>' . esc_html(Domain::money($b[$key])) . '</td>'; }
            echo '<td>' . esc_html($b['overpaid'] ? 'Excedente' : ['pending' => 'Sem pagamento', 'partial' => 'Parcial', 'paid' => 'Quitada'][$b['status']]) . '<br><small>' . esc_html(['active' => 'Ativa', 'cancelled' => 'Cancelada', 'deleted' => 'Na lixeira'][$row['status'] ?? 'active'] ?? '') . '</small></td><td><div class="ie-row-actions"><a class="button" href="' . esc_url(admin_url('admin.php?page=ie-dashboard&view=' . $row['id'])) . '">Detalhes / ações</a>';
            if (Access::canManage() && ($row['status'] ?? 'active') !== 'deleted') { echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=ie-dashboard&edit_registration=' . $row['id'])) . '">Editar</a>'; }
            echo '</div></td></tr>';
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
            if (Access::canManage()) { self::registrationButtons($r); } else { echo '<p class="ie-readonly">Perfil Gerente de Eventos · somente consulta</p>'; }
            echo '<p><a href="' . esc_url(admin_url('admin.php?page=ie-dashboard')) . '">← Inscrições</a></p><div class="ie-panel"><h2>' . esc_html($r['name']) . ' — #' . $id . '</h2><div class="ie-form-grid">';
            foreach (['CPF' => Security::unseal($r['cpf_encrypted']), 'E-mail' => $r['email'], 'Telefone' => $r['phone'], 'Evento' => $event['name'], 'Lote' => $r['lot_name'], 'Conta / ambiente (referência)' => substr($r['scope'], 0, 12), 'Forma do cadastro' => Domain::methodLabel($r['method']), 'Limite original do lote' => $r['max_installments'] . 'x', 'Cartão para quitar hoje' => Domain::installments($r, $event)['max'] . 'x', 'Total' => Domain::money($b['total']), 'Recebido' => Domain::money($b['paid']), 'Confirmado no K8' => Domain::money($b['gateway_paid']), 'Baixa manual ativa' => Domain::money($b['manual_paid']), 'Saldo' => Domain::money($b['balance']), 'Excedente' => Domain::money($b['overpaid']), 'Aceite (UTC)' => $r['terms_accepted_at']] as $label => $value) { echo '<div><b>' . esc_html($label) . '</b><p>' . esc_html($value) . '</p></div>'; }
            echo '</div><details><summary>Condições aceitas</summary><div>' . wp_kses_post(nl2br($r['terms_snapshot'])) . '</div></details></div>';
            echo '<h2>Histórico de pagamentos K8</h2><p>Para reconhecer um pagamento K8 com falha de sincronização, use <strong>Dar baixa manual da transação K8</strong> na cobrança correspondente. Recebimentos externos ficam ao final da página.</p>';
            foreach (Store::payments($id) as $p) {
                echo '<div class="ie-panel"><h3>Tentativa #' . (int) $p['id'] . ' — ' . esc_html(Domain::money((int) $p['amount'])) . '</h3>' . ($p['method'] === 'Cartao' && isset($p['max_installments']) ? '<p>Limite de parcelas deste checkout: <strong>' . (int) $p['max_installments'] . 'x</strong>.</p>' : '') . '<p>Estado: <strong>' . esc_html(['pending' => 'Aguardando pagamento/retorno', 'creating' => 'Criando checkout', 'review' => 'Em conferência', 'paid' => 'Confirmado', 'closed' => 'Encerrado', 'canceling' => 'Cancelamento / estorno em conferência'][$p['state']] ?? $p['state']) . '</strong> · Crédito: ' . esc_html(Domain::money((int) $p['credit'])) . '</p><p>Referência: <code>' . esc_html($p['reference']) . '</code><br>Checkout K8: <code>' . esc_html($p['checkout_id'] ?? 'Não retornado') . '</code><br>Conferido em (UTC): ' . esc_html($p['synced_at'] ?? 'Ainda não conferido') . '</p>';
                echo '<p>Crédito confirmado K8: <strong>' . esc_html(Domain::money((int) $p['gateway_credit'])) . '</strong> · Crédito manual ativo: <strong>' . esc_html(Domain::money((int) $p['manual_credit'])) . '</strong>.</p>';
                if ((int) $p['manual_credit'] > 0) { echo '<p class="ie-k8-manual-status"><strong>Baixada manualmente · aguardando sincronização K8</strong><br>O saldo já considera este recebimento. A confirmação posterior substituirá o crédito manual, sem somar novamente.</p>'; }
                if ($p['last_error']) { echo '<p class="ie-error">' . esc_html($p['last_error']) . '</p>'; }
                echo '<p>Último retorno K8 (UTC): ' . esc_html($p['webhook_at'] ?? 'Ainda não recebido') . '<br>Identificador para conciliação: <code>' . esc_html($p['remote_id_hint'] ?? '') . '</code></p>';
                if (!empty($p['webhook_error'])) { echo '<p class="ie-error">Falha no retorno: ' . esc_html($p['webhook_error']) . '</p>'; }
                if ($p['operator_note']) { echo '<p>' . esc_html($p['operator_note']) . '</p>'; }
                if (Access::canManage()) {
                    self::manualForm($r, $p, $b);
                    self::form('ie_sync_payment', ['payment_id' => $p['id'], 'registration_id' => $id]);
                    self::input('remote_id', 'ID da transação K8 (opcional; vazio usa o ID conhecido ou a referência)', '', 'text', 'pattern="[A-Za-z0-9-]{1,100}"');
                    echo '<p><button class="button">Consultar e conciliar no K8</button></p></form><p>Uma tentativa em conferência não gera outra cobrança automaticamente. Localize a referência no K8 e informe o ID da transação acima.</p>';
                }
                self::diagnostic($p); if (Access::canManage()) { self::cancelForm($p, $r); } echo '</div>';
                if (Access::canManage() && in_array($p['state'], ['review', 'creating', 'pending'], true)) {
                    echo '<details class="ie-panel"><summary>Recuperar tentativa após conferir no K8</summary><p>Para continuar um checkout já criado, copie seu identificador e sua URL. Para liberar uma nova tentativa, primeiro confirme no K8 que não há checkout ativo nem pagamento; desative qualquer link antigo. Pagamentos posteriores de tentativas antigas continuam sendo conciliados.</p>';
                    self::form('ie_recover_payment', ['payment_id' => $p['id'], 'registration_id' => $id]);
                    self::input('checkout_id', 'Identificador do checkout existente', ''); self::input('checkout_url', 'URL do checkout existente', '', 'url');
                    echo '<p><button class="button" name="mode" value="attach">Vincular checkout existente</button></p></form>';
                    self::form('ie_recover_payment', ['payment_id' => $p['id'], 'registration_id' => $id]);
                    self::check('confirmed', 'Conferi no K8: não há cobrança ativa nem pagamento nesta tentativa.', false);
                    echo '<p><button class="button ie-confirm" name="mode" value="close" data-confirm="A conferência no K8 foi concluída e os links antigos foram desativados?">Liberar nova tentativa</button></p></form></details>';
                }
            }
            self::manualHistory($r, $b);
            $audit = Store::auditRows($id);
            if ($audit) { echo '<details class="ie-panel"><summary>Histórico administrativo</summary><div class="ie-table-wrap"><table class="widefat"><thead><tr><th>Data UTC</th><th>Usuário WP</th><th>Ação</th><th>Registro</th></tr></thead><tbody>'; foreach ($audit as $a) { echo '<tr><td>' . esc_html($a['created_at']) . '</td><td>#' . (int) $a['actor_id'] . '</td><td>' . esc_html($a['action']) . '</td><td class="ie-wrap-cell">' . esc_html($a['note']) . '</td></tr>'; } echo '</tbody></table></div></details>'; }
        } catch (\Throwable $e) { echo '<p>' . esc_html($e->getMessage()) . '</p>'; }
    }
    private static function registrationButtons(array $r): void
    {
        $id = (int) $r['id']; $deleted = ($r['status'] ?? 'active') === 'deleted';
        echo '<div class="ie-registration-actions"><span class="ie-badge">' . esc_html(['active' => 'Ativa', 'cancelled' => 'Cancelada', 'deleted' => 'Na lixeira'][$r['status'] ?? 'active'] ?? '') . '</span>';
        if (!$deleted) { echo '<a class="button button-primary" href="' . esc_url(admin_url('admin.php?page=ie-dashboard&edit_registration=' . $id)) . '">Editar inscrição</a>'; }
        self::form('ie_registration_action', ['registration_id' => $id, 'mode' => $deleted ? 'restore' : 'trash']);
        echo '<button class="button ' . ($deleted ? '' : 'ie-confirm') . '" data-confirm="Mover esta inscrição para a lixeira e bloquear novos pagamentos? O histórico será preservado.">' . ($deleted ? 'Restaurar inscrição' : 'Excluir inscrição') . '</button></form>';
        echo '</div>';
        if ($deleted) { echo '<p>Esta inscrição está na lixeira. Os registros K8 continuam disponíveis para conciliação, cancelamento e estorno.</p>'; }
        echo '<details class="ie-panel ie-danger"><summary>Excluir inscrição permanentemente</summary><p>Remove o cadastro, todas as tentativas, créditos locais, sessões e histórico desta inscrição. Não pode ser desfeito. Esta ação não cancela nem estorna pagamentos no K8.</p>';
        self::form('ie_registration_action', ['registration_id' => $id, 'mode' => 'purge']);
        self::input('confirmation', 'Digite EXCLUIR ' . $id . ' para confirmar', '', 'text', 'required autocomplete="off" pattern="EXCLUIR ' . $id . '"');
        echo '<p><button class="button ie-confirm" data-confirm="Excluir permanentemente esta inscrição e todo seu histórico local? Esta ação não pode ser desfeita e não altera transações no K8.">Excluir definitivamente</button></p></form></details>';
    }
    private static function manualForm(array $r, ?array $p, array $balance): void
    {
        if (!Access::canManage()) { return; }
        if (($r['status'] ?? 'active') !== 'active') { if ($p) { echo '<p class="ie-manual-unavailable">Ative/restaure a inscrição para dar baixa manual nesta cobrança K8.</p>'; } return; }
        $paymentId = $p ? (int) $p['id'] : 0; $max = $balance['balance']; $remoteId = ''; $candidates = [];
        if ($p) {
            $rows = Store::manuals((int) $r['id']); $transactions = Store::transactions($paymentId);
            $remoteId = (string) ($p['remote_id_hint'] ?? ''); if (!$remoteId && count($transactions) === 1) { $remoteId = $transactions[0]['remote_id']; }
            if ($remoteId !== '') { try { $remoteId = Domain::remoteId($remoteId); } catch (\Throwable $e) { $remoteId = ''; } }
            $block = Manual::k8Block($p, $rows, $transactions, $remoteId);
            if ($block !== '') { echo '<p class="ie-manual-unavailable">Baixa manual K8: ' . esc_html($block) . '</p>'; return; }
            if (!(int) $p['gateway_credit']) {
                foreach ($rows as $m) { if (!(int) $m['payment_id'] && Manual::active($m) && $m['method'] === $p['method'] && (int) $m['amount'] <= (int) $p['amount']) { $candidates[] = $m; } }
            }
            $max = min((int) $p['amount'], $max + (int) $p['gateway_credit']);
            if (!$max && !$candidates) { echo '<p class="ie-manual-unavailable">Baixa manual K8: a inscrição está sem saldo a receber. Confira os recebimentos já registrados no histórico.</p>'; return; }
        } elseif (!$max) { return; }
        $e = Store::event((int) $r['event_id']);
        echo '<details class="ie-manual-panel' . ($p ? ' ie-k8-manual' : '') . '"' . ($p ? ' id="ie-k8-manual-' . $paymentId . '"' . (!empty($p['last_error']) || !empty($p['webhook_error']) ? ' open' : '') : '') . '><summary>' . ($p ? 'Dar baixa manual da transação K8' : 'Registrar recebimento fora do K8') . '</summary><p>' . ($p ? 'Use quando o pagamento desta cobrança foi recebido no K8, mas a sincronização falhou. A baixa atualiza o saldo no WordPress sem depender de uma consulta K8. Confira o comprovante e informe o ID da transação, se disponível. O estado final verificado pela API substituirá a baixa provisória, inclusive em rejeição/estorno.' : 'Registre um pagamento efetivamente recebido fora desta integração. Ele será identificado como baixa manual. Para um pagamento feito no K8 com retorno atrasado, use a baixa da respectiva cobrança no histórico K8 acima.') . '</p>';
        if ($p && $candidates) {
            echo '<h4>Já registrou este mesmo pagamento como baixa manual?</h4><p>Vincule o registro existente a esta cobrança K8 para evitar duplicidade quando o retorno chegar. O valor e o saldo permanecem iguais.</p>';
            self::form('ie_link_manual_k8', ['registration_id' => $r['id'], 'payment_id' => $paymentId]); echo '<div class="ie-form-grid"><label class="ie-field"><span>Baixa já registrada</span><select name="manual_id" required>';
            foreach ($candidates as $m) { echo '<option value="' . (int) $m['id'] . '">#' . (int) $m['id'] . ' · ' . esc_html(Domain::money((int) $m['amount']) . ' · ' . Domain::dateBR($m['paid_on'])) . '</option>'; }
            echo '</select></label>'; self::input('remote_id', 'ID da transação K8 (opcional)', $remoteId, 'text', 'pattern="[A-Za-z0-9-]{1,100}" maxlength="100"'); self::input('note', 'Motivo / comprovante do vínculo', '', 'text', 'minlength="3" maxlength="1000" required'); echo '</div>';
            self::check('confirmed', 'Conferi que esta baixa e esta transação K8 representam o mesmo pagamento.', false);
            echo '<p><button class="button ie-confirm" data-confirm="Vincular a baixa existente a esta cobrança, sem adicionar crédito?">Vincular baixa existente ao K8</button></p></form>';
        }
        if (!$max) { echo '</details>'; return; }
        if ($p && $candidates) { echo '<h4>Registrar outro recebimento desta cobrança</h4><p>Use somente se este pagamento ainda não foi lançado acima.</p>'; }
        self::form('ie_manual_payment', ['registration_id' => $r['id'], 'payment_id' => $paymentId, 'request_token' => bin2hex(random_bytes(32))]); echo '<div class="ie-form-grid">';
        self::input('amount', $p ? 'Valor pago total nesta cobrança K8 (R$)' : 'Valor recebido (R$)', number_format($max / 100, 2, '.', ''), 'number', 'min="0.01" max="' . number_format($max / 100, 2, '.', '') . '" step="0.01" required');
        self::input('paid_on', 'Data do pagamento', Domain::today($e), 'date', 'max="' . Domain::today($e) . '" required');
        if ($p) { self::input('remote_id', 'ID da transação K8 (opcional)', $remoteId, 'text', 'pattern="[A-Za-z0-9-]{1,100}" maxlength="100"'); echo '<div><b>Meio da cobrança</b><p>' . esc_html(Manual::METHODS[$p['method']] ?? $p['method']) . '</p></div>'; }
        else { $preferred = $r['method'] === Domain::PHYSICAL_CARD || ($r['method'] === 'Cartao' && empty($e['allow_card']) && !empty($e['allow_card_physical'])) ? Domain::PHYSICAL_CARD : $r['method']; echo '<label class="ie-field"><span>Meio do recebimento</span><select name="method">'; foreach (Manual::METHODS as $value => $label) { echo '<option value="' . esc_attr($value) . '" ' . selected($preferred, $value, false) . '>' . esc_html($label) . '</option>'; } echo '</select></label>'; }
        self::input('note', 'Motivo / identificação do comprovante', '', 'text', 'minlength="3" maxlength="1000" required'); echo '</div>';
        self::check('confirmed', 'Conferi o recebimento e confirmo esta baixa manual. Ela não cancela links nem solicita estorno no K8.', false);
        echo '<p><button class="button button-primary ie-confirm" data-confirm="Registrar este crédito manual no saldo da inscrição?">' . ($p ? 'Confirmar baixa da transação K8' : 'Registrar baixa manual') . '</button></p></form></details>';
    }
    private static function manualHistory(array $r, array $balance): void
    {
        echo '<div class="ie-panel"><h2>Baixas manuais</h2><p>Crédito manual ativo: <strong>' . esc_html(Domain::money($balance['manual_paid'])) . '</strong>. Histórico preservado em reversões e conciliações.</p>';
        foreach (Store::manuals((int) $r['id']) as $m) {
            $status = !empty($m['reversed_at']) ? 'Revertida' : (!empty($m['resolved_at']) ? 'Substituída pela conciliação K8: ' . $m['resolved_state'] : 'Ativa');
            echo '<article class="ie-manual-entry"><h3>Baixa #' . (int) $m['id'] . ' · ' . esc_html(Domain::money((int) $m['amount']) . ' · ' . (Manual::METHODS[$m['method']] ?? $m['method'])) . '</h3><p>' . esc_html($status) . ' · Pagamento em ' . esc_html(Domain::dateBR($m['paid_on'])) . '<br>' . ((int) $m['payment_id'] ? 'Vinculada à cobrança K8 #' . (int) $m['payment_id'] : 'Recebimento fora do K8') . (!empty($m['remote_id']) ? '<br>Transação K8: <code>' . esc_html($m['remote_id']) . '</code>' : '') . '<br>Registrada por usuário WP #' . (int) $m['actor_id'] . ' em ' . esc_html($m['created_at']) . ' UTC.</p><p>' . esc_html($m['note']) . '</p>';
            if (!empty($m['reversed_at'])) { echo '<p>Reversão por usuário WP #' . (int) $m['reversed_by'] . ' em ' . esc_html($m['reversed_at']) . ' UTC: ' . esc_html($m['reverse_note']) . '</p>'; }
            if (Access::canManage() && Manual::active($m)) {
                echo '<details><summary>Reverter esta baixa manual</summary>'; self::form('ie_reverse_manual', ['registration_id' => $r['id'], 'manual_id' => $m['id']]); self::input('note', 'Motivo da reversão', '', 'text', 'required minlength="3" maxlength="1000"'); self::check('confirmed', 'Confirmo a reversão do crédito local. Esta ação não devolve dinheiro nem solicita estorno ao K8.', false);
                echo '<p><button class="button ie-confirm" data-confirm="Reverter o crédito desta baixa manual?">Reverter baixa manual</button></p></form></details>';
            }
            echo '</article>';
        }
        self::manualForm($r, null, $balance); echo '</div>';
    }
    public static function manualPayment(): void
    {
        self::guard('ie_manual_payment'); $id = absint($_POST['registration_id'] ?? 0);
        try { $manualId = Manual::save($id, (array) wp_unslash($_POST)); self::redirect('ie-dashboard', 'Baixa manual #' . $manualId . ' registrada/conferida. Consulte o histórico e o saldo.', false, ['view' => $id]); }
        catch (\Throwable $e) { self::redirect('ie-dashboard', $e->getMessage(), true, ['view' => $id]); }
    }
    public static function reverseManual(): void
    {
        self::guard('ie_reverse_manual'); $id = absint($_POST['registration_id'] ?? 0);
        try {
            $m = Store::manual(absint($_POST['manual_id'] ?? 0));
            if ((int) $m['registration_id'] !== $id) { throw new \RuntimeException('Esta baixa não pertence à inscrição.'); }
            Manual::reverse((int) $m['id'], (string) wp_unslash($_POST['note'] ?? ''), !empty($_POST['confirmed']));
            self::redirect('ie-dashboard', 'Baixa manual revertida. O histórico foi preservado e o K8 não recebeu solicitação de estorno.', false, ['view' => $id]);
        } catch (\Throwable $e) { self::redirect('ie-dashboard', $e->getMessage(), true, ['view' => $id]); }
    }
    public static function linkManualK8(): void
    {
        self::guard('ie_link_manual_k8'); $id = absint($_POST['registration_id'] ?? 0);
        try {
            Manual::linkK8($id, absint($_POST['payment_id'] ?? 0), absint($_POST['manual_id'] ?? 0), (array) wp_unslash($_POST));
            self::redirect('ie-dashboard', 'Baixa existente vinculada à cobrança K8. O saldo foi preservado, sem novo crédito.', false, ['view' => $id]);
        } catch (\Throwable $e) { self::redirect('ie-dashboard', $e->getMessage(), true, ['view' => $id]); }
    }
    private static function editRegistration(int $id): void
    {
        try {
            $r = Store::registration($id); self::registrationButtons($r);
            if (($r['status'] ?? 'active') === 'deleted') { return; }
            echo '<div class="ie-panel"><h2>Editar inscrição #' . $id . '</h2><p>' . esc_html($r['lot_name'] . ' · ' . Domain::money((int) $r['total']) . ' · Cartão até ' . $r['max_installments'] . 'x') . '</p>';
            self::form('ie_save_registration', ['registration_id' => $id]); echo '<div class="ie-form-grid">';
            self::input('name', 'Nome completo', $r['name'], 'text', 'required maxlength="200"'); self::input('email', 'E-mail', $r['email'], 'email', 'required maxlength="254"'); self::input('phone', 'Telefone com DDD', $r['phone'], 'tel', 'required'); self::input('cpf', 'CPF', Security::unseal($r['cpf_encrypted']), 'text', 'required');
            echo '<label class="ie-field"><span>Forma de futuros pagamentos</span><select name="method">'; foreach (['Pix' => 'PIX', 'Cartao' => 'Cartão online', Domain::PHYSICAL_CARD => 'Cartão físico (maquininha)'] as $value => $label) { echo '<option value="' . esc_attr($value) . '" ' . selected($r['method'], $value, false) . '>' . esc_html($label) . '</option>'; } echo '</select></label><label class="ie-field"><span>Situação da inscrição</span><select name="status">'; foreach (['active' => 'Ativa', 'cancelled' => 'Cancelada — bloquear novos pagamentos'] as $value => $label) { echo '<option value="' . esc_attr($value) . '" ' . selected($r['status'] ?? 'active', $value, false) . '>' . esc_html($label) . '</option>'; } echo '</select></label>';
            self::textarea('admin_note', 'Observações da organização', $r['admin_note'] ?? ''); echo '</div><p>O lote, o valor e as parcelas contratados ficam preservados. Pagamentos já emitidos conservam o CPF original; novos pagamentos usam o cadastro corrigido.</p><p><button class="button button-primary">Salvar inscrição</button> <a class="button" href="' . esc_url(admin_url('admin.php?page=ie-dashboard&view=' . $id)) . '">Voltar aos detalhes</a></p></form></div>';
        } catch (\Throwable $e) { echo '<p class="ie-error">' . esc_html($e->getMessage()) . '</p>'; }
    }
    private static function diagnostic(array $p): void
    {
        $d = json_decode($p['diagnostic_json'] ?? '', true);
        if (!is_array($d) || empty($d['rows'])) { return; }
        echo '<details class="ie-diagnostic"><summary>Diagnóstico da última consulta K8</summary><p>Conferido em UTC: ' . esc_html($d['checked_at']) . '. O CPF está mascarado.</p><div class="ie-table-wrap"><table class="widefat"><thead><tr><th>Campo</th><th>Na cobrança local</th><th>Devolvido pelo K8</th><th>Conferência</th></tr></thead><tbody>';
        foreach ($d['rows'] as $row) { echo '<tr><td>' . esc_html($row['field']) . '</td><td class="ie-wrap-cell">' . esc_html($row['local']) . '</td><td class="ie-wrap-cell">' . esc_html($row['k8']) . '</td><td class="' . ($row['result'] === 'Divergente' ? 'ie-error' : '') . '">' . esc_html($row['result']) . '</td></tr>'; }
        echo '</tbody></table></div></details>';
    }
    private static function cancelForm(array $p, array $r): void
    {
        $transactions = Store::transactions((int) $p['id']);
        if ($transactions) {
            echo '<h4>Transações K8 identificadas</h4><div class="ie-table-wrap"><table class="widefat"><thead><tr><th>ID da transação</th><th>Estado K8</th><th>Crédito</th><th>Cancelamento</th></tr></thead><tbody>';
            foreach ($transactions as $tx) {
                echo '<tr><td class="ie-wrap-cell"><code>' . esc_html($tx['remote_id']) . '</code></td><td>' . esc_html($tx['remote_state']) . '</td><td>' . esc_html(Domain::money((int) $tx['credit'])) . '</td><td>' . esc_html(['' => 'Sem solicitação', 'sending' => 'Envio em conferência', 'requested' => 'Solicitado; aguardar K8', 'review' => 'Conferir no K8', 'confirmed' => 'Confirmado', 'failed' => 'Recusado ou liberado após conferência'][$tx['cancel_status'] ?? ''] ?? '') . '</td></tr>';
                if (!empty($tx['cancel_error'])) { echo '<tr><td colspan="4" class="ie-error ie-wrap-cell">' . esc_html($tx['cancel_error']) . '</td></tr>'; }
                if (in_array($tx['cancel_status'] ?? '', ['sending', 'requested', 'review'], true)) {
                    echo '<tr><td colspan="4"><details><summary>Conferir solicitação pendente</summary><p>Consulte o K8. Libere somente após confirmar que não há cancelamento/estorno em andamento.</p>'; self::form('ie_review_cancellation', ['registration_id' => $r['id'], 'payment_id' => $p['id'], 'remote_id' => $tx['remote_id']]); self::input('reason', 'Registro da conferência', '', 'text', 'required minlength="3"'); self::check('confirmed', 'Conferi no K8 que não há solicitação de cancelamento/estorno em andamento.', false); echo '<p><button class="button ie-confirm" data-confirm="Registrar a conferência e liberar uma nova solicitação para esta transação?">Registrar conferência</button></p></form></details></td></tr>';
                }
            }
            echo '</tbody></table></div>';
        }
        $remote = (string) ($p['remote_id_hint'] ?? ''); if (!$remote && count($transactions) === 1) { $remote = $transactions[0]['remote_id']; }
        echo '<details class="ie-cancel-panel"><summary>Cancelar transação / estornar pagamento no K8</summary><p>Informe o ID da transação desejada. O K8 decide se o estado atual permite a operação. Um PIX já pago exige estorno. O estorno solicita a devolução integral desta transação; o saldo muda após confirmação.</p>';
        self::form('ie_cancel_payment', ['registration_id' => $r['id'], 'payment_id' => $p['id']]); echo '<div class="ie-form-grid">'; self::input('remote_id', 'ID da transação K8', $remote, 'text', 'required pattern="[A-Za-z0-9-]{1,100}"');
        echo '<label class="ie-field"><span>Operação no K8</span><select name="operation"><option value="cancel">Cancelar transação</option><option value="refund">Estornar pagamento aprovado (PIX ou cartão)</option></select></label>'; self::input('reason', 'Motivo', '', 'text', 'required minlength="3" maxlength="1000"'); echo '</div>'; self::check('confirmed', 'Confirmo a solicitação no K8 e, se houver pagamento, a devolução integral dessa transação.', false);
        echo '<p><button class="button ie-confirm" data-confirm="Enviar esta solicitação de cancelamento/estorno ao K8? A operação pode devolver o valor pago.">Enviar solicitação ao K8</button></p></form></details>';
    }
    public static function saveRegistration(): void
    {
        self::guard('ie_save_registration'); $id = absint($_POST['registration_id'] ?? 0);
        try { Registrations::save($id, (array) wp_unslash($_POST)); self::redirect('ie-dashboard', 'Inscrição atualizada. Histórico de pagamentos preservado.', false, ['view' => $id]); }
        catch (\Throwable $e) { self::redirect('ie-dashboard', $e->getMessage(), true, ['edit_registration' => $id]); }
    }
    public static function registrationAction(): void
    {
        self::guard('ie_registration_action'); $id = absint($_POST['registration_id'] ?? 0); $mode = $_POST['mode'] ?? '';
        try {
            if ($mode === 'trash') { Registrations::trash($id); }
            elseif ($mode === 'restore') { Registrations::restore($id); }
            elseif ($mode === 'purge') { self::confirmDeletion('EXCLUIR ' . $id); Registrations::purge($id); }
            else { throw new \RuntimeException('Ação inválida.'); }
            self::redirect('ie-dashboard', $mode === 'restore' ? 'Inscrição restaurada.' : ($mode === 'purge' ? 'Inscrição e histórico local excluídos permanentemente do WordPress.' : 'Inscrição movida para a lixeira. Histórico K8 preservado.'), false, $mode === 'purge' ? [] : ['view' => $id]);
        } catch (\Throwable $e) { self::redirect('ie-dashboard', $e->getMessage(), true, ['view' => $id]); }
    }
    private static function confirmDeletion(string $expected): void
    {
        if (trim((string) wp_unslash($_POST['confirmation'] ?? '')) !== $expected) { throw new \RuntimeException('Digite exatamente ' . $expected . ' para confirmar a exclusão permanente.'); }
    }
    public static function cancelPayment(): void
    {
        self::guard('ie_cancel_payment'); $id = absint($_POST['registration_id'] ?? 0);
        try { $p = self::postedPayment(); $message = Payments::cancel((int) $p['id'], trim((string) wp_unslash($_POST['remote_id'] ?? '')), (string) ($_POST['operation'] ?? ''), (string) wp_unslash($_POST['reason'] ?? ''), !empty($_POST['confirmed'])); self::redirect('ie-dashboard', $message, false, ['view' => $id]); }
        catch (\Throwable $e) { self::redirect('ie-dashboard', $e->getMessage(), true, ['view' => $id]); }
    }
    public static function reviewCancellation(): void
    {
        self::guard('ie_review_cancellation'); $id = absint($_POST['registration_id'] ?? 0);
        try { $p = self::postedPayment(); Payments::reviewCancellation((int) $p['id'], (string) wp_unslash($_POST['remote_id'] ?? ''), (string) wp_unslash($_POST['reason'] ?? ''), !empty($_POST['confirmed'])); self::redirect('ie-dashboard', 'Conferência registrada. Consulte o estado financeiro atualizado.', false, ['view' => $id]); }
        catch (\Throwable $e) { self::redirect('ie-dashboard', $e->getMessage(), true, ['view' => $id]); }
    }
    public static function syncPayment(): void
    {
        self::guard('ie_sync_payment'); $id = absint($_POST['registration_id'] ?? 0);
        try { $p = self::postedPayment(); $remote = trim((string) wp_unslash($_POST['remote_id'] ?? '')); Payments::sync((int) $p['id'], $remote ?: null); self::redirect('ie-dashboard', 'Pagamento conciliado com a API K8.', false, ['view' => $id]); }
        catch (\Throwable $e) { self::redirect('ie-dashboard', $e->getMessage(), true, ['view' => $id]); }
    }
    private static function postedPayment(): array
    {
        $p = Store::payment(absint($_POST['payment_id'] ?? 0));
        if ((int) $p['registration_id'] !== absint($_POST['registration_id'] ?? 0)) { throw new \RuntimeException('A tentativa não pertence à inscrição selecionada. Atualize a página.'); }
        return $p;
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
            $p = self::postedPayment();
            if ($close && empty($_POST['confirmed'])) { throw new \RuntimeException('Confirme a conferência no K8 antes de liberar a tentativa.'); }
            Payments::recover((int) $p['id'], trim((string) wp_unslash($_POST['checkout_id'] ?? '')), trim((string) wp_unslash($_POST['checkout_url'] ?? '')), $close);
            self::redirect('ie-dashboard', 'Tentativa recuperada. Créditos financeiros continuam sujeitos à confirmação K8.', false, ['view' => $id]);
        } catch (\Throwable $e) { self::redirect('ie-dashboard', $e->getMessage(), true, ['view' => $id]); }
    }
    public static function export(): void
    {
        self::guard('ie_export', true); $f = self::filters(); nocache_headers();
        header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="inscricoes-' . gmdate('Y-m-d') . '.csv"');
        $stream = fopen('php://output', 'w'); fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, ['Inscrição', 'Evento', 'Lote', 'Nome', 'E-mail', 'Telefone', 'CPF', 'Forma do cadastro', 'Valor', 'Recebido', 'Confirmado K8', 'Baixa manual ativa', 'Saldo', 'Excedente', 'Conta/Ambiente', 'Cadastro UTC', 'Situação da inscrição'], ';', '"', '');
        $offset = 0;
        do {
            $rows = self::rows($f, 250, $offset);
            foreach ($rows as $r) {
                $b = Domain::balance((int) $r['total'], (int) $r['paid']); $values = [$r['id'], $r['event_name'], $r['lot_name'], $r['name'], $r['email'], $r['phone'], Security::unseal($r['cpf_encrypted']), $r['method'] === Domain::PHYSICAL_CARD ? Domain::methodLabel($r['method']) : $r['method']];
                foreach (['gateway_paid', 'manual_paid'] as $key) { $b[$key] = (int) $r[$key]; }
                foreach (['total', 'paid', 'gateway_paid', 'manual_paid', 'balance', 'overpaid'] as $key) { $values[] = number_format($b[$key] / 100, 2, ',', ''); }
                $values[] = substr($r['scope'], 0, 12); $values[] = $r['created_at']; $values[] = ['active' => 'Ativa', 'cancelled' => 'Cancelada', 'deleted' => 'Lixeira'][$r['status'] ?? 'active'] ?? ''; fputcsv($stream, array_map([self::class, 'csv'], $values), ';', '"', '');
            }
            $offset += count($rows);
        } while (count($rows) === 250);
        fclose($stream); exit;
    }
}
