<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\AI\Actions;
use App\Services\AI\AIActionRunner;
use Core\Database;

/**
 * STUDIO-2 C1 — Biblioteca de imágenes del sitio para la IA.
 *
 * Fuente ÚNICA para chat y generación, con una regla de producto por encima de
 * todo: **las fotos del negocio van primero**. El banco de imágenes (Unsplash)
 * es relleno, no la opción por defecto.
 *
 * Antes cada consumidor hacía su propio SELECT `ORDER BY id DESC`, sin mirar el
 * origen; como el chat descargaba 3 fotos de Unsplash ANTES de preguntar, esas
 * encabezaban la lista y el modelo casi siempre elegía stock aunque el cliente
 * tuviera su propio material.
 */
final class MediaLibraryService
{
    /** Los logos se ofrecen aparte (BrandService), nunca como foto de contenido. */
    private const BRAND_DIR = '/brand/';

    /**
     * Imágenes de la biblioteca, propias primero.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function images(int $siteId, int $limit = 40, bool $ownOnly = false): array
    {
        // El filtro de logos va en el SQL: si fuera un array_filter posterior,
        // con LIMIT 1 la única fila podría ser el logo y `hasOwnImages()`
        // daría falso teniendo fotos reales (visto en verificación E2E).
        $sql = "SELECT id, path, alt_text, original_name, width, height, source
                FROM media
                WHERE site_id = ? AND mime_type LIKE 'image/%'
                  AND path NOT LIKE ?";
        if ($ownOnly) {
            $sql .= " AND source = 'upload'";
        }
        $sql .= " ORDER BY (source = 'upload') DESC, id DESC LIMIT " . max(1, min(200, $limit));

        return Database::select($sql, [$siteId, '%' . self::BRAND_DIR . '%']);
    }

    /** ¿El negocio ha subido alguna foto suya? Decide si hace falta el banco. */
    public static function hasOwnImages(int $siteId): bool
    {
        return self::images($siteId, 1, true) !== [];
    }

    /** ¿Hay alguna imagen utilizable, sea propia o de banco? */
    public static function hasAnyImages(int $siteId): bool
    {
        return self::images($siteId, 1) !== [];
    }

    /**
     * Bloque de texto para los prompts. Separa explícitamente las fotos propias
     * del banco para que el modelo sepa cuál debe preferir.
     */
    public static function forAi(int $siteId, int $limit = 14): string
    {
        $rows = self::images($siteId, $limit);
        if ($rows === []) {
            return '(la biblioteca del sitio no tiene imágenes)';
        }

        $own = [];
        $bank = [];
        foreach ($rows as $row) {
            $line = self::describeRow($row);
            if ((string) ($row['source'] ?? 'upload') === 'upload') $own[] = $line;
            else $bank[] = $line;
        }

        $out = [];
        if ($own !== []) {
            $out[] = "FOTOS PROPIAS DEL NEGOCIO (PRIORITARIAS: úsalas siempre que encajen, aunque no sean perfectas):\n"
                . implode("\n", $own);
        }
        if ($bank !== []) {
            $out[] = ($own !== [] ? "BANCO DE IMÁGENES (solo si NINGUNA foto propia encaja):\n" : "IMÁGENES DISPONIBLES:\n")
                . implode("\n", $bank);
        }
        return implode("\n\n", $out);
    }

    /** "- /ruta | descripción | horizontal" */
    public static function describeRow(array $row): string
    {
        $alt = trim((string) ($row['alt_text'] ?? ''));
        if ($alt === '') {
            // Sin descripción, el nombre del archivo es lo único que orienta al
            // modelo: "fachada-centro.jpg" → "fachada centro".
            $name = pathinfo((string) ($row['original_name'] ?? ''), PATHINFO_FILENAME);
            $name = trim((string) preg_replace('/[-_]+/', ' ', $name));
            $alt = $name !== '' ? $name . ' (sin descripción)' : 'sin descripción';
        }
        return '- /' . ltrim((string) $row['path'], '/') . ' | ' . $alt . ' | ' . self::orientationOf($row);
    }

    public static function orientationOf(array $row): string
    {
        $w = (int) ($row['width'] ?? 0);
        $h = (int) ($row['height'] ?? 0);
        if ($w <= 0 || $h <= 0) return 'orientación desconocida';
        if ($w >= $h * 1.15) return 'horizontal';
        if ($h >= $w * 1.15) return 'vertical';
        return 'cuadrada';
    }

    /**
     * Elige la foto PROPIA que mejor encaja con un brief ("aula con
     * estudiantes"), sin repetir las ya usadas. Puntúa por palabras compartidas
     * con la descripción y el nombre del archivo; sin coincidencia devuelve
     * null y el llamador decide si recurre al banco.
     *
     * @param array<int,array<string,mixed>> $candidates
     * @param array<int,int> $usedIds
     */
    public static function bestMatch(array $candidates, string $subject, array $usedIds = [], string $orientation = ''): ?array
    {
        $wanted = self::keywords($subject);
        if ($wanted === []) return null;

        $best = null;
        $bestScore = 0;
        foreach ($candidates as $row) {
            if (in_array((int) $row['id'], $usedIds, true)) continue;
            $haystack = self::keywords(((string) ($row['alt_text'] ?? '')) . ' ' . ((string) ($row['original_name'] ?? '')));
            if ($haystack === []) continue;
            $score = count(array_intersect($wanted, $haystack));
            if ($score === 0) continue;
            // Desempate suave por orientación pedida.
            if ($orientation !== '' && self::orientationMatches($row, $orientation)) $score++;
            if ($score > $bestScore) { $bestScore = $score; $best = $row; }
        }
        return $best;
    }

