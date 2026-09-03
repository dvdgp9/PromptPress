<?php

declare(strict_types=1);

namespace App\Services\Canvas;

use App\Services\CacheService;
use App\Services\FormPlacementStore;
use App\Services\FormStore;
use App\Services\FormTemplates;
use App\Services\LanguageService;
use App\Services\Renderer\SectionRenderer;
use Core\Database;

/**
 * FH1 — Persistencia y render de páginas "canvas" (HTML libre).
 *
 * Reglas:
 *  - TODO guardado pasa por CanvasSanitizer y crea una versión restaurable.
 *  - El CSS se guarda saneado pero SIN scope; el scope (#pp-canvas-{id}) se
 *    aplica en render, así las versiones son portables.
 *  - Los componentes funcionales se insertan con placeholders:
 *      {{form:123}} / {{form:slug-de-pagina}}  → formulario real (leads+GDPR)
 *      {{posts:recent}}                        → listado de entradas
 *      {{posts:recent|limit=3|variant=featured-first|heading=Últimas entradas}}
 *      {{products:featured}}                   → productos destacados (C7;
 *      {{products:featured|limit=4|heading=Nuestros productos}}  solo con el
 *      módulo Commerce activo; apagado → comentario HTML invisible)
 */
final class CanvasService
{
    private const MAX_VERSIONS = 25;

    /** F4 — Solo se agrupan los retoques manuales; la IA y la estructura no. */
    private const COALESCE_ORIGIN = 'inline';

    /** F4 — Ventana desde que empieza la versión en curso (segundos). */
    private const COALESCE_SECONDS = 60;

    /** @return array{html:string,css:string}|null */
    public static function get(int $pageId): ?array
    {
        $row = Database::selectOne('SELECT html, css FROM page_canvas WHERE page_id = ?', [$pageId]);
        return $row ? ['html' => (string) $row['html'], 'css' => (string) $row['css']] : null;
    }

    /**
     * Sanea, versiona y guarda. Devuelve el resultado del sanitizado.
     *
     * @return array{html:string,css:string,warnings:array<int,string>}
     */
    public static function save(int $pageId, string $html, string $css, string $origin = 'edit', string $summary = ''): array
    {
        // Placeholders a forma canónica ({{form:x}} sin espacios) para que la
        // detección por LIKE del endpoint de formularios sea fiable.
        $html = self::canonicalizePlaceholders($html);
        $html = self::materializeFormIntents($pageId, $html);

        $clean = CanvasSanitizer::sanitizeHtml($html);
        // El CSS embebido en <style> del HTML se suma al canal CSS. Se guarda
        // limpio pero SIN scope: el scope se aplica en render.
        $cleanCss = CanvasSanitizer::cleanCss(trim($css . "\n" . $clean['css']));

        // FH6 — un cambio nuevo TRUNCA la rama de "rehacer": si el puntero no
        // está en la última versión (el usuario deshizo y ahora edita), las
        // versiones posteriores al puntero dejan de tener sentido.
        $pointer = self::currentVersionId($pageId);
        if ($pointer > 0) {
            Database::execute('DELETE FROM page_versions WHERE page_id = ? AND id > ?', [$pageId, $pointer]);
        }

        $origin = mb_substr($origin, 0, 20);
        $summary = mb_substr($summary, 0, 255);

        // STUDIO-UX F4 — Los retoques seguidos de una misma sección actualizan
        // la versión en curso en vez de abrir otra. Sin esto, cinco clics en
        // "A+" eran cinco versiones: el historial (25) se llenaba de píxeles y
        // "Deshacer" dejaba de significar "deshaz lo que acabo de hacer".
        $mergeId = self::coalescableVersionId($pageId, $origin, $summary, $pointer);
        if ($mergeId > 0) {
            Database::execute(
                'UPDATE page_versions SET html = ?, css = ? WHERE id = ?',
                [$clean['html'], $cleanCss, $mergeId]
            );
            $newVersionId = $mergeId;
        } else {
            Database::execute(
                'INSERT INTO page_versions (page_id, html, css, origin, summary) VALUES (?, ?, ?, ?, ?)',
                [$pageId, $clean['html'], $cleanCss, $origin, $summary]
            );
            $newVersionId = (int) Database::lastInsertId();
        }

        Database::execute(
            'INSERT INTO page_canvas (page_id, html, css, current_version_id) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE html = VALUES(html), css = VALUES(css), current_version_id = VALUES(current_version_id)',
            [$pageId, $clean['html'], $cleanCss, $newVersionId]
        );
        FormPlacementStore::syncPage($pageId, $clean['html']);

        self::pruneVersions($pageId);
        self::invalidateCache($pageId);

        return ['html' => $clean['html'], 'css' => $cleanCss, 'warnings' => $clean['warnings']];
    }

    /**
     * FORMS-R T4 — convierte {{form:contact}} (y demas tipos del catalogo)
     * en un ID real. Reutiliza por form_type para no duplicar definiciones.
     */
    private static function materializeFormIntents(int $pageId, string $html): string
    {
        $page = Database::selectOne('SELECT site_id FROM pages WHERE id = ? LIMIT 1', [$pageId]);
        $siteId = (int) ($page['site_id'] ?? 0);
        if ($siteId <= 0) return $html;

        $types = array_fill_keys(FormTemplates::keys(), true);
        return preg_replace_callback(
            '/\{\{form:([a-z][a-z0-9_-]*)\}\}/i',
            static function (array $match) use ($siteId, $types): string {
                $type = strtolower((string) $match[1]);
                if (!isset($types[$type])) return (string) $match[0];
                return '{{form:' . FormStore::findOrCreateByType($siteId, $type) . '}}';
            },
            $html
        ) ?? $html;
    }

    /**
     * STUDIO-UX F4 — ¿Hay una versión "en curso" en la que quepa este cambio?
     *
     * Solo se fusionan los retoques manuales (`inline`) de la MISMA sección
     * hechos dentro de la ventana: un cambio de IA, uno de estructura o el de
     * otra sección son pasos distintos y merecen su versión. La condición clave
     * es que el puntero esté en la última versión: si el usuario ha deshecho, la
     * rama se trunca y el cambio abre versión nueva en vez de reescribir una
     * versión que ya no es "la actual".
     */
    private static function coalescableVersionId(int $pageId, string $origin, string $summary, int $pointer): int
    {
        if ($origin !== self::COALESCE_ORIGIN || $pointer <= 0) return 0;

        // La ventana se evalúa DENTRO de SQL a propósito: comparar `created_at`
        // (reloj de MySQL) con `time()` (reloj de PHP) daba diferencias negativas
        // en cuanto las dos zonas horarias no coincidían, y una comparación de un
        // solo lado dejaba fusionar cambios de horas después sobre una versión
        // vieja. Con un único reloj el sesgo desaparece.
        $window = (int) self::COALESCE_SECONDS;
        $last = Database::selectOne(
            'SELECT id, origin, summary,
                    (created_at > DATE_SUB(NOW(), INTERVAL ' . $window . ' SECOND)) AS is_fresh
             FROM page_versions WHERE page_id = ? ORDER BY id DESC LIMIT 1',
            [$pageId]
        );
        if (!$last || (int) $last['id'] !== $pointer) return 0;
        if ((string) $last['origin'] !== $origin || (string) $last['summary'] !== $summary) return 0;
        if ((int) $last['is_fresh'] !== 1) return 0;

        return $pointer;
    }

