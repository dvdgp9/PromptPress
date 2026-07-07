/**
 * FEAT-4 AB3 — Cliente del proof-of-work anti-bot (BotGuard).
 *
 * Para cada formulario `form[data-pp-form-id]` pide un reto a
 * /_botguard/challenge y busca en segundo plano (crypto.subtle, en lotes
 * concurrentes con pausas para no congelar la UI) un nonce cuyo
 * sha256(salt + '.' + nonce) empiece por `bits` bits a cero. Al encontrarlo
 * rellena el hidden `_pp_pow`; el envío AJAX de pp-ux.js lo recoge solo.
 *
 * Un reto por formulario (el anti-replay del servidor consume cada reto una
 * vez). Si el visitante envía antes de terminar, se retiene el submit con
 * aria-busy (estilo ya existente) y se relanza al resolver. Si el reto ha
 * caducado al enviar (>2 h con la página abierta) se retira el campo: el
 * servidor acepta sin PoW cuando pasan el resto de capas (degradación
 * confirmada). Sin crypto.subtle/fetch (navegadores antiguos o HTTP plano)
 * el script no hace nada: misma degradación.
 */
(function () {
  'use strict';
  if (!window.fetch || !window.crypto || !window.crypto.subtle || !window.TextEncoder || !window.Promise) return;

  var BATCH = 128; // digests concurrentes por lote

  function leadingZeroBits(bytes) {
    var bits = 0;
    for (var i = 0; i < bytes.length; i++) {
      if (bytes[i] === 0) { bits += 8; continue; }
      var b = bytes[i];
      while ((b & 0x80) === 0) { bits++; b <<= 1; }
      break;
    }
    return bits;
  }

  function challengeUrl(form) {
    // Respeta un posible subdirectorio de instalación: el action del form
    // es siempre <base>/forms/<id>.
    return form.action.replace(/forms\/\d+.*$/, '_botguard/challenge');
  }

  function solve(challenge) {
    var enc = new TextEncoder();
    var found = null;
    var n = 0;
    function batch() {
      if (found !== null) return Promise.resolve(found);
      var jobs = [];
      for (var i = 0; i < BATCH; i++) {
        (function (nonce) {
          jobs.push(crypto.subtle.digest('SHA-256', enc.encode(challenge.salt + '.' + nonce)).then(function (buf) {
            if (found === null && leadingZeroBits(new Uint8Array(buf)) >= challenge.bits) found = nonce;
          }));
        })(n++);
      }
      return Promise.all(jobs).then(function () {
        if (found !== null) return found;
        // Ceder el hilo entre lotes: el usuario está escribiendo.
        return new Promise(function (r) { setTimeout(r, 0); }).then(batch);
      });
    }
    return batch();
  }

  function arm(form) {
    if (form.dataset.ppBotguard) return;
    form.dataset.ppBotguard = 'pending';
    var promise = fetch(challengeUrl(form), { method: 'POST', credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (ch) {
        if (!ch || !ch.challenge) throw new Error('no challenge');
        return solve(ch).then(function (nonce) {
          var input = document.createElement('input');
          input.type = 'hidden';
          input.name = '_pp_pow';
          input.value = ch.challenge + '.' + nonce;
          input.dataset.ppExpires = String(ch.expires);
          form.appendChild(input);
          form.dataset.ppBotguard = 'ready';
        });
      })
      .catch(function () {
        // Sin reto no se bloquea nada: el servidor degrada a las otras capas.
        form.dataset.ppBotguard = 'failed';
      });
    form._ppBotguardPromise = promise;
  }

  // Captura en document: corre antes que el listener AJAX de pp-ux.js y su
  // guard `e.defaultPrevented` hace el resto.
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form || !form.dataset || !form.dataset.ppBotguard) return;

    var input = form.querySelector('input[name="_pp_pow"]');
    if (input && Number(input.dataset.ppExpires) * 1000 < Date.now()) {
      input.parentNode.removeChild(input); // caducado: degradar, no bloquear
      return;
    }
    if (form.dataset.ppBotguard !== 'pending' || form.dataset.ppBotguardWaiting) return;

    // Reto aún resolviéndose: retener este envío y relanzar al terminar.
    e.preventDefault();
    form.dataset.ppBotguardWaiting = '1';
    form.setAttribute('aria-busy', 'true');
    var finish = function () {
      delete form.dataset.ppBotguardWaiting;
      form.removeAttribute('aria-busy');
      // Blindaje anti-bucle: pase lo que pase, este form no se vuelve a
      // retener (si siguiera 'pending' re-interceptaríamos el requestSubmit
      // en cadena infinita).
      if (form.dataset.ppBotguard === 'pending') form.dataset.ppBotguard = 'failed';
      if (typeof form.requestSubmit === 'function') form.requestSubmit();
      else form.submit();
    };
    (form._ppBotguardPromise || Promise.resolve()).then(finish, finish);
  }, true);

  function ready(fn) {
    if (document.readyState !== 'loading') fn();
    else document.addEventListener('DOMContentLoaded', fn);
  }
  ready(function () {
    var forms = document.querySelectorAll('form[data-pp-form-id]');
    for (var i = 0; i < forms.length; i++) arm(forms[i]);
  });
})();
