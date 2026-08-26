<?php

declare(strict_types=1);

namespace App\Modules\Resources;

use App\Services\LanguageService;
use Core\Database;
use InvalidArgumentException;

/**
 * Persistencia y reglas de publicación de recursos descargables (R1).
 *
 * Este store solo gobierna metadatos. ResourceFileService (R2) será quien
 * valide, mueva y retire los binarios; nunca se confía en un path del cliente.
 */
final class ResourceStore
{
    public const MAX_FILE_SIZE = 20 * 1024 * 1024;

    private const DEFAULTS = [
        'title'             => '',
        'description'       => null,
        'category'          => null,
        'cover_media_id'    => null,
        'file_path'         => null,
        'original_filename' => null,
        'file_mime'         => null,
        'file_size'         => null,
        'access_mode'       => 'direct',
        'form_id'           => null,
        'language'          => null,
        'language_scope'    => 'selected',
        'languages'         => null,
        'translation_group' => null,
        'status'            => 'draft',
    ];

    /** @return array<int,array<string,mixed>> */
    public static function all(int $siteId): array
    {
        return self::hydrateMany($siteId, Database::select(
            self::selectSql()
            . ' WHERE r.site_id = ?'
            . " ORDER BY (r.status = 'published') DESC, r.updated_at DESC, r.id DESC",
            [$siteId]
        ));
    }

    /** @return array<string,mixed>|null */
    public static function find(int $siteId, int $id): ?array
    {
        $row = Database::selectOne(
            self::selectSql() . ' WHERE r.site_id = ? AND r.id = ? LIMIT 1',
            [$siteId, $id]
        );
        return $row === null ? null : self::hydrateLanguages($siteId, $row);
    }

    /** @return array<string,mixed>|null */
    public static function findPublishedBySlug(int $siteId, string $language, string $slug): ?array
    {
        $language = LanguageService::normalize($language);
        $row = Database::selectOne(
            self::selectSql()
            . " WHERE r.site_id = ? AND r.slug = ? AND r.status = 'published'"
            . " AND (r.language_scope = 'all' OR EXISTS (SELECT 1 FROM resource_languages rl WHERE rl.resource_id = r.id AND rl.language = ?))"
            . ' ORDER BY (r.language = ?) DESC, r.id ASC LIMIT 1',
            [$siteId, trim($slug, '/'), $language, $language]
        );
        return $row === null ? null : self::hydrateLanguages($siteId, $row);
    }

    /** Recursos visibles en el catálogo público de un idioma. */
    public static function publishedForLanguage(int $siteId, string $language): array
    {
        $language = LanguageService::normalize($language);
        return self::hydrateMany($siteId, Database::select(
            self::selectSql()
            . " WHERE r.site_id = ? AND r.status = 'published'"
            . " AND (r.language_scope = 'all' OR EXISTS (SELECT 1 FROM resource_languages rl WHERE rl.resource_id = r.id AND rl.language = ?))"
            . ' ORDER BY r.published_at DESC, r.id DESC',
            [$siteId, $language]
        ));
    }

    /** Cualquier recurso publicado del sitio, para explicar estados de Studio. */
    public static function hasPublished(int $siteId): bool
    {
        return Database::selectOne(
            "SELECT id FROM resources WHERE site_id = ? AND status = 'published' LIMIT 1",
            [$siteId]
        ) !== null;
    }

    /** Traducciones publicadas de una ficha, para hreflang. */
    public static function publishedTranslations(int $siteId, string $translationGroup): array
    {
        $group = trim($translationGroup);
        if ($group === '') return [];
        return self::hydrateMany($siteId, Database::select(
            self::selectSql()
            . " WHERE r.site_id = ? AND r.translation_group = ? AND r.status = 'published'"
            . ' ORDER BY r.language ASC',
            [$siteId, $group]
        ));
    }

