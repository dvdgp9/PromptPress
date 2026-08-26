<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;

/**
 * FONTS — Tipografías propias del cliente (brandbook).
 *
 * Modelo: una FAMILIA (nombre + rol + fallback) con N ARCHIVOS, uno por corte
 * (peso + estilo). Cada archivo genera su propio `@font-face`, que es lo que
 * evita que el navegador sintetice negritas o cursivas falsas.
 *
 * Los ficheros viven en `storage/uploads/{siteId}/fonts/` (fuera del docroot) y
 * se sirven por la ruta `/brand-assets/{site}/font/{id}`, igual que el logo.
 *
 * En los tokens del design system una familia propia se referencia como
 * `custom:{slug}` — ver DesignSystem::fontCssValue().
 */
final class CustomFontService
{
    // Las constantes se evalúan antes de saber el idioma: guardan la clave y
    // se resuelven en `roles()` al pintar.
    public const ROLES = [
        'both'    => 'font.role.both',
        'heading' => 'font.role.heading',
        'body'    => 'font.role.body',
        'none'    => 'font.role.none',
    ];

    /** Los roles con la etiqueta ya traducida, para el panel. */
    public static function roles(): array
    {
        return array_map(static fn (string $k): string => __($k), self::ROLES);
    }

    /** Peso máximo por archivo. Una fuente web razonable no llega ni de lejos. */
    public const MAX_FILE_BYTES = 3 * 1024 * 1024;

    /** Formatos aceptados => valor `format()` del @font-face + MIME al servir. */
    public const FORMATS = [
        'woff2' => ['css' => 'woff2', 'mime' => 'font/woff2'],
        'woff'  => ['css' => 'woff',  'mime' => 'font/woff'],
        'ttf'   => ['css' => 'truetype', 'mime' => 'font/ttf'],
        'otf'   => ['css' => 'opentype', 'mime' => 'font/otf'],
    ];

    /** Nombre humano de cada peso, para hablarle claro a quien no es técnico. */
    public const WEIGHT_LABELS = [
        100 => 'Thin (100)',
        200 => 'Extra Light (200)',
        300 => 'Light (300)',
        400 => 'Regular (400)',
        500 => 'Medium (500)',
        600 => 'Semibold (600)',
        700 => 'Bold (700)',
        800 => 'Extra Bold (800)',
        900 => 'Black (900)',
    ];

    // ------------------------------------------------------------------
    // Lectura
    // ------------------------------------------------------------------

    /**
     * Familias del sitio con sus archivos, ordenadas por nombre.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function families(int $siteId): array
    {
        try {
            $families = Database::select(
                'SELECT * FROM site_font_families WHERE site_id = ? ORDER BY name ASC',
                [$siteId]
            );
            if ($families === []) return [];

            $files = Database::select(
                'SELECT * FROM site_font_files WHERE site_id = ? ORDER BY weight ASC, style ASC',
                [$siteId]
            );
        } catch (\Throwable $e) {
            // Instalaciones que aún no han corrido la migración: sin fuentes propias.
            return [];
        }

        $byFamily = [];
        foreach ($files as $f) {
            $byFamily[(int) $f['family_id']][] = [
                'id'            => (int) $f['id'],
                'weight'        => (int) $f['weight'],
                'style'         => (string) $f['style'],
                'format'        => (string) $f['format'],
                'path'          => (string) $f['path'],
                'original_name' => (string) $f['original_name'],
                'file_size'     => (int) $f['file_size'],
                'label'         => self::cutLabel((int) $f['weight'], (string) $f['style']),
                'url'           => base_url('brand-assets/' . $siteId . '/font/' . (int) $f['id']),
            ];
        }

        $out = [];
        foreach ($families as $fam) {
            $id = (int) $fam['id'];
            $out[] = [
                'id'       => $id,
                'name'     => (string) $fam['name'],
                'slug'     => (string) $fam['slug'],
                'token'    => 'custom:' . (string) $fam['slug'],
                'role'     => (string) $fam['role'],
                'fallback' => (string) $fam['fallback_stack'],
                'files'    => $byFamily[$id] ?? [],
            ];
        }
        return $out;
    }

    /** @return array<string,mixed>|null */
    public static function familyBySlug(int $siteId, string $slug): ?array
    {
        foreach (self::families($siteId) as $fam) {
            if ($fam['slug'] === $slug) return $fam;
        }
        return null;
    }

