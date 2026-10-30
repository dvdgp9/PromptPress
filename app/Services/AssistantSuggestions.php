<?php

declare(strict_types=1);

namespace App\Services;

use App\Modules\ModuleRegistry;
use Core\Database;

/**
 * ASST-V2 F5 — Sugerencias del asistente en Inicio.
 *
 * Salen de lo que el panel ya sabe auditar (SEO técnico, enlaces internos) y
 * de datos del sitio (blog parado, web corta, servicios sin horario, productos
 * agotados). Nada llama a la IA: solo cuando el usuario pulsa, el panel
 * lateral recibe la petición ya escrita.
 *
 * Tres tipos de acción:
 *   send   la petición se envía sola («Arreglar»)
 *   write  se escribe y espera un dato del usuario («pon el horario de…»)
 *   go     sin ejecutor: lleva a su pantalla
 *
 * La caché guarda los HECHOS (no los textos), así cada usuario los lee en su
 * idioma y su rol se aplica al pedirlos.
 */
final class AssistantSuggestions
{
    public const CACHE_KEY = 'assistant_suggestions_cache';
    public const DISMISSED_KEY = 'assistant_suggestions_dismissed';
    private const CACHE_TTL = 600;
    private const DISMISS_DAYS = 30;
    private const SEVERITY = ['high' => 0, 'medium' => 1, 'low' => 2];
    private const BLOG_STALE_DAYS = 45;
    private const FEW_PAGES = 4;
    private const MAX_NAMED = 5;

    /**
     * Lo que se enseña en Inicio a este rol.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function forDashboard(int $siteId, ?string $role, int $limit = 4): array
    {
        $dismissed = self::dismissed($siteId);
        $items = array_values(array_filter(
            self::fromFacts(self::facts($siteId)),
            static fn (array $s): bool => !isset($dismissed[$s['id']])
        ));
        return array_slice(self::visibleFor($items, $role), 0, max(1, $limit));
    }

    /** «Ahora no»: la oculta 30 días. Solo ids que existen ahora. */
    public static function dismiss(int $siteId, string $id): bool
    {
        if (!in_array($id, array_column(self::fromFacts(self::facts($siteId)), 'id'), true)) {
            return false;
        }
        $dismissed = self::dismissed($siteId);
        $dismissed[$id] = time() + self::DISMISS_DAYS * 86400;
        self::store($siteId, self::DISMISSED_KEY, $dismissed);
        return true;
    }

    /**
     * Solo lo que el rol puede hacer: con el asistente y su capacidad, o el
     * permiso de la pantalla a la que lleva.
     *
     * @param array<int,array<string,mixed>> $items
     * @return array<int,array<string,mixed>>
     */
    public static function visibleFor(array $items, ?string $role): array
    {
        return array_values(array_filter($items, static function (array $s) use ($role): bool {
            if ($s['action'] === 'go') {
                return Permissions::roleHas($role, (string) $s['permission']);
            }
            return Permissions::roleHas($role, Permissions::CAP_ASSISTANT)
                && AssistantCapabilityRegistry::roleCanExecute($role, (string) $s['capability']);
        }));
    }