    /** Sección de formulario activa y perteneciente al sitio. */
    public static function formSection(int $siteId, int $formId): ?array
    {
        if ($formId <= 0) return null;
        return Database::selectOne(
            "SELECT ps.* FROM page_sections ps
             JOIN pages p ON p.id = ps.page_id
             WHERE ps.id = ? AND p.site_id = ? AND ps.section_type = 'form'
               AND ps.status <> 'deleted' LIMIT 1",
            [$formId, $siteId]
        );
    }

    /** @param array<string,mixed> $fields */
    public static function create(int $siteId, array $fields): int
    {
        self::validateRelations($siteId, $fields);
        $f = self::normalize($siteId, $fields);
        self::validateForPersistence($siteId, $f);

        $slug = self::uniqueSlug($siteId, $f['language'], $f['title'], null, $f['language_scope'], $f['languages']);
        $publishedAt = $f['status'] === 'published' ? gmdate('Y-m-d H:i:s') : null;

        Database::execute(
            'INSERT INTO resources
                (site_id, title, slug, description, category, cover_media_id,
                 file_path, original_filename, file_mime, file_size,
                 access_mode, form_id, language, language_scope, translation_group,
                 status, published_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            [
                $siteId, $f['title'], $slug, $f['description'], $f['category'],
                $f['cover_media_id'], $f['file_path'], $f['original_filename'],
                $f['file_mime'], $f['file_size'], $f['access_mode'], $f['form_id'],
                $f['language'], $f['language_scope'], $f['translation_group'], $f['status'], $publishedAt,
            ]
        );

        $id = (int) Database::lastInsertId();
        self::syncLanguages($id, $f['language_scope'], $f['languages']);
        self::flushPageCache($siteId);
        return $id;
    }

    /** @param array<string,mixed> $fields */
    public static function update(int $siteId, int $id, array $fields): bool
    {
        $existing = self::find($siteId, $id);
        if ($existing === null) {
            return false;
        }

        $merged = array_merge(array_intersect_key($existing, self::DEFAULTS), $fields);
        self::validateRelations($siteId, $merged);
        $f = self::normalize($siteId, $merged);
        self::validateForPersistence($siteId, $f);

        $titleChanged = (string) $existing['title'] !== $f['title'];
        $languageChanged = (string) $existing['language'] !== $f['language'];
        $visibilityChanged = (string) ($existing['language_scope'] ?? 'selected') !== $f['language_scope']
            || array_values((array) ($existing['languages'] ?? [])) !== $f['languages'];
        $currentSlug = (string) $existing['slug'];
        $slug = ($titleChanged || $languageChanged
            || ($visibilityChanged && self::slugTaken($siteId, $currentSlug, $id, $f['language_scope'], $f['languages'])))
            ? self::uniqueSlug($siteId, $f['language'], $f['title'], $id, $f['language_scope'], $f['languages'])
            : $currentSlug;

        $publishedAt = null;
        if ($f['status'] === 'published') {
            $publishedAt = (string) $existing['status'] === 'published' && !empty($existing['published_at'])
                ? (string) $existing['published_at']
                : gmdate('Y-m-d H:i:s');
        }

        Database::execute(
            'UPDATE resources
                SET title = ?, slug = ?, description = ?, category = ?, cover_media_id = ?,
                    file_path = ?, original_filename = ?, file_mime = ?, file_size = ?,
                    access_mode = ?, form_id = ?, language = ?, language_scope = ?, translation_group = ?,
                    status = ?, published_at = ?, updated_at = UTC_TIMESTAMP()
              WHERE site_id = ? AND id = ?',
            [
                $f['title'], $slug, $f['description'], $f['category'], $f['cover_media_id'],
                $f['file_path'], $f['original_filename'], $f['file_mime'], $f['file_size'],
                $f['access_mode'], $f['form_id'], $f['language'], $f['language_scope'], $f['translation_group'],
                $f['status'], $publishedAt, $siteId, $id,
            ]
        );
        self::syncLanguages($id, $f['language_scope'], $f['languages']);
        self::flushPageCache($siteId);
        return true;
    }

    public static function delete(int $siteId, int $id): bool
    {
        $deleted = Database::execute(
            'DELETE FROM resources WHERE site_id = ? AND id = ?',
            [$siteId, $id]
        ) > 0;
        if ($deleted) self::flushPageCache($siteId);
        return $deleted;
    }

    /** Los Canvas guardan el HTML expandido, por eso el catálogo invalida su caché. */
    private static function flushPageCache(int $siteId): void
    {
        try {
            \App\Services\CacheService::flush($siteId);
        } catch (\Throwable) {
            // Una caché no disponible nunca debe bloquear la gestión del recurso.
        }
    }

    /**
     * Normaliza campos de entidad, no contenido binario.
     *
     * @param array<string,mixed> $fields
     * @return array<string,mixed>
     */
    public static function normalize(int $siteId, array $fields): array
    {
        $f = array_merge(self::DEFAULTS, array_intersect_key($fields, self::DEFAULTS));

        $title = mb_substr(trim((string) $f['title']), 0, 180);
        if ($title === '') {
            throw new InvalidArgumentException(__('resource.err.title_required'));
        }

        $active = LanguageService::activeFor($siteId);
        $scope = (string) ($f['language_scope'] ?? 'selected');
        if (!in_array($scope, ['selected', 'all'], true)) $scope = 'selected';
        $rawLanguages = $f['languages'] ?? null;
        $languages = [];
        if (is_array($rawLanguages)) {
            foreach ($rawLanguages as $code) {
                $code = LanguageService::normalize((string) $code);
                if (!in_array($code, $active, true)) {
                    throw new InvalidArgumentException(__('resource.err.language_inactive'));
                }
                if (!in_array($code, $languages, true)) $languages[] = $code;
            }
        }
        $requestedLanguage = trim((string) ($f['language'] ?? ''));
        $requestedLanguage = $requestedLanguage !== '' ? LanguageService::normalize($requestedLanguage) : '';
        if ($scope === 'all') {
            $languages = $active;
        } elseif ($rawLanguages === null) {
            $languages = [$requestedLanguage !== '' ? $requestedLanguage : LanguageService::primaryFor($siteId)];
        }
        if ($languages === []) throw new InvalidArgumentException(__('resource.err.languages_required'));
        $language = in_array($requestedLanguage, $languages, true) ? $requestedLanguage : $languages[0];
        if (!LanguageService::isActive($siteId, $language)) throw new InvalidArgumentException(__('resource.err.language_inactive'));

        $group = strtolower(trim((string) ($f['translation_group'] ?? '')));
        if ($group === '') {
            $group = self::newUuid();
        } elseif (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $group) !== 1) {
            throw new InvalidArgumentException(__('resource.err.group_invalid'));
        }

        $accessMode = trim((string) $f['access_mode']);
        if (!in_array($accessMode, ['direct', 'form'], true)) {
            throw new InvalidArgumentException(__('resource.err.access_invalid'));
        }
        $status = trim((string) $f['status']);
        if (!in_array($status, ['draft', 'published'], true)) {
            throw new InvalidArgumentException(__('resource.err.status_invalid'));
        }

        return [
            'title'             => $title,
            'description'       => self::nullableText($f['description'], 8000),
            'category'          => self::nullableText($f['category'], 100),
            'cover_media_id'    => self::nullableId($f['cover_media_id']),
            'file_path'         => self::nullableText($f['file_path'], 500),
            'original_filename' => self::nullableText($f['original_filename'], 255),
            'file_mime'         => self::nullableText($f['file_mime'], 100),
            'file_size'         => ($f['file_size'] === null || $f['file_size'] === '') ? null : (int) $f['file_size'],
            'access_mode'       => $accessMode,
            'form_id'           => self::nullableId($f['form_id']),
            'language'          => $language,
            'language_scope'    => $scope,
            'languages'         => array_values($languages),
            'translation_group' => $group,
            'status'            => $status,
        ];
    }