    /**
     * Familia asignada a un rol ('heading' | 'body'), si la hay.
     * Una familia con rol 'both' cubre los dos.
     *
     * @return array<string,mixed>|null
     */
    public static function familyForRole(int $siteId, string $role): ?array
    {
        foreach (self::families($siteId) as $fam) {
            if ($fam['files'] === []) continue; // familia sin archivos = no aplicable
            if ($fam['role'] === $role || $fam['role'] === 'both') return $fam;
        }
        return null;
    }

    /**
     * Opciones para los selects de fuente: ['custom:slug' => 'Nombre (tuya)'].
     *
     * @return array<string,string>
     */
    public static function fontOptions(int $siteId): array
    {
        $out = [];
        foreach (self::families($siteId) as $fam) {
            if ($fam['files'] === []) continue;
            // DESIGN-MANDA T12 — El sufijo va traducido: el panel puede estar
            // en cualquiera de los 4 idiomas y esto se pinta en el select.
            $out[$fam['token']] = $fam['name'] . ' ' . __('design.font_own_suffix');
        }
        return $out;
    }

    public static function hasAny(int $siteId): bool
    {
        return self::fontOptions($siteId) !== [];
    }

    // ------------------------------------------------------------------
    // Escritura
    // ------------------------------------------------------------------

    /**
     * Crea (o recupera) una familia por nombre.
     *
     * @return int id de la familia
     */
    public static function ensureFamily(int $siteId, string $name, ?string $role = null): int
    {
        $name = trim($name);
        if ($name === '') $name = __('font.default_name');
        $name = mb_substr($name, 0, 120);
        $slug = self::slugFor($name);

        $existing = Database::selectOne(
            'SELECT id FROM site_font_families WHERE site_id = ? AND slug = ? LIMIT 1',
            [$siteId, $slug]
        );
        if ($existing) {
            $id = (int) $existing['id'];
            if ($role !== null) self::assignRole($siteId, $id, $role);
            return $id;
        }

        Database::execute(
            'INSERT INTO site_font_families (site_id, name, slug, role, fallback_stack) VALUES (?, ?, ?, ?, ?)',
            [$siteId, $name, $slug, self::normalizeRole($role ?? 'none'), '']
        );
        return (int) Database::lastInsertId();
    }

    /**
     * Asigna rol a una familia liberando el rol en las demás: un rol, una
     * familia. Si no, el sitio tendría dos candidatas para "títulos" y la
     * elegida dependería del orden de lectura.
     */
    public static function assignRole(int $siteId, int $familyId, string $role): void
    {
        $role = self::normalizeRole($role);

        if ($role !== 'none') {
            // 'both' libera los dos roles; 'heading'/'body' solo el suyo, y a una
            // familia 'both' le queda el rol contrario.
            foreach (Database::select('SELECT id, role FROM site_font_families WHERE site_id = ? AND id <> ?', [$siteId, $familyId]) as $other) {
                $otherRole = (string) $other['role'];
                if ($otherRole === 'none') continue;
                $newRole = self::releaseRole($otherRole, $role);
                if ($newRole !== $otherRole) {
                    Database::execute('UPDATE site_font_families SET role = ? WHERE id = ? AND site_id = ?', [$newRole, (int) $other['id'], $siteId]);
                }
            }
        }

        Database::execute(
            'UPDATE site_font_families SET role = ? WHERE id = ? AND site_id = ?',
            [$role, $familyId, $siteId]
        );
    }

    /** Qué rol le queda a `$current` cuando otra familia reclama `$claimed`. */
    private static function releaseRole(string $current, string $claimed): string
    {
        if ($claimed === 'both') return 'none';
        if ($current === 'both') return $claimed === 'heading' ? 'body' : 'heading';
        return $current === $claimed ? 'none' : $current;
    }

