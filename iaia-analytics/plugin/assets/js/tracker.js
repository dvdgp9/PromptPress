/**
 * tracker.js — telemetría propia de IAIA Analytics.
 *
 * Portado de PromptPress (public/js/pp-analytics.js). Sin cookies ni
 * localStorage: no escribe nada en el navegador. Envía un pageview al cargar
 * y expone window.ppTrack(nombre) para eventos propios. El envío usa
 * navigator.sendBeacon (no bloquea la navegación); fallback a fetch
 * keepalive. El endpoint llega en window.IAIA_ANALYTICS (wp_localize_script).
 */
(function () {
  var cfg = window.IAIA_ANALYTICS;
  var endpoint = cfg && cfg.endpoint;
  if (!endpoint) return;

  function send(payload) {
    try {
      var body = JSON.stringify(payload);
      if (navigator.sendBeacon) {
        // Blob con tipo JSON para que el servidor lo lea como tal.
        navigator.sendBeacon(endpoint, new Blob([body], { type: 'application/json' }));
      } else {
        fetch(endpoint, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: body,
          keepalive: true,
          credentials: 'omit'
        });
      }
    } catch (e) { /* silencioso: la analítica nunca debe romper la página */ }
  }

  function pageview() {
    send({ p: location.pathname, r: document.referrer || '' });
  }

  // API pública para eventos personalizados (p. ej. ppTrack('form_submit')).
  window.ppTrack = function (name) {
    if (!name) return;
    send({ p: location.pathname, e: String(name) });
  };

  pageview();
})();