    /**
     * STUDIO-UX C4 — Contenido de la versión publicada, o null si la página no
     * tiene ninguna fijada (borrador, o anterior a la migración).
     *
     * @return array{html:string,css:string}|null
     */
    private static function publishedSource(int $pageId): ?array
    {
        $row = Database::selectOne(
            'SELECT v.html, v.css
             FROM page_canvas c
             JOIN page_versions v ON v.id = c.published_version_id AND v.page_id = c.page_id
             WHERE c.page_id = ?',
            [$pageId]
        );
        return $row ? ['html' => (string) $row['html'], 'css' => (string) $row['css']] : null;
    }

    /** Versión que está al aire ahora mismo (0 si no hay). */
    public static function publishedVersionId(int $pageId): int
    {
        $row = Database::selectOne('SELECT published_version_id FROM page_canvas WHERE page_id = ?', [$pageId]);
        return (int) ($row['published_version_id'] ?? 0);
    }

    /** Deja al aire el estado de trabajo actual. */
    public static function markPublished(int $pageId): void
    {
        Database::execute(
            'UPDATE page_canvas SET published_version_id = current_version_id WHERE page_id = ?',
            [$pageId]
        );
        self::invalidateCache($pageId);
    }

    /** Retira la página del aire (despublicar). */
    public static function clearPublished(int $pageId): void
    {
        Database::execute('UPDATE page_canvas SET published_version_id = NULL WHERE page_id = ?', [$pageId]);
        self::invalidateCache($pageId);
    }

    /**
     * ¿El estado de trabajo se ha movido respecto a lo que ve el público?
     * Sin versión publicada no hay nada "pendiente": la página no está al aire.
     */
    public static function hasUnpublishedChanges(int $pageId): bool
    {
        $published = self::publishedVersionId($pageId);
        if ($published <= 0) return false;
        return self::currentVersionId($pageId) !== $published;
    }

    /** Puntero de versión actual (o la última si el puntero está sin fijar). */
    private static function currentVersionId(int $pageId): int
    {
        $row = Database::selectOne('SELECT current_version_id FROM page_canvas WHERE page_id = ?', [$pageId]);
        $pointer = (int) ($row['current_version_id'] ?? 0);
        if ($pointer > 0) return $pointer;
        $last = Database::selectOne('SELECT MAX(id) AS id FROM page_versions WHERE page_id = ?', [$pageId]);
        return (int) ($last['id'] ?? 0);
    }

    /** @return array<int,array<string,mixed>> */
    public static function versions(int $pageId, int $limit = 25): array
    {
        $current = self::currentVersionId($pageId);
        $rows = Database::select(
            'SELECT id, origin, summary, created_at, CHAR_LENGTH(html) AS html_size
             FROM page_versions WHERE page_id = ? ORDER BY id DESC LIMIT ' . max(1, min(100, $limit)),
            [$pageId]
        );
        foreach ($rows as &$r) {
            $r['is_current'] = ((int) $r['id'] === $current);
        }
        return $rows;
    }

    /**
     * FH6 — Estado de deshacer/rehacer: si hay versión anterior/posterior al
     * puntero actual.
     *
     * @return array{can_undo:bool,can_redo:bool,current_version_id:int}
     */
    public static function historyState(int $pageId): array
    {
        $current = self::currentVersionId($pageId);
        $prev = Database::selectOne('SELECT MAX(id) AS id FROM page_versions WHERE page_id = ? AND id < ?', [$pageId, $current]);
        $next = Database::selectOne('SELECT MIN(id) AS id FROM page_versions WHERE page_id = ? AND id > ?', [$pageId, $current]);
        return [
            'can_undo' => (int) ($prev['id'] ?? 0) > 0,
            'can_redo' => (int) ($next['id'] ?? 0) > 0,
            'current_version_id' => $current,
            // C4 — la barra del Studio necesita saber si hay algo por publicar.
            'has_unpublished' => self::hasUnpublishedChanges($pageId),
        ];
    }

    /** Mueve el puntero a la versión anterior (deshacer). Devuelve estado o null. */
    public static function undo(int $pageId): ?array
    {
        $current = self::currentVersionId($pageId);
        $prev = Database::selectOne('SELECT MAX(id) AS id FROM page_versions WHERE page_id = ? AND id < ?', [$pageId, $current]);
        $target = (int) ($prev['id'] ?? 0);
        return $target > 0 ? self::moveToVersion($pageId, $target) : null;
    }

    /** Mueve el puntero a la versión posterior (rehacer). Devuelve estado o null. */
    public static function redo(int $pageId): ?array
    {
        $current = self::currentVersionId($pageId);
        $next = Database::selectOne('SELECT MIN(id) AS id FROM page_versions WHERE page_id = ? AND id > ?', [$pageId, $current]);
        $target = (int) ($next['id'] ?? 0);
        return $target > 0 ? self::moveToVersion($pageId, $target) : null;
    }

    /**
     * Restaura/posiciona en una versión concreta SIN crear versión nueva: solo
     * mueve el puntero y aplica su contenido. Un cambio posterior truncará el
     * redo. Devuelve el nuevo estado de historial o null si no existe.
     */
    public static function restore(int $pageId, int $versionId): ?array
    {
        $exists = Database::selectOne('SELECT id FROM page_versions WHERE id = ? AND page_id = ?', [$versionId, $pageId]);
        return $exists ? self::moveToVersion($pageId, $versionId) : null;
    }

    /** Aplica el contenido de una versión a page_canvas y fija el puntero. */
    private static function moveToVersion(int $pageId, int $versionId): array
    {
        $version = Database::selectOne(
            'SELECT html, css FROM page_versions WHERE id = ? AND page_id = ?',
            [$versionId, $pageId]
        );
        if ($version) {
            Database::execute(
                'UPDATE page_canvas SET html = ?, css = ?, current_version_id = ? WHERE page_id = ?',
                [(string) $version['html'], (string) $version['css'], $versionId, $pageId]
            );
            FormPlacementStore::syncPage($pageId, (string) $version['html']);
            self::invalidateCache($pageId);
        }
        return self::historyState($pageId);
    }

    /**
     * HTML público de la página canvas: placeholders expandidos, CSS scoped.
     *
     * FORMS-LANG T1 — el idioma de render es el de la PÁGINA, no el del sitio.
     * Antes esto llamaba a `setSiteContext($siteId)` a secas y pisaba el idioma
     * que el controlador público ya había fijado: en una web bilingüe, todo lo
     * que pinta el canvas (empezando por los formularios) salía en el idioma
     * principal. Si no se pasa, se deduce de la propia fila de `pages`.
     *
     * @return array{html:string,has_form:bool,has_resources:bool}
     */
    public static function renderPublic(int $pageId, int $siteId, ?string $lang = null): array
    {
        // STUDIO-UX C4 — el público lee la versión PUBLICADA. Sin puntero
        // (borradores, o instalaciones anteriores a la migración) se cae al
        // estado de trabajo, que es lo que hacía siempre hasta ahora.
        return self::renderCanvas($pageId, $siteId, $lang, self::publishedSource($pageId) ?? self::get($pageId));
    }

    /**
     * STUDIO-UX C4 — Render del ESTADO DE TRABAJO: lo que el Studio edita y lo
     * que enseñan las previsualizaciones del panel. No es lo que ve el público
     * mientras haya cambios sin publicar.
     *
     * @return array{html:string,has_form:bool,has_resources:bool}
     */
    public static function renderDraft(int $pageId, int $siteId, ?string $lang = null): array
    {
        return self::renderCanvas($pageId, $siteId, $lang, self::get($pageId));
    }

