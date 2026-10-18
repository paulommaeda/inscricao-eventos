<?php
namespace InscricaoEventos;
if (!defined('ABSPATH')) { exit; }

/** Event identity is independent of its dates, lots and financial contract. */
final class Branding
{
    public const ORIGENS = ['primary_color' => '#075ea8', 'accent_color' => '#62c5f4', 'background_color' => '#f0f5fc'];
    public static function present(array $event): array
    {
        foreach (['allow_pix', 'allow_card', 'allow_card_physical'] as $flag) { $event[$flag] = !empty($event[$flag]); }
        $event['brand_preset'] = ($event['brand_preset'] ?? '') === 'origens' ? 'origens' : 'custom';
        $event['cover_url'] = (string) ($event['hero_url'] ?? '');
        if ($event['brand_preset'] === 'origens' && !$event['cover_url']) { $event['cover_url'] = plugins_url('assets/origens-cover.jpg', IE_FILE); }
        return $event;
    }
    public static function enqueue(): void { wp_enqueue_style('ie-brand', plugins_url('assets/brand.css', IE_FILE), [], IE_VERSION); wp_enqueue_script('ie-brand', plugins_url('assets/brand.js', IE_FILE), [], IE_VERSION, true); }
    public static function migrate(): void
    {
        if (!get_option('ie_origens_branding_applied')) {
            $id = absint(get_option('ie_sample_created', 0));
            if ($id) {
                try { $event = Store::event($id); } catch (\Throwable $e) { $event = null; }
                if ($event && empty($event['brand_preset'])) { Store::update('events', array_merge(self::ORIGENS, ['brand_preset' => 'origens', 'hero_url' => '']), ['id' => $id]); }
            }
            update_option('ie_origens_branding_applied', 1, false);
        }
        if (get_option('ie_origens_branding_repaired_161')) { return; }
        // The published retreat is a copy, not the original sample ID. Repair only its
        // exact name + untouched legacy palette, never another event or a custom palette.
        foreach (Store::events() as $event) {
            $name = preg_replace('/[\s\p{Pd}]+/u', ' ', strtolower(trim($event['name'])));
            if ($name !== 'fogo santo origens' || !in_array($event['brand_preset'] ?? '', ['', 'custom'], true)) { continue; }
            $legacy = ['primary_color' => '#215c4a', 'accent_color' => '#d2a45c', 'background_color' => '#f6f5f0'];
            foreach ($legacy as $key => $color) { if (strtolower($event[$key] ?? '') !== $color) { continue 2; } }
            $fields = array_merge(self::ORIGENS, ['brand_preset' => 'origens']);
            $filename = strtolower(basename((string) wp_parse_url($event['hero_url'] ?? '', PHP_URL_PATH)));
            if (in_array($filename, ['', 'origens.jpeg', 'origens.jpg', 'origens.png'], true)) { $fields['hero_url'] = ''; }
            Store::update('events', $fields, ['id' => $event['id']]);
        }
        global $wpdb;
        if (!empty($wpdb->last_error)) { throw new \RuntimeException('Não foi possível aplicar a identidade visual do retiro.'); }
        update_option('ie_origens_branding_repaired_161', 1, false);
    }
    public static function loginEvent(int $id): ?array
    {
        try {
            $event = $id ? Store::event($id) : Store::event(absint(get_option('ie_sample_created', 0)));
            return in_array($event['status'], ['published', 'archived'], true) ? self::present($event) : null;
        } catch (\Throwable $e) { return null; }
    }
}
