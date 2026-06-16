<?php

namespace App\Services;

use Core\Database;

/**
 * T18.4 — Banco de imágenes (Unsplash).
 *
 * La Access Key vive en `config/config.php` (image_bank.access_key) y es
 * UNIVERSAL: la misma clave para todos los sitios alojados. Por eso esta
 * clase cachea agresivamente las búsquedas en disco (24h por defecto) para
 * repartir el rate-limit (5000 req/h en tier production).
 *
 * Atribución obligatoria por términos de Unsplash:
 *   - Mostrar nombre del fotógrafo y enlace a su perfil.
 *   - Enlazar a Unsplash con UTMs `?utm_source=promptpress&utm_medium=referral`.
 *   - Pingear `download_location` cuando una imagen se "selecciona/descarga".
 *
 * Esta clase NO conoce nada de prompts ni de páginas: solo expone búsqueda y
 * descarga. Los hooks de generación automática viven en T18.6.
 */
final class ImageBankService
{
    public const SOURCE_UNSPLASH = 'unsplash';
    private const UTM_PARAMS = '?utm_source=promptpress&utm_medium=referral';
    private const SEARCH_ENDPOINT = 'https://api.unsplash.com/search/photos';
    private const HTTP_TIMEOUT = 8;     // s para search
    private const HTTP_TIMEOUT_DOWNLOAD = 25; // s para descarga binaria

    /** @var bool|null cache estática del estado del schema */
    private static ?bool $schemaReady = null;

    // ======================================================================
    // Estado / configuración
    // ======================================================================

    /** Devuelve la access key configurada o cadena vacía si no hay. */
    public static function accessKey(): string
    {
        return trim((string) config('image_bank.access_key', ''));
    }

    /** Devuelve true si el banco está disponible (key configurada). */
    public static function isAvailable(): bool
    {
        return self::accessKey() !== '';
    }

