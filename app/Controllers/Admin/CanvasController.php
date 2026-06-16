<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\AI\Actions;
use App\Services\AI\AIActionRunner;
use App\Services\BrandService;
use App\Services\Canvas\CanvasService;
use App\Services\DesignSystem;
use App\Services\SeoIndexingService;
use App\Services\SeoRedirectService;
use App\Services\VisualStyleService;
use Core\CSRF;
use Core\Database;
use Core\Request;
use Core\Response;
use Core\View;

/**
 * FH3 — Studio Live: edición conversacional de páginas canvas.
 *
 *   GET  /admin/canvas/{id}            → UI del studio (iframe + chat)
 *   GET  /admin/canvas/{id}/preview    → render de la página (aunque sea draft)
 *                                        con overlay de selección de secciones
 *   POST /admin/canvas/{id}/chat       → aplica una petición de cambio (IA)
 *   GET  /admin/canvas/{id}/versions   → historial
 *   POST /admin/canvas/{id}/restore    → restaurar versión
 *   POST /admin/canvas/{id}/publish    → publicar/despublicar
 */
final class CanvasController
{
    public function studio(array $params = []): void
    {
        $siteId = self::requireSiteId();
        $page = self::findCanvasPage((int) ($params['id'] ?? 0), $siteId);

        $canvas = CanvasService::get((int) $page['id']) ?? ['html' => '', 'css' => ''];

        // Vista standalone (sin layout admin): el studio es una app a pantalla completa.
        View::send('admin/canvas/studio', [
            'page' => $page,
            // FH9 — tokens de marca para que el chrome del Studio use --pp-primary.
            'brandVars' => DesignSystem::renderCssVars(DesignSystem::load($siteId)),
            'sections' => CanvasService::listSections($canvas['html']),
            'versionsCount' => count(CanvasService::versions((int) $page['id'])),
            'history' => CanvasService::historyState((int) $page['id']),
            // FH7 — destinos de enlace para el panel de edición (botones/CTAs).
            'linkTargets' => Database::select(
                'SELECT title, slug FROM pages WHERE site_id = ? ORDER BY title ASC LIMIT 100',
                [$siteId]
            ),
        ]);
    }

    /** Render completo de la página (estado actual, aunque sea draft) + overlay. */
    public function preview(array $params = []): void
    {
        $siteId = self::requireSiteId();
        $page = self::findCanvasPage((int) ($params['id'] ?? 0), $siteId);
        $pageId = (int) $page['id'];

        $canvas = CanvasService::renderPublic($pageId, $siteId);
        $site = Database::selectOne('SELECT name, language FROM sites WHERE id = ?', [$siteId]) ?? [];
        $styleSlug = VisualStyleService::selectedForSite($siteId);

        $h  = '<!doctype html><html lang="' . e((string) ($site['language'] ?? 'es')) . '"><head>';
        $h .= '<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
        $h .= '<meta name="robots" content="noindex">';
        $h .= '<title>' . e((string) $page['title']) . ' — preview</title>';
        $h .= DesignSystem::renderHead($siteId, $styleSlug);
        $h .= '</head><body class="' . e(VisualStyleService::bodyClass($styleSlug)) . '">';
        $h .= BrandService::publicHeader($siteId);
        $h .= '<main>' . $canvas['html'] . '</main>';
        $h .= BrandService::publicFooter($siteId);
        $h .= '<script src="' . e(base_url('public/js/pp-ux.js')) . '" defer></script>';
        // ?clean=1 → vista limpia para "Ver página" cuando es borrador (sin el
        // overlay de selección/edición, que solo tiene sentido en el iframe).
        if (Request::get('clean') !== '1') {
            $h .= self::overlayScript();
        }
        $h .= '</body></html>';

        Response::html($h);
    }

