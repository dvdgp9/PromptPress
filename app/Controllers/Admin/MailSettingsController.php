<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\Mail\MailMessage;
use App\Services\Mail\MailService;
use App\Services\Mail\MailSettings;
use Core\App;
use Core\Auth;
use Core\CSRF;
use Core\Crypto;
use Core\Database;
use Core\Request;
use Core\Response;
use Core\Session;
use Core\View;

/**
 * Ajustes · Correo (EMAIL E4).
 *
 * Configura el envío de correo del sitio (SMTP del buzón del usuario) con una
 * UX comprensible: sin jerga, con autorrelleno por proveedor (E4b) y botón de
 * prueba real. Las credenciales se guardan en `settings` (contraseña cifrada).
 */
class MailSettingsController
{
    private const ENCRYPTIONS = ['tls', 'ssl', 'none'];

    public function index(): void
    {
        $siteId = $this->requireSiteId();
        $this->render($siteId, [], Session::flash('notice'), Session::flash('error'));
    }

    public function update(): void
    {
        CSRF::check();
        $siteId = $this->requireSiteId();

        $input = $this->collectInput();
        $errors = $this->validate($input);
        if ($errors !== []) {
            $this->render($siteId, $errors, null, null, $input);
            return;
        }

        $this->persist($siteId, $input);

        // Si pidió probar al guardar, hacemos un envío real de verificación.
        if (Request::post('test_on_save', '') === '1') {
            $result = $this->sendTestEmail($siteId, $input['from_email']);
            if ($result->ok) {
                Session::flash('notice', 'Ajustes guardados y correo de prueba enviado a ' . $input['from_email'] . '. Revisa tu bandeja (y la carpeta de spam).');
            } else {
                Session::flash('error', 'Ajustes guardados, pero el correo de prueba falló: ' . $result->error);
            }
            Response::redirect(base_url('admin/settings/mail'));
            return;
        }

        Session::flash('notice', 'Ajustes de correo guardados.');
        Response::redirect(base_url('admin/settings/mail'));
    }

    /** Envío de prueba bajo demanda (botón "Enviar correo de prueba"). */
    public function test(): void
    {
        CSRF::check();
        $siteId = $this->requireSiteId();

        if (!MailService::isConfigured($siteId)) {
            Session::flash('error', 'Configura y guarda primero los datos del correo.');
            Response::redirect(base_url('admin/settings/mail'));
            return;
        }

        $config = MailSettings::forSite($siteId);
        $to = trim((string) Request::post('test_to', ''));
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $to = $config['from_email'];
        }

