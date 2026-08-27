<?php

namespace App\Controllers\Admin;

use App\Services\ImageBankService;
use App\Services\MediaLibraryService;
use App\Services\MediaService;
use App\Services\RemoteImageImporter;
use Core\Auth;
use Core\CSRF;
use Core\Database;
use Core\Request;
use Core\Response;
use Core\Session;
use Core\View;

/**
 * Gestión de medios (imágenes) — T8.1.
 * T8.2 añadirá el modal selector consumido desde el editor de secciones.
 */
class MediaController
{
    // ----------------------------------------------------------------------
    // GET /admin/media — galería + form de upload
    // ----------------------------------------------------------------------
    public function index(): void
    {
        $siteId = self::requireSiteId();
        $items  = Database::select(
            'SELECT m.*, u.username AS uploader
             FROM media m
             LEFT JOIN users u ON u.id = m.uploaded_by
             WHERE m.site_id = ?
             ORDER BY m.id DESC',
            [$siteId]
        );
        self::repairRemoteImports($items, $siteId);

        $data = DashboardController::getCommonData();
        $data = array_merge($data, [
            'items'      => $items,
            'maxSize'    => MediaService::MAX_SIZE,
            'allowedExt' => array_values(MediaService::ALLOWED),
            'csrf'       => CSRF::token(),
            // STUDIO-2 C4b — cuántas imágenes siguen sin descripción.
            'missingAlt' => MediaLibraryService::countMissingAlt($siteId),
        ]);
        View::send('admin/media/index', $data);
    }

