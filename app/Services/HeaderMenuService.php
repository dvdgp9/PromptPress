<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;

/**
 * MENU-PAGES — «¿Sale esta página en el menú?» y añadir/quitar una página del
 * menú del header desde la vista de páginas.
 *
 * Lógica PURA sobre la config de `ChromeService` (sin BD): quien llama pasa las
 * listas automáticas por idioma (`$autoByLang`: lang => page_id[] en el orden de
 * `BrandService::autoNavPageIds()`) y guarda el resultado. El orden fino, los
 * desplegables y los enlaces siguen siendo cosa del editor de header.
 *
 * Reglas que sostienen esto:
 * - Cada página toca el menú de SU idioma: principal ⇒ `header.menu`;
 *   secundario ⇒ `i18n[lang].header.menu`.
 * - Menú vacío = automático. La primera vez que se toca se «materializa» con la
 *   lista automática (contacto incluido, como enlace normal), para que no cambie
 *   de golpe lo que se ve.
 * - Al materializar la base en una web multidioma, cada idioma secundario sin
 *   menú propio se congela en SU lista automática: si no, heredaría la base y
 *   empezaría a enlazar páginas de otro idioma.
 * - Nunca se deja un menú vacío: vacío volvería a automático y aparecerían todas
 *   las páginas.
 */
final class HeaderMenuService
{
    /** Igual que el cap de `ChromeService::sanitizeMenu()`. */
    public const MAX_ITEMS = 14;

    /** ¿El menú de ese idioma es personalizado (no automático)? */
    public static function isCustom(array $config, string $lang, string $primaryLang): bool
    {
        return self::effectiveMenu($config, $lang, $primaryLang) !== null;
    }

    /**
     * page_id que salen (visibles) en el header de ese idioma, en orden.
     *
     * @param array<string,int[]> $autoByLang
     * @return int[]
     */
    public static function pageIdsInMenu(array $config, string $lang, string $primaryLang, array $autoByLang): array
    {
        $menu = self::effectiveMenu($config, $lang, $primaryLang);
        if ($menu === null) {
            return array_values(array_map('intval', $autoByLang[$lang] ?? []));
        }
        $ids = [];
        foreach ($menu as $item) {
            if (!is_array($item) || ($item['visible'] ?? true) === false) continue;
            if (($item['type'] ?? 'page') === 'dropdown') {
                foreach ((array) ($item['children'] ?? []) as $child) {
                    if (is_array($child) && ($child['visible'] ?? true) !== false && ($child['type'] ?? 'page') === 'page') {
                        $ids[] = (int) ($child['page_id'] ?? 0);
                    }
                }
            } elseif (($item['type'] ?? 'page') === 'page') {
                $ids[] = (int) ($item['page_id'] ?? 0);
            }
        }
        return $ids;
    }

    /** @param array<string,int[]> $autoByLang */
    public static function contains(array $config, int $pageId, string $lang, string $primaryLang, array $autoByLang): bool
    {
        return in_array($pageId, self::pageIdsInMenu($config, $lang, $primaryLang, $autoByLang), true);
    }

    /**
     * Añade la página al menú de su idioma. Va al final, salvo que el final sea
     * una de `$tailIds` (típicamente la de contacto): entonces justo antes.
     *
     * @param array<string,int[]> $autoByLang
     * @param int[] $tailIds
     * @throws \DomainException 'menu_full'
     */
    public static function add(array $config, int $pageId, string $lang, string $primaryLang, array $autoByLang, array $tailIds = []): array
    {
        $menu = self::editableMenu($config, $lang, $primaryLang, $autoByLang);

        // Si ya está (aunque sea oculta), se hace visible y no se duplica.
        foreach ($menu as $i => $item) {
            if (($item['type'] ?? 'page') === 'page' && (int) ($item['page_id'] ?? 0) === $pageId) {
                $menu[$i]['visible'] = true;
                return self::writeMenu($config, $lang, $primaryLang, $menu);
            }
            if (($item['type'] ?? 'page') === 'dropdown') {
                foreach ((array) ($item['children'] ?? []) as $j => $child) {
                    if (($child['type'] ?? 'page') === 'page' && (int) ($child['page_id'] ?? 0) === $pageId) {
                        $menu[$i]['children'][$j]['visible'] = true;
                        $menu[$i]['visible'] = true;
                        return self::writeMenu($config, $lang, $primaryLang, $menu);
                    }
                }
            }
        }

        if (count($menu) >= self::MAX_ITEMS) {
            throw new \DomainException('menu_full');
        }

        $pos = count($menu);
        $tail = array_map('intval', $tailIds);
        while ($pos > 0) {
            $prev = $menu[$pos - 1];
            if (($prev['type'] ?? 'page') === 'page' && in_array((int) ($prev['page_id'] ?? 0), $tail, true)) {
                $pos--;
                continue;
            }
            break;
        }
        array_splice($menu, $pos, 0, [self::pageItem($pageId)]);
        return self::writeMenu($config, $lang, $primaryLang, $menu);
    }

