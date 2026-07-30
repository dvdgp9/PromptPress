<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;

/**
 * PromptPress — Idioma del sitio (fuente única de verdad).
 *
 * Hasta ahora el idioma vivía en tres sitios: la lista del instalador, la de
 * `SettingsController` y un `languageLabel()` privado en el generador de
 * páginas legales. Y, sobre todo, el pipeline de generación IA ignoraba
 * `sites.language`: pasaba `'es'` literal, así que una web en francés se
 * generaba en castellano.
 *
 * Este servicio centraliza:
 *   - qué idiomas se admiten (`LANGUAGES`, etiqueta para la UI),
 *   - qué idioma tiene un sitio (`codeFor`, cacheado por request),
 *   - cómo se le nombra a la IA (`promptLabel`: el endónimo, "français").
 *
 * Un sitio = un idioma. El multi-idioma real (idioma por página, rutas /fr/,
 * hreflang) NO está aquí: es otro proyecto.
 */
final class LanguageService
{
    /** Idiomas admitidos: código ISO 639-1 => etiqueta para la UI (endónimo). */
    public const LANGUAGES = [
        'es' => 'Español',
        'en' => 'English',
        'ca' => 'Català',
        'gl' => 'Galego',
        'eu' => 'Euskara',
        'fr' => 'Français',
        'pt' => 'Português',
    ];

    public const DEFAULT = 'es';

    /** @var array<int,string> Caché por request: siteId => código. */
    private static array $cache = [];

    public static function isSupported(string $code): bool
    {
        return array_key_exists(strtolower(trim($code)), self::LANGUAGES);
    }

    /**
     * Normaliza cualquier entrada a un código admitido (o el idioma por defecto).
     */
    public static function normalize(?string $code): string
    {
        $code = strtolower(trim((string) $code));
        return self::isSupported($code) ? $code : self::DEFAULT;
    }

    /**
     * Idioma configurado del sitio. Nunca lanza: si la BD falla o el sitio no
     * existe, devuelve el idioma por defecto (la generación no debe caerse por
     * no poder leer un ajuste).
     */
    public static function codeFor(int $siteId): string
    {
        if (isset(self::$cache[$siteId])) {
            return self::$cache[$siteId];
        }

        $code = self::DEFAULT;
        try {
            $row = Database::selectOne('SELECT language FROM sites WHERE id = ? LIMIT 1', [$siteId]);
            $code = self::normalize($row['language'] ?? null);
        } catch (\Throwable $e) {
            // Silencio deliberado: fallback al idioma por defecto.
        }

        return self::$cache[$siteId] = $code;
    }

    /** Etiqueta para la interfaz de administración ("Français"). */
    public static function label(string $code): string
    {
        return self::LANGUAGES[self::normalize($code)];
    }

    /**
     * Nombre del idioma tal y como se le dice a la IA. Se usa el endónimo en
     * minúscula ("français", "português") porque instruir al modelo EN el
     * idioma de destino ancla mucho mejor la salida que pasarle el código ISO.
     */
    public static function promptLabel(string $code): string
    {
        return match (self::normalize($code)) {
            'es' => 'español',
            'en' => 'English',
            'ca' => 'català',
            'gl' => 'galego',
            'eu' => 'euskara',
            'fr' => 'français',
            'pt' => 'português',
            default => 'español',
        };
    }

    /** Atajo: nombre para prompts a partir del sitio. */
    public static function promptLabelFor(int $siteId): string
    {
        return self::promptLabel(self::codeFor($siteId));
    }

    /**
     * Invalida la caché. Obligatorio tras guardar Ajustes: dentro del mismo
     * request se puede haber leído ya el idioma anterior.
     */
    public static function forget(?int $siteId = null): void
    {
        if ($siteId === null) {
            self::$cache = [];
            self::$activeCache = [];
            return;
        }
        unset(self::$cache[$siteId], self::$activeCache[$siteId]);
    }

    // =====================================================================
    // Multi-idioma (I18N-FULL fase 1)
    //
    // Un sitio sin configurar tiene UN idioma activo —el suyo, como principal—
    // y se comporta exactamente igual que antes de todo esto. Los idiomas
    // adicionales se activan a mano desde Ajustes.
    // =====================================================================

    /** @var array<int,array<int,array{code:string,is_primary:bool}>> */
    private static array $activeCache = [];

