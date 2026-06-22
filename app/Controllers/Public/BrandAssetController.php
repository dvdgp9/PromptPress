<?php

declare(strict_types=1);

namespace App\Controllers\Public;

use Core\Database;
use Core\Response;

final class BrandAssetController
{
    public function logo(array $params = []): void
    {
        $siteId = (int) ($params['site'] ?? 0);
        if ($siteId <= 0) Response::notFound();
        $setting = Database::selectOne(
            'SELECT setting_value FROM settings WHERE site_id = ? AND setting_key = ? LIMIT 1',
            [$siteId, 'site_logo_path']
        );
        $relative = ltrim((string) ($setting['setting_value'] ?? ''), '/');
        $prefix = 'storage/uploads/' . $siteId . '/brand/';
        if (!str_starts_with($relative, $prefix)) Response::notFound();
        $filename = basename($relative);
        if (preg_match('/^logo-[a-f0-9]{16}\.(?:png|jpg|webp)$/', $filename) !== 1) Response::notFound();

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
}
