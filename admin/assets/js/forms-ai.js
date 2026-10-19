(function () {
    'use strict';
    document.querySelectorAll('[data-form-ai]').forEach(function (root) {
        var texts = JSON.parse(root.dataset.texts);
        var types = JSON.parse(root.dataset.types);
        var prompt = root.querySelector('[data-ai-description]');
        var reference = root.querySelector('[data-ai-reference]');
        var generate = root.querySelector('[data-ai-generate]');
        var apply = root.querySelector('[data-ai-apply]');
        var status = root.querySelector('[data-ai-status]');
        var review = root.querySelector('[data-ai-review]');
        var preview = root.querySelector('[data-ai-preview]');
        var draft = null;
        var busy = false;
        function message(text, error) {
            status.hidden = false;
            status.textContent = text;
            status.classList.toggle('is-error', !!error);
        }
        function pending(value) {
            busy = value;
            generate.disabled = value;
            apply.disabled = value;
            root.setAttribute('aria-busy', String(value));
        }
        async function post(url, data) {
            var response = await fetch(url, { method: 'POST', body: data, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            var result;
            try { result = await response.json(); } catch (error) { throw new Error(texts.failed); }
            if (!response.ok || !result.ok) throw new Error(result.error || texts.failed);
            return result;
        }
        function element(tag, text, parent) {
            var node = document.createElement(tag);
            node.textContent = text;
            parent.appendChild(node);
            return node;
        }
        function render(data) {
            preview.replaceChildren();
            element('h4', data.heading, preview);
            if (data.description) element('p', data.description, preview);
            var fields = document.createElement('ol');
            preview.appendChild(fields);
            data.fields.forEach(function (field) {
                var item = element('li', '', fields);
                element('strong', field.label, item);
                element('small', (types[field.field_type] || field.field_type) + ' · ' + texts[field.required === '1' ? 'required' : 'optional'], item);
                if (field.placeholder) element('p', field.placeholder, item);
                if (field.options) {
                    var choices = element('div', '', item);
                    choices.className = 'pp-form-ai-review__choices';
                    field.options.forEach(function (option) { element('span', option, choices); });
                }
            });
            element('p', data.submit_text + (data.success_message ? ' · ' + data.success_message : ''), preview);
        }
        root.querySelector('[data-ai-example]').addEventListener('click', function () {
            prompt.value = 'Crea un cuestionario titulado «Cuéntanos qué estás buscando». Descripción: «Nos ayuda a preparar mejor el primer contacto contigo». Cinco preguntas obligatorias de elección única, con botones visibles:\n1. ¿Qué zona te gustaría mejorar? Pecho; Abdomen/cuerpo; Rostro; Zona íntima; Otra.\n2. ¿Qué resultado buscas principalmente? Aumentar volumen; Reducir volumen; Elevar/reafirmar; Remodelar; Rejuvenecer; No estoy segura.\n3. ¿Te has realizado anteriormente alguna cirugía estética? Sí; No.\n4. ¿En qué momento te gustaría realizarla? Lo antes posible; 1–3 meses; 3–6 meses; Todavía me estoy informando.\n5. ¿Qué es lo más importante para ti? Resultado natural; Seguridad; Acompañamiento; Recuperación; Presupuesto.\nNo añadas campos de contacto. Botón: Enviar respuestas.';
            prompt.focus();
        });
        generate.addEventListener('click', async function () {
            if (busy) return;
            var file = reference.files[0];
            if (prompt.value.trim().length < 5 && !file) { message(texts.description_error, true); prompt.focus(); return; }
            if (file && (file.size > 8 * 1024 * 1024 || ['image/png','image/jpeg','image/webp'].indexOf(file.type) === -1)) { message(texts.image_error, true); return; }
            var data = new FormData();
            data.append('_csrf', root.dataset.csrf);
            data.append('description', prompt.value.trim());
            data.append('form_id', root.dataset.formId);
            if (file) data.append('reference', file);
            if (Number(root.dataset.formId)) root.dispatchEvent(new CustomEvent('form-ai-context', { detail: { data: data } }));
            pending(true);
            review.hidden = true;
            draft = null;
            message(texts.generating);
            try {
                var result = await post(root.dataset.designUrl, data);
                draft = result.data;
                render(draft);
                review.hidden = false;
                message(texts.ready.replace('{n}', String(draft.fields.length)));
            } catch (error) { message(error.message || texts.failed, true); }
            finally { pending(false); }
        });
        apply.addEventListener('click', async function () {
            if (busy || !draft) return;
            if (Number(root.dataset.formId)) {
                var detail = { draft: draft, mode: root.querySelector('[data-ai-mode]').value, applied: false };
                root.dispatchEvent(new CustomEvent('form-ai-apply', { detail: detail }));
                if (detail.applied) { review.hidden = true; message(texts.applied); }
                else message(texts.too_many_fields, true);
                return;
            }
            var data = new FormData();
            data.append('_csrf', root.dataset.csrf);
            data.append('draft_json', JSON.stringify(draft));
            pending(true);
            message(texts.saving);
            try {
                var result = await post(root.dataset.createUrl, data);
                window.location.assign(result.edit_url);
            } catch (error) { pending(false); message(error.message || texts.failed, true); }
        });
        root.querySelector('[data-ai-undo]').addEventListener('click', function () {
            root.dispatchEvent(new CustomEvent('form-ai-undo'));
            message(texts.undone);
        });
    });
})();
