<?php

namespace App\Controllers\Admin;

use App\Services\LinkAuditService;
use Core\Auth;
use Core\Response;
use Core\Session;
use Core\View;

/**
 * Chequeo de enlaces internos rotos (T-Links).
 */
class LinkController
{
    // GET /admin/links
    public function index(): void
    {
        $siteId = $this->requireSiteId();
        $issues = LinkAuditService::audit($siteId);

        // Agrupar por página de origen para una lectura más clara.
        $byPage = [];
        foreach ($issues as $i) {
            $byPage[$i['page_id']]['title'] = $i['page_title'];
            $byPage[$i['page_id']]['issues'][] = $i;
        }

        View::send('admin/links/index', array_merge(
            DashboardController::getCommonData(),
            [
                'issues'       => $issues,
                'byPage'       => $byPage,
                'sectionTypes' => SectionController::SECTION_TYPES,
            ]
        ));
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