    /**
     * Guarda un archivo subido dentro de una familia.
     *
     * @param array<string,mixed> $file entrada de $_FILES
     * @return array{ok:bool,error:?string,id:?int,weight:?int,style:?string}
     */
    public static function addFile(int $siteId, int $familyId, mixed $file, ?int $weight = null, ?string $style = null): array
    {
        $check = self::validateUpload($file);
        if ($check !== null) return ['ok' => false, 'error' => $check, 'id' => null, 'weight' => null, 'style' => null];

        $original = (string) ($file['name'] ?? 'fuente');
        $format = self::formatOf($original, (string) $file['tmp_name']);
        if ($format === null) {
            return ['ok' => false, 'error' => __('font.err.not_a_font'), 'id' => null, 'weight' => null, 'style' => null];
        }

        // Si el usuario no dice el peso, lo deducimos del nombre del archivo:
        // "Acme-Bold.woff2" → 700. Es lo que espera quien no es técnico, y en la
        // UI puede corregirlo después.
        $weight = $weight !== null ? self::normalizeWeight($weight) : self::guessWeight($original);
        $style  = $style !== null ? (in_array($style, ['normal', 'italic'], true) ? $style : 'normal') : self::guessStyle($original);

        $dir = PP_ROOT . '/storage/uploads/' . $siteId . '/fonts';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return ['ok' => false, 'error' => 'No se pudo crear la carpeta de fuentes.', 'id' => null, 'weight' => null, 'style' => null];
        }

        $filename = 'font-' . bin2hex(random_bytes(8)) . '.' . $format;
        $absolute = $dir . '/' . $filename;
        $tmp = (string) $file['tmp_name'];
        $moved = is_uploaded_file($tmp) ? move_uploaded_file($tmp, $absolute) : rename($tmp, $absolute);
        if (!$moved || !is_file($absolute)) {
            return ['ok' => false, 'error' => 'No se pudo guardar el archivo de fuente.', 'id' => null, 'weight' => null, 'style' => null];
        }

        $relative = 'storage/uploads/' . $siteId . '/fonts/' . $filename;

        // Sustituir el corte si ya existía (subir otra vez "Bold" reemplaza, no duplica).
        $previous = Database::selectOne(
            'SELECT id, path FROM site_font_files WHERE family_id = ? AND weight = ? AND style = ? LIMIT 1',
            [$familyId, $weight, $style]
        );
        if ($previous) {
            self::unlinkRelative($siteId, (string) $previous['path']);
            Database::execute('DELETE FROM site_font_files WHERE id = ?', [(int) $previous['id']]);
        }

        try {
            Database::execute(
                'INSERT INTO site_font_files (family_id, site_id, weight, style, format, path, original_name, file_size)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$familyId, $siteId, $weight, $style, $format, $relative, mb_substr($original, 0, 255), (int) filesize($absolute)]
            );
        } catch (\Throwable $e) {
            @unlink($absolute);
            error_log('[fonts] site=' . $siteId . ' insert failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'No se pudo registrar la fuente.', 'id' => null, 'weight' => null, 'style' => null];
        }

        return ['ok' => true, 'error' => null, 'id' => (int) Database::lastInsertId(), 'weight' => $weight, 'style' => $style];
    }

    public static function deleteFile(int $siteId, int $fileId): void
    {
        $row = Database::selectOne('SELECT path FROM site_font_files WHERE id = ? AND site_id = ? LIMIT 1', [$fileId, $siteId]);
        if (!$row) return;
        self::unlinkRelative($siteId, (string) $row['path']);
        Database::execute('DELETE FROM site_font_files WHERE id = ? AND site_id = ?', [$fileId, $siteId]);
    }

    public static function deleteFamily(int $siteId, int $familyId): void
    {
        $files = Database::select('SELECT path FROM site_font_files WHERE family_id = ? AND site_id = ?', [$familyId, $siteId]);
        foreach ($files as $f) self::unlinkRelative($siteId, (string) $f['path']);
        Database::execute('DELETE FROM site_font_families WHERE id = ? AND site_id = ?', [$familyId, $siteId]);
    }

    public static function updateFile(int $siteId, int $fileId, int $weight, string $style): void
    {
        $weight = self::normalizeWeight($weight);
        $style = in_array($style, ['normal', 'italic'], true) ? $style : 'normal';
        $row = Database::selectOne('SELECT family_id FROM site_font_files WHERE id = ? AND site_id = ? LIMIT 1', [$fileId, $siteId]);
        if (!$row) return;
        // El corte destino puede estar ocupado por otro archivo: lo liberamos.
        $clash = Database::selectOne(
            'SELECT id FROM site_font_files WHERE family_id = ? AND weight = ? AND style = ? AND id <> ? LIMIT 1',
            [(int) $row['family_id'], $weight, $style, $fileId]
        );
        if ($clash) self::deleteFile($siteId, (int) $clash['id']);
        Database::execute('UPDATE site_font_files SET weight = ?, style = ? WHERE id = ? AND site_id = ?', [$weight, $style, $fileId, $siteId]);
    }