    /** @param array<string,mixed> $fields */
    private static function validateRelations(int $siteId, array $fields): void
    {
        $coverId = self::nullableId($fields['cover_media_id'] ?? null);
        if ($coverId !== null) {
            $cover = Database::selectOne(
                "SELECT id FROM media WHERE id = ? AND site_id = ? AND mime_type LIKE 'image/%' LIMIT 1",
                [$coverId, $siteId]
            );
            if ($cover === null) {
                throw new InvalidArgumentException(__('resource.err.cover_invalid'));
            }
        }

        $formId = self::nullableId($fields['form_id'] ?? null);
        if ($formId !== null) {
            $form = Database::selectOne(
                "SELECT ps.id
                   FROM page_sections ps
                   JOIN pages p ON p.id = ps.page_id
                  WHERE ps.id = ? AND p.site_id = ? AND ps.section_type = 'form'
                    AND ps.status <> 'deleted' LIMIT 1",
                [$formId, $siteId]
            );
            if ($form === null) {
                throw new InvalidArgumentException(__('resource.err.form_invalid'));
            }
        }
    }

    /** @param array<string,mixed> $f */
    private static function validateForPersistence(int $siteId, array $f): void
    {
        $parts = [$f['file_path'], $f['original_filename'], $f['file_mime'], $f['file_size']];
        $present = count(array_filter($parts, static fn (mixed $v): bool => $v !== null && $v !== ''));
        if ($present > 0 && $present < 4) {
            throw new InvalidArgumentException(__('resource.err.file_meta_incomplete'));
        }

        if ($present === 4) {
            $prefix = 'storage/resources/' . $siteId . '/';
            if (!str_starts_with((string) $f['file_path'], $prefix)
                || str_contains((string) $f['file_path'], '..')) {
                throw new InvalidArgumentException(__('resource.err.file_path_invalid'));
            }
            $mime = (string) $f['file_mime'];
            $expectedExt = match ($mime) {
                'application/pdf' => 'pdf',
                'application/epub+zip' => 'epub',
                default => null,
            };
            $pathExt = strtolower(pathinfo((string) $f['file_path'], PATHINFO_EXTENSION));
            $nameExt = strtolower(pathinfo((string) $f['original_filename'], PATHINFO_EXTENSION));
            if ($expectedExt === null || $pathExt !== $expectedExt || $nameExt !== $expectedExt) {
                throw new InvalidArgumentException(__('resource.err.file_type_invalid'));
            }
            if ((int) $f['file_size'] <= 0 || (int) $f['file_size'] > self::MAX_FILE_SIZE) {
                throw new InvalidArgumentException(__('resource.err.file_size_invalid'));
            }
        }

        if ($f['status'] === 'published') {
            if ($present !== 4) {
                throw new InvalidArgumentException(__('resource.err.publish_no_file'));
            }
            if ($f['access_mode'] === 'form' && $f['form_id'] === null) {
                throw new InvalidArgumentException(__('resource.err.publish_no_form'));
            }
        }
    }

