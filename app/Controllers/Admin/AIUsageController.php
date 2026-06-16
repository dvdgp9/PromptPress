<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\AI\Actions;
use Core\Auth;
use Core\Database;
use Core\Request;
use Core\Response;
use Core\Session;
use Core\View;

/**
 * Dashboard de uso de IA (T6.4).
 *
 * Muestra KPIs + tabla de llamadas recientes + resumen por modelo.
 * Sin dependencias externas: agregaciones en SQL directo.
 */
class AIUsageController
{
    private const PAGE_SIZE = 50;

    public function index(): void
    {
        $siteId = $this->requireSiteId();

        // KPIs: hoy, últimos 30 días, totales
        $kpiToday  = $this->aggregates($siteId, 'DATE(created_at) = CURDATE()');
        $kpiMonth  = $this->aggregates($siteId, 'created_at >= (NOW() - INTERVAL 30 DAY)');
        $kpiAll    = $this->aggregates($siteId, '1=1');

        // Resumen por modelo (últimos 30 días)
        $byModel = Database::select(
            "SELECT provider, model,
                    COUNT(*) AS calls,
                    SUM(tokens_input) AS tokens_in,
                    SUM(tokens_output) AS tokens_out,
                    SUM(estimated_cost) AS cost
             FROM ai_logs
             WHERE site_id = ? AND created_at >= (NOW() - INTERVAL 30 DAY)
             GROUP BY provider, model
             ORDER BY cost DESC, calls DESC",
            [$siteId]
        );

        // Resumen por acción (últimos 30 días)
        $byAction = Database::select(
            "SELECT action_type,
                    COUNT(*) AS calls,
                    SUM(tokens_input + tokens_output) AS tokens,
                    SUM(estimated_cost) AS cost,
                    SUM(CASE WHEN status='error' THEN 1 ELSE 0 END) AS errors
             FROM ai_logs
             WHERE site_id = ? AND created_at >= (NOW() - INTERVAL 30 DAY)
             GROUP BY action_type
             ORDER BY calls DESC",
            [$siteId]
        );

        // Paginación de llamadas recientes
        $page = max(1, (int) (Request::get('page') ?? 1));
        $offset = ($page - 1) * self::PAGE_SIZE;

        $total = (int) (Database::selectOne(
            'SELECT COUNT(*) AS c FROM ai_logs WHERE site_id = ?',
            [$siteId]
        )['c'] ?? 0);

        $logs = Database::select(
            "SELECT l.id, l.created_at, l.provider, l.model, l.action_type,
                    l.tokens_input, l.tokens_output, l.estimated_cost, l.duration_ms,
                    l.status, l.error_message,
                    u.username
             FROM ai_logs l
             LEFT JOIN users u ON u.id = l.user_id
             WHERE l.site_id = ?
             ORDER BY l.created_at DESC
             LIMIT " . self::PAGE_SIZE . " OFFSET " . (int) $offset,
            [$siteId]
        );

        $totalPages = (int) max(1, ceil($total / self::PAGE_SIZE));

        View::send('admin/ai/usage', array_merge(
            DashboardController::getCommonData(),
            [
                'kpiToday'   => $kpiToday,
                'kpiMonth'   => $kpiMonth,
                'kpiAll'     => $kpiAll,
                'byModel'    => $byModel,
                'byAction'   => $byAction,
                'logs'       => $logs,
                'page'       => $page,
                'totalPages' => $totalPages,
                'total'      => $total,
                'actionLabels' => array_map(fn($a) => $a['label'], Actions::all()),
            ]
        ));
    }

    /**
     * Devuelve agregados [calls, errors, tokens_in, tokens_out, cost]
     * para la ventana dada (cláusula WHERE adicional).
     */
    private function aggregates(int $siteId, string $whereExtra): array
    {
        $row = Database::selectOne(
            "SELECT COUNT(*) AS calls,
                    SUM(CASE WHEN status='error' THEN 1 ELSE 0 END) AS errors,
                    COALESCE(SUM(tokens_input), 0)  AS tokens_in,
                    COALESCE(SUM(tokens_output), 0) AS tokens_out,
                    COALESCE(SUM(estimated_cost), 0) AS cost
             FROM ai_logs
             WHERE site_id = ? AND ($whereExtra)",
            [$siteId]
        );
        return [
            'calls'      => (int) ($row['calls']      ?? 0),
            'errors'     => (int) ($row['errors']     ?? 0),
            'tokens_in'  => (int) ($row['tokens_in']  ?? 0),
            'tokens_out' => (int) ($row['tokens_out'] ?? 0),
            'cost'       => (float) ($row['cost']     ?? 0),
        ];
    }

    private function requireSiteId(): int
    {
        $siteId = Auth::siteId();
        if ($siteId === null) {
            Session::flash('error', 'No hay sitio activo.');
            Response::redirect(base_url('admin/'));
        }
        return $siteId;
    }
}