    /**
     * Idiomas activos del sitio, el principal primero.
     *
     * @return array<int,string>
     */
    public static function activeFor(int $siteId): array
    {
        return array_column(self::rows($siteId), 'code');
    }

    /** Idioma principal: el que NO lleva prefijo en las URLs. */
    public static function primaryFor(int $siteId): string
    {
        foreach (self::rows($siteId) as $row) {
            if ($row['is_primary']) {
                return $row['code'];
            }
        }
        return self::codeFor($siteId);
    }

    /** ¿El sitio sirve más de un idioma? */
    public static function isMultilingual(int $siteId): bool
    {
        return count(self::rows($siteId)) > 1;
    }

    /**
     * Idioma de una página concreta. Manda el suyo; si no lo tiene (fila
     * anterior a la migración), el del sitio.
     *
     * @param array<string,mixed> $page
     */
    public static function forPage(array $page, int $siteId): string
    {
        $lang = trim((string) ($page['language'] ?? ''));
        return $lang !== '' ? self::normalize($lang) : self::codeFor($siteId);
    }

    /**
     * Prefijo de URL de un idioma: cadena vacía para el principal, el código
     * para los demás. Es la decisión que deja intactas las URLs de las webs ya
     * publicadas: `/contacto` sigue siendo `/contacto`, y el francés vive en
     * `/fr/contact`.
     */
    public static function prefixFor(int $siteId, string $lang): string
    {
        $lang = self::normalize($lang);
        return $lang === self::primaryFor($siteId) ? '' : $lang;
    }

    /** Activa un idioma adicional. Idempotente. */
    public static function enable(int $siteId, string $lang): array
    {
        $lang = trim(strtolower($lang));
        if (!self::isSupported($lang)) {
            return ['ok' => false, 'error' => 'unsupported'];
        }
        if (in_array($lang, self::activeFor($siteId), true)) {
            return ['ok' => true, 'already' => true];
        }
        Database::execute(
            'INSERT INTO site_languages (site_id, code, is_primary, sort_order)
             VALUES (?, ?, 0, (SELECT COALESCE(MAX(x.sort_order), 0) + 1 FROM (SELECT sort_order FROM site_languages WHERE site_id = ?) x))',
            [$siteId, $lang, $siteId]
        );
        self::forget($siteId);
        return ['ok' => true];
    }

    /**
     * Desactiva un idioma adicional.
     *
     * Dos guardas deliberadas, porque esto es destructivo si se hace mal:
     *  - el idioma PRINCIPAL nunca se puede quitar (el sitio se quedaría sin home);
     *  - un idioma con páginas tampoco: primero hay que borrar o mover ese
     *    contenido. Desactivar no debe hacer desaparecer páginas de la web.
     *
     * @return array{ok:bool, error?:string, pages?:int}
     */
    public static function disable(int $siteId, string $lang): array
    {
        $lang = self::normalize($lang);
        if ($lang === self::primaryFor($siteId)) {
            return ['ok' => false, 'error' => 'primary'];
        }

        $row = Database::selectOne(
            'SELECT COUNT(*) c FROM pages WHERE site_id = ? AND language = ?',
            [$siteId, $lang]
        );
        $pages = (int) ($row['c'] ?? 0);
        if ($pages > 0) {
            return ['ok' => false, 'error' => 'has_pages', 'pages' => $pages];
        }

        Database::execute(
            'DELETE FROM site_languages WHERE site_id = ? AND code = ? AND is_primary = 0',
            [$siteId, $lang]
        );
        self::forget($siteId);
        return ['ok' => true];
    }

    /**
     * Cambia el idioma PRINCIPAL del sitio (el que vive sin prefijo).
     *
     * En una web multi-idioma esto reescribiría el esquema de URLs de todas sus
     * páginas —la que no llevaba prefijo pasaría a llevarlo y viceversa—, así
     * que se rechaza: primero hay que desactivar los idiomas adicionales. En una
     * web de un solo idioma es un cambio inocuo y se aplica.
     *
     * @return array{ok:bool, error?:string}
     */
    public static function setPrimary(int $siteId, string $lang): array
    {
        $lang = trim(strtolower($lang));
        if (!self::isSupported($lang)) {
            return ['ok' => false, 'error' => 'unsupported'];
        }
        if ($lang === self::primaryFor($siteId)) {
            return ['ok' => true];
        }
        if (self::isMultilingual($siteId)) {
            return ['ok' => false, 'error' => 'multilingual'];
        }

        try {
            Database::execute(
                'UPDATE site_languages SET code = ? WHERE site_id = ? AND is_primary = 1',
                [$lang, $siteId]
            );
            // Sitio migrado sin fila (no debería, pero no dejamos el catálogo vacío).
            $has = Database::selectOne('SELECT id FROM site_languages WHERE site_id = ? LIMIT 1', [$siteId]);
            if ($has === null) {
                Database::execute(
                    'INSERT INTO site_languages (site_id, code, is_primary, sort_order) VALUES (?, ?, 1, 0)',
                    [$siteId, $lang]
                );
            }
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'db'];
        }

