<?php

declare(strict_types=1);

namespace App\Services;

use App\Controllers\Admin\PageController;
use App\Services\Canvas\CanvasService;
use Core\Auth;
use Core\Database;

/**
 * PromptPress — Guarda una traducción como página hermana (I18N-FULL T5.3).
 *
 * Separado de `PageTranslator` a propósito: traducir (IA) y guardar (base de
 * datos) son dos cosas con riesgos distintos. El riesgo de aquí es duplicar
 * páginas, pisar contenido o publicar sin querer, y se puede probar entero sin
 * gastar una llamada a la IA.
 *
 * Tres invariantes que no se negocian:
 *   1. La traducción nace en BORRADOR. La publica una persona, mirándola.
 *   2. La página ORIGINAL no se toca jamás.
 *   3. Pedir la misma traducción dos veces no crea una segunda página.
 */
final class TranslationWriter
{
    /**
     * Crea la versión traducida de una página canvas.
     *
     * @param array<string,mixed> $source  Fila de `pages`
     * @param array<string,mixed> $payload Salida de PageTranslator
     * @return array{ok:bool, page_id?:int, error?:string, message?:string}
     */
    public static function createCanvas(int $siteId, array $source, string $targetLang, array $payload): array
    {
        $guard = self::guard($siteId, $source, $targetLang);
        if ($guard !== null) {
            return $guard;
        }

        $pageId = self::createSibling($siteId, $source, $targetLang, $payload);
        CanvasService::save(
            $pageId,
            (string) ($payload['html'] ?? ''),
            (string) (CanvasService::get((int) $source['id'])['css'] ?? ''),
            'translation',
            'Traducción automática'
        );

        return ['ok' => true, 'page_id' => $pageId];
    }

    /**
     * Crea la versión traducida de una página por secciones.
     *
     * @param array<string,mixed> $source
     * @param array<string,mixed> $payload
     * @return array{ok:bool, page_id?:int, error?:string, message?:string}
     */
    public static function createSections(int $siteId, array $source, string $targetLang, array $payload): array
    {
        $guard = self::guard($siteId, $source, $targetLang);
        if ($guard !== null) {
            return $guard;
        }

        $pageId = self::createSibling($siteId, $source, $targetLang, $payload);

        // Se copian las secciones del original y se sustituye su contenido por
        // el traducido: así se conservan tipo, orden y estilo.
        $translated = [];
        foreach ((array) ($payload['sections'] ?? []) as $section) {
            $translated[(int) ($section['id'] ?? 0)] = (string) ($section['content'] ?? '');
        }

        $rows = Database::select(
            'SELECT id, section_type, sort_order, content, style, status
             FROM page_sections WHERE page_id = ? ORDER BY sort_order ASC, id ASC',
            [(int) $source['id']]
        );
        foreach ($rows as $row) {
            Database::execute(
                'INSERT INTO page_sections (page_id, section_type, sort_order, content, style, status)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [
                    $pageId,
                    (string) $row['section_type'],
                    (int) $row['sort_order'],
                    $translated[(int) $row['id']] ?? (string) $row['content'],
                    (string) $row['style'],
                    (string) $row['status'],
                ]
            );
        }

