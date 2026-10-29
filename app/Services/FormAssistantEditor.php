<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\AI\Actions;
use App\Services\AI\AIActionRunner;

/**
 * ASST-FORMS — Ejecutor del asistente para `forms.edit`.
 *
 * Reutiliza el mismo diseñador que el editor de formularios (DESIGN_FORM +
 * FormDefinition::fromAI) y solo deja que cambie las preguntas y los textos
 * visibles. Destinatario, base legal, retención, consentimiento comercial,
 * autorrespuesta, tipo e idioma se conservan tal cual.
 *
 * Los formularios no tienen borrador: guardar es publicar. Por eso antes de
 * escribir se guarda una versión (reason before_ai_edit) que el editor de
 * formularios permite restaurar.
 */
final class FormAssistantEditor
{
    /** Lo único que el asistente puede sustituir del content del formulario. */
    public const EDITABLE_KEYS = ['heading', 'description', 'submit_text', 'success_message', 'fields'];

    /** ASST-V2 F3 — vigencia de una vista previa firmada. */
    public const PROPOSAL_TTL = 7200;
    private const PROPOSAL_VERSION = 'fp1';
    private const MAX_PROPOSAL_BYTES = 150000;
    /** A partir de cuántas preguntas quitadas se resumen en una línea. */
    private const GROUP_AFTER = 3;

    /**
     * Pide el cambio a la IA y lo aplica en el mismo paso (sin vista previa).
     *
     * @return array{ok:bool, reply?:string, error?:string}
     * @throws \App\Services\AI\AIException si falla la llamada al modelo
     */
    public static function apply(int $siteId, int $formId, string $instruction, ?int $userId): array
    {
        $proposal = self::propose($siteId, $formId, $instruction);
        if (!$proposal['ok']) {
            return $proposal;
        }
        return self::commit($siteId, $formId, $proposal['content'], $proposal['base_hash'], $userId);
    }

