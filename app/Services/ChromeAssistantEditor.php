<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\AI\Actions;
use App\Services\AI\AIActionRunner;
use Core\Database;

/**
 * ASST-V2 F4b — Ejecutor del asistente para `chrome.edit`.
 *
 * Solo textos y enlaces del header y el pie: menú (con desplegables), botón
 * del header y los textos del pie. Diseño, logo, colores y columnas siguen en
 * el editor manual (chrome.manage).
 *
 * Header y pie no tienen borrador: guardar es publicarlos en todo el sitio.
 * Antes se guarda la config anterior en `versions` (entity chrome) para
 * deshacerlo desde Actividad.
 */
final class ChromeAssistantEditor
{
    public const ENTITY = 'chrome';

    /**
     * Prepara la config nueva, sin guardar nada.
     *
     * @return array{ok:bool, error?:string, before?:array<string,mixed>, content?:array<string,mixed>, base_hash?:string}
     * @throws \App\Services\AI\AIException si falla la llamada al modelo
     */
    public static function propose(int $siteId, string $instruction): array
    {
        $config = ChromeService::load($siteId);
        $primary = LanguageService::primaryFor($siteId);
        $current = self::editable($config, HeaderMenuService::editableMenu($config, $primary, $primary, HeaderMenuService::autoByLang($siteId)));
        $pages = self::sitePages($siteId);

        $result = AIActionRunner::run(Actions::EDIT_CHROME, [
            'request'        => $instruction,
            'current_chrome' => json_encode($current, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'site_pages'     => implode("\n", array_map(
                static fn (array $p): string => $p['id'] . ' | ' . $p['title'] . ' | /' . ltrim($p['slug'], '/') . ' | ' . $p['status'],
                $pages
            )) ?: '-',
        ], $siteId);

        $draft = is_array($result['data'] ?? null) ? $result['data'] : [];
        return self::prepare($siteId, $draft);
    }

    /**
     * Lo que haría guardar `$draft` (salida de la IA): config completa saneada.
     * Separado de propose() para probarlo sin IA.
     *
     * @param array<string,mixed> $draft
     * @return array{ok:bool, error?:string, before?:array<string,mixed>, content?:array<string,mixed>, base_hash?:string}
     */
    public static function prepare(int $siteId, array $draft): array
    {
        $stored = self::storedConfig($siteId);
        $config = ChromeService::load($siteId);
        $primary = LanguageService::primaryFor($siteId);
        $auto = HeaderMenuService::autoByLang($siteId);
        $before = $config;
        // Puede congelar los menús automáticos de los idiomas secundarios: es
        // lo mismo que hace «Añadir al menú» cuando la base deja de ser automática.
        $effectiveMenu = HeaderMenuService::editableMenu($config, $primary, $primary, $auto);

        $header = (array) ($draft['header'] ?? []);
        $footer = (array) ($draft['footer'] ?? []);
        $next = $config;
        $next['header']['menu'] = (array) ($header['menu'] ?? $effectiveMenu);
        $next['header']['cta'] = (array) ($header['cta'] ?? $config['header']['cta']);
        foreach (['tagline', 'nav', 'contact', 'social', 'copyright', 'labels', 'brand'] as $key) {
            if (array_key_exists($key, $footer)) {
                $next['footer'][$key] = $footer[$key];
            }
        }
        $next = ChromeService::sanitize($next);

        // Mismo menú que el automático: se sigue en automático (las páginas
        // nuevas que se publiquen seguirán entrando solas).
        if ((array) ($stored['header']['menu'] ?? []) === []
            && self::sameMenu($next['header']['menu'], ChromeService::sanitize(['header' => ['menu' => $effectiveMenu]])['header']['menu'])) {
            $next['header']['menu'] = [];
            $next['i18n'] = ChromeService::sanitize($before)['i18n'];
        }

        if ($next['header']['menu'] === [] && (array) ($stored['header']['menu'] ?? []) !== []) {
            return ['ok' => false, 'error' => __('asst.err.chrome_empty_menu')];
        }
        $known = array_column(self::sitePages($siteId), 'id');
        foreach (array_merge(self::pageIds($next['header']['menu']), self::pageIds($next['footer']['nav'])) as $id) {
            if (!in_array($id, $known, true)) {
                return ['ok' => false, 'error' => __('asst.err.chrome_unknown_page', ['id' => (string) $id])];
            }
        }
        return [
            'ok'        => true,
            'before'    => ChromeService::sanitize($before),
            'content'   => $next,
            'base_hash' => ChromeService::fingerprint($siteId),
        ];
    }

    /**
     * Guarda una propuesta ya preparada si nadie ha tocado el header y el pie
     * entre medias.
     *
     * @param array<string,mixed> $content
     * @return array{ok:bool, reply?:string, error?:string}
     */
    public static function commit(int $siteId, array $content, string $baseHash, ?int $userId): array
    {
        if (!hash_equals(ChromeService::fingerprint($siteId), $baseHash)) {
            return ['ok' => false, 'error' => __('asst.err.chrome_changed_since_preview')];
        }
        $config = ChromeService::sanitize($content);
        self::snapshot($siteId, $userId, 'before_ai_edit');
        ChromeService::save($siteId, $config);
        CacheService::flush($siteId);

        $reply = __('asst.chrome.updated');
        $translated = array_keys((array) ($config['i18n'] ?? []));
        if ($translated !== []) {
            $reply .= ' ' . __('asst.chrome.review_translations', ['idiomas' => implode(', ', $translated)]);
        }
        return ['ok' => true, 'reply' => $reply];
    }

    /** @return array{ok:bool, reply?:string, error?:string} */
    public static function apply(int $siteId, string $instruction, ?int $userId): array
    {
        $proposal = self::propose($siteId, $instruction);
        if (!$proposal['ok']) {
            return $proposal;
        }
        return self::commit($siteId, $proposal['content'], $proposal['base_hash'], $userId);
    }

    /** Devuelve el header y el pie a una versión (guardando antes la actual). */
    public static function restoreVersion(int $siteId, int $versionId, ?int $userId): bool
    {
        $row = Database::selectOne(
            'SELECT version_data FROM versions WHERE id = ? AND entity_type = ? AND entity_id = ? LIMIT 1',
            [$versionId, self::ENTITY, $siteId]
        );
        $data = $row !== null ? json_decode((string) $row['version_data'], true) : null;
        if (!is_array($data)) return false;
        self::snapshot($siteId, $userId, 'before_restore');
        // Una versión vacía es «nunca configurado»: todo vuelve a automático.
        ChromeService::save($siteId, $data === [] ? [] : ChromeService::sanitize($data));
        CacheService::flush($siteId);
        return true;
    }

    /**
     * Lo que enseña la tarjeta: menú y pie antes y después, y la lista de cambios.
     *
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     * @return array<string,mixed>
     */
    public static function preview(int $siteId, array $before, array $after): array
    {
        $titles = array_column(self::sitePages($siteId), 'title', 'id');
        $primary = LanguageService::primaryFor($siteId);
        $auto = HeaderMenuService::autoByLang($siteId);
        $resolve = static function (array $config) use ($primary, $auto): array {
            return HeaderMenuService::editableMenu($config, $primary, $primary, $auto);
        };
        $old = self::outline($before, $resolve($before), $titles);
        $new = self::outline($after, $resolve($after), $titles);
        return ['kind' => 'chrome', 'before' => $old, 'after' => $new, 'changes' => self::diff($old, $new)];
    }

    /**
     * Versión ligera para la maqueta: etiquetas ya resueltas.
     *
     * @param array<string,mixed> $config
     * @param array<int,array<string,mixed>> $menu menú efectivo (automático resuelto)
     * @param array<int,string> $titles page_id => título
     * @return array<string,mixed>
     */
    public static function outline(array $config, array $menu, array $titles): array
    {
        $item = static function (array $it) use (&$item, $titles): array {
            $type = (string) ($it['type'] ?? 'page');
            $label = trim((string) ($it['label'] ?? ''));
            if ($label === '' && $type === 'page') $label = (string) ($titles[(int) ($it['page_id'] ?? 0)] ?? '#' . (int) ($it['page_id'] ?? 0));
            return [
                'key'      => $type === 'page' ? 'p' . (int) ($it['page_id'] ?? 0) : ($type === 'link' ? 'l' . (string) ($it['url'] ?? '') : 'd' . mb_strtolower($label)),
                'label'    => $label,
                'type'     => $type,
                'visible'  => ($it['visible'] ?? true) !== false,
                'children' => array_map($item, array_values((array) ($it['children'] ?? []))),
            ];
        };
        $cta = (array) ($config['header']['cta'] ?? []);
        $footer = (array) ($config['footer'] ?? []);
        return [
            'menu'   => array_map($item, array_values($menu)),
            'cta'    => [
                'mode'  => (string) ($cta['mode'] ?? 'auto'),
                'label' => (string) ($cta['label'] ?? ''),
                'url'   => (string) ($cta['url'] ?? ''),
            ],
            'footer' => [
                'tagline'   => (string) ($footer['tagline'] ?? ''),
                'nav'       => array_map($item, array_values((array) ($footer['nav'] ?? []))),
                'contact'   => array_filter((array) ($footer['contact'] ?? []), static fn ($v): bool => trim((string) $v) !== ''),
                'social'    => array_values(array_map(static fn (array $s): string => (string) ($s['network'] ?? ''), (array) ($footer['social'] ?? []))),
                'copyright' => (string) ($footer['copyright'] ?? ''),
            ],
        ];
    }

    /**
     * @param array<string,mixed> $old outline()
     * @param array<string,mixed> $new outline()
     * @return array<int,array{kind:string,label:string,detail:string}>
     */
    public static function diff(array $old, array $new): array
    {
        $changes = [];
        $flat = static function (array $menu): array {
            $out = [];
            foreach ($menu as $it) {
                $out[$it['key']] = $it;
                foreach ($it['children'] as $child) $out[$child['key']] = $child + ['parent' => $it['label']];
            }
            return $out;
        };
        $a = $flat($old['menu']);
        $b = $flat($new['menu']);
        foreach ($b as $key => $it) {
            if (!isset($a[$key])) {
                $changes[] = ['kind' => 'added', 'label' => $it['label'], 'detail' => isset($it['parent'])
                    ? __('asst.chrome.in_dropdown', ['menu' => $it['parent']]) : __('asst.chrome.in_menu')];
            } elseif ($a[$key]['label'] !== $it['label']) {
                $changes[] = ['kind' => 'changed', 'label' => $it['label'], 'detail' => __('asst.diff.renamed', ['antes' => $a[$key]['label']])];
            } elseif (($a[$key]['parent'] ?? '') !== ($it['parent'] ?? '')) {
                $changes[] = ['kind' => 'changed', 'label' => $it['label'], 'detail' => isset($it['parent'])
                    ? __('asst.chrome.in_dropdown', ['menu' => $it['parent']]) : __('asst.chrome.in_menu')];
            } elseif ($a[$key]['visible'] !== $it['visible']) {
                $changes[] = ['kind' => 'changed', 'label' => $it['label'], 'detail' => __($it['visible'] ? 'asst.chrome.shown' : 'asst.chrome.hidden')];
            }
        }
        foreach ($a as $key => $it) {
            if (!isset($b[$key])) $changes[] = ['kind' => 'removed', 'label' => $it['label'], 'detail' => __('asst.chrome.from_menu')];
        }
        $common = array_values(array_intersect(array_column($old['menu'], 'key'), array_column($new['menu'], 'key')));
        $commonNew = array_values(array_intersect(array_column($new['menu'], 'key'), array_column($old['menu'], 'key')));
        if ($common !== $commonNew) {
            $changes[] = ['kind' => 'changed', 'label' => __('asst.chrome.menu_order'), 'detail' => ''];
        }

        if ($old['cta'] !== $new['cta']) {
            $changes[] = ['kind' => 'text', 'label' => __('asst.chrome.cta'), 'detail' => self::ctaText($new['cta'])];
        }
        $fa = $old['footer'];
        $fb = $new['footer'];
        foreach (['tagline' => 'asst.chrome.tagline', 'copyright' => 'asst.chrome.copyright'] as $key => $labelKey) {
            if (trim($fa[$key]) !== trim($fb[$key])) {
                $changes[] = ['kind' => 'text', 'label' => __($labelKey), 'detail' => $fb[$key] !== '' ? $fb[$key] : __('asst.chrome.automatic')];
            }
        }
        if (array_column($fa['nav'], 'label', 'key') !== array_column($fb['nav'], 'label', 'key')) {
            $changes[] = ['kind' => 'changed', 'label' => __('asst.chrome.footer_nav'), 'detail' => $fb['nav'] !== []
                ? implode(', ', array_column($fb['nav'], 'label')) : __('asst.chrome.automatic')];
        }
        if ($fa['contact'] != $fb['contact']) {
            $changes[] = ['kind' => 'text', 'label' => __('asst.chrome.contact'), 'detail' => implode(' · ', $fb['contact'])];
        }
        if ($fa['social'] !== $fb['social']) {
            $changes[] = ['kind' => 'text', 'label' => __('asst.chrome.social'), 'detail' => implode(', ', $fb['social']) ?: __('asst.diff.empty')];
        }
        return $changes;
    }

    /** @param array<string,string> $cta */
    private static function ctaText(array $cta): string
    {
        return match ($cta['mode']) {
            'off'    => __('asst.chrome.cta_off'),
            'custom' => $cta['label'] . ($cta['url'] !== '' ? ' → ' . $cta['url'] : ''),
            default  => __('asst.chrome.automatic'),
        };
    }

    /**
     * El subconjunto que ve la IA.
     *
     * @param array<string,mixed> $config
     * @param array<int,array<string,mixed>> $menu
     * @return array<string,mixed>
     */
    private static function editable(array $config, array $menu): array
    {
        $footer = (array) ($config['footer'] ?? []);
        return [
            'header' => ['menu' => array_values($menu), 'cta' => (array) ($config['header']['cta'] ?? [])],
            'footer' => [
                'tagline'   => (string) ($footer['tagline'] ?? ''),
                'nav'       => array_values((array) ($footer['nav'] ?? [])),
                'contact'   => (array) ($footer['contact'] ?? []),
                'social'    => array_values((array) ($footer['social'] ?? [])),
                'copyright' => (string) ($footer['copyright'] ?? ''),
                'labels'    => (array) ($footer['labels'] ?? []),
                'brand'     => (array) ($footer['brand'] ?? []),
            ],
        ];
    }

    /** @param array<int,array<string,mixed>> $a @param array<int,array<string,mixed>> $b */
    private static function sameMenu(array $a, array $b): bool
    {
        return json_encode($a) === json_encode($b);
    }

    /** @param array<int,array<string,mixed>> $menu @return int[] */
    private static function pageIds(array $menu): array
    {
        $ids = [];
        foreach ($menu as $it) {
            if (($it['type'] ?? '') === 'page') $ids[] = (int) ($it['page_id'] ?? 0);
            foreach ((array) ($it['children'] ?? []) as $child) {
                if (($child['type'] ?? '') === 'page') $ids[] = (int) ($child['page_id'] ?? 0);
            }
        }
        return $ids;
    }

    /** @return array<int,array{id:int,title:string,slug:string,status:string}> */
    private static function sitePages(int $siteId): array
    {
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'title' => (string) $r['title'], 'slug' => (string) $r['slug'], 'status' => (string) $r['status'],
        ], Database::select(
            "SELECT id, title, slug, status FROM pages WHERE site_id = ? AND slug NOT LIKE '\\_\\_%' ORDER BY id LIMIT 200",
            [$siteId]
        ));
    }

    /** Lo guardado tal cual (sin defaults); [] si nunca se configuró. @return array<string,mixed> */
    private static function storedConfig(int $siteId): array
    {
        $row = Database::selectOne(
            'SELECT setting_value FROM settings WHERE site_id = ? AND setting_key = ? LIMIT 1',
            [$siteId, ChromeService::SETTING_KEY]
        );
        $data = json_decode((string) ($row['setting_value'] ?? ''), true);
        return is_array($data) ? $data : [];
    }

    private static function snapshot(int $siteId, ?int $userId, string $reason): void
    {
        Database::execute(
            'INSERT INTO versions (entity_type, entity_id, version_data, created_by, reason) VALUES (?, ?, ?, ?, ?)',
            [self::ENTITY, $siteId, json_encode((object) self::storedConfig($siteId), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $userId, $reason]
        );
    }
}
