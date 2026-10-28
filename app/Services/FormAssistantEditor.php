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

    /**
     * @return array{ok:bool, reply?:string, error?:string}
     * @throws \App\Services\AI\AIException si falla la llamada al modelo
     */
    public static function apply(int $siteId, int $formId, string $instruction, ?int $userId): array
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

        FormStore::snapshot($siteId, $formId, $userId, 'before_ai_edit');
        FormStore::update($siteId, $formId, $merged);
        CacheService::flush($siteId);

        return ['ok' => true, 'reply' => self::describe($merged, FormI18n::translatedLanguages($form))];
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