    /**
     * ASST-V2 F3 — Prepara el formulario tal como quedaría, sin guardar nada.
     *
     * @return array{ok:bool, error?:string, before?:array<string,mixed>, content?:array<string,mixed>, base_hash?:string}
     * @throws \App\Services\AI\AIException si falla la llamada al modelo
     */
    public static function propose(int $siteId, int $formId, string $instruction): array
    {
        $form = FormStore::find($siteId, $formId);
        if ($form === null) {
            return ['ok' => false, 'error' => __('asst.err.form_gone')];
        }

        $lang = FormI18n::baseLanguage($form);
        $current = [];
        foreach (self::EDITABLE_KEYS as $key) {
            $current[$key] = $form[$key] ?? ($key === 'fields' ? [] : '');
        }
        // i18n-ignore-start: contexto que viaja al prompt.
        $result = AIActionRunner::run(Actions::DESIGN_FORM, [
            'description'   => $instruction,
            'extra_context' => 'Idioma del formulario: ' . LanguageService::promptLabel($lang)
                . "\nEs un formulario que YA EXISTE: aplica solo el cambio pedido y conserva igual todo lo demás (preguntas, etiquetas, opciones y textos que no se mencionan)."
                . "\nFormulario actual:\n" . json_encode($current, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ], $siteId);
        // i18n-ignore-end

        try {
            $draft = FormDefinition::fromAI((array) $result['data']);
        } catch (\InvalidArgumentException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        $merged = self::merge($form, $draft);
        $errors = FormDefinition::contentErrors($merged);
        if ($errors !== []) {
            return ['ok' => false, 'error' => implode(' ', $errors)];
        }
        return ['ok' => true, 'before' => $form, 'content' => $merged, 'base_hash' => self::contentHash($form)];
    }

    /**
     * Guarda una propuesta ya preparada. Si el formulario ha cambiado desde
     * que se preparó (otra persona, otra pestaña), no se pisa: lo que se
     * enseñó en la vista previa ya no sería lo que se guarda.
     *
     * @param array<string,mixed> $content
     * @return array{ok:bool, reply?:string, error?:string}
     */
    public static function commit(int $siteId, int $formId, array $content, string $baseHash, ?int $userId): array
    {
        $form = FormStore::find($siteId, $formId);
        if ($form === null) {
            return ['ok' => false, 'error' => __('asst.err.form_gone')];
        }
        if (!hash_equals(self::contentHash($form), $baseHash)) {
            return ['ok' => false, 'error' => __('asst.err.form_changed_since_preview')];
        }
        unset($content['id']);
        $errors = FormDefinition::contentErrors($content);
        if ($errors !== []) {
            return ['ok' => false, 'error' => implode(' ', $errors)];
        }

        FormStore::snapshot($siteId, $formId, $userId, 'before_ai_edit');
        FormStore::update($siteId, $formId, $content);
        CacheService::flush($siteId);

        return ['ok' => true, 'reply' => self::describe($content, FormI18n::translatedLanguages($form))];
    }

    /** Huella del contenido guardado de un formulario (sin su id). @param array<string,mixed> $form */
    public static function contentHash(array $form): string
    {
        unset($form['id']);
        return hash('sha256', json_encode($form, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    }

    /**
     * Firma la propuesta para que viaje por el navegador hasta «Aplicar» sin
     * poder tocarse: lo que se guarda es exactamente lo que se enseñó.
     *
     * @param array<string,mixed> $content
     */
    public static function sealProposal(int $siteId, int $formId, string $instruction, array $content, string $baseHash, ?int $now = null): string
    {
        $now ??= time();
        $payload = [
            'site_id'     => $siteId,
            'form_id'     => $formId,
            'instruction' => hash('sha256', trim($instruction)),
            'base_hash'   => $baseHash,
            'expires_at'  => $now + self::PROPOSAL_TTL,
            'content'     => $content,
        ];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $body = self::b64(gzdeflate($json, 6) ?: '');
        $token = self::PROPOSAL_VERSION . '.' . $body . '.'
            . self::b64(hash_hmac('sha256', self::PROPOSAL_VERSION . '.' . $body, self::proposalKey(), true));
        if (strlen($token) > self::MAX_PROPOSAL_BYTES) {
            throw new \RuntimeException('El formulario propuesto es demasiado grande.');
        }
        return $token;
    }

    /**
     * Abre una propuesta firmada. Null si no es válida, ha caducado o no
     * corresponde a este sitio, formulario e instrucción.
     *
     * @return array{content:array<string,mixed>, base_hash:string}|null
     */
    public static function openProposal(string $token, int $siteId, int $formId, string $instruction, ?int $now = null): ?array
    {
        $now ??= time();
        if ($token === '' || strlen($token) > self::MAX_PROPOSAL_BYTES) return null;
        $parts = explode('.', $token);
        if (count($parts) !== 3 || $parts[0] !== self::PROPOSAL_VERSION) return null;
        $expected = self::b64(hash_hmac('sha256', self::PROPOSAL_VERSION . '.' . $parts[1], self::proposalKey(), true));
        if (!hash_equals($expected, $parts[2])) return null;
        $raw = base64_decode(strtr($parts[1], '-_', '+/'), true);
        $json = $raw === false ? false : @gzinflate($raw, 400000);
        $payload = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($payload)
            || (int) ($payload['site_id'] ?? 0) !== $siteId
            || (int) ($payload['form_id'] ?? 0) !== $formId
            || !hash_equals((string) ($payload['instruction'] ?? ''), hash('sha256', trim($instruction)))
            || (int) ($payload['expires_at'] ?? 0) < $now
            || !is_array($payload['content'] ?? null)) {
            return null;
        }
        return ['content' => $payload['content'], 'base_hash' => (string) ($payload['base_hash'] ?? '')];
    }

    /**
     * Lo que enseña la tarjeta: el formulario antes y después, en ligero, y la
     * lista de cambios pregunta a pregunta.
     *
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     * @return array{before:array<string,mixed>, after:array<string,mixed>, changes:array<int,array<string,string>>}
     */
    public static function preview(array $before, array $after): array
    {
        return [
            'before'  => self::outline($before),
            'after'   => self::outline($after),
            'changes' => self::diff($before, $after),
        ];
    }

    /** @param array<string,mixed> $form @return array<string,mixed> */
    public static function outline(array $form): array
    {
        $fields = [];
        foreach ((array) ($form['fields'] ?? []) as $field) {
            if (!is_array($field)) continue;
            $options = $field['options'] ?? [];
            if (is_string($options)) $options = preg_split('/\R/', $options) ?: [];
            $fields[] = [
                'name'     => (string) ($field['name'] ?? ''),
                'label'    => (string) ($field['label'] ?? ''),
                'type'     => (string) ($field['field_type'] ?? 'text'),
                'required' => in_array($field['required'] ?? '0', [true, '1', 1], true),
                'options'  => array_values(array_filter(array_map(static fn ($o): string => trim((string) $o), (array) $options), 'strlen')),
            ];
        }
        return [
            'heading'     => (string) ($form['heading'] ?? ''),
            'description' => (string) ($form['description'] ?? ''),
            'submit_text' => (string) ($form['submit_text'] ?? ''),
            'fields'      => $fields,
        ];
    }

    /**
     * Cambios pregunta a pregunta. Las preguntas se emparejan por `name`, que
     * merge() conserva cuando la etiqueta no cambia.
     *
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     * @return array<int,array{kind:string,label:string,detail:string}>
     */
    public static function diff(array $before, array $after): array
    {
        $old = self::outline($before);
        $new = self::outline($after);
        $changes = [];

        foreach (['heading' => 'asst.diff.heading', 'description' => 'asst.diff.description', 'submit_text' => 'asst.diff.submit'] as $key => $labelKey) {
            if (trim($old[$key]) !== trim($new[$key])) {
                $changes[] = ['kind' => 'text', 'label' => __($labelKey), 'detail' => $new[$key] !== '' ? $new[$key] : __('asst.diff.empty')];
            }
        }

        $oldByName = [];
        foreach ($old['fields'] as $field) $oldByName[$field['name']] = $field;
        $newNames = [];
        foreach ($new['fields'] as $field) {
            $newNames[$field['name']] = true;
            $prev = $oldByName[$field['name']] ?? null;
            if ($prev === null) {
                $changes[] = ['kind' => 'added', 'label' => $field['label'], 'detail' => self::fieldDetail($field)];
                continue;
            }
            $parts = [];
            if ($prev['label'] !== $field['label']) {
                $parts[] = __('asst.diff.renamed', ['antes' => $prev['label']]);
            }
            if ($prev['type'] !== $field['type']) {
                $parts[] = __('asst.diff.type', ['tipo' => self::typeLabel($field['type'])]);
            }
            if ($prev['required'] !== $field['required']) {
                $parts[] = __($field['required'] ? 'asst.diff.now_required' : 'asst.diff.now_optional');
            }
            if ($prev['options'] !== $field['options'] && $field['options'] !== []) {
                $parts[] = __('asst.diff.options', ['n' => (string) count($field['options'])]);
            }
            if ($parts !== []) {
                $changes[] = ['kind' => 'changed', 'label' => $field['label'], 'detail' => implode(' · ', $parts)];
            }
        }
        $removed = array_values(array_filter($old['fields'], static fn (array $f): bool => !isset($newNames[$f['name']])));
        // Muchas preguntas que se van (p. ej. 9 casillas que pasan a un
        // desplegable) se leen mejor en una sola línea.
        if (count($removed) > self::GROUP_AFTER) {
            $changes[] = [
                'kind'   => 'removed',
                'label'  => __('asst.diff.removed_n', ['n' => (string) count($removed)]),
                'detail' => implode(', ', array_column($removed, 'label')),
            ];
        } else {
            foreach ($removed as $field) {
                $changes[] = ['kind' => 'removed', 'label' => $field['label'], 'detail' => self::typeLabel($field['type'])];
            }
        }
        return $changes;
    }

    /** @param array<string,mixed> $field */
    private static function fieldDetail(array $field): string
    {
        $detail = self::typeLabel((string) $field['type']);
        if ($field['options'] !== []) {
            $detail .= ' · ' . __('asst.diff.options', ['n' => (string) count($field['options'])]);
        }
        if ($field['required']) {
            $detail .= ' · ' . __('asst.diff.required');
        }
        return $detail;
    }

    private static function typeLabel(string $type): string
    {
        $key = 'asst.ftype.' . $type;
        $label = __($key);
        return $label !== $key ? $label : $type;
    }

    private static function proposalKey(): string
    {
        $appKey = (string) (\Core\App::config()['app_key'] ?? '');
        if ($appKey === '') throw new \RuntimeException('Assistant requiere app_key en config.php.');
        return hash_hmac('sha256', 'assistant-form-proposal-v1', $appKey, true);
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * Sustituye solo lo editable del formulario por el borrador. Las preguntas
     * que conservan su etiqueta conservan también su `name`, para que las
     * respuestas ya recibidas y sus traducciones sigan asociadas.
     *
     * @param array<string,mixed> $form  content actual (con id)
     * @param array<string,mixed> $draft salida de FormDefinition::fromAI()
     * @return array<string,mixed> content listo para FormStore::update()
     */
    public static function merge(array $form, array $draft): array
    {
        $oldFields = array_values(array_filter((array) ($form['fields'] ?? []), 'is_array'));
        $oldByLabel = [];
        foreach ($oldFields as $field) {
            $key = mb_strtolower(trim((string) ($field['label'] ?? '')));
            if ($key !== '' && !isset($oldByLabel[$key])) {
                $oldByLabel[$key] = (string) ($field['name'] ?? '');
            }
        }

        $fields = [];
        $used = [];
        foreach ((array) ($draft['fields'] ?? []) as $field) {
            $kept = $oldByLabel[mb_strtolower(trim((string) $field['label']))] ?? '';
            if ($kept !== '' && !in_array($kept, $used, true)) {
                $field['name'] = $kept;
            }
            // Un name nuevo no puede pisar el de otra pregunta conservada.
            $base = (string) $field['name'];
            for ($n = 2; in_array($field['name'], $used, true); $n++) {
                $field['name'] = $base . '_' . $n;
            }
            $used[] = (string) $field['name'];
            $fields[] = $field;
        }

        $content = $form;
        unset($content['id']);
        foreach (self::EDITABLE_KEYS as $key) {
            if ($key !== 'fields') {
                $content[$key] = (string) ($draft[$key] ?? '');
            }
        }
        $content['submit_text'] = $content['submit_text'] !== '' ? $content['submit_text'] : 'Enviar';
        $content['fields'] = $fields;

        if (is_array($form['i18n'] ?? null) && $form['i18n'] !== []) {
            $content['i18n'] = self::pruneTranslations($form, $content);
        }
        return $content;
    }

    /**
     * Quita las traducciones que ya no corresponden al texto base: el visitante
     * de otro idioma verá ese texto en el idioma base hasta que se traduzca, en
     * vez de una traducción de la pregunta antigua (las opciones de un
     * desplegable se traducen por posición y mentirían).
     *
     * @param array<string,mixed> $old
     * @param array<string,mixed> $new
     * @return array<string,mixed>
     */
    private static function pruneTranslations(array $old, array $new): array
    {
        $oldFields = [];
        foreach ((array) ($old['fields'] ?? []) as $field) {
            if (is_array($field)) $oldFields[(string) ($field['name'] ?? '')] = $field;
        }
        $newFields = [];
        foreach ((array) $new['fields'] as $field) {
            $newFields[(string) $field['name']] = $field;
        }

        $out = [];
        foreach ((array) $old['i18n'] as $lang => $tr) {
            if (!is_array($tr)) continue;
            foreach (FormI18n::TEXT_KEYS as $key) {
                if (trim((string) ($old[$key] ?? '')) !== trim((string) ($new[$key] ?? ''))) {
                    unset($tr[$key]);
                }
            }
            $fieldTr = is_array($tr['fields'] ?? null) ? $tr['fields'] : [];
            foreach (array_keys($fieldTr) as $name) {
                $before = $oldFields[$name] ?? null;
                $after = $newFields[$name] ?? null;
                if ($before === null || $after === null
                    || (string) ($before['label'] ?? '') !== (string) ($after['label'] ?? '')
                    || (string) ($before['placeholder'] ?? '') !== (string) ($after['placeholder'] ?? '')
                    || array_values((array) ($before['options'] ?? [])) !== array_values((array) ($after['options'] ?? []))) {
                    unset($fieldTr[$name]);
                }
            }
            $tr['fields'] = $fieldTr;
            $out[$lang] = $tr;
        }
        return $out;
    }

    /** Resumen para el informe del asistente. @param string[] $translated */
    private static function describe(array $content, array $translated): string
    {
        $labels = array_map(static fn (array $f): string => (string) $f['label'], (array) $content['fields']);
        $reply = __('asst.form.updated', [
            'formulario' => (string) $content['heading'],
            'preguntas'  => implode(', ', $labels),
        ]);
        if ($translated !== []) {
            $reply .= ' ' . __('asst.form.review_translations', ['idiomas' => implode(', ', $translated)]);
        }
        return $reply;
    }
}
