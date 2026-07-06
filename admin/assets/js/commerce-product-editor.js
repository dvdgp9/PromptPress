/**
 * Editor de producto (FEAT-3 C2): selector de imagen desde la biblioteca.
 *
 * Consume el endpoint JSON /admin/media/library (mismo que usa el picker de
 * secciones) y guarda el id elegido en el input oculto name="media_id".
 */
(function () {
    'use strict';

    var script = document.currentScript;
    var libraryUrl = script ? script.getAttribute('data-library') : null;
    var picker = document.querySelector('[data-media-picker]');
    var modal = document.getElementById('pp-commerce-media-modal');
    if (!libraryUrl || !picker || !modal) return;

    var input = picker.querySelector('[data-media-input]');
    var preview = picker.querySelector('[data-media-preview]');
    var clearBtn = picker.querySelector('[data-media-clear]');
    var grid = modal.querySelector('[data-media-grid]');
    var loaded = false;

    function openModal() {
        modal.hidden = false;
        if (!loaded) loadLibrary();
    }
    function closeModal() {
        modal.hidden = true;
    }

    function loadLibrary() {
        grid.innerHTML = '<p class="pp-booking-soft">Cargando…</p>';
        fetch(libraryUrl, { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                loaded = true;
                var items = (data && data.items) || [];
                if (!items.length) {
                    grid.innerHTML = '<p class="pp-booking-soft">No hay imágenes en la biblioteca todavía.</p>';
                    return;
                }
                grid.innerHTML = '';
                items.forEach(function (m) {
                    if (!/^image\//.test(m.mime_type || '')) return;
                    var btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'pp-commerce-media-item';
                    btn.title = m.name || '';
                    var img = document.createElement('img');
                    img.src = m.url;
                    img.alt = m.alt_text || '';
                    img.loading = 'lazy';
                    btn.appendChild(img);
                    btn.addEventListener('click', function () { choose(m); });
                    grid.appendChild(btn);
                });
            })
            .catch(function () {
                grid.innerHTML = '<p class="pp-alert pp-alert--error">No se pudo cargar la biblioteca.</p>';
            });
    }

    function choose(m) {
        input.value = m.id;
        preview.classList.remove('is-empty');
        preview.innerHTML = '';
        var img = document.createElement('img');
        img.src = m.url;
        img.alt = '';
        preview.appendChild(img);
        if (clearBtn) clearBtn.classList.remove('is-hidden');
        closeModal();
    }

    function clearImage() {
        input.value = '';
        preview.classList.add('is-empty');
        preview.innerHTML = '';
        if (clearBtn) clearBtn.classList.add('is-hidden');
    }

    picker.querySelector('[data-media-open]').addEventListener('click', openModal);
    if (clearBtn) clearBtn.addEventListener('click', clearImage);
    modal.querySelectorAll('[data-media-close]').forEach(function (el) {
        el.addEventListener('click', closeModal);
    });
    document.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape' && !modal.hidden) closeModal();
    });
})();