    // ----------------------------------------------------------------------
    // GET /admin/media/library — JSON para el selector de medios (T8.2)
    // ----------------------------------------------------------------------
    public function library(): void
    {
        $siteId = self::requireSiteId();
        $q = trim((string) Request::get('q', ''));

        $where = 'WHERE m.site_id = ?';
        $params = [$siteId];
        if ($q !== '') {
            $where .= ' AND (m.original_name LIKE ? OR m.alt_text LIKE ?)';
            $needle = '%' . $q . '%';
            $params[] = $needle;
            $params[] = $needle;
        }

        // STUDIO-2 C5 — las fotos propias del negocio, primero; `source` viaja
        // al selector para poder filtrar "Tus fotos" / "De banco".
        ImageBankService::ensureSchema();
        $items = Database::select(
            'SELECT m.id, m.original_name, m.mime_type, m.file_size, m.path,
                    m.alt_text, m.width, m.height, m.created_at, m.source
             FROM media m
             ' . $where . '
             ORDER BY (m.source = \'upload\') DESC, m.id DESC
             LIMIT 120',
            $params
        );
        self::repairRemoteImports($items, $siteId);

        $out = array_map(static function (array $m): array {
            $path = ltrim((string) $m['path'], '/');
            return [
                'id'            => (int) $m['id'],
                'name'          => (string) $m['original_name'],
                'url'           => base_url($path),
                'path'          => '/' . $path,
                'alt_text'      => (string) ($m['alt_text'] ?? ''),
                'mime_type'     => (string) $m['mime_type'],
                'file_size'     => (int) $m['file_size'],
                'width'         => $m['width'] !== null ? (int) $m['width'] : null,
                'height'        => $m['height'] !== null ? (int) $m['height'] : null,
                'created_at'    => (string) $m['created_at'],
                'source'        => (string) ($m['source'] ?? 'upload'),
            ];
        }, $items);

        Response::json([
            'ok'    => true,
            'items' => $out,
        ]);
    }

    // ----------------------------------------------------------------------
    // POST /admin/media — upload
    // ----------------------------------------------------------------------
    public function upload(): void
    {
        CSRF::check();
        $siteId = self::requireSiteId();
        $userId = Auth::id();
        $isAjax = self::wantsJson();

        $file = $_FILES['file'] ?? null;

        // Sin JavaScript el navegador manda un array cuando el input es
        // `multiple`. Se procesan todos en el mismo POST; con JS cada archivo
        // llega en su propia petición y este camino no se usa.
        if (is_array($file) && isset($file['name']) && is_array($file['name'])) {
            $this->uploadBatch($file, $siteId, $userId);
            return;
        }

        $err  = MediaService::validate($file);
        if ($err !== null) {
            if ($isAjax) {
                Response::json(['ok' => false, 'error' => $err], 400);
                return;
            }
            Session::flash('error', $err);
            Response::redirect(base_url('admin/media'));
            return;
        }

        $alt = trim((string) Request::post('alt_text', ''));
        try {
            $row = MediaService::store($file, $siteId, $userId, $alt !== '' ? $alt : null);
            // STUDIO-2 C4 — sin descripción, la foto se describe con IA tras
            // responder: es lo que permite priorizarla luego en generación/chat.
            if ($alt === '') {
                MediaLibraryService::describeAfterResponse((int) ($row['id'] ?? 0), $siteId);
            }
            if ($isAjax) {
                Response::json([
                    'ok'   => true,
                    'item' => [
                        'id'        => (int) ($row['id'] ?? 0),
                        'url'       => base_url(ltrim((string) ($row['path'] ?? ''), '/')),
                        'path'      => '/' . ltrim((string) ($row['path'] ?? ''), '/'),
                        'name'      => (string) ($row['original_name'] ?? ''),
                        'alt_text'  => (string) ($row['alt_text'] ?? ''),
                        'mime_type' => (string) ($row['mime_type'] ?? ''),
                        'width'     => (int) ($row['width'] ?? 0),
                        'height'    => (int) ($row['height'] ?? 0),
                        'file_size' => (int) ($row['file_size'] ?? 0),
                    ],
                ]);
                return;
            }
            Session::flash('success', __('media.flash.uploaded_one'));
        } catch (\Throwable $e) {
            error_log('[MediaController] upload error: ' . $e->getMessage());
            if ($isAjax) {
                Response::json(['ok' => false, 'error' => 'No se pudo procesar la imagen: ' . $e->getMessage()], 500);
                return;
            }
            Session::flash('error', __('media.err.process', ['detalle' => $e->getMessage()]));
        }

        Response::redirect(base_url('admin/media'));
    }

    // ----------------------------------------------------------------------
    // POST /admin/media/import-remote — lote parcial para imágenes pegadas
    // desde correo. Cada URL se trata como no confiable en el servicio.
    // ----------------------------------------------------------------------
    public function importRemote(): void
    {
        CSRF::check();
        $siteId = self::requireSiteId();
        $encoded = (string) Request::post('images', '[]');
        $decoded = json_decode($encoded, true);
        if (!is_array($decoded)) {
            Response::json(['ok' => false, 'error' => 'invalid_images'], 422);
        }

        $candidates = RemoteImageImporter::normalizeCandidates($decoded);
        if ($candidates === []) {
            Response::json(['ok' => false, 'error' => 'no_importable_images'], 422);
        }

        @set_time_limit(180);
        $results = (new RemoteImageImporter())->importBatch($candidates, $siteId, Auth::id());
        $imported = count(array_filter($results, static fn (array $result): bool => ($result['ok'] ?? false) === true));
        Response::json([
            'ok' => true,
            'imported' => $imported,
            'failed' => count($results) - $imported,
            'results' => $results,
        ]);
    }

    /**
     * Subida múltiple sin JavaScript: un archivo malo no debe invalidar los
     * buenos, así que se procesan todos y se informa del resultado conjunto.
     *
     * @param array<string,mixed> $files entrada de $_FILES con arrays paralelos
     */
    private function uploadBatch(array $files, int $siteId, ?int $userId): void
    {
        $alt = trim((string) Request::post('alt_text', ''));
        $ok = 0;
        $errors = [];

        foreach (array_keys($files['name']) as $i) {
            if ((int) ($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;

            $one = [
                'name'     => (string) $files['name'][$i],
                'type'     => (string) ($files['type'][$i] ?? ''),
                'tmp_name' => (string) ($files['tmp_name'][$i] ?? ''),
                'error'    => (int) ($files['error'][$i] ?? UPLOAD_ERR_OK),
                'size'     => (int) ($files['size'][$i] ?? 0),
            ];

            $err = MediaService::validate($one);
            if ($err !== null) {
                $errors[] = $one['name'] . ': ' . $err;
                continue;
            }
            try {
                $row = MediaService::store($one, $siteId, $userId, $alt !== '' ? $alt : null);
                if ($alt === '') {
                    MediaLibraryService::describeAfterResponse((int) ($row['id'] ?? 0), $siteId);
                }
                $ok++;
            } catch (\Throwable $e) {
                error_log('[MediaController] batch upload error: ' . $e->getMessage());
                $errors[] = $one['name'] . ': ' . __('media.err.process_short');
            }
        }

        if ($ok > 0) {
            Session::flash('success', __($ok === 1 ? 'media.flash.uploaded_one' : 'media.flash.uploaded_n', ['n' => $ok]));
        }
        if ($errors !== []) {
            Session::flash('error', implode(' · ', array_slice($errors, 0, 5)));
        }
        if ($ok === 0 && $errors === []) {
            Session::flash('error', __('media.error.pick_one'));
        }

        Response::redirect(base_url('admin/media'));
    }

    private static function wantsJson(): bool
    {
        $xhr    = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
        $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
        return $xhr || stripos($accept, 'application/json') !== false;
    }

    // ----------------------------------------------------------------------
    // POST /admin/media/describe-missing — describe con IA las imágenes que
    // no tienen texto alternativo (STUDIO-2 C4b).
    //
    // Va POR LOTES pequeños y devuelve cuántas quedan: cada petición termina
    // rápido (nada de un proceso de varios minutos que muere por timeout) y el
    // navegador va llamando otra vez mientras muestra el progreso.
    // ----------------------------------------------------------------------
    public function describeMissing(): void
    {
        CSRF::check();
        $siteId = self::requireSiteId();

        $batch = MediaLibraryService::idsMissingAlt($siteId, 3);
        if ($batch === []) {
            Response::json(['ok' => true, 'described' => 0, 'remaining' => 0, 'items' => []]);
        }

        @set_time_limit(180);
        $items = [];
        $failed = 0;       // falló la IA → merece reintento
        $unavailable = 0;  // el archivo no está: nunca se va a poder describir
        foreach ($batch as $mediaId) {
            try {
                $outcome = MediaLibraryService::describeOne($mediaId, $siteId);
                if ($outcome['status'] === 'ok') {
                    $items[] = ['id' => $mediaId, 'alt_text' => $outcome['alt']];
                } elseif ($outcome['status'] === 'unavailable') {
                    $unavailable++;
                } elseif ($outcome['status'] === 'failed') {
                    $failed++;
                }
            } catch (\Throwable $e) {
                $failed++;
                error_log('[MediaController] describe-missing media=' . $mediaId . ': ' . $e->getMessage());
            }
        }

        // Nada salió y encima falló la IA: cortar con un mensaje honesto en vez
        // de dejar al navegador pidiendo lotes en bucle.
        if ($items === [] && $failed > 0) {
            Response::json([
                'ok' => false,
                'error' => __('media.error.describe_failed'),
                'remaining' => MediaLibraryService::countMissingAlt($siteId),
            ], 502);
        }

        Response::json([
            'ok' => true,
            'described' => count($items),
            // Todo el lote eran archivos que ya no están: no hay avance posible.
            'blocked' => $items === [] && $unavailable > 0,
            'unavailable' => $unavailable,
            'remaining' => MediaLibraryService::countMissingAlt($siteId),
            'items' => $items,
        ]);
    }

    // ----------------------------------------------------------------------
    // POST /admin/media/{id}/alt — actualiza el texto alternativo
    // ----------------------------------------------------------------------
    public function updateAlt(array $params = []): void
    {
        CSRF::check();
        $siteId = self::requireSiteId();
        $row    = self::findOrFail((int) ($params['id'] ?? 0), $siteId);

        $alt = trim((string) Request::post('alt_text', ''));
        if (mb_strlen($alt) > 500) {
            $alt = mb_substr($alt, 0, 500);
        }
        Database::execute(
            'UPDATE media SET alt_text = ? WHERE id = ? AND site_id = ?',
            [$alt !== '' ? $alt : null, (int) $row['id'], $siteId]
        );
        Session::flash('success', __('media.flash.alt_updated'));
        Response::redirect(base_url('admin/media'));
    }

    // ----------------------------------------------------------------------
    // POST /admin/media/{id}/delete
    // ----------------------------------------------------------------------
    public function destroy(array $params = []): void
    {
        CSRF::check();
        $siteId = self::requireSiteId();
        $row    = self::findOrFail((int) ($params['id'] ?? 0), $siteId);

        MediaService::delete($row);
        Session::flash('success', __('media.flash.deleted'));
        Response::redirect(base_url('admin/media'));
    }

    // ----------------------------------------------------------------------
    // T18.4 — Banco de imágenes (Unsplash)
    // ----------------------------------------------------------------------

    /** GET /admin/media/bank — vista del buscador del banco. */
    public function bankIndex(): void
    {
        $siteId = self::requireSiteId();
        $data = DashboardController::getCommonData();
        $data = array_merge($data, [
            'available' => ImageBankService::isAvailable(),
            'csrf'      => CSRF::token(),
        ]);
        View::send('admin/media/bank', $data);
    }

    /** GET /admin/media/bank/search?q=...&orientation=... — JSON con resultados. */
    public function bankSearch(): void
    {
        self::requireSiteId();
        if (!ImageBankService::isAvailable()) {
            Response::json(['ok' => false, 'error' => __('media.error.bank_off')], 503);
        }
        $q = trim((string) Request::get('q', ''));
        $orientation = (string) Request::get('orientation', 'landscape');
        if ($q === '' || mb_strlen($q) < 2) {
            Response::json(['ok' => true, 'items' => []]);
        }
        $items = ImageBankService::search($q, 12, $orientation);
        // Recortar la respuesta: el front no necesita `download_location` ni urls grandes.
        $out = array_map(static function (array $r): array {
            return [
                'id'           => $r['id'],
                'description'  => $r['description'],
                'alt'          => $r['alt'],
                'thumb'        => $r['urls']['thumb'] ?? '',
                'preview'      => $r['urls']['small'] ?? $r['urls']['regular'] ?? '',
                'photographer' => $r['photographer']['name'] ?? '',
                'profile_url'  => $r['photographer']['profile_url'] ?? '',
                'source_url'   => $r['links_html'] ?? '',
                'width'        => $r['width'] ?? 0,
                'height'       => $r['height'] ?? 0,
            ];
        }, $items);
        Response::json(['ok' => true, 'items' => $out, 'query' => $q]);
    }

    /**
     * POST /admin/media/bank/import — descarga e ingesta una imagen.
     * Body: result_id, query, orientation (opcional, alt opcional).
     */
    public function bankImport(): void
    {
        CSRF::check();
        $siteId = self::requireSiteId();
        if (!ImageBankService::isAvailable()) {
            Response::json(['ok' => false, 'error' => __('media.error.bank_off')], 503);
        }

        $resultId = trim((string) Request::post('result_id', ''));
        $query    = trim((string) Request::post('query', ''));
        $orientation = (string) Request::post('orientation', 'landscape');
        $alt      = trim((string) Request::post('alt', ''));

        if ($resultId === '' || $query === '') {
            Response::json(['ok' => false, 'error' => __('media.error.missing_params')], 422);
        }

        // Refetchamos los resultados en caché para localizar el result_id elegido.
        // Esto evita que el front nos pueda inyectar URLs arbitrarias.
        $candidates = ImageBankService::search($query, 12, $orientation);
        $hit = null;
        foreach ($candidates as $r) {
            if (($r['id'] ?? '') === $resultId) { $hit = $r; break; }
        }
        if ($hit === null) {
            Response::json(['ok' => false, 'error' => __('media.error.not_in_results')], 404);
        }

        try {
            $row = ImageBankService::downloadToMedia($hit, $siteId, Auth::id(), $alt !== '' ? $alt : null);
        } catch (\Throwable $e) {
            error_log('[MediaController] bankImport error: ' . $e->getMessage());
            Response::json(['ok' => false, 'error' => __('media.error.download_failed')], 500);
        }

        $path = ltrim((string) $row['path'], '/');
        Response::json([
            'ok' => true,
            'media' => [
                'id'               => (int) $row['id'],
                'name'             => (string) $row['original_name'],
                'url'              => base_url($path),
                'path'             => '/' . $path,
                'alt_text'         => (string) ($row['alt_text'] ?? ''),
                'mime_type'        => (string) $row['mime_type'],
                'width'            => $row['width'] !== null ? (int) $row['width'] : null,
                'height'           => $row['height'] !== null ? (int) $row['height'] : null,
                'source'           => 'unsplash',
                'attribution_name' => (string) ($row['attribution_name'] ?? ''),
                'attribution_url'  => (string) ($row['attribution_url'] ?? ''),
            ],
        ]);
    }

    // ======================================================================
    /** @param array<int,array<string,mixed>> $items */
    private static function repairRemoteImports(array $items, int $siteId): void
    {
        foreach ($items as $item) {
            $path = ltrim((string) ($item['path'] ?? ''), '/');
            if (!str_starts_with($path, 'storage/uploads/' . $siteId . '/email-')) continue;
            if (!RemoteImageImporter::repairStoredMedia($item, $siteId)) {
                error_log('[MediaController] remote import unreadable media_id=' . (int) ($item['id'] ?? 0));
            }
        }
    }

    private static function findOrFail(int $id, int $siteId): array
    {
        $row = Database::selectOne(
            'SELECT * FROM media WHERE id = ? AND site_id = ? LIMIT 1',
            [$id, $siteId]
        );
        if (!$row) {
            Session::flash('error', __('media.error.not_found'));
            Response::redirect(base_url('admin/media'));
        }
        return $row;
    }

    private static function requireSiteId(): int
    {
        $siteId = Auth::siteId();
        if ($siteId === null) {
            Session::flash('error', __('common.no_active_site'));
            Response::redirect(base_url('admin/'));
        }
        return $siteId;
    }
}
