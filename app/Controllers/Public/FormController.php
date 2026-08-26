<?php

declare(strict_types=1);

namespace App\Controllers\Public;

use App\Services\FormI18n;
use App\Services\FormSubmissionService;
use App\Services\LanguageService;
use App\Services\Microcopy;
use App\Services\Security\BotGuard;
use App\Services\Mail\MailMessage;
use App\Services\Mail\MailService;
use App\Modules\Resources\ResourceAccessService;
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
                Response::json([
                    'ok' => false,
                    'status' => 'not_found',
                    'message' => Microcopy::t('form.not_found', LanguageService::DEFAULT),
                ], 404);
            }
            Response::notFound('Formulario no encontrado');
        }

        $target = $this->redirectTarget($sectionId);
        $content = json_decode((string) ($section['content'] ?? '{}'), true);
        $content = is_array($content) ? $content : [];
        $formSiteId = (int) ($section['site_id'] ?? 0);
        // FORMS-LANG T2 — idioma de la PÁGINA desde la que se envió (hidden
        // `_lang`), validado contra los idiomas activos del sitio. Un `_lang`
        // manipulado no es un riesgo, pero tampoco vale para elegir textos.
        $lang = $this->submissionLanguage($formSiteId);
        // El mensaje de éxito sale en el idioma de la página desde la que se
        // envió, si el formulario tiene traducción para él (FORMS-LANG T6).
        $localized = FormI18n::resolve($content, $lang);
        $successMessage = trim((string) ($localized['success_message'] ?? ''))
            ?: Microcopy::t('form.success', $lang);
        if (!CSRF::validate(is_string(Request::post('_csrf')) ? Request::post('_csrf') : null)) {
            $this->respond($target, $sectionId, 'error', Microcopy::t('form.session_expired', $lang), 419);
        }

        if (trim((string) Request::post('company_url', '')) !== '') {
            $this->respond($target, $sectionId, 'ok', $successMessage);
        }

        // FEAT-4 AB1 — time-trap: envío demasiado rápido o timestamp ausente/
        // manipulado → bot: mismo "ok" falso del honeypot, sin crear nada.
        // Caducado (>6 h) → humano con la pestaña vieja: error amable.
        $ts = Request::post('_pp_ts');
        $tsCheck = BotGuard::verifyTimestamp(is_string($ts) ? $ts : null);
        if ($tsCheck === BotGuard::TOO_FAST || $tsCheck === BotGuard::INVALID) {
            $this->respond($target, $sectionId, 'ok', $successMessage);
        }
        if ($tsCheck === BotGuard::EXPIRED) {
            $this->respond(
                $target,
                $sectionId,
                'error',
                Microcopy::t('form.expired_page', $lang),
                422
            );
        }

        // FEAT-4 AB4 — proof-of-work. Presente y válido → 'pow'. Presente pero
        // inválido o reutilizado → bot: "ok" falso. Ausente o caducado →
        // degradación confirmada: se acepta con las capas base y queda
        // auditado como 'timetrap' (el hidden lo pone JS; sin JS no llega).
        $pow = Request::post('_pp_pow');
        $botCheck = 'timetrap';
        if (is_string($pow) && $pow !== '') {
            $powCheck = BotGuard::verifySolution($pow);
            if ($powCheck === BotGuard::POW_INVALID || $powCheck === BotGuard::POW_REPLAY) {
                $this->respond($target, $sectionId, 'ok', $successMessage);
            }
            if ($powCheck === BotGuard::POW_OK) {
                $botCheck = 'pow';
            }
        }

        $ipHash = FormSubmissionService::ipHash(Request::ip());
        if (FormSubmissionService::isRateLimited($sectionId, $ipHash)) {
            $this->respond(
                $target,
                $sectionId,
                'rate_limited',
                Microcopy::t('form.rate_limited', $lang),
                429
            );
        }

        // Las etiquetas localizadas: si un campo falla, el aviso nombra el campo
        // como lo ve el visitante, no como está en el idioma base.
        $fields = is_array($localized['fields'] ?? null) ? $localized['fields'] : [];
        [$payload, $errors, $sender] = $this->collectPayload($fields, (int) $section['site_id'], $sectionId);

        if ($errors !== []) {
            FormSubmissionService::deleteFilesFromPayload($payload);
            $detail = Microcopy::t('form.check_fields', $lang, [
                'fields' => implode(', ', array_slice($errors, 0, 2)),
            ]);
            if (!$this->wantsJson()) Session::flash('form_error_' . $sectionId, $detail);
            $this->respond($target, $sectionId, 'error', $detail, 422);
        }

        // E5 — notificación por correo vía MailService (SMTP configurable),
        // en sustitución del antiguo @mail(). Si el correo no está configurado,
        // se marca 'skipped' (la respuesta queda guardada igualmente).
        $siteId = (int) $section['site_id'];
        $originPage = $this->resolveOriginPage($siteId, $target, $section);
        $arEnabled = (string) ($content['autoresponder_enabled'] ?? '0') === '1';
        $visitorEmail = (string) ($sender['email'] ?? '');
        $arStatus = $arEnabled ? 'skipped' : 'disabled';
        // El motivo del fallo se guarda y se LEE EN EL PANEL (bandeja de Mensajes),
        // así que va en el idioma del panel, no en el de la página del visitante.
        $arError = $arEnabled ? __('ar.err.not_sent') : null;
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
                 sender_name, sender_email, sender_phone, payload, ip_hash, user_agent, bot_check,
                 status, email_status, email_error, autoresponder_status, autoresponder_error, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                (int) $section['site_id'],
                (int) $originPage['id'],
                $sectionId,
                (string) $originPage['title'],
                (string) ($content['heading'] ?? 'Formulario'),
                $sender['name'] ?: null,
                $sender['email'] ?: null,
                $sender['phone'] ?: null,
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $ipHash,
                mb_substr(Request::userAgent(), 0, 500),
                $botCheck,
                'unread',
                $emailStatus,
                $emailError,
                $arStatus,
                $arError,
                date('Y-m-d H:i:s'),
            ]
        );
        $submissionId = (int) Database::lastInsertId();

        // FEAT-3 A6 — conversión form_submit en la analítica propia. Server-side
        // (los POST de formulario no se cachean). EventRecorder ya es tolerante
        // a fallos; el try/catch cubre además cualquier error de carga del módulo.
        try {
            if (\App\Modules\ModuleRegistry::isEnabled($siteId, 'analytics')) {
                $slug = trim((string) ($originPage['slug'] ?? ''), '/');
                \App\Modules\Analytics\EventRecorder::record(
                    $siteId,
                    'form_submit',
                    $slug === '' ? '/' : '/' . $slug,
                    null,
                    Request::ip(),
                    Request::userAgent()
                );
            }
        } catch (\Throwable $e) {
            // La analítica jamás debe romper un envío de formulario.
        }

        // FORMS F6 — autorrespuesta al visitante (absorbe E6). Texto fijo del
        // formulario con placeholders {{nombre}}/{{sitio}} sustituidos (sin IA).
        if ($arEnabled && $visitorEmail === '') {
            $arError = __('ar.err.no_visitor_email');
        } elseif ($arEnabled && !MailService::isConfigured($siteId)) {
            $arError = __('ar.err.mail_not_configured');
        } elseif ($arEnabled) {
            $site = Database::selectOne('SELECT name FROM sites WHERE id = ? LIMIT 1', [$siteId]);
            $repl = [
                '{{nombre}}' => (string) ($sender['name'] ?? ''),
                '{{sitio}}'  => (string) ($site['name'] ?? ''),
            ];
            // El correo va al VISITANTE: en el idioma de la página que usó.
            $fallbackSubject = Microcopy::t('form.tpl.autoresponder_subject', $lang);
            $subject = strtr((string) ($localized['autoresponder_subject'] ?? $fallbackSubject), $repl);
            $bodyText = strtr((string) ($localized['autoresponder_body'] ?? ''), $repl);
            if (trim($bodyText) !== '') {
                $arMsg = new MailMessage($visitorEmail, $subject !== '' ? $subject : $fallbackSubject, $bodyText);
                $arResult = MailService::send($siteId, $arMsg, 'autoresponder');
                $arStatus = $arResult->ok ? 'sent' : 'failed';
                $arError = $arResult->ok ? null : mb_substr((string) $arResult->error, 0, 500);
            } else {
                $arError = __('ar.err.empty_message');
            }
        }
        if ($arEnabled && $arStatus === 'skipped' && $arError === null) {
            $arError = __('ar.err.not_sent');
        }
        Database::execute(
            'UPDATE form_submissions SET autoresponder_status = ?, autoresponder_error = ? WHERE id = ?',
            [$arStatus, $arError, $submissionId]
        );

        $resourceContext = Request::post('_resource_context');
        $downloadUrl = is_string($resourceContext) && $resourceContext !== ''
            ? ResourceAccessService::downloadUrlForSubmission(
                $resourceContext,
                $siteId,
                $sectionId,
                $submissionId,
                $lang
            )
            : null;
        $this->respond(
            $target,
            $sectionId,
            'ok',
            $successMessage,
            200,
            $submissionId,
            $downloadUrl,
            $downloadUrl !== null ? Microcopy::t('resources.download_ready', $lang) : null
        );
    }

    /**
     * FORMS-LANG T2 — idioma con el que se pintó el formulario que se envía.
     *
     * Viene del hidden `_lang` y solo se acepta si es un idioma ACTIVO del
     * sitio; cualquier otra cosa cae al idioma del sitio. Así el visitante de
     * `/fr/contact` recibe la confirmación en francés aunque el sitio tenga el
     * castellano como principal.
     */
    private function submissionLanguage(int $siteId): string
    {
        $posted = Request::post('_lang');
        $posted = is_string($posted) ? strtolower(trim($posted)) : '';
        if ($posted !== '' && in_array($posted, LanguageService::activeFor($siteId), true)) {
            return $posted;
        }
        return LanguageService::codeFor($siteId);
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
        ?int $submissionId = null,
        ?string $downloadUrl = null,
        ?string $downloadLabel = null
    ): never {
        if ($this->wantsJson()) {
            $payload = [
                'ok' => $status === 'ok',
                'status' => $status,
                'message' => $message,
                'section_id' => $sectionId,
            ];
            if ($submissionId !== null) $payload['submission_id'] = $submissionId;
            if ($downloadUrl !== null) {
                $payload['download_url'] = $downloadUrl;
                $payload['download_label'] = $downloadLabel ?? '';
            }
            Response::json($payload, $httpStatus);
        }

        if ($downloadUrl !== null) Response::redirect($downloadUrl);

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
                    OR EXISTS (
                        SELECT 1 FROM resources rr
                        JOIN settings rst ON rst.site_id = rr.site_id
                          AND rst.setting_key = 'module_resources_enabled'
                          AND rst.setting_value = '1'
                        WHERE rr.form_id = s.id AND rr.status = 'published'
                          AND rr.access_mode = 'form'
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
                    // i18n-ignore: error de subida que ve el VISITANTE. Su idioma es el de
                    // la web, no el del panel: su sitio es Microcopy (ver nota en
                    // FormSubmissionService). Gap preexistente, fuera de ADMIN-I18N.
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
            // i18n-ignore: no es interfaz, es el dato que queda guardado en la
            // respuesta. Traducirlo lo congelaría en el idioma del momento.
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

    /** Resuelve la pagina publica desde la ruta de retorno del formulario. */
    private function resolveOriginPage(int $siteId, string $target, array $fallback): array
    {
        $path = '/' . ltrim((string) (parse_url($target, PHP_URL_PATH) ?: '/'), '/');
        if ($path === '/') {
            $page = Database::selectOne(
                "SELECT id, title, slug FROM pages WHERE site_id = ? AND page_type = 'home' AND status = 'published' ORDER BY id ASC LIMIT 1",
                [$siteId]
            );
        } else {
            $page = Database::selectOne(
                'SELECT id, title, slug FROM pages WHERE site_id = ? AND slug = ? AND status = ? LIMIT 1',
                [$siteId, trim($path, '/'), 'published']
            );
        }
        return $page ?? [
            'id' => (int) ($fallback['page_id'] ?? 0),
            'title' => (string) ($fallback['page_title'] ?? 'Formulario'),
            'slug' => (string) ($fallback['slug'] ?? ''),
        ];
    }
}
