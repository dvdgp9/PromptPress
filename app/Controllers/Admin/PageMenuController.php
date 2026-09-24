<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\CacheService;
use App\Services\ChromeService;
use App\Services\HeaderMenuService;
use App\Services\LanguageService;
use Core\Auth;
use Core\CSRF;
use Core\Database;
use Core\Request;
use Core\Response;

/**
 * MENU-PAGES — Añadir/quitar una página del menú del header desde la vista de
 * páginas. El orden y los desplegables siguen en «Header y pie».
 */
class PageMenuController
{
    /**
     * POST /admin/pages/{id}/menu   action=add|remove
     * JSON: {ok, in_menu, message} | {ok:false, error}
     */
    public function toggle(array $params = []): void
    {
        CSRF::check();
        // Mismo permiso que el editor de header: cambia algo que sale en TODAS
        // las páginas.
        if (Auth::role() !== 'admin') {
            Response::json(['ok' => false, 'error' => __('page_menu.admin_only')], 403);
        }
        $siteId = Auth::siteId();
        if ($siteId === null) {
            Response::json(['ok' => false, 'error' => __('common.no_active_site')], 400);
        }

        $page = Database::selectOne(
            'SELECT id, title, status, language, page_type FROM pages WHERE id = ? AND site_id = ? LIMIT 1',
            [(int) ($params['id'] ?? 0), $siteId]
        );
        if (!$page) {
            Response::json(['ok' => false, 'error' => __('post_ctrl.page_not_found')], 404);
        }
        $action = (string) Request::post('action', '');
        if (!in_array($action, ['add', 'remove'], true)) {
            Response::json(['ok' => false, 'error' => __('page_menu.bad_action')], 422);
        }
        // Un borrador en el menú sería un enlace a una página que no se ve.
        if ($action === 'add' && ($page['status'] ?? '') !== 'published') {
            Response::json(['ok' => false, 'error' => __('page_menu.publish_first')], 422);
        }

        $pageId  = (int) $page['id'];
        $lang    = LanguageService::forPage($page, $siteId);
        $primary = LanguageService::primaryFor($siteId);
        $auto    = HeaderMenuService::autoByLang($siteId);
        $config  = ChromeService::sanitize(ChromeService::load($siteId));

        try {
            $next = $action === 'add'
                ? HeaderMenuService::add($config, $pageId, $lang, $primary, $auto, self::contactIds($siteId, $lang))
                : HeaderMenuService::remove($config, $pageId, $lang, $primary, $auto);
        } catch (\DomainException $e) {
            $key = $e->getMessage() === 'menu_full' ? 'page_menu.full' : 'page_menu.last_item';
            Response::json(['ok' => false, 'error' => __($key, ['n' => HeaderMenuService::MAX_ITEMS])], 422);
        }

        $next = ChromeService::sanitize($next);
        if ($next !== $config) {
            ChromeService::save($siteId, $next);
            // El header va dentro del HTML cacheado de cada página.
            CacheService::flush($siteId);
        }

        $inMenu = HeaderMenuService::contains($next, $pageId, $lang, $primary, $auto);
        error_log('[PageMenu] site=' . $siteId . ' page=' . $pageId . ' lang=' . $lang
            . ' action=' . $action . ' in_menu=' . ($inMenu ? '1' : '0'));

        Response::json([
            'ok' => true,
            'in_menu' => $inMenu,
            'message' => __($inMenu ? 'page_menu.added' : 'page_menu.removed', ['titulo' => (string) $page['title']]),
        ]);
    }

    /**
     * Páginas de contacto del idioma: una página nueva entra ANTES de ellas si
     * están al final del menú (Contacto suele cerrar el menú).
     *
     * @return int[]
     */
    private static function contactIds(int $siteId, string $lang): array
    {
        try {
            $rows = Database::select(
                "SELECT id FROM pages WHERE site_id = ? AND page_type = 'contact' AND (language = ? OR language IS NULL OR language = '')",
                [$siteId, $lang]
            );
        } catch (\Throwable $e) {
            return [];
        }
        return array_map(static fn(array $r) => (int) $r['id'], $rows);
    }
}
