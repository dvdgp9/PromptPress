<?php

declare(strict_types=1);

namespace App\Services;

use Core\Auth;
use Core\Database;

/**
 * PromptPress — El panel de control, en el idioma del cliente (ADMIN-I18N).
 *
 * La web pública ya habla siete idiomas (`LanguageService`, `Microcopy`), pero
 * el panel seguía siendo castellano puro: un cliente francés tenía una web
 * perfecta en francés y un panel que no entendía. Esto lo arregla.
 *
 * NO se mezcla con `Microcopy`, aunque se parezcan: aquel traduce lo que ve el
 * VISITANTE de la web (botón de enviar, banner de cookies) y su idioma es el de
 * la página; este traduce lo que ve el GESTOR, y su idioma es el suyo. Son dos
 * decisiones distintas que a veces coinciden.
 *
 * Reglas de uso, que son las que evitan los dos errores clásicos:
 *
 *   1. `<?= e(__('pages.title')) ?>` — el texto vuelve SIN escapar y se escapa
 *      en la vista, como cualquier otra variable. Si una clave lleva HTML a
 *      propósito, se llama `algo.html` y se pinta sin `e()`. El test
 *      `tests/admin_i18n.php` falla si aparece HTML en una clave que no acaba
 *      en `.html`.
 *
 *   2. Solo se traduce lo que se PINTA. Los prompts que viajan a la IA, los
 *      `name=` de los formularios, las claves de array y los valores que se
 *      guardan en base de datos se quedan en castellano para siempre: traducir
 *      un prompt rompe la generación de contenido, y en silencio.
 *
 * Añadir un idioma = crear `lang/admin/xx.php` y añadir `xx` a `LOCALES`. Nada
 * más. Los idiomas soportados por la web que no tengan catálogo aquí (`ca`,
 * `gl`, `eu` hoy) degradan a castellano, que es correcto, no un error.
 */
final class AdminI18n
{
    /** Idiomas con catálogo de panel. Subconjunto de `LanguageService::LANGUAGES`. */
    public const LOCALES = ['es', 'en', 'fr', 'pt'];

    /** Idioma fuente: el que se escribe a mano y del que se traduce todo. */
    public const SOURCE = 'es';

    /** Prefijo de las claves que viajan al navegador. */
    private const JS_PREFIX = 'js.';

    /** Sufijo de las claves del panel que también necesita el JavaScript. */
    private const JS_SUFFIX = '_js';

    /** Idioma activo del request. `null` = aún sin resolver. */
    private static ?string $locale = null;

    /** @var array<string, array<string,string>> Catálogos ya cargados. */
    private static array $catalogs = [];

    // =====================================================================
    // Idioma activo
    // =====================================================================

    /**
     * Idioma del panel para este request. Se resuelve una vez y se recuerda:
     * a mitad de request no puede cambiar, y leer la BD en cada `__()` sería
     * absurdo (una pantalla del panel llama a esto cientos de veces).
     */
    public static function locale(): string
    {
        if (self::$locale !== null) {
            return self::$locale;
        }

        return self::$locale = self::resolveFrom(
            self::userPreference(),
            self::sitePrimary(),
            $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null
        );
    }

    /**
     * Fija el idioma a mano. Para los tests y para el instalador/onboarding,
     * donde todavía no hay ni usuario ni sitio de los que deducirlo.
     */
    public static function setLocale(string $locale): void
    {
        $locale = strtolower(trim($locale));
        self::$locale = in_array($locale, self::LOCALES, true) ? $locale : self::SOURCE;
    }

    /** Obliga a resolver de nuevo. Tras guardar la preferencia del usuario. */
    public static function forget(): void
    {
        self::$locale = null;
    }

