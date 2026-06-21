<?php

declare(strict_types=1);

namespace App\Controllers\Public;

use App\Services\FormSubmissionService;
use App\Services\Mail\MailMessage;
use App\Services\Mail\MailService;
use Core\CSRF;
use Core\Database;
use Core\Request;
use Core\Response;
use Core\Session;

final class FormController
{
    public function submit(array $params = []): void
    {
        FormSubmissionService::ensureSchema();

        $sectionId = (int) ($params['sectionId'] ?? 0);
        $section = $this->findSection($sectionId);
        if ($section === null) {
            if ($this->wantsJson()) {
                Response::json(['ok' => false, 'status' => 'not_found', 'message' => 'Formulario no encontrado.'], 404);
            }
            Response::notFound('Formulario no encontrado');
        }

        $target = $this->redirectTarget($sectionId);
        $content = json_decode((string) ($section['content'] ?? '{}'), true);
        $content = is_array($content) ? $content : [];
        $successMessage = trim((string) ($content['success_message'] ?? '')) ?: 'Gracias, hemos recibido tu mensaje.';
        if (!CSRF::validate(is_string(Request::post('_csrf')) ? Request::post('_csrf') : null)) {
            $this->respond($target, $sectionId, 'error', 'La sesión ha caducado. Recarga la página e inténtalo de nuevo.', 419);
        }

        if (trim((string) Request::post('company_url', '')) !== '') {
            $this->respond($target, $sectionId, 'ok', $successMessage);
        }

        $ipHash = FormSubmissionService::ipHash(Request::ip());
        if (FormSubmissionService::isRateLimited($sectionId, $ipHash)) {
            $this->respond(
                $target,
                $sectionId,
                'rate_limited',
                'Hemos recibido varios envíos seguidos. Espera unos minutos antes de volver a intentarlo.',
                429
            );
        }

        $fields = is_array($content['fields'] ?? null) ? $content['fields'] : [];
        [$payload, $errors, $sender] = $this->collectPayload($fields, (int) $section['site_id'], $sectionId);

        if ($errors !== []) {
            FormSubmissionService::deleteFilesFromPayload($payload);
            $detail = 'Revisa estos campos: ' . implode(', ', array_slice($errors, 0, 2)) . '.';
            if (!$this->wantsJson()) Session::flash('form_error_' . $sectionId, $detail);
            $this->respond($target, $sectionId, 'error', $detail, 422);
        }

        // E5 — notificación por correo vía MailService (SMTP configurable),
        // en sustitución del antiguo @mail(). Si el correo no está configurado,
        // se marca 'skipped' (la respuesta queda guardada igualmente).
        $siteId = (int) $section['site_id'];
        // FORMS F6 — destino del aviso: el del propio formulario si está definido,
        // si no, el del sitio.
        $formNotify = trim((string) ($content['notify_email'] ?? ''));
        $recipient = filter_var($formNotify, FILTER_VALIDATE_EMAIL)
            ? $formNotify
            : FormSubmissionService::recipientForSite($siteId);
        $emailStatus = 'skipped';
        $emailError = null;
        if ($recipient !== null && MailService::isConfigured($siteId)) {
            $subject = 'Nuevo mensaje desde ' . (string) ($section['page_title'] ?? 'PromptPress');
            $body = FormSubmissionService::emailBody([
                'page_title' => (string) ($section['page_title'] ?? ''),
                'section_heading' => (string) ($content['heading'] ?? 'Formulario'),
            ], $payload);
            $message = new MailMessage(
                $recipient,
                $subject,
                $body,
                '',
                '',
                ($sender['email'] ?? '') !== '' ? $sender['email'] : null, // Reply-To: responder va al visitante
                ($sender['name'] ?? '') !== '' ? $sender['name'] : null
            );
            $result = MailService::send($siteId, $message, 'form_submission');
            $emailStatus = $result->ok ? 'sent' : 'failed';
            $emailError = $result->ok ? null : mb_substr((string) $result->error, 0, 500);
        }

        Database::execute(
            'INSERT INTO form_submissions
                (site_id, page_id, section_id, page_title, section_heading,
                 sender_name, sender_email, sender_phone, payload, ip_hash, user_agent,
                 status, email_status, email_error, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                (int) $section['site_id'],
                (int) $section['page_id'],
                $sectionId,
                (string) $section['page_title'],
                (string) ($content['heading'] ?? 'Formulario'),
                $sender['name'] ?: null,
                $sender['email'] ?: null,
                $sender['phone'] ?: null,
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $ipHash,
                mb_substr(Request::userAgent(), 0, 500),
                'unread',
                $emailStatus,
                $emailError,
                date('Y-m-d H:i:s'),
            ]
        );
        $submissionId = (int) Database::lastInsertId();

        // FORMS F6 — autorrespuesta al visitante (absorbe E6). Texto fijo del
        // formulario con placeholders {{nombre}}/{{sitio}} sustituidos (sin IA).
        $arEnabled = (string) ($content['autoresponder_enabled'] ?? '0') === '1';
        $visitorEmail = (string) ($sender['email'] ?? '');
        if ($arEnabled && $visitorEmail !== '' && MailService::isConfigured($siteId)) {
            $site = Database::selectOne('SELECT name FROM sites WHERE id = ? LIMIT 1', [$siteId]);
            $repl = [
                '{{nombre}}' => (string) ($sender['name'] ?? ''),
                '{{sitio}}'  => (string) ($site['name'] ?? ''),
            ];
            $subject = strtr((string) ($content['autoresponder_subject'] ?? 'Hemos recibido tu mensaje'), $repl);
            $bodyText = strtr((string) ($content['autoresponder_body'] ?? ''), $repl);
            if (trim($bodyText) !== '') {
                $arMsg = new MailMessage($visitorEmail, $subject !== '' ? $subject : 'Hemos recibido tu mensaje', $bodyText);
                MailService::send($siteId, $arMsg, 'autoresponder');
            }
        }

        $this->respond($target, $sectionId, 'ok', $successMessage, 200, $submissionId);
    }

