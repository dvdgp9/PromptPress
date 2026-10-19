<?php

declare(strict_types=1);

namespace App\Services;

/** Shared contract for AI drafts, the editor and public choice validation. */
final class FormDefinition
{
    public const TYPES = ['text', 'email', 'tel', 'textarea', 'checkbox', 'select', 'radio', 'checkbox_group', 'number', 'date', 'url', 'file'];
    public const CHOICE_TYPES = ['select', 'radio', 'checkbox_group'];
    public const MAX_FIELDS = 50;
    public const MAX_OPTIONS = 30;

    public static function name(string $value): string
    {
        $value = strtr($value, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n','Á'=>'a','É'=>'e','Í'=>'i','Ó'=>'o','Ú'=>'u','Ü'=>'u','Ñ'=>'n']);
        return substr(trim((string) preg_replace('/[^a-z0-9_\-]+/', '_', strtolower($value)), '_-'), 0, 50);
    }

    /** Reject incomplete/unsupported drafts before replacing any editor content. */
    public static function fromAI(array $draft): array
    {
        $out = [];
        foreach (['heading'=>160, 'description'=>500, 'submit_text'=>60, 'success_message'=>240, 'autoresponder_subject'=>200, 'autoresponder_body'=>4000] as $key => $limit) {
            $value = $draft[$key] ?? '';
            if (!is_string($value) || mb_strlen(trim($value)) > $limit) {
                throw new \InvalidArgumentException(__('forms.ai.invalid_output'));
            }
            $out[$key] = trim($value);
        }
        $fields = $draft['fields'] ?? null;
        if ($out['heading'] === '' || !is_array($fields) || count($fields) < 1 || count($fields) > self::MAX_FIELDS) {
            throw new \InvalidArgumentException(__('forms.ai.invalid_output'));
        }
        $out['submit_text'] = $out['submit_text'] ?: 'Enviar';
        $out['fields'] = [];
        $names = [];
        $labels = [];
        foreach (array_values($fields) as $i => $field) {
            if (!is_array($field) || !is_string($field['label'] ?? null) || trim($field['label']) === ''
                || mb_strlen($field['label']) > 120 || !in_array($field['field_type'] ?? null, self::TYPES, true)) {
                throw new \InvalidArgumentException(__('forms.ai.invalid_output'));
            }
            $label = trim($field['label']);
            if (in_array($label, $labels, true)) throw new \InvalidArgumentException(__('forms.ai.duplicate_names'));
            $labels[] = $label;
            $base = self::name($label) ?: 'field_' . ($i + 1);
            // Reserve protocol/honeypot keys even when a question uses that label.
            if (in_array($base, ['company_url', 'fields', 'form_id'], true)) $base = 'field_' . $base;
            $name = $base;
            for ($suffix = 2; in_array($name, $names, true); $suffix++) $name = $base . '_' . $suffix;
            $names[] = $name;
            $placeholder = $field['placeholder'] ?? '';
            if (!is_string($placeholder) || mb_strlen($placeholder) > 160) throw new \InvalidArgumentException(__('forms.ai.invalid_output'));
            $required = $field['required'] ?? false;
            if (!in_array($required, [true, false, '1', '0', 1, 0], true)) throw new \InvalidArgumentException(__('forms.ai.invalid_output'));
            $clean = ['label'=>$label, 'name'=>$name, 'field_type'=>$field['field_type'], 'required'=>in_array($required, [true, '1', 1], true) ? '1' : '0', 'placeholder'=>trim($placeholder)];
            if (in_array($clean['field_type'], self::CHOICE_TYPES, true)) {
                $options = $field['options'] ?? null;
                if (!is_array($options) || count($options) < 1 || count($options) > self::MAX_OPTIONS) throw new \InvalidArgumentException(__('forms.ai.invalid_options'));
                $clean['options'] = [];
                foreach ($options as $option) {
                    if (!is_string($option) || trim($option) === '' || mb_strlen($option) > 120) throw new \InvalidArgumentException(__('forms.ai.invalid_options'));
                    $option = trim($option);
                    if (!in_array($option, $clean['options'], true)) $clean['options'][] = $option;
                }
            }
            if ($clean['field_type'] === 'file') {
                $preset = $field['file_accept'] ?? 'documents';
                if (!in_array($preset, ['documents','images','cv'], true)) throw new \InvalidArgumentException(__('forms.ai.invalid_output'));
                if (isset($field['file_max_mb']) && !is_numeric($field['file_max_mb'])) throw new \InvalidArgumentException(__('forms.ai.invalid_output'));
                $clean['file_accept'] = $preset;
                $clean['file_max_mb'] = max(1, min(10, (int) ($field['file_max_mb'] ?? 5)));
                $clean['file_custom_ext'] = '';
            }
            $out['fields'][] = $clean;
        }
        return $out;
    }

    /** @return array{0:string[],1:bool} Values in configured order; never accept forged options. */
    public static function multipleValues(mixed $raw, array $options, bool $required): array
    {
        if (!is_array($raw) || count($raw) > self::MAX_OPTIONS) return [[], false];
        foreach ($raw as $value) {
            if (!is_string($value) || !in_array($value, $options, true)) return [[], false];
        }
        $values = array_values(array_filter($options, static fn(string $option): bool => in_array($option, $raw, true)));
        return [$values, !$required || $values !== []];
    }
}