    private static function orientationMatches(array $row, string $orientation): bool
    {
        $actual = self::orientationOf($row);
        return ($orientation === 'landscape' && $actual === 'horizontal')
            || ($orientation === 'portrait' && $actual === 'vertical')
            || ($orientation === 'squarish' && $actual === 'cuadrada');
    }

    /** Palabras significativas en minúsculas y sin acentos (>3 letras). */
    public static function keywords(string $text): array
    {
        $t = mb_strtolower(trim($text));
        $t = strtr($t, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
        $t = (string) preg_replace('/[^a-z0-9\s]+/', ' ', $t);
        $stop = ['para', 'con', 'una', 'unos', 'unas', 'del', 'las', 'los', 'que', 'por', 'sin', 'sobre', 'foto', 'fotos', 'imagen', 'imagenes', 'jpg', 'png', 'webp', 'img', 'dsc', 'photo'];
        $words = array_filter(
            preg_split('/\s+/', $t) ?: [],
            static fn (string $w): bool => mb_strlen($w) > 3 && !in_array($w, $stop, true)
        );
        return array_values(array_unique($words));
    }

    // ==================================================================
    // C4 — Descripción automática de las fotos subidas
    // ==================================================================

    /** Cuántas imágenes del sitio siguen sin descripción. */
    public static function countMissingAlt(int $siteId): int
    {
        $row = Database::selectOne(
            "SELECT COUNT(*) AS c FROM media
             WHERE site_id = ? AND mime_type LIKE 'image/%'
               AND (alt_text IS NULL OR alt_text = '')",
            [$siteId]
        );
        return (int) ($row['c'] ?? 0);
    }

    /**
     * IDs de imágenes sin descripción, las más recientes primero (son las que
     * el usuario tiene más presentes).
     *
     * @return array<int,int>
     */
    public static function idsMissingAlt(int $siteId, int $limit = 5): array
    {
        $rows = Database::select(
            "SELECT id FROM media
             WHERE site_id = ? AND mime_type LIKE 'image/%'
               AND (alt_text IS NULL OR alt_text = '')
             ORDER BY id DESC LIMIT " . max(1, min(20, $limit)),
            [$siteId]
        );
        return array_map(static fn (array $r): int => (int) $r['id'], $rows);
    }

    /**
     * Programa la descripción de una imagen para DESPUÉS de responder al
     * navegador: subir una foto no puede quedarse esperando a la IA. Mismo
     * patrón que el procesado de documentos del onboarding.
     */
    public static function describeAfterResponse(int $mediaId, int $siteId): void
    {
        register_shutdown_function(static function () use ($mediaId, $siteId): void {
            // Cerrar la respuesta antes de trabajar, cuando el SAPI lo permite.
            if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
            @set_time_limit(60);
            try {
                self::describeNow($mediaId, $siteId);
            } catch (\Throwable $e) {
                error_log('[MediaLibrary] descripción fallida media=' . $mediaId . ': ' . $e->getMessage());
            }
        });
    }

    /**
     * Describe una imagen con el modelo ligero (visión) y guarda el resultado
     * como `alt_text`. No pisa una descripción escrita por el usuario.
     *
     * @return string la descripción guardada ('' si no se pudo)
     */
    public static function describeNow(int $mediaId, int $siteId): string
    {
        return self::describeOne($mediaId, $siteId)['alt'];
    }

    /**
     * Como describeNow(), pero contando POR QUÉ no se pudo. Hace falta para el
     * botón por lotes: un archivo que ya no está en disco no se va a poder
     * describir nunca (hay que saltarlo), mientras que un fallo del proveedor
     * sí merece reintentarse. Sin distinguirlos, una imagen rota dejaba el
     * proceso repitiendo el mismo lote con un error que culpaba a la IA.
     *
     * @return array{status:'ok'|'done'|'unavailable'|'failed', alt:string}
     */
    public static function describeOne(int $mediaId, int $siteId): array
    {
        $row = Database::selectOne(
            "SELECT id, path, mime_type, alt_text FROM media WHERE id = ? AND site_id = ? AND mime_type LIKE 'image/%'",
            [$mediaId, $siteId]
        );
        if ($row === null) return ['status' => 'unavailable', 'alt' => ''];
        if (trim((string) ($row['alt_text'] ?? '')) !== '') return ['status' => 'done', 'alt' => ''];

        $abs = PP_ROOT . '/' . ltrim((string) $row['path'], '/');
        // Archivo ausente, ilegible o demasiado grande: no es un fallo de la IA.
        if (!is_file($abs) || filesize($abs) > 6 * 1024 * 1024) return ['status' => 'unavailable', 'alt' => ''];
        $binary = @file_get_contents($abs);
        if ($binary === false || $binary === '') return ['status' => 'unavailable', 'alt' => ''];

        $result = AIActionRunner::run(Actions::DESCRIBE_IMAGE, [
            'language' => LanguageService::promptLabelFor($siteId),
            '_images' => [[
                'mime' => (string) $row['mime_type'],
                'data' => base64_encode($binary),
            ]],
        ], $siteId);

        $data = is_array($result['data'] ?? null) ? $result['data'] : [];
        $alt = trim((string) ($data['alt'] ?? ''));
        if ($alt === '') return ['status' => 'failed', 'alt' => ''];

        $alt = mb_substr($alt, 0, 300);
        Database::execute('UPDATE media SET alt_text = ? WHERE id = ? AND site_id = ?', [$alt, $mediaId, $siteId]);
        return ['status' => 'ok', 'alt' => $alt];
    }
}
