<?php
/**
 * Plugin Name: Inscrição de Eventos
 * Plugin URI: https://github.com/paulommaeda/inscricao-eventos
 * Description: Eventos com identidade própria, lotes, checkout K8 e pagamentos parciais por PIX.
 * Version: 1.0.0
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Author: Paulo Maeda
 * License: GPL-2.0-or-later
 * Text Domain: inscricao-eventos
 * Update URI: https://github.com/paulommaeda/inscricao-eventos
 */
if (!defined('ABSPATH')) { exit; }
define('IE_VERSION', '1.0.0');
define('IE_FILE', __FILE__);
define('IE_DIR', __DIR__ . '/');
foreach (['Domain', 'Security', 'Store', 'Gateway', 'Payments', 'Api', 'Admin', 'Plugin'] as $ie_class) {
    require_once IE_DIR . 'includes/' . $ie_class . '.php';
}
register_activation_hook(__FILE__, [\InscricaoEventos\Plugin::class, 'activate']);
register_deactivation_hook(__FILE__, [\InscricaoEventos\Plugin::class, 'deactivate']);
\InscricaoEventos\Plugin::boot();
