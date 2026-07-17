<?php

declare(strict_types=1);

namespace IaiaAnalytics;

/**
 * Ga4Importer — importa histórico de Google Analytics 4 desde CSV (F4).
 *
 * Entrada: CSV exportado de GA4 (Exploraciones o Informes) que contenga una
 * columna de fecha diaria y las métricas de vistas/usuarios; para las
 * dimensiones page/referrer/device, además la columna de la dimensión.
 * Tolera: líneas de comentario iniciales (# ...), separador coma/punto y
 * coma/tabulador, cabeceras en español o inglés y fechas YYYYMMDD o
 * YYYY-MM-DD.
 *
 * Los datos se vuelcan en iaia_daily (mismo modelo que los rollups propios):
 * INSERT ... ON DUPLICATE KEY UPDATE, así que reimportar un fichero es
 * idempotente. Si un día importado tiene además eventos propios crudos, el
 * próximo rollup re-agrega ese día y los datos propios PREVALECEN (el rollup
 * borra y reescribe el día completo por dimensión).
 *
 * Nota de semántica: los "usuarios activos" diarios de GA4 no son idénticos a
 * nuestros únicos diarios (metodologías distintas); el histórico importado es
 * orientativo. Cada import se anota en la opción iaia_analytics_ga4_imports.
 */
final class Ga4Importer
{
    private const MAX_BYTES = 20 * 1024 * 1024;
    private const MAX_ROWS  = 200000;

    /** Cabeceras aceptadas (minúsculas, sin acentos) por rol. */
    private const HEADERS = [
        'date'  => ['fecha', 'date', 'dia', 'day'],
        'views' => ['vistas', 'views', 'vistas de pagina', 'pageviews', 'visualizaciones'],
        'users' => ['usuarios activos', 'active users', 'usuarios', 'users', 'total de usuarios', 'total users'],
        'page'  => ['ruta de pagina y clase de pantalla', 'ruta de la pagina y clase de pantalla', 'page path and screen class', 'ruta de pagina', 'page path', 'pagina'],
        'referrer' => ['fuente de la sesion', 'session source', 'fuente', 'source', 'fuente/medio de la sesion', 'session source / medium', 'fuente/medio'],
        'device'   => ['categoria de dispositivo', 'device category', 'dispositivo', 'device'],
    ];

    /**
     * Importa un CSV subido para una dimensión ('total'|'page'|'referrer'|'device').
     *
     * @param array{tmp_name:string, name:string, size:int, error:int} $file
     * @return array{ok:bool, error?:string, imported?:int, days?:int, skipped?:int}
     */
    public static function import(array $file, string $dimension): array
    {
        if (!in_array($dimension, ['total', 'page', 'referrer', 'device'], true)) {
            return ['ok' => false, 'error' => 'Dimensión no válida.'];
        }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            return ['ok' => false, 'error' => 'No se ha recibido el fichero (¿supera el límite de subida de PHP?).'];
        }
        if ((int) $file['size'] > self::MAX_BYTES) {
            return ['ok' => false, 'error' => 'El fichero supera los 20 MB.'];
        }

        $handle = fopen($file['tmp_name'], 'r');
        if ($handle === false) {
            return ['ok' => false, 'error' => 'No se pudo leer el fichero.'];
        }

        try {
            [$cols, $delimiter, $error] = self::findHeader($handle, $dimension);
            if ($error !== null) {
                return ['ok' => false, 'error' => $error];
            }

            global $wpdb;
            $daily    = Schema::daily();
            $imported = 0;
            $skipped  = 0;
            $days     = [];
            $rows     = 0;

            while (($row = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
                if (++$rows > self::MAX_ROWS) {
                    return ['ok' => false, 'error' => 'Demasiadas filas (máx. ' . self::MAX_ROWS . ').'];
                }
                $day = self::normalizeDate((string) ($row[$cols['date']] ?? ''));
                if ($day === null) {
                    $skipped++;
                    continue;
                }
                $views = self::normalizeInt((string) ($row[$cols['views']] ?? ''));
                $users = $cols['users'] !== null ? self::normalizeInt((string) ($row[$cols['users']] ?? '')) : 0;
                if ($views === null) {
                    $skipped++;
                    continue;
                }

                $dimKey = '';
                if ($dimension !== 'total') {
                    $dimKey = self::normalizeKey($dimension, (string) ($row[$cols['key']] ?? ''));
                    if ($dimKey === null) {
                        $skipped++;
                        continue;
                    }
                }

                $wpdb->query($wpdb->prepare(
                    "INSERT INTO {$daily} (site_id, day, dimension, dim_key, pageviews, visitors)
                     VALUES (%d, %s, %s, %s, %d, %d)
                     ON DUPLICATE KEY UPDATE pageviews = VALUES(pageviews), visitors = VALUES(visitors)",
                    Schema::SITE_ID, $day, $dimension, $dimKey, $views, $users ?? 0
                ));
                $imported++;
                $days[$day] = true;
            }

            self::logImport($dimension, $imported, count($days), (string) $file['name']);

            return ['ok' => true, 'imported' => $imported, 'days' => count($days), 'skipped' => $skipped];
        } finally {
            fclose($handle);
        }
    }

