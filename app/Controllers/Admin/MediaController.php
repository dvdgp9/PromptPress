<?php

namespace App\Controllers\Admin;

use App\Services\ImageBankService;
use App\Services\MediaService;
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

        $data = DashboardController::getCommonData();
        $data = array_merge($data, [
            'items'      => $items,
            'maxSize'    => MediaService::MAX_SIZE,
            'allowedExt' => array_values(MediaService::ALLOWED),
            'csrf'       => CSRF::token(),
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

        $items = Database::select(
            'SELECT m.id, m.original_name, m.mime_type, m.file_size, m.path,
                    m.alt_text, m.width, m.height, m.created_at
             FROM media m
             ' . $where . '
             ORDER BY m.id DESC
             LIMIT 120',
            $params
        );

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
            if ($isAjax) {
                Response::json([
                    'ok'   => true,
                    'item' => [
                        'id'        => (int) ($row['id'] ?? 0),
                        'url'       => base_url(ltrim((string) ($row['path'] ?? ''), '/')),
                        'name'      => (string) ($row['original_name'] ?? ''),
                        'alt_text'  => (string) ($row['alt_text'] ?? ''),
                        'width'     => (int) ($row['width'] ?? 0),
                        'height'    => (int) ($row['height'] ?? 0),
                        'file_size' => (int) ($row['file_size'] ?? 0),
                    ],
                ]);
                return;
            }
            Session::flash('success', 'Imagen subida correctamente.');
        } catch (\Throwable $e) {
            error_log('[MediaController] upload error: ' . $e->getMessage());
            if ($isAjax) {
                Response::json(['ok' => false, 'error' => 'No se pudo procesar la imagen: ' . $e->getMessage()], 500);
                return;
            }
            Session::flash('error', 'No se pudo procesar la imagen: ' . $e->getMessage());
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
        Session::flash('success', 'Texto alternativo actualizado.');
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
        Session::flash('success', 'Imagen eliminada.');
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
            Response::json(['ok' => false, 'error' => 'Banco de imágenes no configurado.'], 503);
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
            Response::json(['ok' => false, 'error' => 'Banco de imágenes no configurado.'], 503);
        }

        $resultId = trim((string) Request::post('result_id', ''));
        $query    = trim((string) Request::post('query', ''));
        $orientation = (string) Request::post('orientation', 'landscape');
        $alt      = trim((string) Request::post('alt', ''));

        if ($resultId === '' || $query === '') {
            Response::json(['ok' => false, 'error' => 'Faltan parámetros.'], 422);
        }

        // Refetchamos los resultados en caché para localizar el result_id elegido.
        // Esto evita que el front nos pueda inyectar URLs arbitrarias.
        $candidates = ImageBankService::search($query, 12, $orientation);
        $hit = null;
        foreach ($candidates as $r) {
            if (($r['id'] ?? '') === $resultId) { $hit = $r; break; }
        }
        if ($hit === null) {
            Response::json(['ok' => false, 'error' => 'Imagen no encontrada en los resultados recientes. Repite la búsqueda.'], 404);
        }

        try {
            $row = ImageBankService::downloadToMedia($hit, $siteId, Auth::id(), $alt !== '' ? $alt : null);
        } catch (\Throwable $e) {
            error_log('[MediaController] bankImport error: ' . $e->getMessage());
            Response::json(['ok' => false, 'error' => 'No se pudo descargar la imagen.'], 500);
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
    private static function findOrFail(int $id, int $siteId): array
    {
        $row = Database::selectOne(
            'SELECT * FROM media WHERE id = ? AND site_id = ? LIMIT 1',
            [$id, $siteId]
        );
        if (!$row) {
            Session::flash('error', 'Medio no encontrado.');
            Response::redirect(base_url('admin/media'));
        }
        return $row;
    }

    private static function requireSiteId(): int
    {
        $siteId = Auth::siteId();
        if ($siteId === null) {
            Session::flash('error', 'No hay sitio activo.');
            Response::redirect(base_url('admin/'));
        }
        return $siteId;
    }
}
