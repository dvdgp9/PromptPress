/**
 * Detail de documento — rename inline, copy to clipboard, search highlight, delete modal.
 */
(function () {
    'use strict';

    // ---------- Rename ----------
    var editBtn = document.getElementById('pp-edit-title-btn');
    var titleDisplay = document.getElementById('pp-doc-title-display');
    var renameForm = document.getElementById('pp-rename-form');
    var cancelRename = document.getElementById('pp-cancel-rename');

    if (editBtn && renameForm) {
        editBtn.addEventListener('click', function () {
            titleDisplay.parentElement.hidden = true;
            renameForm.hidden = false;
            var input = renameForm.querySelector('input[name=title]');
            input.focus();
            input.select();
        });
        cancelRename.addEventListener('click', function () {
            renameForm.hidden = true;
            titleDisplay.parentElement.hidden = false;
        });
    }

    // ---------- Delete modal ----------
    var deleteBtn = document.getElementById('pp-delete-btn');
    var deleteModal = document.getElementById('pp-delete-modal');
    if (deleteBtn && deleteModal) {
        deleteBtn.addEventListener('click', function () {
            deleteModal.hidden = false;
            deleteModal.setAttribute('aria-hidden', 'false');
        });
        deleteModal.querySelectorAll('[data-close-modal]').forEach(function (el) {
            el.addEventListener('click', function () {
                deleteModal.hidden = true;
                deleteModal.setAttribute('aria-hidden', 'true');
            });
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !deleteModal.hidden) {
                deleteModal.hidden = true;
                deleteModal.setAttribute('aria-hidden', 'true');
            }
        });
    }

    // ---------- Copy extracted text ----------
    var copyBtn = document.getElementById('pp-doc-copy-btn');
    var textEl = document.getElementById('pp-doc-text');
    if (copyBtn && textEl) {
        copyBtn.addEventListener('click', function () {
            var text = textEl.textContent || '';
            if (!text) return;
            var ok = function () {
                var orig = copyBtn.textContent;
                copyBtn.textContent = '✓ Copiado';
                copyBtn.classList.add('is-success');
                setTimeout(function () {
                    copyBtn.textContent = orig;
                    copyBtn.classList.remove('is-success');
                }, 1500);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(ok).catch(function () {
                    fallbackCopy(text, ok);
                });
            } else {
                fallbackCopy(text, ok);
            }
        });
    }

    function fallbackCopy(text, onDone) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.top = '-9999px';
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); onDone(); }
        catch (e) { /* no-op */ }
        ta.remove();
    }

    // ---------- Search + highlight ----------
    var searchInput = document.getElementById('pp-doc-search');
    var searchInfo = document.getElementById('pp-doc-search-info');
    if (searchInput && textEl) {
        var originalText = textEl.textContent;

        var doSearch = debounce(function () {
            var q = searchInput.value.trim();
            if (!q) {
                // Restore original plain text
                textEl.textContent = originalText;
                searchInfo.hidden = true;
                return;
            }
            var escaped = q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            var re = new RegExp(escaped, 'gi');
            var count = 0;
            var html = escapeHtml(originalText).replace(
                new RegExp(escaped, 'gi'),
                function (m) { count++; return '<mark>' + escapeHtml(m) + '</mark>'; }
            );
            textEl.innerHTML = html;
            if (count === 0) {
                searchInfo.hidden = false;
                searchInfo.className = 'pp-doc-search-info pp-err';
                searchInfo.textContent = 'Sin coincidencias';
            } else {
                searchInfo.hidden = false;
                searchInfo.className = 'pp-doc-search-info pp-ok';
                searchInfo.textContent = count + ' coincidencia' + (count > 1 ? 's' : '');
                // Scroll al primer match
                var firstMark = textEl.querySelector('mark');
                if (firstMark) firstMark.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }, 200);

        searchInput.addEventListener('input', doSearch);
    }

    function escapeHtml(s) {
        return (s == null ? '' : String(s)).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function debounce(fn, wait) {
        var t;
        return function () {
            clearTimeout(t);
            var args = arguments, ctx = this;
            t = setTimeout(function () { fn.apply(ctx, args); }, wait);
        };
    }
})();
