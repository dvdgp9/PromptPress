<?php

declare(strict_types=1);

namespace App\Controllers\Public;

use App\Services\CustomFontService;
use Core\Database;
use Core\Response;
use Core\Session;

final class BrandAssetController
{
    public function logo(array $params = []): void
    {
        $this->serveLogo($params, 'site_logo_path');
    }

    /** LOGO2 — Variante para fondos oscuros. */
    public function logoDark(array $params = []): void
    {
        $this->serveLogo($params, 'site_logo_dark_path');
    }

    private function serveLogo(array $params, string $settingKey): void
    {
        // Sin esto, la descarga del asset espera al lock de sesión de la
        // petición de la página (ver Session::close).
        Session::close();

        $siteId = (int) ($params['site'] ?? 0);
        if ($siteId <= 0) Response::notFound();
        $setting = Database::selectOne(
            'SELECT setting_value FROM settings WHERE site_id = ? AND setting_key = ? LIMIT 1',
            [$siteId, $settingKey]
        );
        $relative = ltrim((string) ($setting['setting_value'] ?? ''), '/');
        $prefix = 'storage/uploads/' . $siteId . '/brand/';
        if (!str_starts_with($relative, $prefix)) Response::notFound();
        $filename = basename($relative);
        if (preg_match('/^logo(?:-dark)?-[a-f0-9]{16}\.(?:png|jpg|webp)$/', $filename) !== 1) Response::notFound();

        $absolute = PP_ROOT . '/' . $relative;
        if (!is_file($absolute)) Response::notFound();
        $mime = (string) (mime_content_type($absolute) ?: 'application/octet-stream');
        if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) Response::notFound();

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string) filesize($absolute));
        header('Cache-Control: no-cache, must-revalidate');
        header('X-Content-Type-Options: nosniff');
        readfile($absolute);
        exit;
    }

    /**
     * FONTS — Sirve un archivo de fuente propia del sitio.
     * El id es inmutable (resubir un corte crea una fila nueva), así que se
     * puede cachear a largo plazo sin riesgo de servir una fuente vieja.
     */
    public function font(array $params = []): void
    {
        Session::close();

        $siteId = (int) ($params['site'] ?? 0);
        $fileId = (int) ($params['id'] ?? 0);
        if ($siteId <= 0 || $fileId <= 0) Response::notFound();

        $row = Database::selectOne(
            'SELECT path, format FROM site_font_files WHERE id = ? AND site_id = ? LIMIT 1',
            [$fileId, $siteId]
        );
        if (!$row) Response::notFound();

        $relative = ltrim((string) $row['path'], '/');
        $prefix = 'storage/uploads/' . $siteId . '/fonts/';
        if (!str_starts_with($relative, $prefix)) Response::notFound();
        if (preg_match('/^font-[a-f0-9]{16}\.(?:woff2|woff|ttf|otf)$/', basename($relative)) !== 1) Response::notFound();

        $absolute = PP_ROOT . '/' . $relative;
        if (!is_file($absolute)) Response::notFound();

        $mime = CustomFontService::FORMATS[(string) $row['format']]['mime'] ?? 'font/woff2';
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string) filesize($absolute));
        header('Cache-Control: public, max-age=31536000, immutable');
        header('X-Content-Type-Options: nosniff');
        header('Access-Control-Allow-Origin: *');
        readfile($absolute);
        exit;
    }
}