        $result = $this->sendTestEmail($siteId, $to);
        if ($result->ok) {
            Session::flash('notice', 'Correo de prueba enviado a ' . $to . '. Revisa tu bandeja (y la carpeta de spam).');
        } else {
            Session::flash('error', 'No se pudo enviar el correo de prueba: ' . $result->error);
        }
        Response::redirect(base_url('admin/settings/mail'));
    }

    // ======================================================================
    // Helpers
    // ======================================================================

    private function sendTestEmail(int $siteId, string $to): \App\Services\Mail\MailResult
    {
        $site = Database::selectOne('SELECT name FROM sites WHERE id = ? LIMIT 1', [$siteId]);
        $siteName = (string) ($site['name'] ?? 'tu sitio');

        $text = "¡Funciona! 🎉\n\n"
            . "Este es un correo de prueba enviado desde PromptPress para «{$siteName}».\n"
            . "Si lo estás leyendo, el envío de correo está bien configurado.\n\n"
            . 'Fecha: ' . date('Y-m-d H:i:s');
        $html = '<div style="font-family:system-ui,Arial,sans-serif;font-size:15px;color:#1f2937;line-height:1.6">'
            . '<p style="font-size:18px;font-weight:700;margin:0 0 12px">¡Funciona! 🎉</p>'
            . '<p>Este es un correo de prueba enviado desde PromptPress para <strong>' . e($siteName) . '</strong>.</p>'
            . '<p>Si lo estás leyendo, el envío de correo está bien configurado.</p>'
            . '<p style="color:#6b7280;font-size:13px;margin-top:20px">Fecha: ' . date('Y-m-d H:i:s') . '</p>'
            . '</div>';

        $message = new MailMessage($to, 'Prueba de correo · PromptPress', $text, $html);
        return MailService::send($siteId, $message, 'test');
    }

    /** @return array<string,string> */
    private function collectInput(): array
    {
        return [
            'from_email' => trim((string) Request::post('from_email', '')),
            'from_name'  => trim((string) Request::post('from_name', '')),
            'host'       => trim((string) Request::post('host', '')),
            'port'       => trim((string) Request::post('port', '')),
            'encryption' => (string) Request::post('encryption', 'tls'),
            'user'       => trim((string) Request::post('user', '')),
            'pass'       => (string) Request::post('pass', ''),
        ];
    }

    /** @param array<string,string> $input @return array<int,string> */
    private function validate(array $input): array
    {
        $errors = [];
        if ($input['from_email'] === '' || !filter_var($input['from_email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'La dirección de remitente debe ser un email válido (ej. info@tudominio.com).';
        }
        if ($input['host'] === '') {
            $errors[] = 'Falta el servidor de correo saliente.';
        }
        $port = (int) $input['port'];
        if ($port < 1 || $port > 65535) {
            $errors[] = 'El puerto debe ser un número entre 1 y 65535 (lo habitual es 587 o 465).';
        }
        if (!in_array($input['encryption'], self::ENCRYPTIONS, true)) {
            $errors[] = 'Tipo de cifrado no válido.';
        }
        return $errors;
    }

    /** @param array<string,string> $input */
    private function persist(int $siteId, array $input): void
    {
        $this->saveSetting($siteId, MailSettings::KEY_TRANSPORT, 'smtp', false);
        $this->saveSetting($siteId, MailSettings::KEY_FROM_EMAIL, $input['from_email'], false);
        $this->saveSetting($siteId, MailSettings::KEY_FROM_NAME, $input['from_name'], false);
        $this->saveSetting($siteId, MailSettings::KEY_SMTP_HOST, $input['host'], false);
        $this->saveSetting($siteId, MailSettings::KEY_SMTP_PORT, (string) (int) $input['port'], false);
        $this->saveSetting($siteId, MailSettings::KEY_SMTP_ENC, $input['encryption'], false);
        $this->saveSetting($siteId, MailSettings::KEY_SMTP_USER, $input['user'], false);

        // Contraseña: si llega vacía, se conserva la guardada (no se borra).
        if ($input['pass'] !== '') {
            $appKey = (string) (App::config()['app_key'] ?? '');
            if ($appKey === '') {
                Session::flash('error', 'No se pudo cifrar la contraseña: falta app_key en la configuración.');
                return;
            }
            $encrypted = Crypto::encrypt($input['pass'], $appKey);
            $this->saveSetting($siteId, MailSettings::KEY_SMTP_PASS, $encrypted, true);
        }
    }

    private function saveSetting(int $siteId, string $key, string $value, bool $encrypted): void
    {
        Database::execute(
            'INSERT INTO settings (site_id, setting_key, setting_value, is_encrypted)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), is_encrypted = VALUES(is_encrypted)',
            [$siteId, $key, $value, $encrypted ? 1 : 0]
        );
    }

    /**
     * @param array<int,string> $errors
     * @param array<string,string> $overrides valores del formulario (tras error de validación)
     */
    private function render(int $siteId, array $errors, ?string $notice, ?string $error, array $overrides = []): void
    {
        $config = MailSettings::forSite($siteId);
        $hasPassword = $this->hasStoredPassword($siteId);

        // Si venimos de un error de validación, repintar lo que el usuario escribió.
        $form = [
            'from_email' => $overrides['from_email'] ?? $config['from_email'],
            'from_name'  => $overrides['from_name']  ?? $config['from_name'],
            'host'       => $overrides['host']        ?? $config['host'],
            'port'       => $overrides['port']        ?? ($config['port'] > 0 ? (string) $config['port'] : ''),
            'encryption' => $overrides['encryption']  ?? $config['encryption'],
            'user'       => $overrides['user']        ?? $config['user'],
        ];

        View::send('admin/settings/mail', array_merge(
            DashboardController::getCommonData(),
            [
                'form'         => $form,
                'configured'   => MailService::isConfigured($siteId),
                'has_password' => $hasPassword,
                'recent_log'   => $this->recentLog($siteId),
                'errors'       => $errors,
                'notice'       => $notice,
                'error'        => $error,
                'csrf'         => CSRF::token(),
            ]
        ));
    }

    private function hasStoredPassword(int $siteId): bool
    {
        $row = Database::selectOne(
            'SELECT setting_value FROM settings WHERE site_id = ? AND setting_key = ?',
            [$siteId, MailSettings::KEY_SMTP_PASS]
        );
        return $row !== null && trim((string) $row['setting_value']) !== '';
    }

    /** @return array<int,array<string,mixed>> */
    private function recentLog(int $siteId): array
    {
        MailService::ensureSchema();
        return Database::select(
            'SELECT recipient, subject, status, context, error, created_at
             FROM email_log WHERE site_id = ? ORDER BY id DESC LIMIT 5',
            [$siteId]
        );
    }

    private function requireSiteId(): int
    {
        $siteId = Auth::siteId();
        if ($siteId === null) {
            Session::flash('error', 'No hay sitio activo.');
            Response::redirect(base_url('admin/'));
        }
        return $siteId;
    }
}