        self::forget($siteId);
        return ['ok' => true];
    }

    /** ¿Es `$code` uno de los idiomas activos del sitio? */
    public static function isActive(int $siteId, string $code): bool
    {
        return in_array(strtolower(trim($code)), self::activeFor($siteId), true);
    }

    /**
     * Coloca el prefijo de idioma que corresponde dentro del slug.
     *
     * El prefijo vive DENTRO de `pages.slug` (que admite barras y ya se usa
     * así para páginas anidadas). Gracias a eso el catch-all `/{slug:path}`
     * resuelve `/fr/contact` sin tocar el enrutado.
     *
     * Es idempotente y reescribe: si el slug ya trae un prefijo de idioma
     * activo, se sustituye en vez de acumularse (`fr/fr/contact` sería un bug).
     */
    public static function applySlugPrefix(int $siteId, string $slug, string $lang): string
    {
        $segments = array_values(array_filter(explode('/', trim($slug, '/')), static fn ($s) => $s !== ''));
        if ($segments !== [] && self::isActive($siteId, $segments[0])) {
            array_shift($segments);
        }
        $rest = implode('/', $segments);

        $prefix = self::prefixFor($siteId, $lang);
        if ($prefix === '') {
            return $rest;
        }
        return $rest === '' ? $prefix : $prefix . '/' . $rest;
    }

    /**
     * ¿Este slug invade el espacio de nombres de OTRO idioma activo?
     *
     * Sin esta guarda, una página del idioma principal llamada «fr» dejaría sin
     * home al francés. Un idioma que NO está activo no reserva nada: `/pt/algo`
     * es un slug legítimo mientras el portugués esté apagado.
     */
    public static function slugCollidesWithLanguage(int $siteId, string $slug, string $lang): bool
    {
        $slug = trim($slug, '/');
        if ($slug === '') {
            return false;
        }
        $first = strtolower(explode('/', $slug)[0]);
        if (!self::isActive($siteId, $first)) {
            return false;
        }
        return $first !== self::normalize($lang);
    }

    /**
     * Si el slug es exactamente el prefijo de un idioma secundario activo,
     * devuelve ese idioma: `/fr` y `/fr/` son la home francesa. Si no, null.
     */
    public static function languageFromHomeSlug(int $siteId, string $slug): ?string
    {
        $slug = strtolower(trim($slug, '/'));
        if ($slug === '' || !self::isActive($siteId, $slug)) {
            return null;
        }
        return $slug === self::primaryFor($siteId) ? null : $slug;
    }

    /**
     * Filas de `site_languages` cacheadas. Si la tabla aún no existe (sitio sin
     * migrar) o está vacía, se comporta como un sitio de un solo idioma.
     *
     * @return array<int,array{code:string,is_primary:bool}>
     */
    private static function rows(int $siteId): array
    {
        if (isset(self::$activeCache[$siteId])) {
            return self::$activeCache[$siteId];
        }

        $rows = [];
        try {
            $found = Database::select(
                'SELECT code, is_primary FROM site_languages WHERE site_id = ?
                 ORDER BY is_primary DESC, sort_order ASC, code ASC',
                [$siteId]
            );
            foreach ($found as $r) {
                $code = self::normalize((string) $r['code']);
                $rows[] = ['code' => $code, 'is_primary' => (bool) $r['is_primary']];
            }
        } catch (\Throwable $e) {
            // Sin tabla todavía: un solo idioma.
        }

        if ($rows === []) {
            $rows = [['code' => self::codeFor($siteId), 'is_primary' => true]];
        }

        return self::$activeCache[$siteId] = $rows;
    }
}
