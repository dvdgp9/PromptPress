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
  });
})();
