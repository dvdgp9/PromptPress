<?php

declare(strict_types=1);

namespace App\Services\Compliance;

/**
 * E-GDPR G4 — Renderer del banner público de cookies + scripts de tracking.
 *
 * Diseño UX:
 *  - Banner anclado abajo, animación de entrada.
 *  - Botones Aceptar / Rechazar / Configurar con el MISMO peso visual
 *    (requisito legal: rechazar no puede ser más oculto que aceptar).
 *  - Modal "Configurar" con toggles por categoría (Necesarias bloqueada).
 *  - Enlace "Configurar cookies" persistente en el footer para reabrir.
 *  - Persistencia: cookie `pp_consent` con JSON {v, ts, categories}.
 *  - Carga condicional: GA4, Meta Pixel y otros scripts solo se inyectan
 *    cuando la categoría correspondiente está aceptada.
 *
 * Si el manifest no tiene servicios habilitados en categorías no-necesarias,
 * `render()` devuelve cadena vacía (no se inyecta nada).
 */
final class CookieBanner
{
    /** Versión del schema del cookie de consentimiento. */
    private const CONSENT_COOKIE_VERSION = 1;

    /**
     * Genera el HTML+CSS+JS del banner. Pensado para inyectar antes de `</body>`.
     * Devuelve string vacía si no hay nada que gestionar (sin tracking activo).
     */
    public static function render(array $manifest): string
    {
        $needsBanner = TrackingCatalog::needsBanner($manifest);
        $enabledServices = TrackingCatalog::enabledForPublic($manifest);
        $activeCategories = TrackingCatalog::activeCategories($manifest);

        // Render siempre: el JS gestiona vídeos embebidos click-to-load aunque
        // no haya tracking en este sitio. La UI del banner se muestra solo si
        // hay categorías opcionales pendientes de consent (controlado por el JS).

        $banner = (array) ($manifest['banner'] ?? []);
        $title  = $banner['title']           ?? 'Cookies en este sitio';
        $desc   = $banner['description']     ?? 'Usamos cookies necesarias para que la web funcione. Si lo aceptas, también usaremos otras para analítica y mejorar tu experiencia. Puedes cambiar tu decisión cuando quieras.';
        $accept = $banner['accept_label']    ?? 'Aceptar todas';
        $reject = $banner['reject_label']    ?? 'Rechazar opcionales';
        $configure = $banner['configure_label'] ?? 'Configurar';
        $version = (int) ($banner['version'] ?? 1);

        // Categorías que se ofrecen en el modal: las que tienen al menos un
        // servicio activo + necessary (siempre).
        $categories = [];
        foreach (TrackingCatalog::CATEGORIES as $catKey => $catDef) {
            if (!in_array($catKey, $activeCategories, true)) continue;
            $categories[] = [
                'key'         => $catKey,
                'label'       => $catDef['label'],
                'description' => $catDef['description'],
                'always_on'   => !empty($catDef['always_on']),
            ];
        }

        // Buscar enlace a política de cookies si existe.
        $cookiePolicyUrl = self::resolvePolicyLink($manifest);

        $bannerJson = json_encode([
            'version'   => $version,
            'cookieName'=> 'pp_consent',
            'cookieVersion' => self::CONSENT_COOKIE_VERSION,
            'categories'=> $categories,
            'services'  => $enabledServices,
            'policyUrl' => $cookiePolicyUrl,
            'texts'     => [
                'title'     => $title,
                'desc'      => $desc,
                'accept'    => $accept,
                'reject'    => $reject,
                'configure' => $configure,
                'save'      => 'Guardar elección',
                'saveAll'   => 'Aceptar todas',
                'modalTitle'=> 'Configurar cookies',
                'reopenLink'=> 'Configurar cookies',
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        // HTML del banner + modal: se renderizan ocultos por defecto; el JS los
        // muestra cuando no hay cookie de consent o cuando el usuario reabre.
        $html = '<div id="pp-cookie-banner-root" hidden></div>'
              . '<script>window.PP_COOKIE_CONFIG = ' . $bannerJson . ';</script>'
              . self::scriptTag();

        return $html;
    }

    private static function resolvePolicyLink(array $manifest): string
    {
        $legal = (array) ($manifest['legal_pages'] ?? []);
        $cookieId = $legal['cookie_policy'] ?? null;
        if (!$cookieId) return '';
        try {
            $row = \Core\Database::selectOne(
                "SELECT slug FROM pages WHERE id = ? AND status = 'published' LIMIT 1",
                [(int) $cookieId]
            );
            if ($row) return base_url(ltrim((string) $row['slug'], '/'));
        } catch (\Throwable $e) {}
        return '';
    }

    /**
     * Devuelve el script JS que gestiona el banner. Inline para evitar request
     * extra (script es pequeño, <4KB minificable).
     */
    private static function scriptTag(): string
    {
        return '<script>' . self::JS_BANNER . '</script>';
    }

    // El JS del banner se mantiene en una constante para mantener PHP claro.
    // Lee window.PP_COOKIE_CONFIG y maneja todo el ciclo de vida del consent.
    private const JS_BANNER = <<<'JS'
(function(){
  var C = window.PP_COOKIE_CONFIG; if (!C) return;
  var COOKIE = C.cookieName, root = document.getElementById('pp-cookie-banner-root');
  if (!root) return;

  function getCookie(name){
    var m = document.cookie.match('(?:^|; )'+name.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')+'=([^;]*)');
    return m ? decodeURIComponent(m[1]) : null;
  }
  function setCookie(name, value, days){
    var d = new Date(); d.setTime(d.getTime() + days*86400000);
    document.cookie = name + '=' + encodeURIComponent(value) + '; expires=' + d.toUTCString() + '; path=/; SameSite=Lax';
  }
  function readConsent(){
    var raw = getCookie(COOKIE); if (!raw) return null;
    try { var p = JSON.parse(raw); if (p && p.v === C.cookieVersion && p.bv === C.version) return p; } catch(e){}
    return null;
  }
  function writeConsent(categories){
    var payload = { v: C.cookieVersion, bv: C.version, ts: Date.now(), categories: categories };
    setCookie(COOKIE, JSON.stringify(payload), 180);
    return payload;
  }
  function defaultDenied(){
    var cats = {};
    C.categories.forEach(function(c){ cats[c.key] = !!c.always_on; });
    return cats;
  }
  function defaultAccepted(){
    var cats = {};
    C.categories.forEach(function(c){ cats[c.key] = true; });
    return cats;
  }

  // -------- Tracking gating --------
  var injected = {};
  function applyConsent(categories){
    C.services.forEach(function(s){
      if (categories[s.category] && !injected[s.key]) {
        try { loadService(s); injected[s.key] = true; } catch(e) { /* ignore */ }
      }
    });
  }
  function loadService(s){
    if (s.key === 'ga4') {
      var id = s.config.measurement_id; if (!id) return;
      var sc = document.createElement('script'); sc.async = true;
      sc.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(id);
      document.head.appendChild(sc);
      window.dataLayer = window.dataLayer || [];
      function gtag(){ window.dataLayer.push(arguments); }
      window.gtag = window.gtag || gtag;
      gtag('js', new Date()); gtag('config', id, {'anonymize_ip': true});
    } else if (s.key === 'meta_pixel') {
      var pid = s.config.pixel_id; if (!pid) return;
      !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');
      window.fbq('init', pid); window.fbq('track', 'PageView');
    }
  }

  // -------- UI --------
  function escapeHtml(s){ return String(s||'').replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }

  function bannerHtml(){
    var policyLink = C.policyUrl ? ' <a class="pp-cb__link" href="'+escapeHtml(C.policyUrl)+'">'+escapeHtml(C.texts.configure || 'Más info')+'</a>' : '';
    return '<aside class="pp-cb" role="dialog" aria-labelledby="pp-cb-title" aria-modal="false">'
      + '<div class="pp-cb__inner">'
      +   '<div class="pp-cb__text">'
      +     '<h2 id="pp-cb-title" class="pp-cb__title">'+escapeHtml(C.texts.title)+'</h2>'
      +     '<p class="pp-cb__desc">'+escapeHtml(C.texts.desc)+(C.policyUrl ? ' <a class="pp-cb__link" href="'+escapeHtml(C.policyUrl)+'">Más información</a>.' : '')+'</p>'
      +   '</div>'
      +   '<div class="pp-cb__actions">'
      +     '<button type="button" class="pp-cb__btn pp-cb__btn--secondary" data-cb-action="configure">'+escapeHtml(C.texts.configure)+'</button>'
      +     '<button type="button" class="pp-cb__btn pp-cb__btn--secondary" data-cb-action="reject">'+escapeHtml(C.texts.reject)+'</button>'
      +     '<button type="button" class="pp-cb__btn pp-cb__btn--primary" data-cb-action="accept">'+escapeHtml(C.texts.accept)+'</button>'
      +   '</div>'
      + '</div>'
      + '</aside>';
  }

  function modalHtml(state){
    var items = C.categories.map(function(c){
      var checked = !!state[c.key], disabled = !!c.always_on;
      return '<label class="pp-cb-cat">'
        + '<input type="checkbox" data-cb-cat="'+escapeHtml(c.key)+'" '+(checked?'checked':'')+' '+(disabled?'disabled':'')+'>'
        + '<span class="pp-cb-cat__switch" aria-hidden="true"></span>'
        + '<span class="pp-cb-cat__text">'
        +   '<strong>'+escapeHtml(c.label)+(disabled?' <em>(siempre activas)</em>':'')+'</strong>'
        +   '<span>'+escapeHtml(c.description)+'</span>'
        + '</span>'
        + '</label>';
    }).join('');
    return '<div class="pp-cb-modal" role="dialog" aria-labelledby="pp-cb-modal-title" aria-modal="true">'
      + '<div class="pp-cb-modal__backdrop" data-cb-action="close-modal"></div>'
      + '<div class="pp-cb-modal__panel">'
      +   '<header class="pp-cb-modal__head">'
      +     '<h2 id="pp-cb-modal-title">'+escapeHtml(C.texts.modalTitle)+'</h2>'
      +     '<button type="button" class="pp-cb-modal__close" data-cb-action="close-modal" aria-label="Cerrar">&times;</button>'
      +   '</header>'
      +   '<div class="pp-cb-modal__body">'+items+'</div>'
      +   '<footer class="pp-cb-modal__foot">'
      +     '<button type="button" class="pp-cb__btn pp-cb__btn--secondary" data-cb-action="save">'+escapeHtml(C.texts.save)+'</button>'
      +     '<button type="button" class="pp-cb__btn pp-cb__btn--primary" data-cb-action="accept-all">'+escapeHtml(C.texts.saveAll)+'</button>'
      +   '</footer>'
      + '</div>'
      + '</div>';
  }

  var modalState = null;

  function showBanner(){
    root.innerHTML = bannerHtml();
    root.hidden = false;
    requestAnimationFrame(function(){
      var el = root.querySelector('.pp-cb'); if (el) el.classList.add('is-visible');
    });
  }
  function hideBanner(){
    var el = root.querySelector('.pp-cb');
    if (!el) { root.hidden = true; return; }
    el.classList.remove('is-visible');
    setTimeout(function(){ root.innerHTML = ''; root.hidden = true; }, 220);
  }
  function showModal(initial){
    modalState = Object.assign({}, initial);
    root.innerHTML += modalHtml(modalState);
    var modal = root.querySelector('.pp-cb-modal');
    requestAnimationFrame(function(){ modal && modal.classList.add('is-visible'); });
    document.addEventListener('keydown', escClose);
  }
  function closeModal(){
    var modal = root.querySelector('.pp-cb-modal'); if (!modal) return;
    modal.classList.remove('is-visible');
    document.removeEventListener('keydown', escClose);
    setTimeout(function(){ modal && modal.remove(); }, 200);
  }
  function escClose(e){ if (e.key === 'Escape') closeModal(); }

  root.addEventListener('click', function(e){
    var btn = e.target.closest('[data-cb-action]'); if (!btn) return;
    var action = btn.dataset.cbAction;
    var current = readConsent();
    if (action === 'accept') { var c = writeConsent(defaultAccepted()); applyConsent(c.categories); hideBanner(); }
    else if (action === 'reject') { var c2 = writeConsent(defaultDenied()); hideBanner(); /* nothing to apply */ }
    else if (action === 'configure') { showModal((current && current.categories) || defaultDenied()); }
    else if (action === 'close-modal') { closeModal(); }
    else if (action === 'save') {
      var modal = root.querySelector('.pp-cb-modal');
      var cats = {};
      modal.querySelectorAll('[data-cb-cat]').forEach(function(cb){ cats[cb.dataset.cbCat] = cb.checked || cb.disabled; });
      var c3 = writeConsent(cats); applyConsent(c3.categories); closeModal(); hideBanner();
    }
    else if (action === 'accept-all') { var c4 = writeConsent(defaultAccepted()); applyConsent(c4.categories); closeModal(); hideBanner(); }
  });

  // Enlace persistente para reabrir
  document.addEventListener('click', function(e){
    var trigger = e.target.closest('[data-cb-reopen]'); if (!trigger) return;
    e.preventDefault();
    var current = readConsent();
    showModal((current && current.categories) || defaultDenied());
  });

  // -------- Click-to-load YouTube/Vimeo --------
  function activateVideoCta(el){
    var embed = el.getAttribute('data-pp-video-embed');
    if (!embed) return;
    // Replace placeholder by iframe.
    var iframe = document.createElement('iframe');
    iframe.src = embed;
    iframe.allow = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share';
    iframe.setAttribute('allowfullscreen', '');
    iframe.setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
    iframe.className = 'pp-video-cta__iframe';
    el.innerHTML = '';
    el.classList.add('is-loaded');
    el.appendChild(iframe);
  }
  function activateAllVideos(){
    document.querySelectorAll('.pp-video-cta:not(.is-loaded)').forEach(activateVideoCta);
  }
  document.addEventListener('click', function(e){
    var cta = e.target.closest('.pp-video-cta'); if (!cta) return;
    e.preventDefault();
    // Guardamos la elección del usuario para que el resto de vídeos de la
    // sesión (y otras visitas) se carguen automáticamente sin pedírselo otra vez.
    var current = readConsent() || { categories: defaultDenied() };
    current.categories.external_media = true;
    writeConsent(current.categories);
    activateVideoCta(cta);
  });
  // Si ya hay consent para external_media al cargar, auto-activar todos.
  var initial = readConsent();
  if (initial && initial.categories && initial.categories.external_media) {
    activateAllVideos();
  }

  // Init: aplicar consent existente o mostrar banner (solo si hay categorías
  // opcionales que pedir al visitante).
  var hasOptionalCats = C.categories.some(function(c){ return !c.always_on; });
  var stored = readConsent();
  if (stored) {
    applyConsent(stored.categories);
  } else if (hasOptionalCats) {
    showBanner();
  }
})();
JS;
}