    /**
     * La cascada, aislada de la base de datos para poder probarla de verdad:
     *
     *   preferencia del usuario → idioma principal del sitio → navegador → es
     *
     * Cada escalón solo cuenta si ese idioma tiene catálogo de panel. Un sitio
     * en catalán con un usuario sin preferencia da panel en castellano, no un
     * panel a medio traducir.
     *
     * El navegador es el último recurso a propósito: solo manda durante el alta,
     * cuando el sitio aún no tiene idioma. En cuanto lo tiene, el sitio gana —
     * si no, cambiar de portátil cambiaría el idioma del panel.
     */
    public static function resolveFrom(?string $userLang, ?string $siteLang, ?string $acceptLanguage): string
    {
        foreach ([$userLang, $siteLang] as $candidate) {
            $code = strtolower(trim((string) $candidate));
            if ($code !== '' && in_array($code, self::LOCALES, true)) {
                return $code;
            }
        }

        $fromBrowser = self::parseAcceptLanguage($acceptLanguage);
        if ($fromBrowser !== null) {
            return $fromBrowser;
        }

        return self::SOURCE;
    }

    /**
     * Primer idioma del `Accept-Language` que tenga catálogo, respetando los
     * pesos `q`. Devuelve `null` si el header no sirve o no trae nada nuestro.
     */
    private static function parseAcceptLanguage(?string $header): ?string
    {
        $header = trim((string) $header);
        if ($header === '') {
            return null;
        }

        $weighted = [];
        foreach (explode(',', $header) as $part) {
            $bits = explode(';', trim($part));
            $tag  = strtolower(trim($bits[0]));
            if ($tag === '') {
                continue;
            }
            // 'fr-FR' y 'fr' son el mismo idioma para nosotros.
            $code = explode('-', $tag)[0];
            if (!in_array($code, self::LOCALES, true)) {
                continue;
            }
            $q = 1.0;
            foreach (array_slice($bits, 1) as $param) {
                if (str_starts_with(trim($param), 'q=')) {
                    $q = (float) substr(trim($param), 2);
                }
            }
            // El primero que aparece gana los empates: `array_key_exists` en vez
            // de `max()` para no reordenar idiomas con el mismo peso.
            if (!array_key_exists($code, $weighted) || $q > $weighted[$code]) {
                $weighted[$code] = $q;
            }
        }

        if ($weighted === []) {
            return null;
        }

        arsort($weighted);
        return (string) array_key_first($weighted);
    }

    /** Preferencia guardada del usuario logueado, o `null` si hereda del sitio. */
    private static function userPreference(): ?string
    {
        $userId = Auth::id();
        if ($userId === null) {
            return null;
        }

        try {
            $row = Database::selectOne('SELECT language FROM users WHERE id = ? LIMIT 1', [$userId]);
        } catch (\Throwable $e) {
            // La columna puede no existir todavía (instalación sin migrar): el
            // panel en castellano es infinitamente mejor que un panel caído.
            return null;
        }

        $code = strtolower(trim((string) ($row['language'] ?? '')));
        return $code !== '' ? $code : null;
    }

