<?php

declare(strict_types=1);

namespace App\Services\Mail;

use Core\Database;

/**
 * Punto de entrada único para enviar correo desde la plataforma.
 *
 * - Resuelve el transporte del sitio (SMTP hoy) vía MailTransportFactory.
 * - Registra cada intento en `email_log` (enviado/fallido + diagnóstico).
 * - No lanza excepciones al llamante: devuelve un MailResult para que la UI
 *   muestre éxito o un error legible sin romper el flujo (p. ej. el envío de
 *   un formulario nunca debe fallar para el visitante por un fallo de correo).
 */
final class MailService
{
    public static function ensureSchema(): void
    {
        Database::execute(
            "CREATE TABLE IF NOT EXISTS email_log (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                site_id INT UNSIGNED NOT NULL,
                recipient VARCHAR(255) NOT NULL,
                subject VARCHAR(500) NOT NULL,
                transport VARCHAR(40) NOT NULL DEFAULT 'smtp',
                context VARCHAR(40) NOT NULL DEFAULT 'other',
                status ENUM('sent','failed') NOT NULL DEFAULT 'failed',
                error VARCHAR(500) DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_email_log_site_date (site_id, created_at),
                INDEX idx_email_log_status (site_id, status),
                CONSTRAINT fk_email_log_site
                    FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    /** ¿El sitio tiene el correo configurado para poder enviar? */
    public static function isConfigured(int $siteId): bool
    {
        return MailSettings::isConfigured($siteId);
    }

    /**
     * Envía un mensaje y registra el resultado.
     *
     * @param string $context etiqueta para el log: 'form_submission' | 'autoresponder' | 'test' | 'other'
     */
    public static function send(int $siteId, MailMessage $message, string $context = 'other'): MailResult
    {
        $transport = MailTransportFactory::forSite($siteId);
        if ($transport === null) {
            $result = MailResult::failure('El correo no está configurado todavía. Configúralo en Ajustes → Correo.', 'none');
            self::log($siteId, $message, $result, $context);
            return $result;
        }

        try {
            $transport->send($message);
            $result = MailResult::success($transport->name());
        } catch (MailException $e) {
            $result = MailResult::failure($e->getMessage(), $transport->name());
        } catch (\Throwable $e) {
            $result = MailResult::failure('Error inesperado al enviar el correo.', $transport->name());
        }

        self::log($siteId, $message, $result, $context);
        return $result;
    }

    private static function log(int $siteId, MailMessage $message, MailResult $result, string $context): void
    {
        try {
            self::ensureSchema();
            Database::execute(
                'INSERT INTO email_log (site_id, recipient, subject, transport, context, status, error)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [
                    $siteId,
                    mb_substr($message->toEmail, 0, 255),
                    mb_substr($message->subject, 0, 500),
                    $result->transport,
                    $context,
                    $result->ok ? 'sent' : 'failed',
                    $result->ok ? null : mb_substr((string) $result->error, 0, 500),
                ]
            );
        } catch (\Throwable) {
            // El log nunca debe tumbar el envío.
        }
    }
}