    // ------------------------------------------------------------------
    // Render
    // ------------------------------------------------------------------

    /**
     * Bloque `@font-face` (uno por archivo) de todas las familias del sitio.
     * Cadena vacía si el sitio no tiene fuentes propias.
     */
    public static function renderFontFaceCss(int $siteId, bool $absoluteUrls = true): string
    {
        $blocks = [];
        foreach (self::families($siteId) as $fam) {
            foreach ($fam['files'] as $file) {
                $url = $absoluteUrls ? $file['url'] : 'brand-assets/' . $siteId . '/font/' . $file['id'];
                $format = self::FORMATS[$file['format']]['css'] ?? 'woff2';
                $blocks[] = "@font-face{font-family:\"" . self::escapeFamily($fam['name']) . "\";"
                    . "src:url(\"" . $url . "\") format(\"" . $format . "\");"
                    . "font-weight:" . $file['weight'] . ";"
                    . "font-style:" . $file['style'] . ";"
                    . "font-display:swap}";
            }
        }
        return $blocks === [] ? '' : implode("\n", $blocks) . "\n";
    }

    /**
     * FONTS — `<link rel="preload">` de los cortes que la página va a usar sí o
     * sí: el peso normal de la familia de títulos y el de la de textos.
     *
     * Solo esos dos. Precargar todos los cortes competiría por el ancho de
     * banda con lo que de verdad se ve primero y empeoraría la sensación.
     *
     * @param array<string,mixed> $tokens tokens ya resueltos (con los roles aplicados)
     */
    public static function renderPreloadLinks(int $siteId, ?array $tokens = null): string
    {
        $typo = (array) (($tokens ?? [])['typography'] ?? []);
        $wanted = [
            'heading' => (int) ($typo['weight_bold'] ?? 700),
            'body'    => (int) ($typo['weight_regular'] ?? 400),
        ];

        $links = [];
        $seen = [];
        foreach ($wanted as $role => $weight) {
            $family = self::familyForRole($siteId, $role);
            if ($family === null) continue;

            $file = self::bestFileFor($family, $weight);
            if ($file === null || isset($seen[$file['id']])) continue;
            $seen[$file['id']] = true;

            $links[] = '<link rel="preload" as="font" type="' . (self::FORMATS[$file['format']]['mime'] ?? 'font/woff2')
                . '" href="' . htmlspecialchars((string) $file['url'], ENT_QUOTES, 'UTF-8') . '" crossorigin>';
        }

        return $links === [] ? '' : implode("\n", $links) . "\n";
    }

    /**
     * Corte más adecuado para un peso: el exacto si existe; si no, el peso
     * normal más cercano (nunca una cursiva, que no es lo que se ve primero).
     *
     * @param array<string,mixed> $family
     * @return array<string,mixed>|null
     */
    public static function bestFileFor(array $family, int $weight): ?array
    {
        $candidates = array_values(array_filter(
            (array) ($family['files'] ?? []),
            static fn ($f) => ($f['style'] ?? 'normal') === 'normal'
        ));
        if ($candidates === []) return null;

        usort($candidates, static fn ($a, $b) => abs($a['weight'] - $weight) <=> abs($b['weight'] - $weight));
        return $candidates[0];
    }

    /**
     * Valor CSS `font-family` de una familia propia (con su fallback).
     */
    public static function cssValue(array $family): string
    {
        $fallback = trim((string) ($family['fallback'] ?? ''));
        if ($fallback === '') {
            $fallback = 'system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif';
        }
        return '"' . self::escapeFamily((string) $family['name']) . '", ' . $fallback;
    }

    /**
     * Marca de tiempo de la última modificación de fuentes del sitio, para
     * cache-busting de la hoja pública.
     */
    public static function version(int $siteId): string
    {
        try {
            $row = Database::selectOne(
                'SELECT MAX(GREATEST(f.updated_at, COALESCE(l.last_file, f.updated_at))) AS v
                 FROM site_font_families f
                 LEFT JOIN (SELECT family_id, MAX(created_at) AS last_file FROM site_font_files GROUP BY family_id) l
                   ON l.family_id = f.id
                 WHERE f.site_id = ?',
                [$siteId]
            );
            $v = (string) ($row['v'] ?? '');
            return $v === '' ? '0' : (string) strtotime($v);
        } catch (\Throwable $e) {
            return '0';
        }
    }