    /**
     * Busca la fila de cabecera (saltando comentarios '#' y líneas vacías) y
     * mapea los índices de columna necesarios.
     *
     * @return array{0:?array, 1:string, 2:?string} [cols, delimiter, error]
     */
    private static function findHeader($handle, string $dimension): array
    {
        while (($line = fgets($handle)) !== false) {
            $trim = trim($line);
            if ($trim === '' || str_starts_with($trim, '#')) {
                continue;
            }
            // Detectar separador por frecuencia en la línea de cabecera.
            $delimiter = ',';
            foreach ([';', "\t"] as $cand) {
                if (substr_count($trim, $cand) > substr_count($trim, $delimiter)) {
                    $delimiter = $cand;
                }
            }
            $headers = array_map([self::class, 'normalizeHeader'], str_getcsv($trim, $delimiter, '"', '\\'));

            $cols = [
                'date'  => self::findCol($headers, self::HEADERS['date']),
                'views' => self::findCol($headers, self::HEADERS['views']),
                'users' => self::findCol($headers, self::HEADERS['users']),
                'key'   => $dimension === 'total' ? null : self::findCol($headers, self::HEADERS[$dimension]),
            ];
            if ($cols['date'] === null) {
                return [null, $delimiter, 'No se encontró la columna de fecha. Exporta desde GA4 incluyendo la dimensión "Fecha" (valores diarios).'];
            }
            if ($cols['views'] === null) {
                return [null, $delimiter, 'No se encontró la columna de vistas ("Vistas" / "Views").'];
            }
            if ($dimension !== 'total' && $cols['key'] === null) {
                return [null, $delimiter, 'No se encontró la columna de la dimensión elegida en la cabecera del CSV.'];
            }
            return [$cols, $delimiter, null];
        }
        return [null, ',', 'El fichero no contiene una cabecera reconocible.'];
    }

    private static function normalizeHeader(string $h): string
    {
        $h = mb_strtolower(trim($h, "\xEF\xBB\xBF \t\"'"));
        return strtr($h, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n']);
    }

    private static function findCol(array $headers, array $candidates): ?int
    {
        foreach ($candidates as $cand) {
            $idx = array_search($cand, $headers, true);
            if ($idx !== false) {
                return (int) $idx;
            }
        }
        return null;
    }

    /** '20250115' o '2025-01-15' → '2025-01-15'; otra cosa → null. */
    private static function normalizeDate(string $raw): ?string
    {
        $raw = trim($raw);
        if (preg_match('/^\d{8}$/', $raw)) {
            $raw = substr($raw, 0, 4) . '-' . substr($raw, 4, 2) . '-' . substr($raw, 6, 2);
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return null;
        }
        return checkdate((int) substr($raw, 5, 2), (int) substr($raw, 8, 2), (int) substr($raw, 0, 4)) ? $raw : null;
    }

    /** Números GA4 pueden llevar separador de miles o decimales: a entero. */
    private static function normalizeInt(string $raw): ?int
    {
        $raw = str_replace([',', '.', ' '], ['', '', ''], trim($raw));
        return preg_match('/^\d+$/', $raw) === 1 ? (int) $raw : null;
    }

    /** Normaliza la clave al formato de nuestros rollups; null = descartar. */
    private static function normalizeKey(string $dimension, string $raw): ?string
    {
        $raw = trim($raw);
        switch ($dimension) {
            case 'page':
                if ($raw === '' || $raw === '(not set)') {
                    return null;
                }
                $path = (string) parse_url($raw, PHP_URL_PATH);
                if ($path === '') {
                    $path = '/';
                }
                return substr($path[0] === '/' ? $path : '/' . $path, 0, 255);
            case 'referrer':
                $raw = mb_strtolower($raw);
                if ($raw === '(direct)' || $raw === '(none)' || $raw === 'direct') {
                    return ''; // Directo
                }
                if ($raw === '' || $raw === '(not set)') {
                    return null;
                }
                // "google / organic" → google; "www.foo.com" → foo.com
                $raw = trim(explode('/', $raw)[0]);
                $raw = preg_replace('/^www\./', '', $raw) ?? $raw;
                return substr($raw, 0, 255);
            case 'device':
                $raw = mb_strtolower($raw);
                return in_array($raw, ['desktop', 'mobile', 'tablet'], true) ? $raw : null;
        }
        return null;
    }

    /** Anota el import (transparencia y soporte). */
    private static function logImport(string $dimension, int $rows, int $days, string $filename): void
    {
        $log   = get_option('iaia_analytics_ga4_imports', []);
        $log   = is_array($log) ? $log : [];
        $log[] = [
            'when'      => current_time('mysql'),
            'file'      => sanitize_file_name($filename),
            'dimension' => $dimension,
            'rows'      => $rows,
            'days'      => $days,
        ];
        update_option('iaia_analytics_ga4_imports', array_slice($log, -20), false);
    }
}
