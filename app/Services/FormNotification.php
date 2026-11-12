<?php

declare(strict_types=1);

namespace App\Services;

/** Editable notification templates, shared by delivery and the editor preview. */
final class FormNotification
{
    /** Same renderer as delivery, with example answers and no side effects. */
    public static function preview(array $content, array $meta): array
    {
        $payload = [];
        $sender = ['name' => '', 'email' => '', 'phone' => ''];
        foreach ($content['fields'] ?? [] as $field) {
            $type = (string) ($field['field_type'] ?? 'text');
            $value = __('form.notice.sample_value');
            if ($type === 'email') $value = 'alex@example.com';
            elseif ($type === 'tel') $value = '+34 612 84 79 53';
            elseif ($type === 'number') $value = '2';
            elseif ($type === 'date') $value = date('Y-m-d');
            elseif ($type === 'url') $value = 'https://example.com';
            elseif ($type === 'checkbox') $value = '1';
            elseif ($type === 'file') $value = ['type' => 'file', 'original_name' => 'documento.pdf', 'size' => 24576];
            elseif (!empty($field['options'])) $value = $type === 'checkbox_group' ? array_slice($field['options'], 0, 2) : $field['options'][0];
            $lower = strtolower((string) ($field['name'] ?? '') . ' ' . (string) ($field['label'] ?? ''));
            if ($type === 'text' && (str_contains($lower, 'nombre') || str_contains($lower, 'name'))) $value = 'Alex';
            $payload[(string) $field['label']] = $value;
            if (is_string($value)) {
                if ($type === 'email' && $sender['email'] === '') $sender['email'] = $value;
                if (($type === 'tel' || str_contains($lower, 'tel')) && $sender['phone'] === '') $sender['phone'] = $value;
                if ((str_contains($lower, 'nombre') || str_contains($lower, 'name')) && $sender['name'] === '') $sender['name'] = $value;
            }
        }
        return self::render($content, $meta, $payload, $sender);
    }

    public static function bodyTemplate(array $content): string
    {
        $body = trim((string) ($content['notify_body'] ?? ''));
        if ($body === '') return __('form.notice.default_body');
        // v1 only edited the introduction. Keep its submitted data on upgrade.
        if ((int) ($content['notify_template_version'] ?? 1) < 2 && !str_contains($body, '{{respuestas}}')) {
            $body .= "\n\n{{respuestas}}";
        }
        return $body;
    }