    /**
     * Quita la página del menú de su idioma (de arriba o de un desplegable; un
     * desplegable que se queda sin hijos desaparece).
     *
     * @param array<string,int[]> $autoByLang
     * @throws \DomainException 'menu_last_item'
     */
    public static function remove(array $config, int $pageId, string $lang, string $primaryLang, array $autoByLang): array
    {
        if (!self::contains($config, $pageId, $lang, $primaryLang, $autoByLang)
            && !self::hasHiddenItem($config, $pageId, $lang, $primaryLang)
        ) {
            return $config;
        }
        $menu = self::editableMenu($config, $lang, $primaryLang, $autoByLang);

        $out = [];
        foreach ($menu as $item) {
            $type = $item['type'] ?? 'page';
            if ($type === 'page' && (int) ($item['page_id'] ?? 0) === $pageId) continue;
            if ($type === 'dropdown') {
                $item['children'] = array_values(array_filter(
                    (array) ($item['children'] ?? []),
                    static fn($c) => !(($c['type'] ?? 'page') === 'page' && (int) ($c['page_id'] ?? 0) === $pageId)
                ));
                if ($item['children'] === []) continue;
            }
            $out[] = $item;
        }

        if ($out === []) {
            throw new \DomainException('menu_last_item');
        }
        return self::writeMenu($config, $lang, $primaryLang, $out);
    }

    // --- Con BD: lo que necesitan el controlador y la vista de páginas -----

    /**
     * Lista automática de cada idioma activo del sitio.
     *
     * @return array<string,int[]>
     */
    public static function autoByLang(int $siteId): array
    {
        $langs = LanguageService::activeFor($siteId);
        $primary = LanguageService::primaryFor($siteId);
        if (!in_array($primary, $langs, true)) $langs[] = $primary;
        $out = [];
        foreach ($langs as $lang) {
            $out[$lang] = BrandService::autoNavPageIds($siteId, $lang);
        }
        return $out;
    }

    /**
     * Todas las páginas del sitio que salen hoy en el header de su idioma
     * (para el chip «En el menú»).
     *
     * @return array<int,true> page_id => true
     */
    public static function siteInMenuIds(int $siteId, ?array $config = null): array
    {
        $config ??= ChromeService::load($siteId);
        $auto = self::autoByLang($siteId);
        $primary = LanguageService::primaryFor($siteId);
        $out = [];
        foreach (array_keys($auto) as $lang) {
            foreach (self::pageIdsInMenu($config, (string) $lang, $primary, $auto) as $id) {
                $out[$id] = true;
            }
        }
        return $out;
    }

    /**
     * MENU-PAGES T5 — Aviso al PUBLICAR: «esta página no sale en el menú».
     *
     * Solo para las páginas que normalmente irían en el menú (primer nivel; ni
     * portada, ni legales, ni entradas del blog), y solo si de verdad no salen.
     * Quien llama decide cuándo preguntarlo (al pasar de borrador a publicada,
     * no en cada «Publicar cambios»).
     *
     * @return array{page_id:int,message:string,can_add:bool}|null
     */
    public static function publishHint(int $siteId, int $pageId, bool $canAdd): ?array
    {
        try {
            $page = Database::selectOne(
                'SELECT id, title, status, language, page_type, parent_id FROM pages WHERE id = ? AND site_id = ? LIMIT 1',
                [$pageId, $siteId]
            );
        } catch (\Throwable $e) {
            return null;
        }
        if (!$page || ($page['status'] ?? '') !== 'published') return null;
        if ((int) ($page['parent_id'] ?? 0) > 0) return null;
        if (in_array((string) ($page['page_type'] ?? ''), ['home', 'legal', 'article'], true)) return null;

        $lang = LanguageService::forPage($page, $siteId);
        $primary = LanguageService::primaryFor($siteId);
        if (self::contains(ChromeService::load($siteId), $pageId, $lang, $primary, self::autoByLang($siteId))) {
            return null;
        }
        return [
            'page_id' => $pageId,
            'message' => __('js.map.published_not_in_menu', ['titulo' => (string) $page['title']]),
            'can_add' => $canAdd,
        ];
    }