    /**
     * Las reglas, puras: hechos → sugerencias ordenadas por gravedad.
     *
     * @param array<string,mixed> $facts
     * @return array<int,array<string,mixed>>
     */
    public static function fromFacts(array $facts): array
    {
        $out = [];
        $seo = (array) ($facts['seo'] ?? []);
        $pagesWith = static function (string $code, bool $canvasOnly) use ($seo): array {
            $pages = [];
            foreach ($seo as $issue) {
                if (($issue['code'] ?? '') !== $code) continue;
                // Las legales no las toca el asistente (legal.content) y no buscan tráfico.
                if (($issue['page_type'] ?? '') === 'legal') continue;
                if ($canvasOnly && ($issue['render_mode'] ?? '') !== 'canvas') continue;
                $pages[(int) $issue['page_id']] = (string) $issue['page_title'];
            }
            return $pages;
        };

        // Enlaces internos rotos: no hay ejecutor (páginas de secciones), se
        // lleva a su pestaña de SEO (permiso de Visibilidad, como esa pantalla).
        $links = (array) ($facts['links'] ?? []);
        if ($links !== []) {
            $missing = array_values(array_filter($links, static fn (array $l): bool => ($l['problem'] ?? '') === 'missing'));
            $n = count(array_unique(array_map(static fn (array $l): string => $l['page_id'] . $l['link'], $links)));
            $out[] = self::make('links', $missing !== [] ? 'high' : 'medium', 'go', null, $links, [
                'title'  => $missing !== [] ? __('asst.sugg.links_title', ['n' => (string) $n]) : __('asst.sugg.links_draft_title', ['n' => (string) $n]),
                'detail' => __('asst.sugg.links_detail', ['paginas' => self::names(array_column($links, 'page_title', 'page_id'))]),
                'cta'    => __('asst.sugg.cta_go'),
                'url'    => base_url('admin/seo?tab=links'),
                'permission' => Permissions::CAP_VISIBILITY,
                'count'  => $n,
            ]);
        }

        foreach ((array) ($facts['services_without_hours'] ?? []) as $service) {
            $out[] = self::make('booking_hours', 'high', 'write', 'booking.service.edit', [$service['id']], [
                'title'  => __('asst.sugg.hours_title', ['servicio' => (string) $service['name']]),
                'detail' => __('asst.sugg.hours_detail'),
                'prompt' => __('asst.sugg.hours_prompt', ['servicio' => (string) $service['name']]),
                'cta'    => __('asst.sugg.cta_write'),
            ]);
        }

        $meta = $pagesWith('og_description_missing', false);
        if ($meta !== []) {
            $out[] = self::make('seo_meta', 'medium', 'send', 'seo.page.edit', array_keys($meta), [
                'title'  => __(count($meta) === 1 ? 'asst.sugg.meta_title_one' : 'asst.sugg.meta_title', ['n' => (string) count($meta)]),
                'detail' => __('asst.sugg.meta_detail', ['paginas' => self::names($meta)]),
                'prompt' => __('asst.sugg.meta_prompt', ['paginas' => self::names($meta)]),
                'cta'    => __('asst.sugg.cta_fix'),
                'count'  => count($meta),
            ]);
        }

        $h1 = $pagesWith('h1_missing', true) + $pagesWith('h1_multiple', true);
        if ($h1 !== []) {
            $out[] = self::make('seo_h1', 'medium', 'send', 'pages.canvas.edit', array_keys($h1), [
                'title'  => __(count($h1) === 1 ? 'asst.sugg.h1_title_one' : 'asst.sugg.h1_title', ['n' => (string) count($h1)]),
                'detail' => __('asst.sugg.h1_detail', ['paginas' => self::names($h1)]),
                'prompt' => __('asst.sugg.h1_prompt', ['paginas' => self::names($h1)]),
                'cta'    => __('asst.sugg.cta_fix'),
                'count'  => count($h1),
            ]);
        }

        foreach ((array) ($facts['products_out_of_stock'] ?? []) as $product) {
            $out[] = self::make('out_of_stock', 'medium', 'write', 'commerce.product.edit', [$product['id']], [
                'title'  => __('asst.sugg.stock_title', ['producto' => (string) $product['name']]),
                'detail' => __('asst.sugg.stock_detail'),
                'prompt' => __('asst.sugg.stock_prompt', ['producto' => (string) $product['name']]),
                'cta'    => __('asst.sugg.cta_write'),
            ]);
        }

        $alt = $pagesWith('image_alt_missing', true);
        if ($alt !== []) {
            $out[] = self::make('seo_alt', 'low', 'send', 'pages.canvas.edit', array_keys($alt), [
                'title'  => __(count($alt) === 1 ? 'asst.sugg.alt_title_one' : 'asst.sugg.alt_title', ['n' => (string) count($alt)]),
                'detail' => __('asst.sugg.alt_detail', ['paginas' => self::names($alt)]),
                'prompt' => __('asst.sugg.alt_prompt', ['paginas' => self::names($alt)]),
                'cta'    => __('asst.sugg.cta_fix'),
                'count'  => count($alt),
            ]);
        }

        $posts = (array) ($facts['posts'] ?? []);
        if ((int) ($posts['count'] ?? 0) > 0 && ($posts['last_days'] ?? null) !== null && (int) $posts['last_days'] > self::BLOG_STALE_DAYS) {
            $out[] = self::make('blog_ideas', 'low', 'send', 'posts.ideas', [(int) ($posts['count'] ?? 0)], [
                'title'  => __('asst.sugg.blog_title', ['dias' => (string) (int) $posts['last_days']]),
                'detail' => __('asst.sugg.blog_detail'),
                'prompt' => __('asst.sugg.blog_prompt'),
                'cta'    => __('asst.sugg.cta_ideas'),
            ]);
        }

        if (isset($facts['pages']['published']) && (int) $facts['pages']['published'] < self::FEW_PAGES) {
            $out[] = self::make('page_ideas', 'low', 'send', 'pages.ideas', [(int) $facts['pages']['published']], [
                'title'  => __('asst.sugg.pages_title'),
                'detail' => __('asst.sugg.pages_detail', ['n' => (string) (int) $facts['pages']['published']]),
                'prompt' => __('asst.sugg.pages_prompt'),
                'cta'    => __('asst.sugg.cta_ideas'),
            ]);
        }

        // Estable: gravedad y, dentro, el orden de las reglas de arriba.
        $order = array_flip(array_keys($out));
        uksort($out, static function (int $a, int $b) use ($out, $order): int {
            return [self::SEVERITY[$out[$a]['severity']], $order[$a]] <=> [self::SEVERITY[$out[$b]['severity']], $order[$b]];
        });
        return array_values($out);
    }