    /**
     * Valida una Access Key arbitraria contra la API de Unsplash (1 petición
     * ligera). Pensado para el instalador, donde la key aún no está configurada.
     *
     * @return array{ok:bool, error:?string}
     */
    public static function validateKey(string $key): array
    {
        $key = trim($key);
        if ($key === '') {
            return ['ok' => false, 'error' => 'La clave está vacía.'];
        }

        $url = self::SEARCH_ENDPOINT . '?' . http_build_query(['query' => 'office', 'per_page' => 1]);
        $headers = ['Accept-Version: v1', 'Authorization: Client-ID ' . $key];

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_TIMEOUT        => self::HTTP_TIMEOUT,
                CURLOPT_FOLLOWLOCATION => false,
            ]);
            curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if ($status === 0) {
                return ['ok' => false, 'error' => 'No se pudo conectar con Unsplash' . ($err !== '' ? ': ' . $err : '.')];
            }
        } else {
            $ctx = stream_context_create(['http' => [
                'method'        => 'GET',
                'header'        => implode("\r\n", $headers),
                'timeout'       => self::HTTP_TIMEOUT,
                'ignore_errors' => true,
            ]]);
            $body = @file_get_contents($url, false, $ctx);
            if ($body === false) {
                return ['ok' => false, 'error' => 'No se pudo conectar con Unsplash.'];
            }
            $status = 0;
            foreach (($http_response_header ?? []) as $h) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) { $status = (int) $m[1]; }
            }
        }

        if ($status === 200) {
            return ['ok' => true, 'error' => null];
        }
        if ($status === 401 || $status === 403) {
            return ['ok' => false, 'error' => 'Unsplash rechazó la clave (no válida o sin permisos).'];
        }
        return ['ok' => false, 'error' => 'Unsplash respondió con un código inesperado (' . $status . ').'];
    }

    /**
     * Self-healing migration: añade las columnas T18.4 a la tabla `media` si
     * no existen. Idempotente; solo hace trabajo la primera vez. Cacheado por
     * request para no consultar information_schema en cada llamada.
     */
    public static function ensureSchema(): void
    {
        if (self::$schemaReady === true) return;

        $cols = Database::select(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'media'"
        );
        $existing = array_map(fn($r) => $r['COLUMN_NAME'], $cols);

        $needed = ['source', 'source_id', 'source_url', 'attribution_name', 'attribution_url'];
        $missing = array_values(array_diff($needed, $existing));
        if (empty($missing)) {
            self::$schemaReady = true;
            return;
        }

        $ddl = [];
        foreach ($missing as $col) {
            switch ($col) {
                case 'source':           $ddl[] = "ADD COLUMN source VARCHAR(20) NOT NULL DEFAULT 'upload'"; break;
                case 'source_id':        $ddl[] = "ADD COLUMN source_id VARCHAR(120) DEFAULT NULL"; break;
                case 'source_url':       $ddl[] = "ADD COLUMN source_url VARCHAR(500) DEFAULT NULL"; break;
                case 'attribution_name': $ddl[] = "ADD COLUMN attribution_name VARCHAR(160) DEFAULT NULL"; break;
                case 'attribution_url':  $ddl[] = "ADD COLUMN attribution_url VARCHAR(500) DEFAULT NULL"; break;
            }
        }
        if (!empty($ddl)) {
            Database::execute('ALTER TABLE media ' . implode(', ', $ddl));
        }
        self::$schemaReady = true;
    }

    // ======================================================================
    // Búsqueda (con caché)
    // ======================================================================

    /**
     * Busca fotos en Unsplash. Devuelve array de items normalizado:
     *   [
     *     'id' => 'abc',
     *     'description' => 'Plato de pasta',
     *     'alt' => '...',
     *     'urls' => ['regular' => '...', 'small' => '...', 'thumb' => '...'],
     *     'download_location' => 'https://api.unsplash.com/...',
     *     'photographer' => ['name' => '...', 'profile_url' => '...'],
     *     'links_html' => 'https://unsplash.com/photos/abc',
     *   ]
     *
     * En error o sin key, devuelve [] (no lanza).
     *
     * @param string $orientation 'landscape'|'portrait'|'squarish'|''
     */
    public static function search(string $query, int $perPage = 12, string $orientation = 'landscape'): array
    {
        $query = trim($query);
        if ($query === '' || !self::isAvailable()) return [];

        $perPage = max(1, min(30, $perPage));
        $orientation = in_array($orientation, ['landscape', 'portrait', 'squarish'], true) ? $orientation : '';

        // Cache hit
        $cacheKey = self::cacheKey($query, $perPage, $orientation);
        $cached = self::readCache($cacheKey);
        if ($cached !== null) return $cached;

        // Llamada a la API
        $params = ['query' => $query, 'per_page' => $perPage, 'content_filter' => 'high'];
        if ($orientation !== '') $params['orientation'] = $orientation;

        $url = self::SEARCH_ENDPOINT . '?' . http_build_query($params);
        $response = self::httpGet($url, [
            'Accept-Version: v1',
            'Authorization: Client-ID ' . self::accessKey(),
        ], self::HTTP_TIMEOUT);

        if ($response === null) {
            error_log('[ImageBankService] search HTTP error for query: ' . $query);
            return [];
        }
        $decoded = json_decode($response, true);
        if (!is_array($decoded) || !isset($decoded['results']) || !is_array($decoded['results'])) {
            error_log('[ImageBankService] search response no válido para query: ' . $query);
            return [];
        }

        $items = [];
        foreach ($decoded['results'] as $r) {
            if (!is_array($r) || empty($r['id'])) continue;
            $items[] = self::normalizeResult($r);
        }

        self::writeCache($cacheKey, $items);
        return $items;
    }

    /**
     * Descarga una imagen de Unsplash a `storage/uploads/{site}/` y la registra
     * en `media` con `source='unsplash'` + atribución. Pingea el endpoint
     * `download_location` por términos de Unsplash.
     *
     * @param array $result  item devuelto por search()
     * @param int   $siteId
     * @param int|null $userId
     * @param string|null $alt  override del alt_text; si null usa la descripción del item.
     * @return array fila media insertada
     * @throws \RuntimeException en errores de red, mime no soportado, etc.
     */
    public static function downloadToMedia(array $result, int $siteId, ?int $userId, ?string $alt = null): array
    {
        if (!self::isAvailable()) {
            throw new \RuntimeException('Banco de imágenes no configurado.');
        }
        self::ensureSchema();

        $resultId = (string) ($result['id'] ?? '');
        $imageUrl = (string) ($result['urls']['regular'] ?? '');
        if ($resultId === '' || $imageUrl === '') {
            throw new \RuntimeException('Resultado de imagen inválido.');
        }

        // Idempotencia barata: si ya tenemos esta foto para este site, devolverla.
        $existing = Database::selectOne(
            "SELECT * FROM media WHERE site_id = ? AND source = 'unsplash' AND source_id = ? LIMIT 1",
            [$siteId, $resultId]
        );
        if ($existing) {
            self::pingDownloadEndpoint((string) ($result['download_location'] ?? ''));
            return $existing;
        }

        // Descargar binario
        $bin = self::httpGet($imageUrl, [], self::HTTP_TIMEOUT_DOWNLOAD, true);
        if ($bin === null || strlen($bin) < 1024) {
            throw new \RuntimeException('No se pudo descargar la imagen.');
        }

        // Detectar mime por contenido (no por extensión — Unsplash envía sin ext fiable)
        $mime = self::detectMimeFromBinary($bin);
        if (!isset(MediaService::ALLOWED[$mime])) {
            throw new \RuntimeException('La imagen descargada tiene un formato no soportado: ' . $mime);
        }
        $ext = MediaService::ALLOWED[$mime];

        // Guardar en disco
        $dir = MediaService::ensureSiteDir($siteId);
        $filename = 'unsplash-' . preg_replace('/[^a-zA-Z0-9_-]/', '', $resultId) . '-' . substr(bin2hex(random_bytes(4)), 0, 6) . '.' . $ext;
        $absPath = $dir . '/' . $filename;
        if (file_put_contents($absPath, $bin) === false) {
            throw new \RuntimeException('No se pudo escribir la imagen en disco.');
        }
        $relPath = 'storage/uploads/' . $siteId . '/' . $filename;

        // Atribución con UTMs obligatorios
        $photogName = (string) ($result['photographer']['name'] ?? '');
        $photogUrl  = (string) ($result['photographer']['profile_url'] ?? '');
        $attrUrl = $photogUrl !== '' ? $photogUrl . self::UTM_PARAMS : '';

        $altText = $alt !== null && $alt !== ''
            ? $alt
            : (string) ($result['alt'] ?? $result['description'] ?? '');

        $row = MediaService::storeFromBinary($absPath, $relPath, $mime, $siteId, $userId, [
            'original_name'    => 'Unsplash · ' . ($resultId),
            'alt_text'         => $altText,
            'source'           => self::SOURCE_UNSPLASH,
            'source_id'        => $resultId,
            'source_url'       => (string) ($result['links_html'] ?? ''),
            'attribution_name' => $photogName,
            'attribution_url'  => $attrUrl,
        ]);

        // Ping obligatorio a Unsplash (no bloqueante: best-effort).
        self::pingDownloadEndpoint((string) ($result['download_location'] ?? ''));

        return $row;
    }

    /**
     * F21.T21.4 — Busca y descarga automáticamente una imagen destacada para
     * una entrada de blog. Compone la query a partir del título + keywords del
     * excerpt/body. Devuelve la fila `media` resultante o `null` si nada.
     *
     * @param int $attempt  índice del resultado a usar (0 = primer match,
     *                      1 = segundo, etc.). Para implementar "Probar otra
     *                      imagen" sin repetir. Hace módulo del nº de
     *                      resultados, así que si attempt > count vuelve a
     *                      empezar (mejor que fallar).
     *
     * Usado por:
     *   - Botón "Auto" en metadatos de entrada (T21.4).
     *   - Flujos IA T21.5-7 que generan entradas y quieren featured image.
     */
    public static function pickFeaturedForPost(
        int $siteId,
        ?int $userId,
        string $title,
        string $excerpt = '',
        string $bodyText = '',
        string $orientation = 'landscape',
        int $attempt = 0
    ): ?array {
        if (!self::isAvailable()) return null;

        $query = self::composePostQuery($title, $excerpt, $bodyText);
        if ($query === '') return null;

        // Pedimos suficientes resultados para soportar varios intentos sin
        // re-hacer la query (la caché de 24h también ayuda).
        $perPage = max(6, min(20, $attempt + 6));
        $results = self::search($query, $perPage, $orientation);
        if (empty($results)) {
            $results = self::search(self::keywordsFrom($title, 4), $perPage, $orientation);
            if (empty($results)) return null;
        }

        $idx = $attempt % count($results);
        try {
            return self::downloadToMedia($results[$idx], $siteId, $userId, $title);
        } catch (\Throwable $e) {
            error_log('[pickFeaturedForPost] download error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Compone una query inteligente para buscar imagen de un artículo:
     *   - Toma 4-5 palabras significativas del título
     *   - Si el título es genérico, añade 1-2 keywords del excerpt
     *
     * Aplica filtros: stopwords castellanas, palabras cortas (<4), números puros.
     */
    private static function composePostQuery(string $title, string $excerpt = '', string $bodyText = ''): string
    {
        $titleKeywords = self::keywordsFrom($title, 5);
        if ($titleKeywords === '') return '';

        // Si el título tiene <3 keywords significativos, refuerza con excerpt.
        $titleWords = explode(' ', $titleKeywords);
        if (count($titleWords) < 3 && $excerpt !== '') {
            $extra = self::keywordsFrom($excerpt, 2);
            if ($extra !== '') return trim($titleKeywords . ' ' . $extra);
        }
        return $titleKeywords;
    }

    /**
     * Extrae las N keywords más relevantes de un texto.
     * Filtra stopwords castellanas + palabras cortas.
     */
    private static function keywordsFrom(string $text, int $maxWords = 5): string
    {
        $text = mb_strtolower(trim($text));
        if ($text === '') return '';
        // Quitar puntuación
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text) ?? '';
        $words = preg_split('/\s+/u', $text) ?: [];

        static $stopwords = null;
        if ($stopwords === null) {
            $stopwords = array_flip([
                'el','la','los','las','un','una','unos','unas','de','del','y','o','u','a','en',
                'por','para','con','sin','sobre','que','como','cuando','donde','quien','este',
                'esta','estos','estas','ese','esa','esos','esas','aquel','aquella','su','sus',
                'tu','tus','mi','mis','lo','le','les','se','si','no','ni','pero','más','muy',
                'ya','también','sólo','solo','todo','todos','toda','todas','año','años','dia',
                'día','días','año','años','aquí','allí','así','hace','hacer','hay','han','has',
                'fue','ser','está','están','sea','sean','será','sus','vez','veces','tan','tanto',
                'cual','cuales','qué','cómo','cuándo','dónde','quién','según','contra','desde',
                'hasta','entre','durante','antes','después','mientras','aunque','porque',
                'sin','tras','bajo','ante','about','your','that','this','with','from','they',
            ]);
        }

        $kept = [];
        foreach ($words as $w) {
            if (mb_strlen($w) < 4) continue;
            if (is_numeric($w)) continue;
            if (isset($stopwords[$w])) continue;
            $kept[] = $w;
            if (count($kept) >= $maxWords) break;
        }
        return implode(' ', $kept);
    }

    /**
     * Construye un fragmento HTML de atribución para una imagen Unsplash.
     * Ejemplo: <small class="pp-image-attr">Foto de <a>Jane</a> en <a>Unsplash</a></small>
     */
    public static function attributionHtml(string $photographerName, string $photographerUrl): string
    {
        if ($photographerName === '') return '';
        $unsplash = 'https://unsplash.com/' . self::UTM_PARAMS;
        $photog = $photographerUrl !== '' ? $photographerUrl : $unsplash;
        $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return '<small class="pp-image-attr">Foto de <a href="' . $e($photog) . '" rel="noopener">' . $e($photographerName) . '</a> en <a href="' . $e($unsplash) . '" rel="noopener">Unsplash</a></small>';
    }

    /**
     * Busca atribución por URL relativa (path en `media.path`).
     * Devuelve [name, url] o ['',''] si no hay o no procede.
     *
     * @return array{0: string, 1: string}
     */
    public static function attributionFor(int $siteId, string $imageUrl): array
    {
        $imageUrl = trim($imageUrl);
        if ($imageUrl === '') return ['', ''];

        // Acepta tanto URL absoluta como ruta relativa que termina en el path guardado.
        $row = Database::selectOne(
            "SELECT attribution_name, attribution_url FROM media
             WHERE site_id = ? AND source = 'unsplash' AND (path = ? OR path = ? OR ? LIKE CONCAT('%', path))
             LIMIT 1",
            [$siteId, ltrim($imageUrl, '/'), $imageUrl, $imageUrl]
        );
        if (!$row) return ['', ''];
        return [(string) ($row['attribution_name'] ?? ''), (string) ($row['attribution_url'] ?? '')];
    }

    // ======================================================================
    // Helpers privados
    // ======================================================================

    /** Mapea un resultado raw de la API a nuestra estructura normalizada. */
    private static function normalizeResult(array $r): array
    {
        return [
            'id'                => (string) $r['id'],
            'description'       => (string) ($r['description'] ?? ''),
            'alt'               => (string) ($r['alt_description'] ?? $r['description'] ?? ''),
            'urls' => [
                'regular' => (string) ($r['urls']['regular'] ?? ''),
                'small'   => (string) ($r['urls']['small'] ?? ''),
                'thumb'   => (string) ($r['urls']['thumb'] ?? ''),
            ],
            'download_location' => (string) ($r['links']['download_location'] ?? ''),
            'photographer' => [
                'name'        => (string) ($r['user']['name'] ?? ''),
                'profile_url' => (string) ($r['user']['links']['html'] ?? ''),
            ],
            'links_html'        => (string) ($r['links']['html'] ?? ''),
            'width'             => (int) ($r['width'] ?? 0),
            'height'            => (int) ($r['height'] ?? 0),
        ];
    }

    /** Pingea el download endpoint de Unsplash (best-effort, no bloquea). */
    private static function pingDownloadEndpoint(string $url): void
    {
        if ($url === '' || !self::isAvailable()) return;
        // Async-ish: timeout corto y no procesamos respuesta.
        @self::httpGet($url, [
            'Accept-Version: v1',
            'Authorization: Client-ID ' . self::accessKey(),
        ], 4);
    }

    /**
     * GET HTTP simple. Devuelve body string o null en error.
     * Si $binary es true, no decodifica nada (caller maneja bytes).
     */
    private static function httpGet(string $url, array $headers = [], int $timeoutSec = 8, bool $binary = false): ?string
    {
        if (!function_exists('curl_init')) {
            // Fallback file_get_contents
            $opts = ['http' => [
                'method'        => 'GET',
                'header'        => implode("\r\n", $headers),
                'timeout'       => $timeoutSec,
                'ignore_errors' => true,
            ]];
            $ctx = stream_context_create($opts);
            $body = @file_get_contents($url, false, $ctx);
            return $body === false ? null : $body;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => $timeoutSec,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeoutSec),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_USERAGENT      => 'PromptPress/1.0 (+https://promptpress.local)',
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false || $code >= 400) {
            error_log('[ImageBankService] HTTP ' . $code . ' ' . $url . ($err ? ' err=' . $err : ''));
            return null;
        }
        return is_string($body) ? $body : null;
    }

    /** Detecta MIME de imagen por magic bytes. */
    private static function detectMimeFromBinary(string $bin): string
    {
        $head = substr($bin, 0, 16);
        if (str_starts_with($head, "\xFF\xD8\xFF")) return 'image/jpeg';
        if (str_starts_with($head, "\x89PNG\r\n\x1A\n")) return 'image/png';
        if (str_starts_with($head, 'GIF87a') || str_starts_with($head, 'GIF89a')) return 'image/gif';
        if (substr($head, 0, 4) === 'RIFF' && substr($bin, 8, 4) === 'WEBP') return 'image/webp';
        return 'application/octet-stream';
    }

    private static function cacheDir(): string
    {
        return PP_STORAGE . '/cache/image_bank';
    }

    private static function cacheKey(string $query, int $perPage, string $orientation): string
    {
        return md5(strtolower($query) . '|' . $perPage . '|' . $orientation);
    }

    private static function readCache(string $key): ?array
    {
        $file = self::cacheDir() . '/' . $key . '.json';
        if (!is_file($file)) return null;

        $ttl = (int) config('image_bank.cache_ttl', 86400);
        if ((time() - filemtime($file)) > $ttl) return null;

        $raw = @file_get_contents($file);
        if ($raw === false) return null;
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    private static function writeCache(string $key, array $items): void
    {
        $dir = self::cacheDir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) return;
        $file = $dir . '/' . $key . '.json';
        @file_put_contents($file, json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
