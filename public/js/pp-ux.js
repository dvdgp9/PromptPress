/**
 * FH5 — pp-ux.js: comportamientos declarativos de PromptPress.
 *
 * La IA (y cualquier autor) los invoca con atributos `data-pp-behavior`;
 * el JS vive aquí, curado y único para todo el sitio. Sin dependencias.
 *
 *   accordion → <div data-pp-behavior="accordion"><details><summary>…</summary>…</details>…</div>
 *               (details nativo; este JS solo cierra los hermanos al abrir uno)
 *   reveal    → data-pp-behavior="reveal" [data-pp-reveal-delay="1..5"]
 *               aparición suave al entrar en viewport (IntersectionObserver)
 *   slider    → <div data-pp-behavior="slider"><div>slide</div>…</div>
 *               carrusel con scroll-snap + flechas inyectadas
 *   counter   → <span data-pp-behavior="counter">120</span>
 *               anima la cifra de 0 al valor al hacerse visible
 *
 * También gestiona el menú móvil del header del sitio (.pp-site-header).
 * Respeta prefers-reduced-motion: reveal/counter se muestran sin animar.
 */
(function () {
  'use strict';

  var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  function ready(fn) {
    if (document.readyState !== 'loading') fn();
    else document.addEventListener('DOMContentLoaded', fn);
  }

  function all(selector, root) {
    return Array.prototype.slice.call((root || document).querySelectorAll(selector));
  }

  // ------------------------------------------------------------------
  // accordion — details nativo + "solo uno abierto" dentro del grupo
  // ------------------------------------------------------------------
  function initAccordion(el) {
    el.addEventListener('toggle', function (e) {
      var opened = e.target;
      if (!(opened instanceof HTMLDetailsElement) || !opened.open) return;
      all('details[open]', el).forEach(function (d) {
        if (d !== opened) d.open = false;
      });
    }, true);
  }

  // ------------------------------------------------------------------
  // reveal — aparición al entrar en viewport
  // ------------------------------------------------------------------
  var revealObserver = null;
  function initReveal(el) {
    if (reduceMotion || !('IntersectionObserver' in window)) {
      el.classList.add('pp-ux-in');
      return;
    }
    el.classList.add('pp-ux-reveal');
    if (!revealObserver) {
      revealObserver = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
          if (!entry.isIntersecting) return;
          entry.target.classList.add('pp-ux-in');
          revealObserver.unobserve(entry.target);
        });
      }, { threshold: 0.15, rootMargin: '0px 0px -40px 0px' });
    }
    revealObserver.observe(el);
  }

  // ------------------------------------------------------------------
  // slider — scroll-snap + flechas
  // ------------------------------------------------------------------
  function initSlider(el) {
    if (el.dataset.ppUxReady) return;
    el.dataset.ppUxReady = '1';

    var track = document.createElement('div');
    track.className = 'pp-ux-slider__track';
    while (el.firstChild) track.appendChild(el.firstChild);
    el.appendChild(track);
    el.classList.add('pp-ux-slider');

    if (track.children.length < 2) return;

    function arrow(dir) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'pp-ux-slider__arrow pp-ux-slider__arrow--' + (dir < 0 ? 'prev' : 'next');
      b.setAttribute('aria-label', dir < 0 ? 'Anterior' : 'Siguiente');
      b.innerHTML = dir < 0
        ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>'
        : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>';
      b.addEventListener('click', function () {
        var slide = track.children[0];
        var step = slide ? slide.getBoundingClientRect().width + 24 : track.clientWidth * 0.8;
        track.scrollBy({ left: dir * step, behavior: reduceMotion ? 'auto' : 'smooth' });
      });
      return b;
    }
    el.appendChild(arrow(-1));
    el.appendChild(arrow(1));

    function syncArrows() {
      var max = track.scrollWidth - track.clientWidth - 4;
      el.classList.toggle('pp-ux-slider--at-start', track.scrollLeft <= 4);
      el.classList.toggle('pp-ux-slider--at-end', track.scrollLeft >= max);
    }
    track.addEventListener('scroll', syncArrows, { passive: true });
    window.addEventListener('resize', syncArrows, { passive: true });
    syncArrows();
  }

  // ------------------------------------------------------------------
  // counter — anima la cifra al hacerse visible
  // ------------------------------------------------------------------
  function initCounter(el) {
    var raw = el.textContent || '';
    var match = raw.match(/[\d.,]+/);
    if (!match) return;
    var target = parseFloat(match[0].replace(/\./g, '').replace(',', '.'));
    if (!isFinite(target) || reduceMotion || !('IntersectionObserver' in window)) return;

    var prefix = raw.slice(0, match.index);
    var suffix = raw.slice(match.index + match[0].length);
    var decimals = (match[0].split(',')[1] || '').length;
    var started = false;

    var obs = new IntersectionObserver(function (entries) {
      if (!entries[0].isIntersecting || started) return;
      started = true;
      obs.disconnect();
      var t0 = performance.now(), dur = 1200;
      function frame(now) {
        var p = Math.min(1, (now - t0) / dur);
        var eased = 1 - Math.pow(1 - p, 3);
        var value = (target * eased).toFixed(decimals).replace('.', ',');
        el.textContent = prefix + value + suffix;
        if (p < 1) requestAnimationFrame(frame);
        else el.textContent = raw;
      }
      requestAnimationFrame(frame);
    }, { threshold: 0.6 });
    obs.observe(el);
  }

  // ------------------------------------------------------------------
  // menú móvil del header del sitio
  // ------------------------------------------------------------------
  function initSiteNav() {
    var header = document.querySelector('.pp-site-header');
    if (!header) return;
    var nav = header.querySelector('.pp-site-header__nav');
    if (!nav || header.querySelector('.pp-site-header__burger')) return;

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'pp-site-header__burger';
    btn.setAttribute('aria-label', 'Abrir menú');
    btn.setAttribute('aria-expanded', 'false');
    btn.innerHTML = '<span></span><span></span><span></span>';
    btn.addEventListener('click', function () {
      var open = header.classList.toggle('is-nav-open');
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      btn.setAttribute('aria-label', open ? 'Cerrar menú' : 'Abrir menú');
    });
    header.querySelector('.pp-site-header__inner').appendChild(btn);

    nav.addEventListener('click', function (e) {
      if (e.target.closest('a')) {
        header.classList.remove('is-nav-open');
        btn.setAttribute('aria-expanded', 'false');
      }
    });
  }

  // ------------------------------------------------------------------
  // form files — nombre visible + validación ligera antes de enviar
  // ------------------------------------------------------------------
  function formatBytes(bytes) {
    if (bytes >= 1048576) return (bytes / 1048576).toFixed(bytes % 1048576 ? 1 : 0).replace('.', ',') + ' MB';
    if (bytes >= 1024) return (bytes / 1024).toFixed(bytes % 1024 ? 1 : 0).replace('.', ',') + ' KB';
    return bytes + ' B';
  }

  function initFormFile(field) {
    if (field.dataset.ppFileReady) return;
    field.dataset.ppFileReady = '1';
    var input = field.querySelector('input[type="file"]');
    var name = field.querySelector('[data-pp-file-name]');
    var help = field.parentNode ? field.parentNode.querySelector('[data-pp-file-help]') : null;
    if (!input || !name) return;

    var defaultName = name.textContent || 'Ningún archivo seleccionado';
    var defaultHelp = help ? (help.getAttribute('data-pp-file-help') || help.textContent || '') : '';
    function sync() {
      field.classList.remove('is-invalid');
      if (help) help.textContent = defaultHelp;
      var file = input.files && input.files[0] ? input.files[0] : null;
      if (!file) {
        name.textContent = defaultName;
        return true;
      }
      name.textContent = file.name + ' · ' + formatBytes(file.size);
      var max = parseInt(field.getAttribute('data-max-bytes') || '0', 10);
      if (max > 0 && file.size > max) {
        field.classList.add('is-invalid');
        if (help) help.textContent = 'El archivo supera el máximo permitido de ' + formatBytes(max) + '.';
        return false;
      }
      return true;
    }
    input.addEventListener('change', sync);
    var form = input.form;
    if (form && !form.dataset.ppFileSubmitReady) {
      form.dataset.ppFileSubmitReady = '1';
      form.addEventListener('submit', function (e) {
        var ok = true;
        all('[data-pp-file-field]', form).forEach(function (fileField) {
          var fileInput = fileField.querySelector('input[type="file"]');
          if (fileInput && fileInput.files && fileInput.files[0]) {
            var max = parseInt(fileField.getAttribute('data-max-bytes') || '0', 10);
            if (max > 0 && fileInput.files[0].size > max) ok = false;
          }
        });
        if (!ok) {
          e.preventDefault();
          var invalid = form.querySelector('[data-pp-file-field].is-invalid');
          if (invalid) invalid.scrollIntoView({ block: 'center', behavior: reduceMotion ? 'auto' : 'smooth' });
        }
      });
    }
    sync();
  }

  // ------------------------------------------------------------------
  // formularios — envío AJAX progresivo; sin JS conserva POST + redirect
  // ------------------------------------------------------------------
  function formNotice(form, message, success) {
    var panel = form.closest('.pp-form__panel') || form.parentNode;
    var notice = panel ? panel.querySelector('.pp-form__notice[data-pp-ajax-notice]') : null;
    if (!notice) {
      notice = document.createElement('div');
      notice.setAttribute('data-pp-ajax-notice', '1');
      notice.setAttribute('role', success ? 'status' : 'alert');
      if (panel) panel.insertBefore(notice, form);
    }
    notice.className = 'pp-form__notice pp-form__notice--' + (success ? 'success' : 'error');
    notice.textContent = message;
    notice.setAttribute('role', success ? 'status' : 'alert');
    return notice;
  }

  function initAjaxForm(form) {
    if (form.dataset.ppAjaxReady || !window.fetch || !window.FormData) return;
    form.dataset.ppAjaxReady = '1';
    form.addEventListener('submit', function (e) {
      if (e.defaultPrevented || form.dataset.ppSubmitting === '1') return;
      e.preventDefault();
      form.dataset.ppSubmitting = '1';
      form.setAttribute('aria-busy', 'true');
      var submit = form.querySelector('[type="submit"]');
      if (submit) submit.disabled = true;

      fetch(form.action, {
        method: 'POST',
        body: new FormData(form),
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin'
      }).then(function (response) {
        return response.json().catch(function () {
          return { ok: false, message: 'No se pudo procesar la respuesta del servidor.' };
        }).then(function (data) {
          data.httpOk = response.ok;
          return data;
        });
      }).then(function (data) {
        var ok = !!data.ok && !!data.httpOk;
        var notice = formNotice(form, data.message || (ok
          ? 'Gracias, hemos recibido tu mensaje.'
          : 'No se pudo enviar el formulario. Revisa los campos e inténtalo de nuevo.'), ok);
        notice.scrollIntoView({ block: 'nearest', behavior: reduceMotion ? 'auto' : 'smooth' });
        if (ok) {
          form.reset();
          all('[data-pp-file-field]', form).forEach(function (field) {
            var input = field.querySelector('input[type="file"]');
            if (input) input.dispatchEvent(new Event('change'));
          });
          document.dispatchEvent(new CustomEvent('pp:form-success', {
            detail: { formId: parseInt(form.dataset.ppFormId || '0', 10), response: data }
          }));
        }
      }).catch(function () {
        formNotice(form, 'No hay conexión ahora mismo. Comprueba tu conexión e inténtalo de nuevo.', false);
      }).finally(function () {
        delete form.dataset.ppSubmitting;
        form.removeAttribute('aria-busy');
        if (submit) submit.disabled = false;
      });
    });
  }

  // ------------------------------------------------------------------
  ready(function () {
    all('[data-pp-behavior]').forEach(function (el) {
      switch (el.getAttribute('data-pp-behavior')) {
        case 'accordion': initAccordion(el); break;
        case 'reveal': initReveal(el); break;
        case 'slider': initSlider(el); break;
        case 'counter': initCounter(el); break;
      }
    });
    initSiteNav();
    all('[data-pp-file-field]').forEach(initFormFile);
    all('.pp-form__form[data-pp-form-id]').forEach(initAjaxForm);
  });
})();