    /** Aplica una petición de cambio del chat. JSON. */
    public function chat(array $params = []): void
    {
        $siteId = self::requireSiteId();
        $page = self::findCanvasPage((int) ($params['id'] ?? 0), $siteId);
        $pageId = (int) $page['id'];

        CSRF::check();
        $instruction = trim((string) Request::post('instruction', ''));
        $sectionId = trim((string) Request::post('section', ''));
        if ($instruction === '' || mb_strlen($instruction) > 1200) {
            Response::json(['ok' => false, 'error' => 'Cuéntame el cambio en unas pocas frases.'], 422);
        }

        $canvas = CanvasService::get($pageId);
        if ($canvas === null) {
            Response::json(['ok' => false, 'error' => 'Esta página aún no tiene contenido canvas.'], 404);
        }

        try {
            if ($sectionId !== '') {
                $result = self::applySectionEdit($siteId, $pageId, $page, $canvas, $sectionId, $instruction);
            } else {
                $result = self::applyPageEdit($siteId, $page, $canvas, $instruction);
            }
        } catch (\Throwable $e) {
            error_log('[canvas chat] page=' . $pageId . ' ' . get_class($e) . ': ' . $e->getMessage());
            Response::json([
                'ok' => false,
                'error' => 'No he podido aplicar ese cambio. Prueba a pedirlo de otra forma, o un cambio más concreto.',
            ], 502);
        }

        $scope = $sectionId !== '' ? self::sectionLabel($sectionId) : 'Toda la página';
        $summary = $scope . ' — ' . mb_substr($instruction, 0, 90);
        $saved = CanvasService::save($pageId, $result['html'], $result['css'], 'chat', $summary);

        Response::json([
            'ok' => true,
            'reply' => $result['reply'],
            'history' => CanvasService::historyState($pageId),
            'sections' => CanvasService::listSections($saved['html']),
        ]);
    }

    /**
     * FH4 — Guardado de edición directa (texto/imagen) de UNA sección,
     * sin IA. El iframe envía la sección serializada; aquí se revierten los
     * embeds a placeholders, se integra en la página y se versiona (`inline`).
     */
    public function updateSection(array $params = []): void
    {
        $siteId = self::requireSiteId();
        $page = self::findCanvasPage((int) ($params['id'] ?? 0), $siteId);
        $pageId = (int) $page['id'];

        CSRF::check();
        $sectionId = trim((string) Request::post('section', ''));
        $sectionHtml = (string) Request::post('html', '');
        if ($sectionId === '' || trim($sectionHtml) === '') {
            Response::json(['ok' => false, 'error' => 'Falta la sección o el contenido.'], 422);
        }

        $canvas = CanvasService::get($pageId);
        if ($canvas === null) {
            Response::json(['ok' => false, 'error' => 'Esta página aún no tiene contenido canvas.'], 404);
        }

        $clean = CanvasService::normalizeEditedSectionHtml($sectionHtml);
        $newHtml = CanvasService::replaceSection($canvas['html'], $sectionId, $clean);
        if ($newHtml === null) {
            Response::json(['ok' => false, 'error' => 'No se encontró esa parte de la página.'], 404);
        }

        $summary = self::sectionLabel($sectionId) . ' — edición directa';
        CanvasService::save($pageId, $newHtml, $canvas['css'], 'inline', $summary);
        Response::json(['ok' => true, 'history' => CanvasService::historyState($pageId)]);
    }

    /** FH6 — Deshacer: mueve el puntero a la versión anterior. */
    public function undo(array $params = []): void
    {
        $siteId = self::requireSiteId();
        $page = self::findCanvasPage((int) ($params['id'] ?? 0), $siteId);
        CSRF::check();
        $state = CanvasService::undo((int) $page['id']);
        Response::json($state !== null
            ? ['ok' => true, 'history' => $state]
            : ['ok' => false, 'error' => 'No hay nada que deshacer.'], $state !== null ? 200 : 409);
    }

    /** FH6 — Rehacer: mueve el puntero a la versión siguiente. */
    public function redo(array $params = []): void
    {
        $siteId = self::requireSiteId();
        $page = self::findCanvasPage((int) ($params['id'] ?? 0), $siteId);
        CSRF::check();
        $state = CanvasService::redo((int) $page['id']);
        Response::json($state !== null
            ? ['ok' => true, 'history' => $state]
            : ['ok' => false, 'error' => 'No hay nada que rehacer.'], $state !== null ? 200 : 409);
    }