    /**
     * Idioma principal del sitio en sesión.
     *
     * Antes del login no hay sesión, y ahí está justo la pantalla que más
     * importa que esté bien: el formulario de acceso. En una instalación de un
     * solo sitio —que es la inmensa mayoría— se usa el idioma de ESE sitio. Con
     * varios no se puede adivinar cuál, así que se deja decidir al navegador.
     */
    private static function sitePrimary(): ?string
    {
        $siteId = Auth::siteId();

        try {
            if ($siteId === null) {
                $rows = Database::select('SELECT id FROM sites LIMIT 2');
                if (count($rows) !== 1) {
                    return null;
                }
                $siteId = (int) $rows[0]['id'];
            }

            return LanguageService::primaryFor($siteId);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Valor para el atributo `lang` del `<html>`. */
    public static function htmlLang(): string
    {
        return self::locale();
    }

    // =====================================================================
    // Traducción
    // =====================================================================

    /**
     * Texto de una clave, en el idioma activo.
     *
     * Nunca lanza y nunca devuelve cadena vacía: si falta en el idioma activo
     * cae al castellano, y si tampoco está ahí devuelve la propia clave. Una
     * traducción olvidada tiene que verse fea en pantalla, no tumbar el panel
     * ni dejar un hueco invisible que nadie reporta.
     *
     * @param array<string, string|int|float> $vars
     */
    public static function t(string $key, array $vars = []): string
    {
        $text = self::catalog(self::locale())[$key]
            ?? self::catalog(self::SOURCE)[$key]
            ?? $key;

        return $vars === [] ? $text : self::interpolate($text, $vars);
    }

    /**
     * Sustituye `{nombre}` por su valor. Se eligió esta sintaxis y no `%s`
     * porque el traductor (humano o IA) tiene que poder REORDENAR la frase sin
     * romperla, y con `%s` posicional eso es imposible en francés o portugués,
     * donde el orden de los complementos cambia.
     *
     * Lo que no venga en `$vars` se deja tal cual: mejor un `{n}` visible que
     * una frase mutilada.
     *
     * @param array<string, string|int|float> $vars
     */
    public static function interpolate(string $text, array $vars): string
    {
        if ($vars === []) {
            return $text;
        }

        $map = [];
        foreach ($vars as $name => $value) {
            $map['{' . $name . '}'] = (string) $value;
        }

        return strtr($text, $map);
    }

    /** ¿Existe la clave en el idioma fuente? Útil en migraciones graduales. */
    public static function has(string $key): bool
    {
        return isset(self::catalog(self::SOURCE)[$key]);
    }

    /**
     * Catálogo completo de un idioma. Cacheado por request; los ficheros son
     * PHP planos precisamente para que opcache los sirva sin parsear nada.
     *
     * @return array<string,string>
     */
    public static function catalog(string $locale): array
    {
        $locale = strtolower(trim($locale));
        if (!in_array($locale, self::LOCALES, true)) {
            $locale = self::SOURCE;
        }

        if (isset(self::$catalogs[$locale])) {
            return self::$catalogs[$locale];
        }

        $file = PP_ROOT . '/lang/admin/' . $locale . '.php';
        $data = is_file($file) ? require $file : [];

        return self::$catalogs[$locale] = is_array($data) ? $data : [];
    }

    /**
     * Las claves que necesita el JavaScript, ya resueltas al idioma activo.
     *
     * No viaja todo el catálogo: mandar las ~2.000 cadenas del panel al
     * navegador en cada carga sería tirar ancho de banda por un texto que casi
     * nunca se llega a ver. El resto se traduce en el servidor, donde ya está
     * el dato. Viajan dos formas:
     *
     *   - prefijo `js.` — cadenas que solo existen para el JS;
     *   - sufijo `_js`  — una cadena del panel que ADEMÁS necesita el JS
     *     (`nav.design_js`, `table.status_js`…), para no duplicar el texto.
     *
     * El sufijo faltaba aquí: sus 15 claves se usaban con `pp.t()` y el panel
     * pintaba el nombre de la clave en crudo ("nav.design_js") en el editor de
     * secciones, el de header/pie, el studio y el banco de imágenes.
     *
     * @return array<string,string>
     */
    public static function jsCatalog(): array
    {
        $out = [];
        foreach (array_keys(self::catalog(self::SOURCE)) as $key) {
            if (str_starts_with($key, self::JS_PREFIX) || str_ends_with($key, self::JS_SUFFIX)) {
                $out[$key] = self::t($key);
            }
        }
        return $out;
    }

    // =====================================================================
    // Solo para los tests
    // =====================================================================

    /** @param array<string,string> $strings */
    public static function injectForTesting(string $locale, array $strings): void
    {
        $locale = strtolower(trim($locale));
        self::$catalogs[$locale] = array_merge(self::catalog($locale), $strings);
    }

    public static function resetForTesting(): void
    {
        self::$catalogs = [];
        self::$locale   = null;
    }
}
