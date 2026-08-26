/** Recursos R3 — interacción progresiva, selector de portada y confirmación. */
(function () {
    'use strict';

    var script = document.currentScript;
    var libraryUrl = script ? script.getAttribute('data-library') : '';
    var editor = document.getElementById('pp-resource-editor');
    if (!editor) return;

    var fileInput = editor.querySelector('[data-file-input]');
    var fileName = editor.querySelector('[data-file-name]');
    var formSettings = editor.querySelector('[data-form-settings]');
    var formSelect = editor.querySelector('[data-form-select]');
    var readiness = editor.querySelector('[data-readiness]');
    var save = editor.querySelector('[data-save]');
    var languagesBox = editor.querySelector('[data-resource-languages]');

    function selectedValue(name) {
        var checked = editor.querySelector('input[name="' + name + '"]:checked');
        return checked ? checked.value : '';
    }

    function updateSelectedCards(name, selector) {
        editor.querySelectorAll(selector).forEach(function (card) {
            var radio = card.querySelector('input[name="' + name + '"]');
            card.classList.toggle('is-selected', !!radio && radio.checked);
        });
    }

    function updateReadiness() {
        if (!readiness) return;
        var hasFile = editor.getAttribute('data-has-file') === '1'
            || !!(fileInput && fileInput.files && fileInput.files.length);
        var needsForm = selectedValue('access_mode') === 'form';
        var hasForm = !needsForm || !!(formSelect && formSelect.value);
        var issues = [];
        if (!hasFile) issues.push(readiness.getAttribute('data-file-text'));
        if (!hasForm) issues.push(readiness.getAttribute('data-form-text'));

        var title = readiness.querySelector('[data-readiness-title]');
        var list = readiness.querySelector('[data-readiness-list]');
        readiness.classList.toggle('is-ready', issues.length === 0);
        title.textContent = issues.length === 0
            ? readiness.getAttribute('data-ready-text')
            : pp.t('js.resources.before_publish');
        list.innerHTML = '';
        issues.forEach(function (issue) {
            var li = document.createElement('li');
            li.textContent = issue;
            list.appendChild(li);
        });
    }

    editor.querySelectorAll('input[name="access_mode"]').forEach(function (radio) {
        radio.addEventListener('change', function () {
            updateSelectedCards('access_mode', '.pp-resource-choice');
            formSettings.hidden = selectedValue('access_mode') !== 'form';
            updateReadiness();
        });
    });
    editor.querySelectorAll('input[name="status"]').forEach(function (radio) {
        radio.addEventListener('change', function () {
            updateSelectedCards('status', '.pp-resource-status-option');
        });
    });
    if (formSelect) formSelect.addEventListener('change', updateReadiness);
    if (fileInput) {
        fileInput.addEventListener('change', function () {
            fileName.textContent = fileInput.files && fileInput.files[0]
                ? fileInput.files[0].name
                : pp.t('js.resources.no_file');
            updateReadiness();
        });
    }

    if (languagesBox) {
        var allLanguages = languagesBox.querySelector('[data-language-all]');
        var languageOptions = Array.prototype.slice.call(languagesBox.querySelectorAll('input[name="languages[]"]'));
        function updateLanguages() {
            var all = !!(allLanguages && allLanguages.checked);
            if (allLanguages) allLanguages.closest('label').classList.toggle('is-selected', all);
            languageOptions.forEach(function (input) {
                input.disabled = all;
                input.closest('label').classList.toggle('is-selected', all || input.checked);
            });
        }
        if (allLanguages) allLanguages.addEventListener('change', updateLanguages);
        languageOptions.forEach(function (input) { input.addEventListener('change', updateLanguages); });
        updateLanguages();
    }
    editor.addEventListener('submit', function () {
        if (!save) return;
        save.disabled = true;
        save.textContent = pp.t('js.resources.saving');
    });

    // Selector de portada: se carga solo al abrir para que el editor sea rápido.
    var picker = editor.querySelector('[data-media-picker]');
    var modal = document.getElementById('pp-resource-media-modal');
    if (libraryUrl && picker && modal) {
        var input = picker.querySelector('[data-media-input]');
        var preview = picker.querySelector('[data-media-preview]');
        var clear = picker.querySelector('[data-media-clear]');
        var grid = modal.querySelector('[data-media-grid]');
        var loaded = false;
        var opener = null;

        function closeMedia() {
            modal.hidden = true;
            if (opener) opener.focus();
        }
        function choose(media) {
            input.value = media.id;
            preview.classList.remove('is-empty');
            preview.innerHTML = '';
            var img = document.createElement('img');
            img.src = media.url;
            img.alt = '';
            preview.appendChild(img);
            clear.classList.remove('is-hidden');
            closeMedia();
        }
        function loadMedia() {
            grid.innerHTML = '<p class="pp-booking-soft">' + pp.t('js.common.loading') + '</p>';
            fetch(libraryUrl, {headers: {'Accept': 'application/json'}})
                .then(function (response) {
                    if (!response.ok) throw new Error('HTTP ' + response.status);
                    return response.json();
                })
                .then(function (data) {
                    loaded = true;
                    var items = ((data && data.items) || []).filter(function (item) {
                        return /^image\//.test(item.mime_type || '');
                    });
                    grid.innerHTML = '';
                    if (!items.length) {
                        grid.innerHTML = '<p class="pp-booking-soft">' + pp.t('js.media.empty') + '</p>';
                        return;
                    }
                    items.forEach(function (media) {
                        var button = document.createElement('button');
                        button.type = 'button';
                        button.className = 'pp-commerce-media-item';
                        button.title = media.name || '';
                        var img = document.createElement('img');
                        img.src = media.url;
                        img.alt = media.alt_text || '';
                        img.loading = 'lazy';
                        button.appendChild(img);
                        button.addEventListener('click', function () { choose(media); });
                        grid.appendChild(button);
                    });
                })
                .catch(function (error) {
                    grid.innerHTML = '<p class="pp-alert pp-alert--error">' + pp.t('js.media.load_error') + '</p>';
                    if (window.console) console.error('[Resources] media library:', error);
                });
        }
        picker.querySelector('[data-media-open]').addEventListener('click', function (event) {
            opener = event.currentTarget;
            modal.hidden = false;
            modal.querySelector('.pp-modal__close').focus();
            if (!loaded) loadMedia();
        });
        clear.addEventListener('click', function () {
            input.value = '';
            preview.classList.add('is-empty');
            preview.innerHTML = '<span aria-hidden="true">R</span>';
            clear.classList.add('is-hidden');
        });
        modal.querySelectorAll('[data-media-close]').forEach(function (element) {
            element.addEventListener('click', closeMedia);
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !modal.hidden) closeMedia();
        });
    }

    var deleteDialog = document.querySelector('[data-delete-dialog]');
    var deleteOpen = document.querySelector('[data-delete-open]');
    if (deleteDialog && deleteOpen) {
        function closeDelete() {
            deleteDialog.hidden = true;
            deleteOpen.focus();
        }
        deleteOpen.addEventListener('click', function () {
            deleteDialog.hidden = false;
            deleteDialog.querySelector('[data-delete-close]').focus();
        });
        deleteDialog.querySelector('[data-delete-close]').addEventListener('click', closeDelete);
        deleteDialog.addEventListener('click', function (event) {
            if (event.target === deleteDialog) closeDelete();
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !deleteDialog.hidden) closeDelete();
        });
    }

    updateReadiness();
})();
