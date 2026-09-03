<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Modelo puro de la navegación principal del panel.
 *
 * No lee sesión, BD ni traducciones: recibe los módulos activos y devuelve una
 * estructura determinista que la vista puede renderizar como lista plana (N1)
 * o como grupos progresivos (N2).
 */
final class AdminNavigation
{
    /** @var string[] */
    private const LEGACY_ORDER = [
        'home',
        'assistant_home',
        'pages',
        'posts',
        'media',
        'forms',
        'messages',
        'knowledge',
        'documents',
        'design',
        'chrome',
        'seo',
        'marketing',
        'analytics',
        'bookings',
        'shop',
        'resources',
        'ai_usage',
        'privacy',
        'modules',
        'settings',
    ];

    /**
     * @param array<int|string, bool|string> $enabledModules Lista de claves o mapa clave => bool.
     * @return array<int, array<string, mixed>>
     */
    public static function build(string $currentPath, array $enabledModules = []): array
    {
        $path = self::normalizePath($currentPath);
        $enabled = self::normalizeModules($enabledModules);
        $catalog = self::catalog();

        $home = self::prepareLink($catalog['home'], $path);
        $navigation = [$home];

        foreach (self::groupDefinitions() as $groupKey => $definition) {
            $items = [];
            foreach ($definition['items'] as $itemKey) {
                $item = $catalog[$itemKey];
                $module = $item['module'] ?? null;
                if (is_string($module) && !isset($enabled[$module])) {
                    continue;
                }
                $items[] = self::prepareLink($item, $path);
            }

            // Hoy todos los grupos tienen destinos base. Mantener la guarda
            // evita encabezados vacíos si el catálogo modular crece después.
            if ($items === []) {
                continue;
            }

            $navigation[] = [
                'type' => 'group',
                'key' => $groupKey,
                'label_key' => $definition['label_key'],
                'icon' => $definition['icon'],
                'active' => self::containsActive($items),
                'items' => $items,
            ];
        }

        return $navigation;
    }

    /**
     * Aplana Inicio + destinos de cada grupo en el nuevo orden conceptual.
     *
     * @param array<int, array<string, mixed>> $navigation
     * @return array<int, array<string, mixed>>
     */
    public static function flatten(array $navigation): array
    {
        $flat = [];
        foreach ($navigation as $entry) {
            if (($entry['type'] ?? '') === 'link') {
                $flat[] = $entry;
                continue;
            }
            foreach (($entry['items'] ?? []) as $item) {
                if (is_array($item) && ($item['type'] ?? '') === 'link') {
                    $flat[] = $item;
                }
            }
        }
        return $flat;
    }

    /**
     * Adaptador temporal para que N1 pueda alimentar el layout sin alterar aún
     * su presentación plana. N2 lo sustituirá por el render de grupos.
     *
     * @param array<int, array<string, mixed>> $navigation
     * @return array<int, array<string, mixed>>
     */
    public static function flatForCurrentLayout(array $navigation): array
    {
        $byKey = [];
        foreach (self::flatten($navigation) as $item) {
            $byKey[(string) $item['key']] = $item;
        }

        $ordered = [];
        foreach (self::LEGACY_ORDER as $key) {
            if (isset($byKey[$key])) {
                $ordered[] = $byKey[$key];
            }
        }
        return $ordered;
    }

