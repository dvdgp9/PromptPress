<?php

declare(strict_types=1);

namespace App\Modules\Analytics;

use App\Modules\ModuleRegistry;
use Core\Auth;
use Core\Database;
use Core\Request;
use Core\Response;
use Core\Session;
use Core\View;

/**
 * AnalyticsController — módulo Analytics (FEAT-3).
 *
 * - POST /_analytics/collect (A3): ingesta pública stateless, 204 siempre.
 * - GET /admin/analytics (A5): dashboard. Dispara el rollup perezoso.
 * - GET /admin/analytics/data (A5): JSON para cambiar de rango sin recargar.
 */
final class AnalyticsController
{
    /** GET /admin/analytics — dashboard del módulo. */
    public function dashboard(array $params = []): void
    {
        $siteId = self::requireSiteId();
        RollupService::maybeRun($siteId);

        $range = (int) Request::get('range', 30);
        View::send('admin/analytics/index', [
            'stats'  => StatsService::forRange($siteId, $range),
            'ranges' => StatsService::RANGES,
        ]);
    }

    /** GET /admin/analytics/data?range=N — JSON para el selector de rango. */
    public function data(array $params = []): void
    {
        $siteId = self::requireSiteId();
        $range  = (int) Request::get('range', 30);
        Response::json(['ok' => true, 'stats' => StatsService::forRange($siteId, $range)]);
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

    public function collect(array $params = []): void
    {
        $data = Request::json();

        $siteId = (int) ($data['s'] ?? 0);
        if ($siteId <= 0) {
            Response::noContent();
        }

        // Doble check de activación por si el guard cambiara: el sitio del
        // payload debe tener el módulo activo (no basta con el sitio del guard).
        if (!ModuleRegistry::isEnabled($siteId, 'analytics')) {
            Response::noContent();
        }

        // Verificar que el sitio existe (payload podría mentir el id).
        $exists = Database::selectOne('SELECT id FROM sites WHERE id = ? LIMIT 1', [$siteId]);
        if ($exists === null) {
            Response::noContent();
        }

        $path      = (string) ($data['p'] ?? '/');
        $referrer  = isset($data['r']) ? (string) $data['r'] : null;
        $eventType = isset($data['e']) && $data['e'] !== '' ? (string) $data['e'] : 'pageview';

        EventRecorder::record(
            $siteId,
            $eventType,
            $path,
            $referrer,
            Request::ip(),
            Request::userAgent()
        );

        Response::noContent();
    }
}
