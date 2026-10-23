<?php

declare(strict_types=1);

namespace App\Services;

use Core\Auth;
use Core\Database;
use Core\RememberToken;

/**
 * CONSTRUCCION — «Web en construcción».
 *
 * Mientras está activo, el visitante ve una portada con la marca (logo, icono,
 * colores) y un mensaje, con HTTP 503 para que los buscadores no indexen ni la
 * portada ni la web a medio hacer. Quien tiene sesión en el panel sigue viendo
 * la web de verdad, con un aviso en la píldora del panel.
 *
 * Distinto de `MaintenanceMode`: aquel lo enciende el actualizador unos
 * segundos y vive en un fichero porque la BD puede no responder; este lo decide
 * el gestor y dura lo que tarde en lanzar la web, así que es un ajuste más.
 *
 * Solo se interceptan las visitas (GET/HEAD) a páginas. Los POST siguen su
 * curso (formularios, reservas, anti-bot) y también los assets y las APIs:
 * cortarlos no protege nada y rompería el panel y los previews.
 */
final class ConstructionMode
{
    public const SETTING_ENABLED = 'site_under_construction';
    public const SETTING_MESSAGE = 'site_construction_message';
    public const MESSAGE_MAX = 500;

    /** Rutas que nunca se tapan: la propia ruta y todo lo que cuelga de ella. */
    private const ALLOWED = [
        '/admin', '/install', '/api', '/brand-assets', '/public', '/storage',
        '/design.css', '/favicon.ico', '/robots.txt', '/_health',
    ];

    /** ¿Esta petición es una visita a una página que habría que tapar? Sin BD. */
    public static function intercepts(string $method, string $path): bool
    {
        if (!in_array(strtoupper($method), ['GET', 'HEAD'], true)) return false;
        foreach (self::ALLOWED as $allowed) {
            if ($path === $allowed || str_starts_with($path, $allowed . '/')) return false;
        }
        return true;
    }

    /** @return array{enabled:bool,message:string} */
    public static function settings(int $siteId): array
    {
        $out = ['enabled' => false, 'message' => ''];
        if ($siteId <= 0) return $out;
        try {
            $rows = Database::select(
                'SELECT setting_key, setting_value FROM settings WHERE site_id = ? AND setting_key IN (?, ?)',
                [$siteId, self::SETTING_ENABLED, self::SETTING_MESSAGE]
            );
        } catch (\Throwable $e) {
            return $out;
        }
        foreach ($rows as $r) {
            if ($r['setting_key'] === self::SETTING_ENABLED) $out['enabled'] = (string) $r['setting_value'] === '1';
            if ($r['setting_key'] === self::SETTING_MESSAGE) $out['message'] = trim((string) $r['setting_value']);
        }
        return $out;
    }

    public static function isActive(int $siteId): bool
    {
        return self::settings($siteId)['enabled'];
    }

    /** ¿El visitante es del equipo (sesión del panel iniciada)? */
    public static function viewerIsTeam(): bool
    {
        if (Auth::check()) return true;
        // La sesión de PHP dura poco; con «mantener la sesión iniciada» se
        // reabre aquí igual que en el panel, o el gestor se encontraría su
        // propia portada de construcción al volver al día siguiente.
        if (RememberToken::hasCookie()) {
            $userId = RememberToken::resume();
            if ($userId !== null) {
                Auth::login($userId);
                return true;
            }
        }
        return false;
    }

    /** Portada que ve el visitante, con la marca del sitio. */
    public static function render(int $siteId): string
    {
        $lang = LanguageService::codeFor($siteId);
        $settings = self::settings($siteId);
        $brand = BrandService::data($siteId);
        $name = (string) $brand['name'];

        $message = $settings['message'] !== ''
            ? $settings['message']
            : Microcopy::t('construction.default_message', $lang);

        $logoUrl = BrandService::logoUrl($siteId, 'light');
        $mark = $logoUrl !== ''
            ? '<img class="pp-construction__logo" src="' . e($logoUrl) . '" alt="' . e($name) . '">'
            : '<p class="pp-construction__name">' . e($name) . '</p>';

        $css = PP_ROOT . '/public/css/construction.css';

        return '<!doctype html><html lang="' . e($lang) . '"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="robots" content="noindex, nofollow">'
            . '<title>' . e($name) . '</title>'
            . DesignSystem::renderHead($siteId)
            . '<link rel="stylesheet" href="' . e(base_url('public/css/construction.css')) . '?v=' . e((string) (@filemtime($css) ?: PP_VERSION)) . '">'
            . '</head><body class="pp-construction">'
            . '<main class="pp-construction__box">'
            . $mark
            . '<h1 class="pp-construction__title">' . e(Microcopy::t('construction.title', $lang)) . '</h1>'
            . '<p class="pp-construction__message">' . nl2br(e($message)) . '</p>'
            . '</main></body></html>';
    }
}