    /**
     * Lo que se sabe del sitio ahora (con caché de 10 minutos).
     *
     * @return array<string,mixed>
     */
    public static function facts(int $siteId): array
    {
        $row = Database::selectOne(
            'SELECT setting_value FROM settings WHERE site_id = ? AND setting_key = ? LIMIT 1',
            [$siteId, self::CACHE_KEY]
        );
        $cached = json_decode((string) ($row['setting_value'] ?? ''), true);
        if (is_array($cached) && is_array($cached['facts'] ?? null) && (int) ($cached['at'] ?? 0) > time() - self::CACHE_TTL) {
            return $cached['facts'];
        }

        $started = microtime(true);
        $facts = [
            'seo' => self::safe(static fn (): array => array_map(
                static fn (array $i): array => array_intersect_key($i, array_flip(['page_id', 'page_title', 'page_type', 'render_mode', 'code'])),
                SeoTechnicalAuditService::audit($siteId, 60)
            )),
            'links' => self::safe(static fn (): array => array_map(
                static fn (array $i): array => array_intersect_key($i, array_flip(['page_id', 'page_title', 'link', 'problem'])),
                LinkAuditService::audit($siteId)
            )),
            'posts' => self::safe(static function () use ($siteId): array {
                $row = Database::selectOne(
                    "SELECT COUNT(*) AS n, DATEDIFF(NOW(), MAX(CASE WHEN status = 'published' THEN COALESCE(published_at, updated_at) END)) AS days
                     FROM pages WHERE site_id = ? AND page_type = 'article'",
                    [$siteId]
                );
                return ['count' => (int) ($row['n'] ?? 0), 'last_days' => $row['days'] !== null ? (int) $row['days'] : null];
            }),
            'pages' => self::safe(static fn (): array => ['published' => (int) (Database::selectOne(
                "SELECT COUNT(*) AS n FROM pages WHERE site_id = ? AND status = 'published'
                   AND page_type NOT IN ('article', 'legal') AND slug NOT LIKE '\\_\\_%'",
                [$siteId]
            )['n'] ?? 0)]),
            'services_without_hours' => self::safe(static fn (): array => ModuleRegistry::isEnabled($siteId, 'booking')
                ? array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name']], Database::select(
                    'SELECT s.id, s.name FROM booking_services s
                     WHERE s.site_id = ? AND s.active = 1
                       AND NOT EXISTS (SELECT 1 FROM booking_hours h WHERE h.service_id = s.id AND h.weekday IS NOT NULL)
                     ORDER BY s.name LIMIT 3',
                    [$siteId]
                )) : []),
            'products_out_of_stock' => self::safe(static fn (): array => ModuleRegistry::isEnabled($siteId, 'commerce')
                ? array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name']], Database::select(
                    "SELECT id, name FROM commerce_products
                     WHERE site_id = ? AND active = 1 AND product_type = 'physical' AND stock = 0
                     ORDER BY name LIMIT 3",
                    [$siteId]
                )) : []),
        ];
        error_log(sprintf('[assistant suggestions] site=%d facts ms=%d seo=%d links=%d', $siteId,
            (int) ((microtime(true) - $started) * 1000), count($facts['seo']), count($facts['links'])));
        self::store($siteId, self::CACHE_KEY, ['at' => time(), 'facts' => $facts]);
        return $facts;
    }

    // ------------------------------------------------------------------

    /**
     * @param array<int|string,mixed> $targets lo afectado: si cambia, el id cambia y vuelve a salir
     * @param array<string,mixed> $fields
     * @return array<string,mixed>
     */
    private static function make(string $rule, string $severity, string $action, ?string $capability, array $targets, array $fields): array
    {
        return array_merge([
            'id'         => $rule . ':' . substr(sha1((string) json_encode(array_values($targets))), 0, 10),
            'rule'       => $rule,
            'severity'   => $severity,
            'action'     => $action,
            'send'       => $action === 'send',
            'capability' => $capability,
            'permission' => null,
            'url'        => null,
            'prompt'     => '',
            'count'      => 1,
        ], $fields);
    }

    /** «A», «B» y 3 más. @param array<int,string> $titles */
    private static function names(array $titles): string
    {
        $titles = array_values(array_unique($titles));
        $shown = array_map(static fn (string $t): string => '«' . $t . '»', array_slice($titles, 0, self::MAX_NAMED));
        $rest = count($titles) - count($shown);
        return implode(', ', $shown) . ($rest > 0 ? ' ' . __('asst.sugg.and_more', ['n' => (string) $rest]) : '');
    }

    /** Una fuente que falla no tumba las demás. @param callable():array $read */
    private static function safe(callable $read): array
    {
        try {
            return $read();
        } catch (\Throwable $e) {
            error_log('[assistant suggestions] ' . get_class($e) . ': ' . $e->getMessage());
            return [];
        }
    }

    /** @return array<string,int> id => hasta (solo los vigentes) */
    private static function dismissed(int $siteId): array
    {
        $row = Database::selectOne(
            'SELECT setting_value FROM settings WHERE site_id = ? AND setting_key = ? LIMIT 1',
            [$siteId, self::DISMISSED_KEY]
        );
        $data = json_decode((string) ($row['setting_value'] ?? ''), true);
        return is_array($data) ? array_filter($data, static fn ($until): bool => (int) $until > time()) : [];
    }

    private static function store(int $siteId, string $key, array $value): void
    {
        Database::execute(
            'INSERT INTO settings (site_id, setting_key, setting_value, is_encrypted) VALUES (?, ?, ?, 0)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), is_encrypted = 0',
            [$siteId, $key, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]
        );
    }
}