    // ------------------------------------------------------------------

    /**
     * Menú que se pinta en ese idioma, o null si está en automático. Mismo
     * criterio que `ChromeService::localized()`: la capa del idioma sustituye
     * entera a la base; sin capa, manda la base.
     *
     * @return array<int,array<string,mixed>>|null
     */
    private static function effectiveMenu(array $config, string $lang, string $primaryLang): ?array
    {
        if ($lang !== $primaryLang) {
            $layer = (array) ($config['i18n'][$lang]['header']['menu'] ?? []);
            if ($layer !== []) return array_values($layer);
        }
        $base = (array) ($config['header']['menu'] ?? []);
        return $base !== [] ? array_values($base) : null;
    }

    /**
     * Menú que se va a EDITAR para ese idioma, materializando lo que haga falta
     * dentro de `$config`.
     *
     * Secundario sin capa propia: se parte de SU lista automática, no de la base
     * (la base apunta a páginas del idioma principal).
     *
     * @param array<string,int[]> $autoByLang
     * @return array<int,array<string,mixed>>
     */
    private static function editableMenu(array &$config, string $lang, string $primaryLang, array $autoByLang): array
    {
        if ($lang !== $primaryLang) {
            $layer = (array) ($config['i18n'][$lang]['header']['menu'] ?? []);
            return $layer !== [] ? array_values($layer) : self::itemsFrom($autoByLang[$lang] ?? []);
        }

        $base = (array) ($config['header']['menu'] ?? []);
        if ($base !== []) {
            return array_values($base);
        }
        // La base pasa de automático a personalizado: los idiomas secundarios
        // que seguían en automático se quedan con SU lista, no con la base.
        foreach ($autoByLang as $other => $ids) {
            $other = (string) $other;
            if ($other === $primaryLang || $ids === []) continue;
            if ((array) ($config['i18n'][$other]['header']['menu'] ?? []) !== []) continue;
            $config['i18n'][$other]['header']['menu'] = self::itemsFrom($ids);
        }
        return self::itemsFrom($autoByLang[$primaryLang] ?? []);
    }

    private static function writeMenu(array $config, string $lang, string $primaryLang, array $menu): array
    {
        if ($lang === $primaryLang) {
            $config['header']['menu'] = array_values($menu);
        } else {
            $config['i18n'][$lang]['header']['menu'] = array_values($menu);
        }
        return $config;
    }

    private static function hasHiddenItem(array $config, int $pageId, string $lang, string $primaryLang): bool
    {
        foreach (self::effectiveMenu($config, $lang, $primaryLang) ?? [] as $item) {
            if (($item['type'] ?? 'page') === 'page' && (int) ($item['page_id'] ?? 0) === $pageId) return true;
            foreach ((array) ($item['children'] ?? []) as $c) {
                if (($c['type'] ?? 'page') === 'page' && (int) ($c['page_id'] ?? 0) === $pageId) return true;
            }
        }
        return false;
    }

    /** @param int[] $ids */
    private static function itemsFrom(array $ids): array
    {
        return array_values(array_map(static fn($id) => self::pageItem((int) $id), $ids));
    }

    private static function pageItem(int $pageId): array
    {
        // Etiqueta vacía: el render usa el título de la página, igual que el
        // modo automático, y sigue a la página si la renombran.
        return ['type' => 'page', 'page_id' => $pageId, 'label' => '', 'visible' => true, 'target' => '_self'];
    }
}