        return ['ok' => true, 'page_id' => $pageId];
    }

    /**
     * Estado de traducción de una página: qué idiomas activos tienen versión y
     * cuáles no. Alimenta el panel.
     *
     * @param array<string,mixed> $page
     * @return array<string, array{exists:bool, page_id:int|null, status:string|null, title:string|null}>
     */
    public static function statusFor(int $siteId, array $page): array
    {
        $own   = LanguageService::forPage($page, $siteId);
        $group = trim((string) ($page['translation_group'] ?? ''));

        $siblings = [];
        if ($group !== '') {
            foreach (Database::select(
                'SELECT id, language, status, title FROM pages WHERE site_id = ? AND translation_group = ?',
                [$siteId, $group]
            ) as $row) {
                $siblings[LanguageService::normalize((string) $row['language'])] = $row;
            }
        }

        $out = [];
        foreach (LanguageService::activeFor($siteId) as $code) {
            if ($code === $own) {
                continue; // no se ofrece traducir una página a su propio idioma
            }
            $found = $siblings[$code] ?? null;
            $out[$code] = [
                'exists'  => $found !== null,
                'page_id' => $found !== null ? (int) $found['id'] : null,
                'status'  => $found !== null ? (string) $found['status'] : null,
                'title'   => $found !== null ? (string) $found['title'] : null,
            ];
        }
        return $out;
    }

    // =====================================================================
    // Internos
    // =====================================================================

    /**
     * Comprobaciones previas, públicas para poder ejecutarlas ANTES de llamar a
     * la IA. Traducir a un idioma inactivo, o una página que ya está traducida,
     * no debe costar una llamada al modelo.
     *
     * @param array<string,mixed> $source
     * @return array{ok:bool, error:string, message:string, page_id?:int}|null
     */
    public static function precheck(int $siteId, array $source, string $targetLang): ?array
    {
        return self::guard($siteId, $source, $targetLang);
    }

    /**
     * Comprobaciones previas. Devuelve null si se puede seguir, o el error ya
     * redactado para el usuario si no.
     *
     * @param array<string,mixed> $source
     * @return array{ok:bool, error:string, message:string, page_id?:int}|null
     */
    private static function guard(int $siteId, array $source, string $targetLang): ?array
    {
        $targetLang = LanguageService::normalize($targetLang);
        $own = LanguageService::forPage($source, $siteId);

        if ($targetLang === $own) {
            return [
                'ok' => false,
                'error' => 'same_language',
                'message' => 'Esta página ya está en ' . LanguageService::label($own) . '.',
            ];
        }

        if (!LanguageService::isActive($siteId, $targetLang)) {
            return [
                'ok' => false,
                'error' => 'inactive_language',
                'message' => LanguageService::label($targetLang) . ' no está activo en esta web. '
                    . 'Puedes activarlo en Ajustes.',
            ];
        }

        $existing = self::existing($siteId, $source, $targetLang);
        if ($existing !== null) {
            return [
                'ok' => false,
                'error' => 'exists',
                'page_id' => (int) $existing['id'],
                'message' => 'Esta página ya tiene versión en ' . LanguageService::label($targetLang)
                    . '. Ábrela para revisarla o edítala a mano; no la hemos tocado.',
            ];
        }

        return null;
    }

    /** @param array<string,mixed> $source @return array<string,mixed>|null */
    private static function existing(int $siteId, array $source, string $targetLang): ?array
    {
        $group = trim((string) ($source['translation_group'] ?? ''));
        if ($group === '') {
            return null;
        }
        return Database::selectOne(
            'SELECT id FROM pages WHERE site_id = ? AND translation_group = ? AND language = ? LIMIT 1',
            [$siteId, $group, $targetLang]
        );
    }

    /**
     * Inserta la página hermana (sin contenido todavía) y devuelve su id.
     *
     * @param array<string,mixed> $source
     * @param array<string,mixed> $payload
     */
    private static function createSibling(int $siteId, array $source, string $targetLang, array $payload): int
    {
        $targetLang = LanguageService::normalize($targetLang);
        $title = trim((string) ($payload['title'] ?? '')) ?: (string) ($source['title'] ?? '');

        // El slug se calcula desde el del ORIGINAL, no desde el título traducido:
        // así las dos versiones se reconocen entre sí de un vistazo en el panel
        // (`/servicios` y `/fr/servicios`) y el prefijo lo pone uniqueSlug.
        $slug = PageController::uniqueSlug(
            $siteId,
            (string) ($source['slug'] ?? ''),
            null,
            $targetLang
        );

        $group = trim((string) ($source['translation_group'] ?? ''));
        if ($group === '') {
            // El original es anterior a la migración: se le asigna grupo ahora
            // para poder hermanar la traducción.
            $group = self::newGroup();
            Database::execute('UPDATE pages SET translation_group = ? WHERE id = ?', [$group, (int) $source['id']]);
        }

        Database::execute(
            'INSERT INTO pages
                (site_id, title, slug, page_type, language, translation_group, parent_id,
                 meta_title, meta_description, status, render_mode, sort_order, tree_sort_order,
                 created_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, \'draft\', ?, ?, ?, ?, NOW(), NOW())',
            [
                $siteId,
                mb_substr($title, 0, 500),
                $slug,
                (string) ($source['page_type'] ?? 'landing'),
                $targetLang,
                $group,
                null, // la jerarquía del idioma destino se resuelve aparte
                mb_substr(trim((string) ($payload['meta_title'] ?? '')), 0, 255),
                mb_substr(trim((string) ($payload['meta_description'] ?? '')), 0, 500),
                (string) ($source['render_mode'] ?? 'sections'),
                (int) ($source['sort_order'] ?? 0),
                (int) ($source['tree_sort_order'] ?? 0),
                Auth::id(),
            ]
        );

        return (int) Database::lastInsertId();
    }

    private static function newGroup(): string
    {
        $row = Database::selectOne('SELECT UUID() AS g');
        return (string) ($row['g'] ?? bin2hex(random_bytes(16)));
    }
}
