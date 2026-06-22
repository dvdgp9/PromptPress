<?php

namespace App\Controllers\Admin;

use Core\Auth;
use Core\View;
use Core\Session;
use Core\Database;

/**
 * DashboardController — escritorio del admin.
 */
class DashboardController
{
    public function index(array $params = []): void
    {
        $data  = self::getCommonData();
        $stats = self::getStats(Auth::siteId());
        // E-GDPR G6 — estado de cumplimiento para el widget.
        $compliance = ['level' => 'green', 'gaps' => []];
        $wizardCompleted = true;
        $siteId = Auth::siteId();
        if ($siteId !== null) {
            try {
                $compliance = \App\Services\Compliance\ComplianceService::status($siteId);
                $wizardCompleted = \App\Services\Compliance\ComplianceService::wizardCompleted($siteId);
            } catch (\Throwable $e) {
                // graceful
            }
        }
        View::send('admin/dashboard', array_merge($data, $stats, [
            'compliance'      => $compliance,
            'wizardCompleted' => $wizardCompleted,
        ]));
    }

    /**
     * Calcula stats y recientes para el site actual.
     * Todas las queries son tolerantes a errores: si algo falla, devuelve 0/vacío.
     */
    private static function getStats(?int $siteId): array
    {
        $defaults = [
            'countPages'      => 0,
            'countPublished'  => 0,
            'countDrafts'     => 0,
            'countMedia'      => 0,
            'countDocuments'  => 0,
            'countAILogs'     => 0,
            'aiTokensInput'   => 0,
            'aiTokensOutput'  => 0,
            'aiCostTotal'     => 0.0,
            'recentPages'     => [],
            'recentAILogs'    => [],
        ];

        if ($siteId === null) {
            return $defaults;
        }

        try {
            $row = Database::selectOne(
                'SELECT
                    COUNT(*) AS total,
                    SUM(CASE WHEN status = "published" THEN 1 ELSE 0 END) AS published,
                    SUM(CASE WHEN status = "draft"     THEN 1 ELSE 0 END) AS drafts
                 FROM pages WHERE site_id = ?',
                [$siteId]
            );
            $defaults['countPages']     = (int) ($row['total']     ?? 0);
            $defaults['countPublished'] = (int) ($row['published'] ?? 0);
            $defaults['countDrafts']    = (int) ($row['drafts']    ?? 0);

            $defaults['countMedia']     = (int) (Database::selectOne(
                'SELECT COUNT(*) AS n FROM media WHERE site_id = ?', [$siteId]
            )['n'] ?? 0);

            $defaults['countDocuments'] = (int) (Database::selectOne(
                'SELECT COUNT(*) AS n FROM documents WHERE site_id = ?', [$siteId]
            )['n'] ?? 0);

            $ai = Database::selectOne(
                'SELECT
                    COUNT(*)                AS total,
                    COALESCE(SUM(tokens_input), 0)  AS tokens_in,
                    COALESCE(SUM(tokens_output), 0) AS tokens_out,
                    COALESCE(SUM(estimated_cost), 0) AS cost
                 FROM ai_logs WHERE site_id = ?',
                [$siteId]
            );
            $defaults['countAILogs']    = (int)   ($ai['total']      ?? 0);
            $defaults['aiTokensInput']  = (int)   ($ai['tokens_in']   ?? 0);
            $defaults['aiTokensOutput'] = (int)   ($ai['tokens_out']  ?? 0);
            $defaults['aiCostTotal']    = (float) ($ai['cost']        ?? 0);

            $defaults['recentPages'] = Database::select(
                'SELECT id, title, slug, page_type, status, updated_at
                 FROM pages WHERE site_id = ?
                 ORDER BY updated_at DESC LIMIT 5',
                [$siteId]
            );

            $defaults['recentAILogs'] = Database::select(
                'SELECT id, provider, model, action_type, tokens_input, tokens_output,
                        estimated_cost, status, created_at
                 FROM ai_logs WHERE site_id = ?
                 ORDER BY created_at DESC LIMIT 5',
                [$siteId]
            );
        } catch (\Throwable $e) {
            // Graceful degradation: ignora y devuelve valores por defecto
        }

        return $defaults;
    }

    /**
     * Datos comunes para todas las vistas admin (layout vars).
     * Se reutilizará en otros controllers.
     */
    public static function getCommonData(): array
    {
        $userName = 'Admin';
        $siteName = 'PromptPress';
        $siteLogoUrl = '';

        if (is_installed()) {
            try {
                $userId = Session::get('user_id');
                if ($userId) {
                    $user = Database::selectOne('SELECT username FROM users WHERE id = ?', [$userId]);
                    if ($user) {
                        $userName = $user['username'];
                    }
                }
                $siteId = Session::get('site_id');
                if ($siteId) {
                    $site = Database::selectOne('SELECT name FROM sites WHERE id = ?', [$siteId]);
                    if ($site) {
                        $siteName = $site['name'];
                    }
                    $siteLogoUrl = \App\Services\BrandService::logoUrl((int) $siteId);
                }
            } catch (\Throwable $e) {
                // Silently fall back to defaults
            }
        }

        return [
            'userName' => $userName,
            'siteName' => $siteName,
            'siteLogoUrl' => $siteLogoUrl,
        ];
    }
}