    /** @param array{html:string,css:string}|null $canvas */
    private static function renderCanvas(int $pageId, int $siteId, ?string $lang, ?array $canvas): array
    {
        if ($canvas === null) {
            return ['html' => '<!-- canvas empty -->', 'has_form' => false, 'has_resources' => false];
        }

        $lang = $lang ?? self::pageLanguage($pageId, $siteId);
        $hasForm = false;
        $hasResources = false;
        $html = self::expandPlaceholders($canvas['html'], $siteId, $hasForm, $lang, $hasResources);

        $scope = '#pp-canvas-' . $pageId;
        $css = $canvas['css'] !== '' ? CanvasSanitizer::sanitizeCss($canvas['css'], $scope) : '';

        $out = '<div id="pp-canvas-' . $pageId . '" class="pp-canvas">' . $html . '</div>';
        if ($css !== '') {
            $out .= '<style>' . $css . '</style>';
        }
        return ['html' => $out, 'has_form' => $hasForm, 'has_resources' => $hasResources];
    }

    /**
     * {{form:...}}, {{posts:recent}} y {{products:featured}} → componentes
     * reales del sistema.
     */
    public static function expandPlaceholders(string $html, int $siteId, bool &$hasForm = false, ?string $lang = null, bool &$hasResources = false): string
    {
        SectionRenderer::setSiteContext($siteId, $lang);
        // Idioma efectivo de la página: lo necesita el calendario para hablarle
        // al visitante en el idioma que está leyendo.
        $pageLang = \App\Services\LanguageService::normalize($lang ?? \App\Services\LanguageService::codeFor($siteId));

        $result = preg_replace_callback(
            '/\{\{\s*(form|posts|products|booking|resources)\s*:\s*([a-z0-9\-_\/]+)((?:\s*\|\s*[a-z0-9_-]+\s*=\s*[^|}]+)*)\s*\}\}/iu',
            static function (array $m) use ($siteId, &$hasForm, &$hasResources, $pageLang): string {
                $kind = strtolower($m[1]);
                $ref = strtolower($m[2]);
                $optionsRaw = (string) ($m[3] ?? '');
                $placeholderRef = self::canonicalPlaceholderRef($kind, $ref, $optionsRaw);

                // FH4 — el embed se envuelve con un marcador para que el editor
                // inline pueda revertirlo a su placeholder al guardar la sección.
                $wrap = static fn(string $html): string =>
                    '<div class="pp-canvas-embed" data-pp-placeholder="' . htmlspecialchars($placeholderRef, ENT_QUOTES, 'UTF-8') . '">' . $html . '</div>';

                if ($kind === 'resources') {
                    $content = \App\Modules\Resources\FeaturedResourcesRenderer::render(
                        $siteId,
                        $pageLang,
                        self::parsePlaceholderOptions($optionsRaw)
                    );
                    if ($content === '') return '<!-- pp:resources (módulo apagado o sin publicaciones) -->';
                    $hasResources = true;
                    return $wrap($content);
                }

                // C7 — productos destacados; solo con el módulo Commerce activo.
                if ($kind === 'products') {
                    if (!\App\Modules\ModuleRegistry::isEnabled($siteId, 'commerce')) {
                        return '<!-- pp:products (módulo tienda desactivado) -->';
                    }
                    $content = \App\Modules\Commerce\FeaturedProductsRenderer::render(
                        $siteId,
                        self::parsePlaceholderOptions($optionsRaw)
                    );
                    return $content === ''
                        ? '<!-- pp:products (sin productos activos) -->'
                        : $wrap($content);
                }

                // MODULOS M2 — calendario de reservas; solo con el módulo activo.
                if ($kind === 'booking') {
                    if (!\App\Modules\ModuleRegistry::isEnabled($siteId, 'booking')) {
                        return '<!-- pp:booking (módulo de reservas desactivado) -->';
                    }
                    $opts = self::parsePlaceholderOptions($optionsRaw);
                    // `{{booking:auto}}` (o cualquier ref no numérica) = el primer
                    // servicio activo: así la IA puede insertarlo sin conocer ids.
                    $content = \App\Modules\Booking\BookingEmbedRenderer::render($siteId, [
                        'service_id' => ctype_digit($ref) ? (int) $ref : 0,
                        'days'       => $opts['days'] ?? null,
                        'lang'       => $pageLang,
                    ]);
                    if ($content !== '') {
                        return $wrap($content);
                    }
                    // Distinguir los dos motivos ayuda a entender una página que
                    // "ha perdido" el calendario: puede que no haya servicios, o
                    // que el que se eligió esté desactivado o borrado.
                    return \App\Modules\Booking\BookingEmbedRenderer::embeddableServices($siteId) === []
                        ? '<!-- pp:booking (sin servicios reservables activos) -->'
                        : '<!-- pp:booking "' . htmlspecialchars($ref, ENT_QUOTES, 'UTF-8') . '" no disponible -->';
                }

                if ($kind === 'posts') {
                    $content = self::postsPlaceholderContent($optionsRaw);
                    return $wrap(SectionRenderer::render([
                        'id' => 0,
                        'section_type' => 'posts_listing',
                        'content' => json_encode($content['content'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'style' => json_encode(['variant' => $content['variant']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ]));
                }

                $section = self::findFormSection($siteId, $ref);
                if ($section === null) {
                    return '<!-- pp:form "' . htmlspecialchars($ref, ENT_QUOTES, 'UTF-8') . '" no encontrado -->';
                }
                $hasForm = true;
                return $wrap(SectionRenderer::render($section));
            },
            $html
        );
        $expanded = $result ?? $html;
        return $hasResources ? self::collapseRedundantResourceHeadings($expanded) : $expanded;
    }

    /**
     * Si una sección editorial ya tiene su propio h1-h6, el heading genérico
     * del grid ("Recursos") repite la misma información y rompe la jerarquía.
     * Se quita solo en ese contexto. Un bloque independiente conserva su h2;
     * el `<section aria-label>` del renderer mantiene el nombre accesible.
     */
    private static function collapseRedundantResourceHeadings(string $html): string
    {
        if (!str_contains($html, 'pp-featured-resources__head')) return $html;

        [$doc, $root] = self::domFromHtml($html);
        if (!$root) return $html;

        $embeds = [];
        foreach ($root->getElementsByTagName('*') as $element) {
            if (!$element instanceof \DOMElement) continue;
            $placeholder = strtolower(trim($element->getAttribute('data-pp-placeholder')));
            if (str_starts_with($placeholder, 'resources:')) $embeds[] = $element;
        }

        $changed = false;
        foreach ($embeds as $embed) {
            $section = $embed->parentNode;
            while ($section instanceof \DOMElement && $section !== $root && !$section->hasAttribute('data-pp-section')) {
                $section = $section->parentNode;
            }
            if (!$section instanceof \DOMElement || !$section->hasAttribute('data-pp-section')) continue;

            $hasContextHeading = false;
            foreach (['h1', 'h2', 'h3', 'h4', 'h5', 'h6'] as $tag) {
                foreach ($section->getElementsByTagName($tag) as $heading) {
                    if (!self::nodeIsInside($heading, $embed)) {
                        $hasContextHeading = true;
                        break 2;
                    }
                }
            }
            if (!$hasContextHeading) continue;

            $headers = [];
            foreach ($embed->getElementsByTagName('header') as $header) {
                if (!$header instanceof \DOMElement) continue;
                $classes = ' ' . preg_replace('/\s+/', ' ', trim($header->getAttribute('class'))) . ' ';
                if (str_contains($classes, ' pp-featured-resources__head ')) $headers[] = $header;
            }
            foreach ($headers as $header) {
                $header->parentNode?->removeChild($header);
                $changed = true;
            }
        }

        if (!$changed) return $html;
        $out = '';
        foreach ($root->childNodes as $child) $out .= $doc->saveHTML($child);
        return trim($out);
    }

    private static function nodeIsInside(\DOMNode $node, \DOMNode $ancestor): bool
    {
        for ($cursor = $node; $cursor !== null; $cursor = $cursor->parentNode) {
            if ($cursor === $ancestor) return true;
        }
        return false;
    }

    private static function canonicalizePlaceholders(string $html): string
    {
        return preg_replace_callback(
            '/\{\{\s*(form|posts|products|booking|resources)\s*:\s*([a-z0-9\-_\/]+)((?:\s*\|\s*[a-z0-9_-]+\s*=\s*[^|}]+)*)\s*\}\}/iu',
            static fn(array $m): string => '{{' . self::canonicalPlaceholderRef(
                strtolower($m[1]),
                strtolower($m[2]),
                (string) ($m[3] ?? '')
            ) . '}}',
            $html
        ) ?? $html;
    }

    private static function canonicalPlaceholderRef(string $kind, string $ref, string $optionsRaw = ''): string
    {
        if ($kind === 'form') {
            return $kind . ':' . $ref;
        }

        // posts, products y booking llevan opciones canónicas ordenadas.
        $optionKeys = match ($kind) {
            'posts'   => ['limit', 'variant', 'heading', 'subheading'],
            'booking' => ['days'],
            default   => ['limit', 'heading'],
        };
        $opts = self::parsePlaceholderOptions($optionsRaw);
        $parts = [];
        foreach ($optionKeys as $key) {
            if (isset($opts[$key]) && $opts[$key] !== '') {
                $parts[] = $key . '=' . $opts[$key];
            }
        }

        return $kind . ':' . $ref . ($parts !== [] ? '|' . implode('|', $parts) : '');
    }

    /**
     * @return array{content:array<string,string>,variant:string}
     */
    private static function postsPlaceholderContent(string $optionsRaw): array
    {
        $opts = self::parsePlaceholderOptions($optionsRaw);
        $limit = (int) ($opts['limit'] ?? 3);
        $limit = max(1, min(20, $limit));

        $variant = (string) ($opts['variant'] ?? 'default');
        if (!in_array($variant, ['default', 'editorial-list', 'featured-first'], true)) {
            $variant = 'default';
        }

        return [
            'content' => [
                'heading' => mb_substr((string) ($opts['heading'] ?? ''), 0, 120),
                'subheading' => mb_substr((string) ($opts['subheading'] ?? ''), 0, 240),
                'limit' => (string) $limit,
            ],
            'variant' => $variant,
        ];
    }

    /**
     * @return array<string,string>
     */
    /**
     * C7 — Pista para los prompts canvas ({modules_hint}) sobre los bloques
     * de módulos disponibles en ESTE sitio. Con un módulo apagado devuelve una
     * negativa explícita para que la IA no invente su placeholder.
     *
     * MODULOS M2 — aquí entra también Reservas: es lo que permite que el gestor
     * escriba "pon un calendario para pedir cita" en el chat del studio y la IA
     * inserte el placeholder real en vez de dibujar un calendario de mentira.
     */
    public static function modulesHint(int $siteId): string
    {
        $parts = [];

        if (\App\Modules\ModuleRegistry::isEnabled($siteId, 'commerce')) {
            // i18n-ignore: contexto de tienda que viaja al prompt.
            $parts[] = "Este sitio tiene TIENDA ONLINE activa. Si la página pide enseñar productos (o encaja de forma natural), inserta el placeholder `{{products:featured}}` (opciones: `{{products:featured|limit=4|heading=Nuestros productos}}`): el sistema lo sustituye por una cuadrícula real de productos con foto, precio y enlace a la tienda. NUNCA dibujes tarjetas de producto a mano ni inventes productos o precios. La tienda vive en /tienda (puedes enlazar CTAs a esa ruta).";
        } else {
            // i18n-ignore: relleno del prompt.
            $parts[] = '(este sitio no tiene tienda online: NO uses placeholders {{products:...}})';
        }

        $services = \App\Modules\ModuleRegistry::isEnabled($siteId, 'booking')
            ? \App\Modules\Booking\BookingEmbedRenderer::embeddableServices($siteId)
            : [];
        if ($services !== []) {
            // La ficha de cada servicio viaja ENTERA (duración y precio incluidos):
            // sin estos datos el modelo escribía el texto de alrededor a ojo y
            // acababa anunciando "Duración 15 minutos" al lado de un calendario
            // que daba huecos de 60. Los datos reales son estos y no otros.
            $list = implode('; ', array_map(
                static function (array $s): string {
                    $ficha = $s['id'] . ' = "' . $s['name'] . '" (' . $s['duration_min'] . ' min';
                    if (trim($s['price_label']) !== '') {
                        $ficha .= ', ' . $s['price_label'];
                    }
                    return $ficha . ')';
                },
                array_slice($services, 0, 8)
            ));
            // i18n-ignore-start: contexto de reservas que viaja al prompt, no al panel.
            $parts[] = "Este sitio tiene RESERVAS DE CITAS activas. Si la página pide pedir cita, reservar o ver disponibilidad, inserta el placeholder `{{booking:auto}}`: el sistema lo sustituye por el calendario real con los huecos libres y el formulario de reserva. Para un servicio concreto usa su id; `auto` coge el primero activo. Opción: `{{booking:auto|days=7}}` para acortar la agenda visible. NUNCA dibujes un calendario, unas horas o un formulario de reserva a mano: no funcionarían.\n"
                . "SERVICIOS REALES (id = nombre (duración, precio)): {$list}.\n"
                . "El calendario YA muestra el nombre, la duración y el precio de cada servicio: no hace falta repetirlos en el texto de alrededor. Si aun así los mencionas, usa EXACTAMENTE estos valores. NUNCA inventes una duración, un precio, unos horarios ni un número de plazas: si no está en esta lista, no lo escribas.";
            // i18n-ignore-end
        } else {
            // i18n-ignore: relleno del prompt.
            $parts[] = '(este sitio no tiene reservas de citas: NO uses placeholders {{booking:...}})';
        }

        $resourcesAvailable = \App\Modules\ModuleRegistry::isEnabled($siteId, 'resources')
            && \App\Modules\Resources\ResourceStore::hasPublished($siteId);
        if ($resourcesAvailable) {
            // i18n-ignore: contexto funcional para el prompt de Canvas.
            $parts[] = "Este sitio tiene RECURSOS DESCARGABLES publicados. Para mostrarlos usa `{{resources:featured}}` (opciones: `{{resources:featured|limit=3|heading=Recursos destacados}}`). El sistema inserta únicamente las fichas disponibles en el idioma de la página y sus enlaces. NUNCA inventes ebooks, guías, archivos ni tarjetas de descarga a mano.";
        } else {
            // i18n-ignore: negativa explícita para evitar bloques ficticios.
            $parts[] = '(este sitio no tiene recursos descargables publicados: NO uses placeholders {{resources:...}})';
        }

        return implode("\n", $parts);
    }

    private static function parsePlaceholderOptions(string $raw): array
    {
        $out = [];
        if ($raw === '') return $out;

        foreach (preg_split('/\|/u', $raw) ?: [] as $part) {
            $part = trim($part);
            if ($part === '' || !str_contains($part, '=')) continue;
            [$key, $value] = array_map('trim', explode('=', $part, 2));
            $key = strtolower($key);
            if (!in_array($key, ['limit', 'variant', 'heading', 'subheading', 'days'], true)) continue;
            $value = trim(strip_tags($value));
            $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '';
            if ($value === '') continue;
            $out[$key] = $value;
        }

        return $out;
    }

    /**
     * Resuelve un formulario por id de sección o por slug de página (primera
     * sección `form` de esa página). Solo secciones de páginas del sitio.
     *
     * @return array<string,mixed>|null fila de page_sections
     */
    private static function findFormSection(int $siteId, string $ref): ?array
    {
        if (ctype_digit($ref)) {
            return Database::selectOne(
                "SELECT ps.* FROM page_sections ps
                 JOIN pages p ON p.id = ps.page_id
                 WHERE ps.id = ? AND ps.section_type = 'form' AND ps.status != 'deleted' AND p.site_id = ?
                 LIMIT 1",
                [(int) $ref, $siteId]
            );
        }
        return Database::selectOne(
            "SELECT ps.* FROM page_sections ps
             JOIN pages p ON p.id = ps.page_id
             WHERE p.site_id = ? AND p.slug = ? AND ps.section_type = 'form' AND ps.status != 'deleted'
             ORDER BY ps.sort_order ASC LIMIT 1",
            [$siteId, $ref]
        );
    }

    /**
     * FORMS-LANG T1 — idioma de una página canvas. Nunca lanza: si la fila no
     * existe o la BD falla, cae al idioma del sitio (que es lo que se hacía
     * antes de esto).
     */
    private static function pageLanguage(int $pageId, int $siteId): string
    {
        try {
            $row = Database::selectOne('SELECT language FROM pages WHERE id = ? LIMIT 1', [$pageId]);
            if ($row !== null && trim((string) $row['language']) !== '') {
                return LanguageService::normalize((string) $row['language']);
            }
        } catch (\Throwable $e) {
            // Silencio deliberado: el fallback de abajo es correcto.
        }
        return LanguageService::codeFor($siteId);
    }

    // ==================================================================
    // FH3 — Helpers de sección (para el chat anclado del Studio Live)
    // ==================================================================

    /** HTML exterior de una sección top-level por su data-pp-section. */
    public static function extractSection(string $pageHtml, string $sectionId): ?string
    {
        [$doc, $root] = self::domFromHtml($pageHtml);
        if (!$root) return null;
        foreach ($root->childNodes as $node) {
            if ($node instanceof \DOMElement && $node->getAttribute('data-pp-section') === $sectionId) {
                return $doc->saveHTML($node);
            }
        }
        return null;
    }

    /** Sustituye una sección top-level por HTML nuevo. Devuelve el HTML de página resultante o null. */
    public static function replaceSection(string $pageHtml, string $sectionId, string $newSectionHtml): ?string
    {
        [$doc, $root] = self::domFromHtml($pageHtml);
        if (!$root) return null;

        [$newDoc, $newRoot] = self::domFromHtml($newSectionHtml);
        if (!$newRoot) return null;
        $replacement = null;
        foreach ($newRoot->childNodes as $node) {
            if ($node instanceof \DOMElement) { $replacement = $node; break; }
        }
        if (!$replacement) return null;
        // Forzar que conserve su ancla aunque el modelo la cambie.
        $replacement->setAttribute('data-pp-section', $sectionId);

        foreach ($root->childNodes as $node) {
            if ($node instanceof \DOMElement && $node->getAttribute('data-pp-section') === $sectionId) {
                $imported = $doc->importNode($replacement, true);
                $root->replaceChild($imported, $node);
                $out = '';
                foreach ($root->childNodes as $child) $out .= $doc->saveHTML($child);
                return trim($out);
            }
        }
        return null;
    }

    /** Lista [{id,label}] de secciones top-level de una página canvas. */
    public static function listSections(string $pageHtml): array
    {
        [, $root] = self::domFromHtml($pageHtml);
        if (!$root) return [];
        $out = [];
        foreach ($root->childNodes as $node) {
            if ($node instanceof \DOMElement && $node->hasAttribute('data-pp-section')) {
                $id = $node->getAttribute('data-pp-section');
                // `data-pp-label` lo ponen los bloques insertados desde el panel
                // (formulario, calendario) porque su id lleva un sufijo aleatorio
                // para no repetirse: sin esto, "Partes de esta página" mostraba
                // "Booking 3 cf4f44f6" en vez de "Calendario: Consulta inicial".
                $label = trim($node->getAttribute('data-pp-label'));
                $out[] = [
                    'id'    => $id,
                    'label' => $label !== '' ? $label : ucfirst(str_replace(['-', '_'], ' ', $id)),
                ];
            }
        }
        return $out;
    }

    /**
     * STUDIO-STRUCTURE S1 — Inserta UNA sección top-level respecto a un ancla.
     *
     * Sin ancla, `before` significa principio y `after`, final. A diferencia
     * del helper histórico `insertAfterSection()`, un ancla desconocida o un
     * fragmento que no sea exactamente una sección se rechaza con null: las
     * operaciones manuales nunca deben degradar silenciosamente a "al final".
     */
    public static function insertSectionRelative(
        string $pageHtml,
        string $insertHtml,
        string $anchorId = '',
        string $position = 'after'
    ): ?string {
        if (!in_array($position, ['before', 'after'], true)) return null;

        [$doc, $root] = self::domFromHtml($pageHtml);
        [, $insertRoot] = self::domFromHtml($insertHtml);
        if (!$root || !$insertRoot) return null;

        $insertElements = [];
        foreach ($insertRoot->childNodes as $node) {
            if ($node instanceof \DOMElement) $insertElements[] = $node;
        }
        if (count($insertElements) !== 1) return null;

        $source = $insertElements[0];
        $newId = trim($source->getAttribute('data-pp-section'));
        if (strtolower($source->tagName) !== 'section' || $newId === '') return null;

        $sections = self::topLevelSections($root);
        foreach ($sections as $section) {
            if ($section->getAttribute('data-pp-section') === $newId) return null;
        }

        $anchor = null;
        if ($anchorId !== '') {
            foreach ($sections as $section) {
                if ($section->getAttribute('data-pp-section') === $anchorId) {
                    $anchor = $section;
                    break;
                }
            }
            if (!$anchor) return null;
        }

        $imported = $doc->importNode($source, true);
        if ($anchor) {
            $before = $position === 'before' ? $anchor : $anchor->nextSibling;
            $root->insertBefore($imported, $before);
        } elseif ($position === 'before' && $sections !== []) {
            $root->insertBefore($imported, $sections[0]);
        } else {
            $root->appendChild($imported);
        }

        return self::serializeCanvasRoot($doc, $root);
    }

    /** Elimina exclusivamente una sección top-level; null si el id no existe. */
    public static function deleteSection(string $pageHtml, string $sectionId): ?string
    {
        if ($sectionId === '') return null;
        [$doc, $root] = self::domFromHtml($pageHtml);
        if (!$root) return null;

        foreach (self::topLevelSections($root) as $section) {
            if ($section->getAttribute('data-pp-section') !== $sectionId) continue;
            $root->removeChild($section);
            return self::serializeCanvasRoot($doc, $root);
        }
        return null;
    }

    /**
     * STUDIO-UX F6 — Copia una sección de OTRA página, literal y sin IA.
     *
     * El chat ya sabía imitar una sección de otra página, pero reescribiéndola
     * con el modelo (6,7 s de media y con coste). Copiar es leer e insertar.
     *
     * Lo delicado es el CSS: las clases no son únicas entre páginas (en dev,
     * `inicio` y `sobre-nosotros` definen `.fgl-hero` con reglas distintas), así
     * que la copia viaja con sus clases renombradas y solo con las reglas que le
     * tocan. Así la página de destino no cambia de aspecto por recibir algo.
     *
     * @return array{html:string,css:string,id:string,label:string}|null
     */
    public static function copySectionInto(
        string $targetHtml,
        string $targetCss,
        string $sourceHtml,
        string $sourceCss,
        string $sectionId,
        string $anchorId,
        string $position,
        string $suffix
    ): ?array {
        if ($sectionId === '' || $suffix === '') return null;
        if (!in_array($position, ['before', 'after'], true)) return null;

        [$sourceDoc, $sourceRoot] = self::domFromHtml($sourceHtml);
        if (!$sourceRoot) return null;

        $source = null;
        foreach (self::topLevelSections($sourceRoot) as $section) {
            if ($section->getAttribute('data-pp-section') === $sectionId) { $source = $section; break; }
        }
        if ($source === null) return null;

        // Id libre en el DESTINO (la copia convive con las secciones de allí).
        [, $targetRoot] = self::domFromHtml($targetHtml);
        if (!$targetRoot) return null;
        $taken = [];
        foreach (self::topLevelSections($targetRoot) as $section) {
            $taken[$section->getAttribute('data-pp-section')] = true;
        }
        $base = (string) preg_replace('/-\d+$/', '', $sectionId);
        if ($base === '') $base = $sectionId;
        $newId = $base;
        $n = 2;
        while (isset($taken[$newId])) { $newId = $base . '-' . $n; $n++; }

        /** @var \DOMElement $clone */
        $clone = $source->cloneNode(true);
        $clone->setAttribute('data-pp-section', $newId);
        self::resuffixInnerIds($clone, $suffix);

        $classes = self::renameClassesInTree($clone, $suffix);
        $extraCss = CanvasSanitizer::extractRulesForClasses($sourceCss, $classes, $suffix);

        $fragment = $sourceDoc->saveHTML($clone);
        if ($fragment === false) return null;

        $newHtml = self::insertSectionRelative($targetHtml, $fragment, $anchorId, $position);
        if ($newHtml === null) return null;

        $label = trim($clone->getAttribute('data-pp-label'));
        return [
            'html'  => $newHtml,
            'css'   => trim($targetCss . "\n" . $extraCss),
            'id'    => $newId,
            'label' => $label !== '' ? $label : ucfirst(str_replace(['-', '_'], ' ', $newId)),
        ];
    }

    /**
     * Renombra las clases del árbol copiado (menos las del sistema `pp-*`, que
     * son globales) y devuelve los nombres ORIGINALES para poder buscar sus
     * reglas en el CSS de origen.
     *
     * @return string[]
     */
    private static function renameClassesInTree(\DOMElement $root, string $suffix): array
    {
        $seen = [];
        $elements = array_merge([$root], iterator_to_array($root->getElementsByTagName('*')));
        foreach ($elements as $el) {
            if (!$el instanceof \DOMElement) continue;
            $value = trim($el->getAttribute('class'));
            if ($value === '') continue;
            $out = [];
            foreach (preg_split('/\s+/', $value) ?: [] as $class) {
                if ($class === '') continue;
                if (str_starts_with($class, 'pp-')) { $out[] = $class; continue; }
                $seen[$class] = true;
                $out[] = $class . $suffix;
            }
            $el->setAttribute('class', implode(' ', $out));
        }
        return array_keys($seen);
    }

    /**
     * STUDIO-UX F10 — Integra un cambio de sección sobre el ESTADO ACTUAL de la
     * página, no sobre la foto que se leyó al empezar.
     *
     * El chat lee la página, tarda entre 7 y 34 s en volver del modelo y guarda
     * la página entera. Como la edición manual no está bloqueada durante ese
     * rato, construir el resultado sobre la foto vieja borraba en silencio lo
     * que el usuario hubiera tocado a mano en otra sección.
     *
     * Devuelve null si la sección ya no existe (la borraron mientras tanto):
     * mejor un conflicto explícito que resucitarla.
     *
     * @return array{html:string,css:string}|null
     */
    public static function integrateSectionEdit(
        int $pageId,
        string $snapshotHtml,
        string $snapshotCss,
        string $sectionId,
        string $newSectionHtml,
        string $cssAppend
    ): ?array {
        $current = self::get($pageId);
        $baseHtml = $current['html'] ?? $snapshotHtml;
        $baseCss  = $current['css'] ?? $snapshotCss;

        $newSectionHtml = trim($newSectionHtml);
        if ($newSectionHtml === '') {
            // Cambio solo de estilo: la sección se queda como está AHORA.
            $html = $baseHtml;
        } else {
            $html = self::replaceSection($baseHtml, $sectionId, $newSectionHtml);
            if ($html === null) return null;
        }

        $cssAppend = trim($cssAppend);
        return [
            'html' => $html,
            'css'  => $baseCss . ($cssAppend !== '' ? "\n/* chat */\n" . $cssAppend : ''),
        ];
    }

    /** Devuelve el HTML de UNA sección top-level, o null si no está. */
    public static function sectionHtml(string $pageHtml, string $sectionId): ?string
    {
        if ($sectionId === '') return null;
        [$doc, $root] = self::domFromHtml($pageHtml);
        if (!$root) return null;
        foreach (self::topLevelSections($root) as $section) {
            if ($section->getAttribute('data-pp-section') !== $sectionId) continue;
            $html = $doc->saveHTML($section);
            return $html === false ? null : $html;
        }
        return null;
    }

    /**
     * STUDIO-UX F5 — Aplica un orden completo de secciones de una sola vez.
     *
     * El orden recibido tiene que ser EXACTAMENTE el mismo conjunto de ids que
     * ya hay en la página: ni de más, ni de menos, ni repetidos. Cualquier otra
     * cosa significa que el cliente trabajaba sobre un DOM viejo, y en ese caso
     * se rechaza (409) en vez de reordenar a medias y perder una sección.
     *
     * @param string[] $orderedIds
     */
    public static function reorderSections(string $pageHtml, array $orderedIds): ?string
    {
        $orderedIds = array_values(array_map('strval', $orderedIds));
        if ($orderedIds === []) return null;
        if (count(array_unique($orderedIds)) !== count($orderedIds)) return null;

        [$doc, $root] = self::domFromHtml($pageHtml);
        if (!$root) return null;

        $sections = self::topLevelSections($root);
        $byId = [];
        foreach ($sections as $section) {
            $byId[$section->getAttribute('data-pp-section')] = $section;
        }
        if (count($byId) !== count($orderedIds)) return null;
        foreach ($orderedIds as $id) {
            if (!isset($byId[$id])) return null;
        }

        // Reinsertar en orden al final del root: cada `appendChild` mueve el
        // nodo, así que al terminar la lista queda exactamente como se pidió.
        foreach ($orderedIds as $id) {
            $root->appendChild($byId[$id]);
        }

        return self::serializeCanvasRoot($doc, $root);
    }

    /**
     * Mueve una sección top-level un solo puesto. En un extremo devuelve el
     * mismo HTML normalizado (no-op válido); dirección/id inválidos dan null.
     */
    public static function moveSection(string $pageHtml, string $sectionId, string $direction): ?string
    {
        if ($sectionId === '' || !in_array($direction, ['up', 'down'], true)) return null;
        [$doc, $root] = self::domFromHtml($pageHtml);
        if (!$root) return null;

        $sections = self::topLevelSections($root);
        $index = null;
        foreach ($sections as $i => $section) {
            if ($section->getAttribute('data-pp-section') === $sectionId) {
                $index = $i;
                break;
            }
        }
        if ($index === null) return null;

        if ($direction === 'up' && $index > 0) {
            $root->insertBefore($sections[$index], $sections[$index - 1]);
        } elseif ($direction === 'down' && $index < count($sections) - 1) {
            $next = $sections[$index + 1];
            $root->insertBefore($sections[$index], $next->nextSibling);
        }

        return self::serializeCanvasRoot($doc, $root);
    }

    /**
     * STUDIO-UX F1 — Duplica una sección top-level y deja la copia justo
     * detrás del original. Sin IA: duplicar es la forma natural de hacer
     * crecer una página que ya funciona, y pedirle al modelo que reescriba la
     * sección entera para eso costaba entre 7 y 34 segundos.
     *
     * El id nuevo parte de la BASE del original (sin sufijo numérico), así que
     * duplicar una copia da "services-3" y no "services-2-2".
     *
     * @return array{html:string,id:string,label:string}|null
     */
    public static function duplicateSection(string $pageHtml, string $sectionId): ?array
    {
        if ($sectionId === '') return null;
        [$doc, $root] = self::domFromHtml($pageHtml);
        if (!$root) return null;

        $source = null;
        $taken = [];
        foreach (self::topLevelSections($root) as $section) {
            $id = $section->getAttribute('data-pp-section');
            $taken[$id] = true;
            if ($id === $sectionId) $source = $section;
        }
        if ($source === null) return null;

        $base = (string) preg_replace('/-\d+$/', '', $sectionId);
        if ($base === '') $base = $sectionId;
        $n = 2;
        while (isset($taken[$base . '-' . $n])) $n++;
        $newId = $base . '-' . $n;

        /** @var \DOMElement $clone */
        $clone = $source->cloneNode(true);
        $clone->setAttribute('data-pp-section', $newId);

        // Sin marcar la etiqueta, "Partes de esta página" mostraría dos filas
        // idénticas ("Calendario: Consulta inicial" dos veces) y no habría forma
        // de saber cuál es cuál.
        $label = trim($clone->getAttribute('data-pp-label'));
        if ($label !== '') {
            $label .= ' (' . $n . ')';
            $clone->setAttribute('data-pp-label', $label);
        }

        self::resuffixInnerIds($clone, '-' . $n);
        $source->parentNode->insertBefore($clone, $source->nextSibling);

        return [
            'html'  => self::serializeCanvasRoot($doc, $root),
            'id'    => $newId,
            'label' => $label !== '' ? $label : ucfirst(str_replace(['-', '_'], ' ', $newId)),
        ];
    }

    /**
     * Re-sufija los ids internos de una sección clonada y las referencias que
     * apuntan a ellos. Dos elementos con el mismo id en el documento rompen los
     * anclas y los `details`/`label` asociados.
     */
    private static function resuffixInnerIds(\DOMElement $section, string $suffix): void
    {
        $map = [];
        $elements = array_merge([$section], iterator_to_array($section->getElementsByTagName('*')));
        foreach ($elements as $el) {
            if (!$el instanceof \DOMElement) continue;
            $id = trim($el->getAttribute('id'));
            if ($id === '') continue;
            $map[$id] = $id . $suffix;
            $el->setAttribute('id', $id . $suffix);
        }
        if ($map === []) return;

        foreach ($elements as $el) {
            if (!$el instanceof \DOMElement) continue;

            $href = $el->getAttribute('href');
            if ($href !== '' && $href[0] === '#' && isset($map[substr($href, 1)])) {
                $el->setAttribute('href', '#' . $map[substr($href, 1)]);
            }

            foreach (['for', 'aria-labelledby', 'aria-describedby', 'aria-controls'] as $attr) {
                $value = trim($el->getAttribute($attr));
                if ($value === '') continue;
                $refs = preg_split('/\s+/', $value) ?: [];
                $el->setAttribute($attr, implode(' ', array_map(
                    static fn(string $ref): string => $map[$ref] ?? $ref,
                    $refs
                )));
            }
        }
    }

    /** @return array<int,\DOMElement> */
    private static function topLevelSections(\DOMElement $root): array
    {
        $sections = [];
        foreach ($root->childNodes as $node) {
            if (
                $node instanceof \DOMElement
                && strtolower($node->tagName) === 'section'
                && $node->hasAttribute('data-pp-section')
            ) {
                $sections[] = $node;
            }
        }
        return $sections;
    }

    private static function serializeCanvasRoot(\DOMDocument $doc, \DOMElement $root): string
    {
        $out = '';
        foreach ($root->childNodes as $child) $out .= $doc->saveHTML($child);
        return trim($out);
    }

    /** Inserta HTML despues de una seccion top-level; sin seleccion, al final. */
    public static function insertAfterSection(string $pageHtml, string $insertHtml, string $sectionId = ''): string
    {
        [$doc, $root] = self::domFromHtml($pageHtml);
        [, $insertRoot] = self::domFromHtml($insertHtml);
        if (!$root || !$insertRoot) return $pageHtml . "\n" . $insertHtml;

        $nodes = [];
        foreach ($insertRoot->childNodes as $node) {
            if ($node instanceof \DOMElement) $nodes[] = $doc->importNode($node, true);
        }
        if ($nodes === []) return $pageHtml;

        $anchor = null;
        if ($sectionId !== '') {
            foreach ($root->childNodes as $node) {
                if ($node instanceof \DOMElement && $node->getAttribute('data-pp-section') === $sectionId) {
                    $anchor = $node;
                    break;
                }
            }
        }
        $before = $anchor?->nextSibling;
        foreach ($nodes as $node) $root->insertBefore($node, $before);

        $out = '';
        foreach ($root->childNodes as $child) $out .= $doc->saveHTML($child);
        return trim($out);
    }

    /**
     * PAGE-FROM-REF — Resume el "ADN visual" de una página canvas existente para
     * usarla como SEMILLA de coherencia al generar una página nueva: la secuencia
     * de secciones + su CSS (recortado a un presupuesto). El modelo NO copia este
     * CSS; lo usa como referencia de TRATAMIENTO (espaciados, radios, sombras,
     * tipografía, estilo de tarjetas/botones). Cadena vacía si la página no es
     * canvas o no tiene contenido.
     */
    public static function designSeed(int $pageId, int $cssBudget = 2600): string
    {
        $canvas = self::get($pageId);
        if ($canvas === null) {
            return '';
        }

        $roles = [];
        foreach (self::listSections($canvas['html']) as $section) {
            $slug = trim((string) ($section['id'] ?? ''));
            if ($slug !== '') {
                $roles[] = $slug;
            }
        }

        $css = trim($canvas['css']);
        if (mb_strlen($css) > $cssBudget) {
            $css = mb_substr($css, 0, $cssBudget) . "\n/* …CSS recortado… */";
        }

        $parts = [];
        if ($roles !== []) {
            // i18n-ignore: descripción de la semilla que viaja al prompt.
            $parts[] = 'Secuencia de secciones de la semilla: ' . implode(' → ', $roles) . '.';
        }
        if ($css !== '') {
            // i18n-ignore: instrucción sobre la semilla, viaja al prompt.
        $parts[] = "CSS de la semilla (úsalo como referencia de TRATAMIENTO —escala de espaciados, radios, sombras, tipografía, estilo de tarjetas y botones—; NO lo copies literal, escribe tu propio CSS coherente con él):\n" . $css;
        }

        return trim(implode("\n\n", $parts));
    }

    /**
     * FH4 — Normaliza una sección que vuelve del editor en vivo:
     *  - los embeds expandidos ([data-pp-placeholder]) vuelven a ser {{form:x}}
     *    o {{posts:recent|limit=3|variant=featured-first}}
     *  - se eliminan clases/atributos del overlay del studio
     * El sanitizado de verdad ocurre después, en save().
     */
    public static function normalizeEditedSectionHtml(string $sectionHtml): string
    {
        [$doc, $root] = self::domFromHtml($sectionHtml);
        if (!$root) return $sectionHtml;

        // Revertir embeds → placeholder de texto.
        $embeds = [];
        foreach ($root->getElementsByTagName('*') as $el) {
            if ($el instanceof \DOMElement && $el->hasAttribute('data-pp-placeholder')) {
                $embeds[] = $el;
            }
        }
        foreach ($embeds as $el) {
            $ref = trim($el->getAttribute('data-pp-placeholder'));
            if (preg_match('/^(form|posts|products|booking|resources):([a-z0-9\-_\/]+)((?:\|[a-z0-9_-]+=[^|}]+)*)$/iu', $ref, $m)) {
                $canonical = self::canonicalPlaceholderRef(strtolower($m[1]), strtolower($m[2]), (string) ($m[3] ?? ''));
                $el->parentNode?->replaceChild($doc->createTextNode('{{' . $canonical . '}}'), $el);
            } else {
                $el->parentNode?->removeChild($el);
            }
        }

        // Limpiar restos del overlay (hover/selección/edición).
        foreach ($root->getElementsByTagName('*') as $el) {
            if (!$el instanceof \DOMElement) continue;
            if ($el->hasAttribute('contenteditable')) $el->removeAttribute('contenteditable');
            if ($el->hasAttribute('data-pp-img-edit')) $el->removeAttribute('data-pp-img-edit');
            $classes = preg_split('/\s+/', trim($el->getAttribute('class'))) ?: [];
            $kept = array_values(array_filter($classes, static fn($c) => !str_starts_with($c, 'pp-studio-')));
            if ($kept !== $classes) {
                $kept === [] ? $el->removeAttribute('class') : $el->setAttribute('class', implode(' ', $kept));
            }
        }

        self::stripRuntimeBehaviorMarkup($doc, $root);

        $out = '';
        foreach ($root->childNodes as $child) $out .= $doc->saveHTML($child);
        return trim($out);
    }

    /**
     * Quita el andamiaje que `pp-ux.js` inyecta en tiempo de ejecución.
     *
     * El Studio guarda el `outerHTML` del DOM VIVO, y para entonces pp-ux ya ha
     * envuelto los slides en `.pp-ux-slider__track`, ha añadido dos `<button>` de
     * flechas y ha marcado el contenedor con `data-pp-ux-ready`. Si eso se
     * persiste, al recargar `initSlider` ve la marca de "ya inicializado", sale
     * sin enganchar listeners y el carrusel queda como una tira horizontal
     * congelada con dos flechas muertas: no se puede pasar de slide ni, por
     * tanto, seleccionar las fotos que quedan fuera de pantalla.
     *
     * Lo mismo con `reveal` (clases `pp-ux-reveal`/`pp-ux-in`, que dejarían la
     * animación quemada) y con `counter` (que estaría guardando la cifra a medio
     * animar).
     *
     * La fuente de verdad es SIEMPRE `data-pp-behavior`; el resto se reconstruye
     * en cada carga.
     */
    public static function stripRuntimeBehaviorMarkup(\DOMDocument $doc, \DOMElement $root): void
    {
        $xpath = new \DOMXPath($doc);

        // 1. Flechas inyectadas.
        $arrows = [];
        foreach ($xpath->query('.//button[contains(concat(" ", normalize-space(@class), " "), " pp-ux-slider__arrow ")]', $root) as $node) {
            $arrows[] = $node;
        }
        foreach ($arrows as $node) $node->parentNode?->removeChild($node);

        // 2. Track: subir los slides un nivel y quitar el envoltorio.
        $tracks = [];
        foreach ($xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " pp-ux-slider__track ")]', $root) as $node) {
            $tracks[] = $node;
        }
        foreach ($tracks as $track) {
            $parent = $track->parentNode;
            if ($parent === null) continue;
            while ($track->firstChild !== null) {
                $parent->insertBefore($track->firstChild, $track);
            }
            $parent->removeChild($track);
        }

        // 3. Marcas y clases de runtime + cifra original del contador.
        foreach ($root->getElementsByTagName('*') as $el) {
            if (!$el instanceof \DOMElement) continue;

            if ($el->hasAttribute('data-pp-ux-ready')) $el->removeAttribute('data-pp-ux-ready');

            if ($el->hasAttribute('data-pp-counter-raw')) {
                $el->textContent = $el->getAttribute('data-pp-counter-raw');
                $el->removeAttribute('data-pp-counter-raw');
            }

            $classes = preg_split('/\s+/', trim($el->getAttribute('class'))) ?: [];
            $kept = array_values(array_filter($classes, static fn ($c) => $c !== '' && !str_starts_with($c, 'pp-ux-')));
            if ($kept !== $classes) {
                $kept === [] ? $el->removeAttribute('class') : $el->setAttribute('class', implode(' ', $kept));
            }
        }
    }

    /**
     * Igual que lo anterior pero sobre un fragmento de HTML suelto.
     * Lo usan la reparación de páginas ya guardadas y los tests.
     */
    public static function cleanRuntimeBehaviorMarkup(string $html): string
    {
        if ($html === '' || !str_contains($html, 'pp-ux-')) return $html;

        [$doc, $root] = self::domFromHtml($html);
        if (!$root) return $html;

        self::stripRuntimeBehaviorMarkup($doc, $root);

        $out = '';
        foreach ($root->childNodes as $child) $out .= $doc->saveHTML($child);
        return trim($out);
    }

    /** @return array{0:\DOMDocument,1:?\DOMElement} */
    private static function domFromHtml(string $html): array
    {
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML(
            '<?xml encoding="UTF-8"><!doctype html><html><body><div data-pp-canvas-root="1">' . $html . '</div></body></html>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        foreach ($doc->getElementsByTagName('div') as $div) {
            if ($div->getAttribute('data-pp-canvas-root') === '1') return [$doc, $div];
        }
        return [$doc, null];
    }

    private static function pruneVersions(int $pageId): void
    {
        $rows = Database::select(
            'SELECT id FROM page_versions WHERE page_id = ? ORDER BY id DESC LIMIT 1000',
            [$pageId]
        );
        $ids = array_map(static fn(array $r) => (int) $r['id'], $rows);
        $stale = array_slice($ids, self::MAX_VERSIONS);
        // Nunca podar la versión a la que apunta el puntero (deshacer profundo)
        // NI la que está al aire (C4): sin ella el público se quedaría sin
        // página, que es el peor fallo posible de todo el módulo.
        $protected = [self::currentVersionId($pageId), self::publishedVersionId($pageId)];
        $stale = array_values(array_filter($stale, static fn(int $id) => !in_array($id, $protected, true)));
        if ($stale !== []) {
            Database::execute(
                'DELETE FROM page_versions WHERE page_id = ? AND id IN (' . implode(',', array_fill(0, count($stale), '?')) . ')',
                array_merge([$pageId], $stale)
            );
        }
    }

    private static function invalidateCache(int $pageId): void
    {
        $page = Database::selectOne('SELECT * FROM pages WHERE id = ?', [$pageId]);
        if ($page) {
            try {
                CacheService::invalidatePage((int) $page['site_id'], $page);
            } catch (\Throwable $e) {
                // best-effort
            }
        }
    }
}
