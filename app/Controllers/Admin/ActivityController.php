<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\ActivityService;
use Core\Auth;
use Core\CSRF;
use Core\Request;
use Core\Response;
use Core\Session;
use Core\View;

/**
 * ASST-V2 F1 — /admin/actividad: todos los cambios recientes en páginas y
 * formularios, del asistente o a mano, con «Deshacer».
 */
class ActivityController
{
    public function index(): void
    {
        $siteId = self::requireSiteId();
        $filter = (string) Request::get('f', 'all');
        if (!in_array($filter, ActivityService::FILTERS, true)) {
            $filter = 'all';
        }
        View::send('admin/activity/index', array_merge(DashboardController::getCommonData(), [
            'entries' => ActivityService::entries($siteId, Auth::role(), $filter),
            'filter'  => $filter,
            'notice'  => Session::flash('notice'),
            'error'   => Session::flash('error'),
            'csrf'    => CSRF::token(),
        ]));
    }

    public function undo(): void
    {
        $siteId = self::requireSiteId();
        CSRF::check();
        $result = ActivityService::undo(
            $siteId,
            Auth::role(),
            Auth::id(),
            (string) Request::post('type', ''),
            (int) Request::post('entity_id', 0),
            (int) Request::post('version_id', 0)
        );
        Session::flash($result['ok'] ? 'notice' : 'error', __($result['message'], $result['vars'] ?? []));
        $filter = (string) Request::post('f', 'all');
        Response::redirect(base_url('admin/actividad' . ($filter !== 'all' && in_array($filter, ActivityService::FILTERS, true) ? '?f=' . $filter : '')));
    }

    private static function requireSiteId(): int
    {
        $siteId = Auth::siteId();
        if ($siteId === null) {
            Session::flash('error', __('bk.err.no_site'));
            Response::redirect(base_url('admin/'));
        }
        return $siteId;
    }
}
