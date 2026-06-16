<?php

namespace App\Services;

use Core\Database;

/**
 * Resuelve el estilo editorial de las entradas.
 *
 * En v1 solo expone el contrato y las clases CSS. Los estilos visuales se
 * implementan en fases posteriores para no cambiar entradas existentes.
 */
final class ArticleTemplateService
{
    public const SETTING_KEY = 'article_template';
    public const DEFAULT = 'classic';

    private const TEMPLATES = [
        'classic' => 'Clásico editorial',
        'magazine' => 'Revista visual',
        'minimal' => 'Minimalista',
        'visual' => 'Visual amplio',
    ];

    /** @return array<string,string> */
    public static function options(): array
    {
        return self::TEMPLATES;
    }

    public static function normalize(?string $value): string
    {
        $value = trim((string) $value);
        return isset(self::TEMPLATES[$value]) ? $value : self::DEFAULT;
    }

    public static function forSite(int $siteId): string
    {
        if ($siteId <= 0) {
            return self::DEFAULT;
        }

        $row = Database::selectOne(
            'SELECT setting_value FROM settings WHERE site_id = ? AND setting_key = ? LIMIT 1',
            [$siteId, self::SETTING_KEY]
        );

        return self::normalize($row['setting_value'] ?? null);
    }

    public static function bodyClass(string $template): string
    {
        return 'pp-article-template--' . self::cssSafe(self::normalize($template));
    }

    private static function cssSafe(string $value): string
    {
        return preg_replace('/[^a-z0-9_-]+/i', '-', $value) ?: self::DEFAULT;
    }
}
