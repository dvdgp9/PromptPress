/**
 * pp-analytics.js — telemetría propia de PromptPress (FEAT-3 A3).
 *
 * Sin cookies ni localStorage: no escribe nada en el navegador. Envía un
 * pageview al cargar y expone window.ppTrack(nombre) para eventos propios.
 * El envío usa navigator.sendBeacon (no bloquea la navegación); fallback a
 * fetch keepalive. El site_id llega en data-site del propio <script>.
 */
(function () {
  var s = document.currentScript;
  var siteId = s && s.getAttribute('data-site');
  if (!siteId) return;

  var ENDPOINT = '/_analytics/collect';

  function send(payload) {
    payload.s = Number(siteId);
    try {
      var body = JSON.stringify(payload);
      if (navigator.sendBeacon) {
        // Blob con tipo JSON para que el servidor lo lea como tal.
        navigator.sendBeacon(ENDPOINT, new Blob([body], { type: 'application/json' }));
      } else {
        fetch(ENDPOINT, {
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
