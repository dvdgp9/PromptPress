/**
 * Upload de documentos con drag & drop + XHR progress bar.
 * Fallback: si el navegador no soporta XHR upload o FormData, el form submit normal funciona igual.
 */
(function () {
    'use strict';

    var form     = document.getElementById('pp-doc-upload-form');
    var dropzone = document.getElementById('pp-dropzone');
    var input    = document.getElementById('pp-file-input');
    var selected = document.getElementById('pp-dropzone-selected');
    var nameEl   = document.getElementById('pp-dropzone-name');
    var metaEl   = document.getElementById('pp-dropzone-meta');
    var extras   = document.getElementById('pp-dropzone-extras');
    var clearBtn = document.getElementById('pp-dropzone-clear');
    var progress = document.getElementById('pp-dropzone-progress');
    var progressFill  = document.getElementById('pp-dropzone-progress-fill');
    var progressLabel = document.getElementById('pp-dropzone-progress-label');
    var submitBtn = document.getElementById('pp-doc-submit');
    var titleInput = form ? form.querySelector('input[name=title]') : null;

    if (!form || !dropzone || !input) return;

    var MAX_SIZE = window.PP_DOC_MAX_SIZE || (20 * 1024 * 1024);

    // ---------- Drag & drop ----------
    ['dragenter', 'dragover'].forEach(function (ev) {
        dropzone.addEventListener(ev, function (e) {
            e.preventDefault();
            e.stopPropagation();
            dropzone.classList.add('is-dragover');
        });
    });
    ['dragleave', 'drop'].forEach(function (ev) {
        dropzone.addEventListener(ev, function (e) {
            e.preventDefault();
            e.stopPropagation();
            dropzone.classList.remove('is-dragover');
        });
    });
    dropzone.addEventListener('drop', function (e) {
        var files = e.dataTransfer && e.dataTransfer.files;
        if (files && files.length > 0) {
            input.files = files;
            onFileSelected(files[0]);
        }
    });

    // ---------- Input change ----------
    input.addEventListener('change', function () {
        if (input.files && input.files[0]) onFileSelected(input.files[0]);
    });

    function onFileSelected(file) {
        // Validación local
        if (file.size > MAX_SIZE) {
            alertInline('El archivo supera los ' + formatSize(MAX_SIZE) + '.');
            resetSelection();
            return;
        }
        var ext = (file.name.split('.').pop() || '').toLowerCase();
        if (['pdf', 'docx', 'txt'].indexOf(ext) === -1) {
            alertInline('Formato no soportado. Sube PDF, DOCX o TXT.');
            resetSelection();
            return;
        }

        // Mostrar selección y extras
        nameEl.textContent = file.name;
        metaEl.textContent = ext.toUpperCase() + ' · ' + formatSize(file.size);
        selected.hidden = false;
        extras.hidden = false;
        dropzone.classList.add('has-file');

        if (titleInput && !titleInput.value) {
            titleInput.value = file.name.replace(/\.[^.]+$/, '');
        }
    }

    if (clearBtn) {
        clearBtn.addEventListener('click', function () {
            resetSelection();
            input.click();
        });
    }

    function resetSelection() {
        input.value = '';
        selected.hidden = true;
        extras.hidden = true;
        dropzone.classList.remove('has-file');
        if (titleInput) titleInput.value = '';
    }

    function alertInline(msg) {
        // Reusamos el patrón de toast si existe, si no, simple alert
        if (typeof window.PPToast === 'function') { window.PPToast(msg, 'error'); return; }
        var t = document.createElement('div');
        t.className = 'pp-alert pp-alert--error';
        t.textContent = msg;
        t.style.marginTop = '12px';
        dropzone.parentElement.insertBefore(t, dropzone.nextSibling);
        setTimeout(function () { t.remove(); }, 4000);
    }

    function formatSize(bytes) {
        if (bytes >= 1048576) return (bytes / 1048576).toFixed(1).replace('.', ',') + ' MB';
        if (bytes >= 1024) return Math.round(bytes / 1024) + ' KB';
        return bytes + ' B';
    }

    // ---------- XHR submit con progress ----------
    form.addEventListener('submit', function (e) {
        if (!input.files || input.files.length === 0) return; // dejar submit normal (error server-side)
        if (!window.FormData || !window.XMLHttpRequest) return; // fallback

        e.preventDefault();
        var fd = new FormData(form);

        var xhr = new XMLHttpRequest();
        xhr.open('POST', form.action, true);
        xhr.withCredentials = true;

        xhr.upload.addEventListener('progress', function (ev) {
            if (ev.lengthComputable) {
                var pct = Math.round((ev.loaded / ev.total) * 100);
                progressFill.style.width = pct + '%';
                progressLabel.textContent = 'Subiendo ' + pct + '%…';
            }
        });
        xhr.upload.addEventListener('load', function () {
            progressFill.style.width = '100%';
            progressLabel.textContent = 'Procesando texto del documento…';
        });
        xhr.onload = function () {
            // El server redirige a /admin/documents con flash. XHR sigue el redirect por defecto.
            if (xhr.status === 200 || xhr.status === 302) {
                window.location = form.action.replace('/upload', '');
            } else if (xhr.status === 403) {
                alertInline('Sesión expirada. Recarga la página.');
                hideProgress();
            } else {
                alertInline('Error subiendo el archivo (HTTP ' + xhr.status + ').');
                hideProgress();
            }
        };
        xhr.onerror = function () {
            alertInline('No se pudo contactar con el servidor.');
            hideProgress();
        };

        progress.hidden = false;
        progressFill.style.width = '0%';
        progressLabel.textContent = 'Subiendo 0%…';
        if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = 'Subiendo…'; }
        dropzone.classList.add('is-uploading');

        xhr.send(fd);
    });

    function hideProgress() {
        progress.hidden = true;
        dropzone.classList.remove('is-uploading');
        if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = 'Subir y procesar'; }
    }
})();
