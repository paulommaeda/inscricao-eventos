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
        if (get_option('ie_origens_branding_applied')) { return; }
        $id = absint(get_option('ie_sample_created', 0));
        if ($id) {
            try { $event = Store::event($id); } catch (\Throwable $e) { $event = null; }
            if ($event && empty($event['brand_preset'])) { Store::update('events', array_merge(self::ORIGENS, ['brand_preset' => 'origens', 'hero_url' => '']), ['id' => $id]); }
        }
        update_option('ie_origens_branding_applied', 1, false);
    }
    public static function loginEvent(int $id): ?array
    {
        try {
            $event = $id ? Store::event($id) : Store::event(absint(get_option('ie_sample_created', 0)));
            return in_array($event['status'], ['published', 'archived'], true) ? self::present($event) : null;
        } catch (\Throwable $e) { return null; }
    }
}