    /** @return array<string, array<string, mixed>> */
    private static function catalog(): array
    {
        return [
            'home' => self::link('home', 'admin/', 'dashboard', 'nav.dashboard', '/admin'),
            'assistant_home' => self::link('assistant_home', 'admin/assistant', 'ai', 'nav.assistant', '/admin/assistant'),
            'knowledge' => self::link('knowledge', 'admin/memory', 'memory', 'nav.knowledge', '/admin/memory'),
            'documents' => self::link('documents', 'admin/documents', 'documents', 'nav.documents', '/admin/documents'),
            'ai_usage' => self::link('ai_usage', 'admin/ai/usage', 'ai', 'nav.ai', '/admin/ai'),
            'pages' => self::link('pages', 'admin/pages', 'pages', 'nav.pages', '/admin/pages'),
            'posts' => self::link('posts', 'admin/posts', 'posts', 'nav.posts', '/admin/posts'),
            'media' => self::link('media', 'admin/media', 'media', 'nav.media', '/admin/media'),
            'forms' => self::link('forms', 'admin/formularios', 'forms', 'nav.forms', '/admin/formularios'),
            'resources' => self::link('resources', 'admin/resources', 'resources', 'nav.resources', '/admin/resources', 'resources'),
            'messages' => self::link('messages', 'admin/forms', 'messages', 'nav.messages', '/admin/forms'),
            'bookings' => self::link('bookings', 'admin/booking', 'booking', 'nav.bookings', '/admin/booking', 'booking'),
            'shop' => self::link('shop', 'admin/commerce', 'commerce', 'nav.shop', '/admin/commerce', 'commerce'),
            'design' => self::link('design', 'admin/design', 'design', 'nav.design', '/admin/design'),
            'chrome' => self::link('chrome', 'admin/chrome', 'chrome', 'nav.chrome', '/admin/chrome'),
            'analytics' => self::link('analytics', 'admin/analytics', 'analytics', 'nav.analytics', '/admin/analytics', 'analytics'),
            'seo' => self::link('seo', 'admin/seo', 'seo', 'nav.seo', '/admin/seo'),
            'marketing' => self::link('marketing', 'admin/marketing', 'marketing', 'nav.marketing', '/admin/marketing'),
            'privacy' => self::link('privacy', 'admin/privacy', 'privacy', 'nav.privacy', '/admin/privacy'),
            'modules' => self::link('modules', 'admin/modules', 'settings', 'nav.modules', '/admin/modules'),
            'settings' => self::link('settings', 'admin/settings', 'settings', 'nav.settings', '/admin/settings'),
        ];
    }

    /** @return array<string, array{label_key:string, icon:string, items:string[]}> */
    private static function groupDefinitions(): array
    {
        return [
            'assistant' => [
                'label_key' => 'nav.group.assistant',
                'icon' => 'ai',
                'items' => ['assistant_home', 'knowledge', 'documents', 'ai_usage'],
            ],
            'content' => [
                'label_key' => 'nav.group.content',
                'icon' => 'pages',
                'items' => ['pages', 'posts', 'media', 'forms', 'resources'],
            ],
            'clients' => [
                'label_key' => 'nav.group.clients',
                'icon' => 'messages',
                'items' => ['messages', 'bookings', 'shop'],
            ],
            'appearance' => [
                'label_key' => 'nav.group.appearance',
                'icon' => 'design',
                'items' => ['design', 'chrome'],
            ],
            'visibility' => [
                'label_key' => 'nav.group.visibility',
                'icon' => 'analytics',
                'items' => ['analytics', 'seo', 'marketing'],
            ],
            'configuration' => [
                'label_key' => 'nav.group.configuration',
                'icon' => 'settings',
                'items' => ['privacy', 'modules', 'settings'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function link(
        string $key,
        string $url,
        string $icon,
        string $labelKey,
        string $match,
        ?string $module = null
    ): array {
        $item = [
            'type' => 'link',
            'key' => $key,
            'url' => $url,
            'icon' => $icon,
            'label_key' => $labelKey,
            'match' => $match,
        ];
        if ($module !== null) {
            $item['module'] = $module;
        }
        return $item;
    }

    /** @param array<string, mixed> $item @return array<string, mixed> */
    private static function prepareLink(array $item, string $path): array
    {
        $item['active'] = self::matchesPath($path, (string) $item['match']);
        return $item;
    }

    /** @param array<int, array<string, mixed>> $items */
    private static function containsActive(array $items): bool
    {
        foreach ($items as $item) {
            if (!empty($item['active'])) {
                return true;
            }
        }
        return false;
    }

    private static function matchesPath(string $path, string $match): bool
    {
        if ($match === '/admin') {
            return $path === '/admin';
        }
        return $path === $match || str_starts_with($path, $match . '/');
    }

    private static function normalizePath(string $path): string
    {
        $parsed = parse_url($path, PHP_URL_PATH);
        $path = is_string($parsed) && $parsed !== '' ? $parsed : $path;
        $path = '/' . ltrim($path, '/');
        $path = rtrim($path, '/');
        return $path === '' ? '/' : $path;
    }

    /**
     * @param array<int|string, bool|string> $modules
     * @return array<string, true>
     */
    private static function normalizeModules(array $modules): array
    {
        $enabled = [];
        foreach ($modules as $key => $value) {
            if (is_int($key)) {
                if (is_string($value) && $value !== '') {
                    $enabled[$value] = true;
                }
                continue;
            }
            if ($value === true || $value === '1') {
                $enabled[(string) $key] = true;
            }
        }
        return $enabled;
    }
}
