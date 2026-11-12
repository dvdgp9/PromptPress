(function () {
    'use strict';
    var panel = document.getElementById('pp-notification-editor');
    if (!panel) return;
    var form = panel.closest('form');
    var subject = document.getElementById('pp-f-notify-subject');
    var body = document.getElementById('pp-f-notify-body');
    var target = body;
    var button = document.getElementById('pp-notify-preview-button');
    var preview = document.getElementById('pp-notify-preview');
    var frame = document.getElementById('pp-notify-preview-frame');
    var status = document.getElementById('pp-notify-preview-status');
    var fieldTokens = document.getElementById('pp-notify-field-tokens');
    var list = document.getElementById('pp-fb-list');
    var request = null;
    var revision = 0;
    var previewed = false;

    [subject, body].forEach(function (input) {
        input.addEventListener('focus', function () { target = input; });
    });
    panel.addEventListener('click', function (event) {
        var tokenButton = event.target.closest('[data-notify-token]');
        if (!tokenButton) return;
        var input = tokenButton.hasAttribute('data-notify-body-only') ? body : target;
        var token = tokenButton.getAttribute('data-notify-token');
        var selected = input.selectionEnd - input.selectionStart;
        if (input.value.length - selected + token.length > input.maxLength) return;
        input.setRangeText(token, input.selectionStart, input.selectionEnd, 'end');
        input.focus();
        input.dispatchEvent(new Event('input', { bubbles: true }));
    });

    function updateFieldTokens() {
        fieldTokens.replaceChildren();
        list.querySelectorAll('[data-fb-row]').forEach(function (row) {
            var label = row.querySelector('[data-fb-label]').value.trim();
            var name = row.querySelector('[data-fb-name]').value;
            if (!label || !name) return;
            var token = document.createElement('button');
            token.type = 'button';
            token.className = 'pp-btn pp-btn--ghost pp-btn--sm';
            token.textContent = label;
            token.setAttribute('data-notify-token', '{{campo:' + name + '}}');
            fieldTokens.appendChild(token);
        });
    }
    function invalidate() {
        revision++;
        if (request) request.abort();
        preview.hidden = true;
        if (previewed) {
            status.hidden = false;
            status.className = '';
            status.textContent = pp.t('js.form_edit.notify_preview_stale');
        }
    }
    form.addEventListener('input', invalidate);
    form.addEventListener('change', invalidate);
    list.addEventListener('input', updateFieldTokens);
    new MutationObserver(function () { updateFieldTokens(); invalidate(); }).observe(list, { childList: true });
    updateFieldTokens();

    button.addEventListener('click', function () {
        var currentRevision = revision;
        var controller = new AbortController();
        request = controller;
        var timedOut = false;
        var timeout = setTimeout(function () { timedOut = true; controller.abort(); }, 20000);
        button.disabled = true;
        preview.hidden = true;
        status.hidden = false;
        status.className = '';
        status.textContent = pp.t('js.form_edit.notify_preview_loading');
        panel.setAttribute('aria-busy', 'true');
        fetch(panel.getAttribute('data-preview-url'), {
            method: 'POST',
            body: new FormData(form),
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            signal: controller.signal
        }).then(function (response) {
            if (!response.ok) throw new Error('preview');
            return response.json();
        }).then(function (result) {
            if (revision !== currentRevision) return;
            if (!result.ok || typeof result.html !== 'string') throw new Error('preview');
            document.getElementById('pp-notify-preview-subject').textContent = result.subject;
            frame.srcdoc = result.html;
            preview.hidden = false;
            previewed = true;
            status.hidden = true;
        }).catch(function (error) {
            if (revision !== currentRevision) {
                status.textContent = pp.t('js.form_edit.notify_preview_stale');
                return;
            }
            if (error.name === 'AbortError' && !timedOut) return;
            status.className = 'pp-design-hint is-error';
            status.textContent = pp.t('js.form_edit.notify_preview_error');
        }).finally(function () {
            clearTimeout(timeout);
            if (request === controller) request = null;
            button.disabled = false;
            panel.removeAttribute('aria-busy');
        });
    });
})();