    /** Etiqueta legible de una sección a partir de su slug. */
    private static function sectionLabel(string $id): string
    {
        $s = trim(str_replace(['-', '_'], ' ', $id));
        return $s !== '' ? mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1) : 'Sección';
    }

    public function versions(array $params = []): void
    {
        $siteId = self::requireSiteId();
        $page = self::findCanvasPage((int) ($params['id'] ?? 0), $siteId);
        Response::json([
            'ok' => true,
            'history' => CanvasService::historyState((int) $page['id']),
            'versions' => array_map(static function (array $v): array {
                $summary = trim((string) ($v['summary'] ?? ''));
                $fallback = match ((string) $v['origin']) {
                    'generate' => 'Generación inicial',
                    'chat' => 'Cambio por chat',
                    'restore' => 'Versión restaurada',
                    'inline' => 'Edición directa',
                    default => 'Edición',
                };
                return [
                    'id' => (int) $v['id'],
                    'origin' => (string) $v['origin'],
                    'label' => $summary !== '' ? $summary : $fallback,
                    'kind' => match ((string) $v['origin']) {
                        'generate' => 'Generación',
                        'chat' => 'Chat IA',
                        'inline' => 'Edición directa',
                        default => 'Cambio',
                    },
                    'is_current' => (bool) ($v['is_current'] ?? false),
                    'created_at' => (string) $v['created_at'],
                ];
            }, CanvasService::versions((int) $page['id'])),
        ]);
    }

    public function restore(array $params = []): void
    {
        $siteId = self::requireSiteId();
        $page = self::findCanvasPage((int) ($params['id'] ?? 0), $siteId);
        CSRF::check();
        $versionId = (int) Request::post('version_id', '0');
        $state = $versionId > 0 ? CanvasService::restore((int) $page['id'], $versionId) : null;
        Response::json($state !== null
            ? ['ok' => true, 'reply' => 'Listo, he recuperado esa versión.', 'history' => $state]
            : ['ok' => false, 'error' => 'No se encontró esa versión.'], $state !== null ? 200 : 404);
    }

    public function publish(array $params = []): void
    {
        $siteId = self::requireSiteId();
        $page = self::findCanvasPage((int) ($params['id'] ?? 0), $siteId);
        CSRF::check();
        $publish = Request::post('publish', '1') === '1';
        Database::execute(
            "UPDATE pages SET status = ?, published_at = ?, updated_at = NOW() WHERE id = ?",
            [$publish ? 'published' : 'draft', $publish ? date('Y-m-d H:i:s') : null, (int) $page['id']]
        );
        \App\Services\CacheService::flush($siteId);
        Response::json(['ok' => true, 'status' => $publish ? 'published' : 'draft']);
    }

    /**
     * FH8 — Guarda los ajustes SEO de la página canvas (meta_title,
     * meta_description, slug) desde el modal "Ajustes" del Studio. JSON.
     */
    public function saveSettings(array $params = []): void
    {
        $siteId = self::requireSiteId();
        $page = self::findCanvasPage((int) ($params['id'] ?? 0), $siteId);
        $pageId = (int) $page['id'];
        CSRF::check();

        $metaTitle = trim((string) Request::post('meta_title', ''));
        $metaDescription = trim((string) Request::post('meta_description', ''));
        $slugInput = trim((string) Request::post('slug', ''));
        $seoNoindex = Request::post('seo_noindex', '') === '1' ? 1 : 0;
        $seoExcludeSitemap = Request::post('seo_exclude_sitemap', '') === '1' ? 1 : 0;
        $canonicalUrl = SeoIndexingService::normalizeCanonical((string) Request::post('canonical_url', ''));
        if (trim((string) Request::post('canonical_url', '')) !== '' && $canonicalUrl === null) {
            Response::json(['ok' => false, 'error' => 'La canonical debe empezar por http:// o https://.'], 422);
        }

        // El "home" siempre cuelga de "/"; no se le toca el slug.
        if (($page['page_type'] ?? '') === 'home') {
            $slug = (string) $page['slug'];
        } else {
            $base = slugify($slugInput !== '' ? $slugInput : (string) $page['title']);
            $slug = PageController::uniqueSlug($siteId, $base, $pageId);
        }

        Database::execute(
            'UPDATE pages SET meta_title = ?, meta_description = ?, slug = ?, seo_noindex = ?, seo_exclude_sitemap = ?, canonical_url = ?, updated_at = NOW() WHERE id = ?',
            [$metaTitle !== '' ? $metaTitle : null, $metaDescription !== '' ? $metaDescription : null, $slug, $seoNoindex, $seoExcludeSitemap, $canonicalUrl, $pageId]
        );

        if (($page['status'] ?? '') === 'published'
            && (string) ($page['slug'] ?? '') !== $slug
            && ($page['page_type'] ?? '') !== 'home'
        ) {
            try {
                SeoRedirectService::createAutomaticSlugRedirect(
                    $siteId,
                    (string) $page['slug'],
                    $slug,
                    $pageId,
                    \Core\Auth::id()
                );
            } catch (\Throwable $e) {
                error_log('[SEO] automatic canvas redirect failed for page ' . $pageId . ': ' . $e->getMessage());
            }
        }
        \App\Services\CacheService::flush($siteId);

        Response::json([
            'ok' => true,
            'meta_title' => $metaTitle,
            'meta_description' => $metaDescription,
            'slug' => $slug,
            'seo_noindex' => $seoNoindex,
            'seo_exclude_sitemap' => $seoExcludeSitemap,
            'canonical_url' => $canonicalUrl,
            'public_url' => base_url(ltrim($slug, '/')),
        ]);
    }

    // ==================================================================
    // Internals
    // ==================================================================

    /** @return array{html:string,css:string,reply:string} */
    private static function applySectionEdit(int $siteId, int $pageId, array $page, array $canvas, string $sectionId, string $instruction): array
    {
        $sectionHtml = CanvasService::extractSection($canvas['html'], $sectionId);
        if ($sectionHtml === null) {
            throw new \RuntimeException('Sección no encontrada: ' . $sectionId);
        }

        $result = AIActionRunner::run(Actions::EDIT_CANVAS_SECTION, [
            'instruction' => $instruction,
            'section_html' => $sectionHtml,
            'page_css' => mb_substr($canvas['css'], 0, 14000),
            'page_title' => (string) $page['title'],
            'language' => 'es',
            'available_images' => self::availableImages($siteId),
        ], $siteId);

        $data = (array) ($result['data'] ?? []);
        $newHtml = CanvasService::replaceSection($canvas['html'], $sectionId, (string) ($data['html'] ?? ''));
        if ($newHtml === null) {
            throw new \RuntimeException('No se pudo integrar la sección editada.');
        }

        $cssAppend = trim((string) ($data['css_append'] ?? ''));
        $css = $canvas['css'] . ($cssAppend !== '' ? "\n/* chat */\n" . $cssAppend : '');

        return ['html' => $newHtml, 'css' => $css, 'reply' => self::reply($data)];
    }

    /** @return array{html:string,css:string,reply:string} */
    private static function applyPageEdit(int $siteId, array $page, array $canvas, string $instruction): array
    {
        $result = AIActionRunner::run(Actions::EDIT_CANVAS_PAGE, [
            'instruction' => $instruction,
            'page_html' => $canvas['html'],
            'page_css' => $canvas['css'],
            'page_title' => (string) $page['title'],
            'language' => 'es',
            'available_images' => self::availableImages($siteId),
        ], $siteId);

        $data = (array) ($result['data'] ?? []);
        return [
            'html' => (string) ($data['html'] ?? ''),
            'css' => trim((string) ($data['css'] ?? '')) !== '' ? (string) $data['css'] : $canvas['css'],
            'reply' => self::reply($data),
        ];
    }

    private static function reply(array $data): string
    {
        $reply = trim((string) ($data['reply'] ?? ''));
        return $reply !== '' ? mb_substr($reply, 0, 400) : 'Hecho, cambio aplicado.';
    }

    /** Últimas imágenes de la biblioteca del sitio, para "cambia la foto". */
    private static function availableImages(int $siteId): string
    {
        $rows = Database::select(
            "SELECT path, alt_text FROM media
             WHERE site_id = ? AND mime_type LIKE 'image/%'
             ORDER BY id DESC LIMIT 12",
            [$siteId]
        );
        if ($rows === []) return '(ninguna en la biblioteca)';
        $lines = [];
        foreach ($rows as $r) {
            $lines[] = '- /' . ltrim((string) $r['path'], '/') . ' — ' . trim((string) ($r['alt_text'] ?? ''));
        }
        return implode("\n", $lines);
    }

    /**
     * Overlay de selección para el iframe del studio: resalta secciones al
     * pasar el ratón, selección con clic, y comunica con el parent.
     */
    /**
     * Overlay del studio dentro del iframe de preview:
     *  - hover/clic en sección → selección para el chat (FH3)
     *  - clic en un TEXTO → edición directa con el cursor (FH4)
     *  - clic en una IMAGEN → selector de la biblioteca (FH4)
     * Los embeds del sistema ([data-pp-placeholder]) no se editan inline.
     */
    private static function overlayScript(): string
    {
        return <<<'HTML'
<style>
  [data-pp-section]{transition:outline-color .15s ease}
  [data-pp-section].pp-studio-hover{outline:2px dashed color-mix(in srgb, var(--pp-primary) 65%, transparent);outline-offset:-2px}
  [data-pp-section].pp-studio-selected{outline:3px solid var(--pp-primary);outline-offset:-3px}
  .pp-studio-tag{position:absolute;z-index:9999;background:var(--pp-primary);color:var(--pp-on-primary,#fff);font:600 12px/1 var(--pp-font-body,sans-serif);padding:6px 10px;border-radius:6px;pointer-events:none;transform:translateY(-100%)}
  .pp-studio-text-hover{outline:1.5px dashed color-mix(in srgb, var(--pp-primary) 55%, transparent);outline-offset:3px;cursor:text;border-radius:2px}
  .pp-studio-editing{outline:2px solid var(--pp-primary);outline-offset:3px;border-radius:2px;cursor:text}
  [data-pp-section] img:not([data-pp-no-edit]):hover{outline:2.5px solid var(--pp-primary);outline-offset:2px;cursor:pointer;filter:brightness(.92)}
  [data-pp-placeholder]{cursor:pointer}
</style>
<script>
(function(){
  var EDITABLE = 'h1,h2,h3,h4,h5,h6,p,li,blockquote,figcaption,a';
  var selected = null, tag = null, editing = null, editingOriginal = '', activeTarget = null;

  function label(id){ var s = id.replace(/[-_]+/g,' '); return s.charAt(0).toUpperCase()+s.slice(1); }
  function post(type, data){ parent.postMessage(Object.assign({source:'pp-studio', type:type}, data||{}), '*'); }
  function sectionOf(el){ return el.closest('[data-pp-section]'); }
  function inEmbed(el){ return !!el.closest('[data-pp-placeholder]'); }

  function showTag(el){
    if(!tag){ tag = document.createElement('div'); tag.className='pp-studio-tag'; document.body.appendChild(tag); }
    var r = el.getBoundingClientRect();
    tag.textContent = label(el.getAttribute('data-pp-section'));
    tag.style.left = (r.left + window.scrollX + 12) + 'px';
    tag.style.top = (r.top + window.scrollY + 28) + 'px';
    tag.style.display = 'block';
  }
  function hideTag(){ if(tag) tag.style.display='none'; }

  function selectSection(sec, toggle, editingFlag){
    if(toggle && selected === sec){
      sec.classList.remove('pp-studio-selected'); selected = null;
      post('section-deselected');
      return;
    }
    if(selected && selected !== sec) selected.classList.remove('pp-studio-selected');
    selected = sec; sec.classList.add('pp-studio-selected');
    post('section-selected', { id: sec.getAttribute('data-pp-section'), label: label(sec.getAttribute('data-pp-section')), editing: !!editingFlag });
  }

  // ---------- Serializado y guardado de la sección editada ----------
  function serializeAndSave(sec){
    var clone = sec.cloneNode(true);
    clone.querySelectorAll('[contenteditable]').forEach(function(n){ n.removeAttribute('contenteditable'); });
    clone.querySelectorAll('.pp-studio-editing,.pp-studio-text-hover,.pp-studio-hover,.pp-studio-selected').forEach(function(n){
      n.classList.remove('pp-studio-editing','pp-studio-text-hover','pp-studio-hover','pp-studio-selected');
      if(!n.getAttribute('class')) n.removeAttribute('class');
    });
    clone.classList.remove('pp-studio-hover','pp-studio-selected');
    if(!clone.getAttribute('class')) clone.removeAttribute('class');
    post('section-changed', { id: sec.getAttribute('data-pp-section'), html: clone.outerHTML });
  }
  function saveTargetSection(){ var sec = sectionOf(activeTarget); if(sec) serializeAndSave(sec); }

  // ---------- Panel contextual: describir y aplicar ----------

  function elementKind(el){
    if(!el) return null;
    if(el.tagName === 'IMG') return 'image';
    if(el.tagName === 'A' || el.tagName === 'BUTTON') return 'link';
    if(el.matches && el.matches('h1,h2,h3,h4,h5,h6,p,li,blockquote,figcaption,span')) return 'text';
    if(el.matches && el.matches('[data-pp-section]')) return 'section';
    return null;
  }

  // Un enlace "parece botón" si tiene relleno o forma de botón (clase o fondo).
  function looksLikeButton(el){
    if(/pp-btn|btn|cta/i.test(el.className || '')) return true;
    var cs = getComputedStyle(el);
    var bg = cs.backgroundColor;
    var hasBg = bg && bg !== 'transparent' && bg !== 'rgba(0, 0, 0, 0)';
    var hasPad = parseFloat(cs.paddingLeft) > 6 && parseFloat(cs.paddingTop) > 4;
    return hasBg || hasPad;
  }

  // Imagen de fondo de una sección (cubre la sección, no es contenido en flujo).
  function bgImageOf(sec){
    var imgs = sec.querySelectorAll('img');
    var sr = sec.getBoundingClientRect();
    for(var i=0;i<imgs.length;i++){
      var im = imgs[i]; var cs = getComputedStyle(im); var r = im.getBoundingClientRect();
      var coverFit = cs.objectFit === 'cover';
      var coversW = r.width >= sr.width * 0.85;
      var coversH = r.height >= sr.height * 0.5;
      var absish = /absolute|fixed/.test(getComputedStyle(im.parentNode).position) || /absolute|fixed/.test(cs.position);
      if((coverFit && coversW && coversH) || (absish && coversW)) return im;
    }
    return null;
  }

  // Lee las props editables del elemento para prerellenar el panel.
  function describe(el, kind){
    var cs = el ? getComputedStyle(el) : null;
    var p = { kind: kind };
    if(kind === 'text' || kind === 'link'){
      p.fontSize = cs ? Math.round(parseFloat(cs.fontSize)) : null;
      p.bold = cs ? (parseInt(cs.fontWeight,10) >= 600) : false;
      p.italic = cs ? (cs.fontStyle === 'italic') : false;
      p.align = el.style.textAlign || (cs ? cs.textAlign : '');
      p.color = cs ? cs.color : '';
    }
    if(kind === 'link'){
      p.href = el.getAttribute('href') || '';
      p.newTab = el.getAttribute('target') === '_blank';
      p.text = (el.textContent || '').trim();
      p.isButton = looksLikeButton(el);
      if(p.isButton && cs) p.fill = cs.backgroundColor;
    }
    if(kind === 'image'){
      p.alt = el.getAttribute('alt') || '';
    }
    if(kind === 'section'){
      p.pad = el.getAttribute('data-pp-pad') || 'default';
      p.reveal = el.getAttribute('data-pp-behavior') === 'reveal';
      p.hasBgImage = !!bgImageOf(el);
      p.bgcolor = cs ? cs.backgroundColor : '';
    }
    return p;
  }

  function reportSelection(el){
    var kind = elementKind(el);
    if(!kind) return;
    activeTarget = el;
    var sec = sectionOf(el);
    if(sec && selected !== sec){
      if(selected) selected.classList.remove('pp-studio-selected');
      selected = sec; sec.classList.add('pp-studio-selected');
    }
    post('element-selected', {
      kind: kind,
      props: describe(el, kind),
      sectionId: sec ? sec.getAttribute('data-pp-section') : '',
      sectionLabel: sec ? label(sec.getAttribute('data-pp-section')) : ''
    });
  }

  var PAD_PRESETS = { 'default':'', 'compact':'48', 'normal':'72', 'roomy':'112' };
  var RADIUS_PRESETS = { 'sharp':'0', 'soft':'8px', 'round':'16px', 'pill':'999px' };
  var DIM_PRESETS = { 'none':'', 'soft':'brightness(0.82)', 'medium':'brightness(0.62)', 'strong':'brightness(0.42)' };

  // Resuelve un valor de color a CSS: 'reset'→'', '#hex'→hex, token→var(--pp-token).
  function colorCss(v){
    if(!v || v === 'reset') return '';
    if(v.charAt(0) === '#') return v;
    return 'var(--pp-' + v + ')';
  }

  function applyToTarget(msg){
    var el = activeTarget;
    if(!el) return;
    var sectionOps = { pad:1, reveal:1, bgcolor:1, bgimg:1, bgdim:1 };

    if(msg.op === 'size'){
      var cur = Math.round(parseFloat(getComputedStyle(el).fontSize)) || 16;
      if(msg.value === 'reset'){ el.style.removeProperty('font-size'); }
      else {
        var next = msg.value === 'up' ? Math.min(96, Math.round(cur*1.12)) : Math.max(11, Math.round(cur/1.12));
        el.style.fontSize = next + 'px';
      }
    }
    else if(msg.op === 'bold'){ el.style.fontWeight = msg.value ? '700' : ''; }
    else if(msg.op === 'italic'){ el.style.fontStyle = msg.value ? 'italic' : ''; }
    else if(msg.op === 'align'){ el.style.textAlign = msg.value || ''; }
    else if(msg.op === 'color'){
      if(msg.value === 'reset') el.style.removeProperty('color');
      else el.style.color = colorCss(msg.value);
    }
    else if(msg.op === 'fill'){
      if(msg.value === 'reset') el.style.removeProperty('background');
      else if(msg.value === 'none'){ el.style.background = 'transparent'; }
      else el.style.background = colorCss(msg.value);
    }
    else if(msg.op === 'radius'){
      if(msg.value === 'reset') el.style.removeProperty('border-radius');
      else el.style.borderRadius = RADIUS_PRESETS[msg.value] || msg.value;
    }
    else if(msg.op === 'link'){ if(msg.value) el.setAttribute('href', msg.value); }
    else if(msg.op === 'newtab'){
      if(msg.value){ el.setAttribute('target','_blank'); el.setAttribute('rel','noopener'); }
      else { el.removeAttribute('target'); el.removeAttribute('rel'); }
    }
    else if(msg.op === 'settext'){ if(typeof msg.value === 'string') el.textContent = msg.value; }
    else if(msg.op === 'alt'){ el.setAttribute('alt', msg.value || ''); }
    else if(sectionOps[msg.op]){
      var sec = sectionOf(el); if(!sec) return;
      if(msg.op === 'pad'){
        sec.setAttribute('data-pp-pad', msg.value);
        var px = PAD_PRESETS[msg.value];
        if(px){ sec.style.paddingTop = px+'px'; sec.style.paddingBottom = px+'px'; }
        else { sec.style.removeProperty('padding-top'); sec.style.removeProperty('padding-bottom'); }
      } else if(msg.op === 'reveal'){
        if(msg.value) sec.setAttribute('data-pp-behavior','reveal');
        else if(sec.getAttribute('data-pp-behavior')==='reveal') sec.removeAttribute('data-pp-behavior');
      } else if(msg.op === 'bgcolor'){
        if(msg.value === 'reset') sec.style.removeProperty('background');
        else sec.style.background = colorCss(msg.value);
      } else if(msg.op === 'bgdim'){
        var img = bgImageOf(sec);
        if(img){ var f = DIM_PRESETS[msg.value] || ''; if(f) img.style.filter = f; else img.style.removeProperty('filter'); }
      } else if(msg.op === 'bgimg'){
        var bg = bgImageOf(sec);
        if(msg.value === 'mark'){
          document.querySelectorAll('[data-pp-img-edit]').forEach(function(n){ n.removeAttribute('data-pp-img-edit'); });
          if(bg){ bg.setAttribute('data-pp-img-edit','1'); }
          return; // el padre abrirá la biblioteca; replace-image guardará
        }
        if(msg.value === 'remove' && bg){
          var wrap = bg.closest('[class*=overlay], [class*=bg], [class*=image], [class*=media]');
          if(wrap && wrap !== sec && sectionOf(wrap) === sec) wrap.remove(); else bg.remove();
        }
      }
      if(!msg.preview) serializeAndSave(sec);
      return;
    }
    if(!msg.preview) saveTargetSection();
  }

  // ---------- Edición de texto ----------
  function startEdit(el){
    if(editing === el) return;
    endEdit(true);
    editing = el;
    editingOriginal = el.innerHTML;
    try { el.contentEditable = 'plaintext-only'; } catch(e) { el.contentEditable = 'true'; }
    if(el.contentEditable !== 'plaintext-only' && el.contentEditable !== 'true') el.setAttribute('contenteditable','true');
    el.classList.add('pp-studio-editing');
    var sec = sectionOf(el);
    if(sec) selectSection(sec, false, true);
    reportSelection(el);
    // El foco debe quedarse AQUÍ (el panel del chat no debe robarlo).
    setTimeout(function(){ if(editing === el && document.activeElement !== el) el.focus(); }, 0);
  }
  function endEdit(commit){
    if(!editing) return;
    var el = editing; editing = null;
    el.removeAttribute('contenteditable');
    el.classList.remove('pp-studio-editing');
    if(!el.getAttribute('class')) el.removeAttribute('class');
    if(!commit){ el.innerHTML = editingOriginal; return; }
    if(el.innerHTML !== editingOriginal){
      var sec = sectionOf(el);
      if(sec) serializeAndSave(sec);
    }
  }

  // mousedown (no click) para que el navegador coloque el cursor donde tocas.
  document.addEventListener('mousedown', function(e){
    var t = e.target;
    if(editing && (editing === t || editing.contains(t))) return; // seguir editando
    if(t.closest && !inEmbed(t)){
      var txt = t.closest(EDITABLE);
      if(txt && sectionOf(txt) && txt.tagName !== 'A'){ startEdit(txt); return; }
    }
    if(editing) endEdit(true);
  });

  document.addEventListener('click', function(e){
    var t = e.target;
    if(editing && (editing === t || editing.contains(t))) return;

    // Comportamientos interactivos (acordeon, flechas de slider): dejarlos
    // funcionar tambien dentro del studio para poder probarlos.
    if(t.closest && (t.closest('summary') || t.closest('.pp-ux-slider__arrow'))) return;

    // Imagen → seleccionar y mostrar su panel (Reemplazar abre la biblioteca).
    if(t.tagName === 'IMG' && sectionOf(t) && !inEmbed(t)){
      e.preventDefault(); e.stopPropagation();
      document.querySelectorAll('[data-pp-img-edit]').forEach(function(n){ n.removeAttribute('data-pp-img-edit'); });
      t.setAttribute('data-pp-img-edit','1');
      selectSection(sectionOf(t), false);
      reportSelection(t);
      return;
    }

    // Enlaces/CTAs: editar su texto al hacer clic (sin navegar).
    if(t.closest && t.closest('a') && sectionOf(t) && !inEmbed(t)){
      e.preventDefault(); e.stopPropagation();
      startEdit(t.closest('a'));
      return;
    }

    var s = sectionOf(t);
    if(!s) return;
    e.preventDefault(); e.stopPropagation();
    if(t.closest(EDITABLE) && !inEmbed(t)) return; // ya en edición por mousedown
    var wasSelected = (selected === s);
    selectSection(s, true);
    if(!wasSelected) reportSelection(s); // recién seleccionada → panel de sección
    else { activeTarget = null; post('element-deselected'); }
  }, true);

  document.addEventListener('keydown', function(e){
    if(!editing) return;
    if(e.key === 'Escape'){ e.preventDefault(); endEdit(false); }
    if(e.key === 'Enter' && editing.tagName !== 'P' && editing.tagName !== 'LI'){ e.preventDefault(); endEdit(true); }
  });
  document.addEventListener('focusout', function(e){
    if(editing && e.target === editing) setTimeout(function(){ if(editing && document.activeElement !== editing) endEdit(true); }, 0);
  });

  // ---------- Hover ----------
  document.addEventListener('mouseover', function(e){
    var s = sectionOf(e.target);
    document.querySelectorAll('.pp-studio-hover').forEach(function(x){ x.classList.remove('pp-studio-hover'); });
    document.querySelectorAll('.pp-studio-text-hover').forEach(function(x){ x.classList.remove('pp-studio-text-hover'); });
    if(!s){ hideTag(); return; }
    s.classList.add('pp-studio-hover'); showTag(s);
    if(!inEmbed(e.target)){
      var txt = e.target.closest(EDITABLE);
      if(txt && txt !== editing && sectionOf(txt)) txt.classList.add('pp-studio-text-hover');
    }
  });

  // ---------- Mensajes del parent ----------
  window.addEventListener('message', function(e){
    var d = e.data || {};
    if(d.source !== 'pp-studio-parent') return;
    if(d.type === 'apply'){ applyToTarget(d); return; }
    if(d.type === 'deselect' && selected){ selected.classList.remove('pp-studio-selected'); selected = null; activeTarget = null; }
    if(d.type === 'scroll-to' && d.y != null){ window.scrollTo(0, d.y); }
    if(d.type === 'select' && d.id){
      var el = document.querySelector('[data-pp-section="'+d.id+'"]');
      if(el){ selectSection(el, false); el.scrollIntoView({behavior:'smooth', block:'start'}); }
    }
    if(d.type === 'replace-image' && d.src){
      var img = document.querySelector('[data-pp-img-edit]');
      if(!img) return;
      img.src = d.src;
      if(d.alt) img.alt = d.alt;
      img.removeAttribute('data-pp-img-edit');
      var sec = sectionOf(img);
      if(sec) serializeAndSave(sec);
    }
  });

  function brandPalette(){
    // Resuelve cada token a su color computado (rgb) usando una sonda, para
    // poder comparar con el color actual de los elementos.
    var probe = document.createElement('span');
    probe.style.cssText = 'position:absolute;visibility:hidden;pointer-events:none';
    (document.querySelector('.pp-canvas') || document.body).appendChild(probe);
    var resolve = function(token){ probe.style.color = 'var(--pp-' + token + ')'; return getComputedStyle(probe).color; };
    var pal = {
      primary: resolve('primary'), 'text': resolve('text'), 'text-muted': resolve('text-muted'),
      'on-primary': resolve('on-primary'), surface: resolve('surface')
    };
    probe.remove();
    return pal;
  }
  post('ready', { scrollY: 0, palette: brandPalette(), sections: Array.prototype.map.call(document.querySelectorAll('[data-pp-section]'), function(s){ return s.getAttribute('data-pp-section'); }) });
  window.addEventListener('scroll', function(){ hideTag(); }, {passive:true});
})();
</script>
HTML;
    }

    private static function findCanvasPage(int $pageId, int $siteId): array
    {
        $page = Database::selectOne(
            "SELECT * FROM pages WHERE id = ? AND site_id = ? AND render_mode = 'canvas' LIMIT 1",
            [$pageId, $siteId]
        );
        if (!$page) Response::notFound();
        return $page;
    }

    private static function requireSiteId(): int
    {
        $siteId = \Core\Auth::siteId();
        if ($siteId === null) Response::redirect(base_url('admin/logout'));
        return $siteId;
    }
}