    private function wantsJson(): bool
    {
        return stripos((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false
            || strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
    }

    private function respond(
        string $target,
        int $sectionId,
        string $status,
        string $message,
        int $httpStatus = 200,
        ?int $submissionId = null
    ): never {
        if ($this->wantsJson()) {
            $payload = [
                'ok' => $status === 'ok',
                'status' => $status,
                'message' => $message,
                'section_id' => $sectionId,
            ];
            if ($submissionId !== null) $payload['submission_id'] = $submissionId;
            Response::json($payload, $httpStatus);
        }

        Response::redirect(
            $target . '?form_status=' . rawurlencode($status) . '&form_section=' . $sectionId . '#sec-' . $sectionId
        );
    }

    private function findSection(int $sectionId): ?array
    {
        // FH1 — además del caso clásico (página anfitriona publicada), un
        // formulario es enviable si alguna página CANVAS publicada del sitio
        // lo referencia vía placeholder ({{form:id}} o {{form:slug-anfitrión}}).
        return Database::selectOne(
            "SELECT s.*, p.id AS page_id, p.site_id, p.title AS page_title, p.slug
             FROM page_sections s
             JOIN pages p ON p.id = s.page_id
             WHERE s.id = ? AND s.section_type = 'form' AND s.status != 'deleted'
               AND (
                    p.status = 'published'
                    OR EXISTS (
                        SELECT 1 FROM page_canvas pc
                        JOIN pages cp ON cp.id = pc.page_id
                        WHERE cp.site_id = p.site_id AND cp.status = 'published'
                          AND (pc.html LIKE CONCAT('%{{form:', s.id, '}}%')
                               OR pc.html LIKE CONCAT('%{{form:', p.slug, '}}%'))
                    )
               )
             LIMIT 1",
            [$sectionId]
        );
    }

    /**
     * @param array<int,mixed> $fields
     * @return array{0:array<string,string>,1:string[],2:array{name:string,email:string,phone:string}}
     */
    private function collectPayload(array $fields, int $siteId, int $sectionId): array
    {
        $payload = [];
        $errors = [];
        $sender = ['name' => '', 'email' => '', 'phone' => ''];

        foreach ($fields as $idx => $field) {
            if (!is_array($field)) continue;
            $label = trim((string) ($field['label'] ?? $field['name'] ?? 'Campo ' . ($idx + 1)));
            $name = preg_replace('/[^a-zA-Z0-9_\-]/', '_', (string) ($field['name'] ?? 'field_' . $idx)) ?: ('field_' . $idx);
            $type = (string) ($field['field_type'] ?? 'text');
            $required = (string) ($field['required'] ?? '0') === '1';
            if ($type === 'file') {
                $file = Request::file($name);
                if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                    if ($required) {
                        $errors[] = $label;
                    }
                    continue;
                }
                $stored = FormSubmissionService::storeUploadedFile($file, $field, $siteId, $sectionId, $name);
                if (!$stored['ok']) {
                    $errors[] = $label . ': ' . (string) ($stored['error'] ?? 'archivo no válido');
                    continue;
                }
                $payload[$label] = $stored['file'] ?? [];
                continue;
            }
            $value = Request::post($name, '');
            $value = is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value;
            $value = trim(mb_substr($value, 0, 5000));

            if ($required && $value === '') {
                $errors[] = $label;
                continue;
            }
            if ($value !== '' && $type === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $errors[] = $label;
                continue;
            }
            if ($value !== '' && $type === 'url' && !filter_var($value, FILTER_VALIDATE_URL)) {
                $errors[] = $label;
                continue;
            }
            if ($value !== '' && $type === 'number' && !is_numeric($value)) {
                $errors[] = $label;
                continue;
            }
            if ($value !== '' && $type === 'date' && !$this->isValidDate($value)) {
                $errors[] = $label;
                continue;
            }
            if ($value !== '' && $type === 'select') {
                $options = is_array($field['options'] ?? null) ? array_map('strval', $field['options']) : [];
                if ($options !== [] && !in_array($value, $options, true)) {
                    $errors[] = $label;
                    continue;
                }
            }

            if ($value !== '') {
                $payload[$label] = $value;
                $lower = strtolower($name . ' ' . $label);
                if ($sender['email'] === '' && $type === 'email') $sender['email'] = $value;
                if ($sender['phone'] === '' && ($type === 'tel' || str_contains($lower, 'tel'))) $sender['phone'] = $value;
                if ($sender['name'] === '' && (str_contains($lower, 'nombre') || str_contains($lower, 'name'))) $sender['name'] = $value;
            }
        }

        // E-GDPR G5 — recoger consent de marketing si la opción está activa.
        if (Request::post('_marketing_consent', '') === '1') {
            $payload['Consentimiento marketing'] = 'sí';
        }

        return [$payload, $errors, $sender];
    }

    private function isValidDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date instanceof \DateTimeImmutable && $date->format('Y-m-d') === $value;
    }

    private function redirectTarget(int $sectionId): string
    {
        $posted = (string) Request::post('_return', '');
        if ($posted !== '' && str_starts_with($posted, '/') && !str_starts_with($posted, '//')) {
            return strtok($posted, '?') ?: '/';
        }

        $ref = (string) ($_SERVER['HTTP_REFERER'] ?? base_url('/'));
        $parts = parse_url($ref);
        $path = (string) ($parts['path'] ?? '/');
        if ($path === '') $path = '/';
        return $path;
    }
}