    // ------------------------------------------------------------------
    // Validación / utilidades
    // ------------------------------------------------------------------

    /** @return string|null mensaje de error, o null si la subida es válida */
    public static function validateUpload(mixed $file): ?string
    {
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return 'Selecciona un archivo de fuente.';
        }
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            return __('font.err.upload_incomplete');
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0) return __('font.err.empty_file');
        if ($size > self::MAX_FILE_BYTES) return 'Cada archivo debe pesar menos de 3 MB.';
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_file($tmp)) return __('font.err.bad_file');
        return null;
    }

    /**
     * Formato real del archivo: extensión Y magic bytes tienen que coincidir.
     * Un `.woff2` que por dentro es un PNG se rechaza aquí.
     */
    public static function formatOf(string $originalName, string $tmpPath): ?string
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!isset(self::FORMATS[$ext])) return null;

        $fh = @fopen($tmpPath, 'rb');
        if ($fh === false) return null;
        $head = (string) fread($fh, 4);
        fclose($fh);
        if (strlen($head) < 4) return null;

        $real = match (true) {
            $head === 'wOF2'                          => 'woff2',
            $head === 'wOFF'                          => 'woff',
            $head === 'OTTO'                          => 'otf',
            $head === "\x00\x01\x00\x00", $head === 'true', $head === 'ttcf' => 'ttf',
            default                                   => null,
        };
        if ($real === null) return null;

        // OTF y TTF comparten contenedor: aceptamos la extensión declarada si el
        // contenedor es compatible (el navegador los distingue por `format()`).
        if ($real === $ext) return $ext;
        if (in_array($ext, ['ttf', 'otf'], true) && in_array($real, ['ttf', 'otf'], true)) return $ext;
        return null;
    }

    public static function normalizeRole(string $role): string
    {
        return isset(self::ROLES[$role]) ? $role : 'none';
    }

    public static function normalizeWeight(int $weight): int
    {
        if ($weight < 100) $weight = 100;
        if ($weight > 900) $weight = 900;
        // Redondear al múltiplo de 100 más cercano.
        return (int) (round($weight / 100) * 100);
    }

    /** Deduce el peso a partir del nombre del archivo. */
    public static function guessWeight(string $filename): int
    {
        $n = strtolower(str_replace([' ', '_', '-', '.'], '', $filename));
        // Orden importante: 'extrabold' contiene 'bold', 'semibold' también.
        $map = [
            'thin' => 100, 'hairline' => 100,
            'extralight' => 200, 'ultralight' => 200,
            'semibold' => 600, 'demibold' => 600,
            'extrabold' => 800, 'ultrabold' => 800,
            'black' => 900, 'heavy' => 900,
            'light' => 300,
            'medium' => 500,
            'bold' => 700,
            'regular' => 400, 'book' => 400, 'normal' => 400,
        ];
        foreach ($map as $needle => $weight) {
            if (str_contains($n, $needle)) return $weight;
        }
        if (preg_match('/(100|200|300|400|500|600|700|800|900)/', $n, $m) === 1) {
            return (int) $m[1];
        }
        return 400;
    }

    public static function guessStyle(string $filename): string
    {
        $n = strtolower($filename);
        return (str_contains($n, 'italic') || str_contains($n, 'oblique')) ? 'italic' : 'normal';
    }

    public static function cutLabel(int $weight, string $style): string
    {
        $label = self::WEIGHT_LABELS[$weight] ?? ((string) $weight);
        return $style === 'italic' ? $label . ' cursiva' : $label;
    }

    public static function slugFor(string $name): string
    {
        $slug = slugify($name);
        return $slug === '' ? 'fuente-' . substr(md5($name), 0, 6) : mb_substr($slug, 0, 120);
    }

    private static function escapeFamily(string $name): string
    {
        return str_replace(['"', '\\', "\n", "\r"], '', $name);
    }

    /** Borra un archivo del disco comprobando que cuelga de la carpeta del site. */
    private static function unlinkRelative(int $siteId, string $relative): void
    {
        $relative = ltrim(trim($relative), '/');
        $prefix = 'storage/uploads/' . $siteId . '/fonts/';
        if ($relative === '' || !str_starts_with($relative, $prefix)) return;
        $absolute = PP_ROOT . '/' . $relative;
        if (is_file($absolute)) @unlink($absolute);
    }
}