    private static function uniqueSlug(int $siteId, string $language, string $title, ?int $ignoreId, string $scope = 'selected', array $languages = []): string
    {
        $base = mb_substr(slugify($title), 0, 170);
        if ($base === '') $base = 'recurso';
        $slug = $base;
        $n = 2;
        while (self::slugTaken($siteId, $slug, $ignoreId, $scope, $languages !== [] ? $languages : [$language])) {
            $suffix = '-' . $n++;
            $slug = mb_substr($base, 0, 180 - mb_strlen($suffix)) . $suffix;
        }
        return $slug;
    }

    private static function slugTaken(int $siteId, string $slug, ?int $ignoreId, string $scope, array $languages): bool
    {
        $sql = 'SELECT id, language, language_scope FROM resources WHERE site_id = ? AND slug = ?';
        $params = [$siteId, $slug];
        if ($ignoreId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $ignoreId;
        }
        foreach (Database::select($sql, $params) as $row) {
            if ($scope === 'all' || (string) ($row['language_scope'] ?? 'selected') === 'all') return true;
            $other = self::storedLanguages((int) $row['id'], (string) $row['language']);
            if (array_intersect($languages, $other) !== []) return true;
        }
        return false;
    }

    /** Idiomas efectivos; `all` incluye también los activados en el futuro. */
    public static function visibleLanguages(int $siteId, array $resource): array
    {
        return self::visibleLanguagesFrom($resource, self::contentLanguagesForSite($siteId));
    }

