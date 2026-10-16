<?php
namespace InscricaoEventos;
if (!defined('ABSPATH')) { exit; }

final class Access
{
    public const VIEW = 'ie_view_events';
    public const ROLE = 'ie_event_manager';
    public static function canManage(): bool { return current_user_can('manage_options'); }
    public static function canView(): bool { return self::canManage() || current_user_can(self::VIEW); }
    public static function requireManage(): void { if (!self::canManage()) { wp_die('Acesso negado. Esta ação exige administrador.', '', ['response' => 403]); } }
    public static function requireView(): void { if (!self::canView()) { wp_die('Acesso negado.', '', ['response' => 403]); } }
    public static function install(): void
    {
        if (get_option('ie_access_version') === IE_VERSION && get_role(self::ROLE)) { return; }
        $role = get_role(self::ROLE) ?: add_role(self::ROLE, 'Gerente de Eventos', ['read' => true, self::VIEW => true]);
        if (!$role) { throw new \RuntimeException('Não foi possível criar o perfil Gerente de Eventos.'); }
        $role->add_cap('read'); $role->add_cap(self::VIEW);
        $admin = get_role('administrator'); if ($admin) { $admin->add_cap(self::VIEW); }
        update_option('ie_access_version', IE_VERSION, false);
    }
}
