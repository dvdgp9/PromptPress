<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;

/**
 * ASST-V2 F2 — Qué está viendo la persona cuando abre el panel del asistente.
 *
 * Con esto «cambia este formulario» funciona sin decir cuál. El navegador solo
 * devuelve {type, id}: el título y la existencia se comprueban aquí otra vez
 * antes de que nada llegue al prompt.
 *
 * Y lo contrario: AssistantScreenContext::deepLink() convierte un item que el
 * asistente aún no hace en un botón al sitio exacto donde hacerlo a mano.
 */
final class AssistantScreenContext
{
    /**
     * Contexto de la pantalla de una ruta del panel, o null si no aporta.
     *
     * @return array{type:string,id:int,label:string}|null
     */
    public static function forPath(int $siteId, string $path): ?array
    {
        $path = '/' . trim((string) parse_url($path, PHP_URL_PATH), '/');
        if (preg_match('#^/admin/formularios/(\d+)$#', $path, $m) === 1) {
            return self::resolve($siteId, 'form', (int) $m[1]);
        }
        if (preg_match('#^/admin/pages/(\d+)/edit$#', $path, $m) === 1) {
            return self::resolve($siteId, 'page', (int) $m[1]);
        }
        if ($path === '/admin' || $path === '/admin/assistant') {
            return null;
        }
        $labelKey = AdminNavigation::labelKeyForPath($path);
        if ($labelKey === null || $labelKey === 'nav.dashboard') {
            return null;
        }
        return ['type' => 'screen', 'id' => 0, 'label' => __($labelKey)];
    }

    /**
     * Vuelve a comprobar lo que manda el navegador.
     *
     * @return array{type:string,id:int,label:string}|null
     */
    public static function resolve(int $siteId, string $type, int $id): ?array
    {
        if ($type === 'form' && $id > 0) {
            $form = FormStore::find($siteId, $id);
            if ($form === null) return null;
            $title = trim((string) ($form['heading'] ?? '')) ?: __('forms.untitled');
            return ['type' => 'form', 'id' => $id, 'label' => __('asst.form.label', ['formulario' => $title])];
        }
        if ($type === 'page' && $id > 0) {
            $page = Database::selectOne(
                "SELECT id, title FROM pages WHERE id = ? AND site_id = ? AND slug NOT LIKE '\\_\\_%' LIMIT 1",
                [$id, $siteId]
            );
            if ($page === null) return null;
            return ['type' => 'page', 'id' => $id, 'label' => __('asst.ctx.page', ['pagina' => (string) $page['title']])];
        }
        return null;
    }

    /**
     * Línea para el prompt del planner. Vacía sin contexto.
     *
     * @param array{type:string,id:int,label:string}|null $context
     */
    public static function promptBlock(?array $context): string
    {
        if ($context === null) {
            return '';
        }
        // i18n-ignore-start: bloque que viaja dentro del prompt.
        $ref = match ($context['type']) {
            'form' => 'form_id=' . $context['id'],
            'page' => 'page_id=' . $context['id'],
            default => '',
        };
        return "PANTALLA ACTUAL: el operador escribe desde " . $context['label']
            . ($ref !== '' ? ' (' . $ref . ')' : '')
            . ". Si dice «este», «esta», «aquí» o no nombra otro elemento, se refiere a eso.\n\n";
        // i18n-ignore-end
    }

    /**
     * Botón «Llévame» para un item que no se aplica solo: al formulario o la
     * página concretos si se conocen; si no, a la pantalla de su capacidad.
     *
     * @param array<string,mixed> $item item normalizado del plan
     * @return array{url:string,label:string}|null
     */
    public static function deepLink(array $item, int $siteId): ?array
    {
        $formId = (int) ($item['form_id'] ?? 0);
        if ($formId > 0) {
            return ['url' => base_url('admin/formularios/' . $formId), 'label' => __('asst.go.form')];
        }
        $pageId = (int) ($item['page_id'] ?? 0);
        if ($pageId > 0) {
            $page = Database::selectOne(
                'SELECT render_mode FROM pages WHERE id = ? AND site_id = ? LIMIT 1',
                [$pageId, $siteId]
            );
            if ($page !== null) {
                return [
                    'url' => base_url(($page['render_mode'] ?? '') === 'canvas' ? 'admin/canvas/' . $pageId : 'admin/pages/' . $pageId . '/edit'),
                    'label' => __('asst.go.page'),
                ];
            }
        }
        $path = trim((string) ($item['admin_path'] ?? ''));
        if ($path === '' || !str_starts_with($path, '/admin')) {
            return null;
        }
        $labelKey = AdminNavigation::labelKeyForPath($path);
        return [
            'url' => base_url(ltrim($path, '/')),
            'label' => $labelKey !== null ? __('asst.go.screen', ['pantalla' => __($labelKey)]) : __('asst.go.generic'),
        ];
    }
}