    /** @param string[] $available @return string[] */
    private static function visibleLanguagesFrom(array $resource, array $available): array
    {
        if ((string) ($resource['language_scope'] ?? 'selected') === 'all') {
            return $available;
        }
        $languages = isset($resource['languages']) && is_array($resource['languages'])
            ? $resource['languages']
            : self::storedLanguages((int) ($resource['id'] ?? 0), (string) ($resource['language'] ?? ''));
        return array_values(array_filter($languages, static fn(string $code): bool => in_array($code, $available, true)));
    }

    /**
     * Un idioma de página existente también es contexto público válido aunque
     * falte en `site_languages` (instalaciones antiguas o páginas importadas).
     */
    public static function languageAvailableForSite(int $siteId, string $language): bool
    {
        $language = strtolower(trim($language));
        return LanguageService::isSupported($language)
            && in_array($language, self::contentLanguagesForSite($siteId), true);
    }

    /** @return string[] */
    private static function contentLanguagesForSite(int $siteId): array
    {
        $languages = LanguageService::activeFor($siteId);
        foreach (Database::select(
            "SELECT DISTINCT language FROM pages WHERE site_id = ? AND language IS NOT NULL AND language <> ''",
            [$siteId]
        ) as $row) {
            $code = strtolower(trim((string) ($row['language'] ?? '')));
            if (LanguageService::isSupported($code) && !in_array($code, $languages, true)) {
                $languages[] = $code;
            }
        }
        return $languages;
    }

    private static function syncLanguages(int $resourceId, string $scope, array $languages): void
    {
        Database::execute('DELETE FROM resource_languages WHERE resource_id = ?', [$resourceId]);
        if ($scope === 'all') return;
        foreach ($languages as $language) {
            Database::execute(
                'INSERT INTO resource_languages (resource_id, language) VALUES (?, ?)',
                [$resourceId, $language]
            );
        }
    }

    private static function storedLanguages(int $resourceId, string $fallback): array
    {
        $rows = $resourceId > 0 ? Database::select(
            'SELECT language FROM resource_languages WHERE resource_id = ? ORDER BY language',
            [$resourceId]
        ) : [];
        $out = array_values(array_map(static fn(array $row): string => (string) $row['language'], $rows));
        return $out !== [] ? $out : ($fallback !== '' ? [$fallback] : []);
    }

    /** @param string[]|null $available */
    private static function hydrateLanguages(int $siteId, array $row, ?array $available = null): array
    {
        $row['language_scope'] = (string) ($row['language_scope'] ?? 'selected');
        $row['languages'] = self::visibleLanguagesFrom($row, $available ?? self::contentLanguagesForSite($siteId));
        return $row;
    }

    private static function hydrateMany(int $siteId, array $rows): array
    {
        $available = self::contentLanguagesForSite($siteId);
        return array_map(static fn(array $row): array => self::hydrateLanguages($siteId, $row, $available), $rows);
    }

    private static function newUuid(): string
    {
        return (string) (Database::selectOne('SELECT UUID() AS uuid')['uuid'] ?? '');
    }

    private static function nullableId(mixed $value): ?int
    {
        return ($value === null || $value === '' || (int) $value <= 0) ? null : (int) $value;
    }

    private static function nullableText(mixed $value, int $limit): ?string
    {
        $text = mb_substr(trim((string) ($value ?? '')), 0, $limit);
        return $text === '' ? null : $text;
    }

    private static function selectSql(): string
    {
        return "SELECT r.*, m.path AS cover_path, m.alt_text AS cover_alt,
                       CASE
                         WHEN ps.id IS NULL THEN NULL
                         ELSE JSON_UNQUOTE(JSON_EXTRACT(ps.content, '$.heading'))
                       END AS form_heading,
                       ps.status AS form_status
                  FROM resources r
                  LEFT JOIN media m ON m.id = r.cover_media_id AND m.site_id = r.site_id
                  LEFT JOIN page_sections ps ON ps.id = r.form_id AND ps.section_type = 'form'";
    }
}
