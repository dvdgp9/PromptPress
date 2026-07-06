<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Modules\ModuleRegistry;
use Core\Auth;
use Core\CSRF;
use Core\Request;
use Core\Response;
use Core\Session;
use Core\View;

/**
 * ModulesController — activación por sitio de los módulos de PromptPress (FEAT-3).
 *
 * Muestra una tarjeta on/off por módulo. Los módulos no disponibles todavía
 * (available:false) aparecen como "próximamente" y no pueden activarse.
 */
class ModulesController
{
    /** GET /admin/modules */
    public function index(): void
    {
        $siteId = self::requireSiteId();
        View::send('admin/modules/index', [
            'modules' => ModuleRegistry::statusFor($siteId),
            'csrf'    => CSRF::token(),
        ]);
    }

    /** POST /admin/modules/toggle */
    public function toggle(): void
    {
        CSRF::check();
        $siteId = self::requireSiteId();

        $key = trim((string) Request::post('module', ''));
        $on  = Request::post('enabled', '0') === '1';

        if (!ModuleRegistry::exists($key) || !ModuleRegistry::isAvailable($key)) {
            Session::flash('error', 'Ese módulo no está disponible.');
            Response::redirect(base_url('admin/modules'));
        }

        ModuleRegistry::setEnabled($siteId, $key, $on);
        // Algunos módulos (Analytics) inyectan markup en el HTML público, que se
        // cachea. Vaciar la caché del sitio para que el cambio se refleje ya.
        \App\Services\CacheService::flush($siteId);
        Session::flash('success', $on
            ? 'Módulo «' . ModuleRegistry::MODULES[$key]['label'] . '» activado.'
            : 'Módulo «' . ModuleRegistry::MODULES[$key]['label'] . '» desactivado.');
        Response::redirect(base_url('admin/modules'));
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