    /** @return array{subject:string,text:string,html:string} */
    public static function render(array $content, array $meta, array $payload, array $sender = []): array
    {
        $formName = trim((string) ($content['heading'] ?? $meta['section_heading'] ?? '')) ?: __('form.mail.form');
        $siteName = trim((string) ($meta['site_name'] ?? '')) ?: $formName;
        $pageName = trim((string) ($meta['page_title'] ?? ''));
        if ($pageName === '' || ($meta['page_slug'] ?? '') === '__forms' || $pageName === 'Formularios (sistema)') {
            $pageName = $formName;
        }
        try {
            $timezone = new \DateTimeZone((string) ($meta['timezone'] ?? date_default_timezone_get()));
        } catch (\Throwable $e) {
            $timezone = new \DateTimeZone('Europe/Madrid');
        }
        $date = (string) ($meta['date'] ?? (new \DateTimeImmutable('now', $timezone))->format('d/m/Y H:i'));
        $tokens = [
            '{{formulario}}' => $formName,
            '{{sitio}}' => $siteName,
            '{{pagina}}' => $pageName,
            '{{fecha}}' => $date,
            '{{nombre}}' => (string) ($sender['name'] ?? ''),
            '{{email}}' => (string) ($sender['email'] ?? ''),
            '{{telefono}}' => (string) ($sender['phone'] ?? ''),
        ];
        $fieldTypes = [];
        foreach (($meta['fields'] ?? $content['fields'] ?? []) as $field) {
            $name = (string) ($field['name'] ?? '');
            $label = (string) ($field['label'] ?? $name);
            if ($name !== '') $tokens['{{campo:' . $name . '}}'] = self::valueText($payload[$label] ?? '');
            $fieldTypes[$label] = (string) ($field['field_type'] ?? 'text');
        }

        $subjectTemplate = trim((string) ($content['notify_subject'] ?? '')) ?: __('form.notice.default_subject');
        // A subject is a single header, even if a visitor's name has line breaks.
        $subject = trim((string) preg_replace('/[\x00-\x1f\x7f]+/', ' ', strtr($subjectTemplate, $tokens)));
        $subject = mb_substr($subject, 0, 200) ?: $formName;

        $textRows = [];
        $htmlRows = '';
        foreach ($payload as $label => $value) {
            $valueText = self::valueText($value);
            $textRows[] = $label . "\n" . $valueText;
            $htmlValue = nl2br(self::escape($valueText), false);
            if (($fieldTypes[$label] ?? '') === 'email' && filter_var($valueText, FILTER_VALIDATE_EMAIL)) {
                $htmlValue = '<a href="mailto:' . self::escape($valueText) . '" style="color:#226654;text-decoration:underline;">' . $htmlValue . '</a>';
            } elseif (($fieldTypes[$label] ?? '') === 'tel' && preg_match('/^\+?[0-9\s().-]{6,30}$/', $valueText)) {
                $htmlValue = '<a href="tel:' . preg_replace('/[^+0-9]/', '', $valueText) . '" style="color:#226654;text-decoration:underline;">' . $htmlValue . '</a>';
            }
            $htmlRows .= '<tr><td style="padding:16px 0;border-bottom:1px solid #e5e7eb;overflow-wrap:anywhere;word-break:break-word;">'
                . '<div style="font-size:13px;line-height:1.5;color:#64748b;margin-bottom:5px;">' . self::escape((string) $label) . '</div>'
                . '<div style="font-size:17px;line-height:1.55;color:#18252e;">' . $htmlValue . '</div></td></tr>';
        }
        $responsesText = implode("\n\n", $textRows);
        $responsesHtml = $htmlRows === '' ? '' : '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin:8px 0 24px;">' . $htmlRows . '</table>';
        $template = self::bodyTemplate($content);
        $text = strtr($template, array_merge($tokens, ['{{respuestas}}' => $responsesText]));

        // Split the template before substitution: submitted values are never
        // interpreted as template syntax or HTML, including a literal token.
        $htmlBody = '';
        foreach (preg_split('/(\{\{respuestas\}\})/', $template, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $part) {
            if ($part === '{{respuestas}}') {
                $htmlBody .= $responsesHtml;
                continue;
            }
            $part = str_replace(["\r\n", "\r"], "\n", strtr($part, $tokens));
            foreach (preg_split('/\n\s*\n/', trim($part)) ?: [] as $paragraph) {
                if ($paragraph !== '') $htmlBody .= '<p style="margin:0 0 20px;font-size:15px;line-height:1.7;color:#425463;overflow-wrap:anywhere;word-break:break-word;">' . nl2br(self::escape($paragraph), false) . '</p>';
            }
        }
        $htmlSite = self::escape($siteName);
        $htmlSubject = self::escape($subject);
        $htmlLang = self::escape(AdminI18n::locale());
        ob_start();
        require PP_VIEWS . '/mail/form-notification.php';
        $html = (string) ob_get_clean();
        return ['subject' => $subject, 'text' => trim($text), 'html' => $html];
    }

    private static function valueText(mixed $value): string
    {
        if (!is_array($value)) return is_scalar($value) ? (string) $value : '';
        if (($value['type'] ?? '') === 'file') {
            $size = FormSubmissionService::formatBytes(max(0, (int) ($value['size'] ?? 0)));
            return (string) ($value['original_name'] ?? __('form.mail.attachment')) . ' (' . $size . ')';
        }
        return implode(', ', array_map(static fn ($item): string => is_scalar($item) ? (string) $item : '', $value));
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
