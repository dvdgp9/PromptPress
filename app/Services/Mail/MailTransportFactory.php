<?php

declare(strict_types=1);

namespace App\Services\Mail;

/**
 * Construye el transporte de correo de un sitio según sus ajustes.
 * Hoy solo SMTP; mañana, con añadir un `case`, entra un transporte por API.
 */
final class MailTransportFactory
{
    public static function forSite(int $siteId): ?MailTransportInterface
    {
        $config = MailSettings::forSite($siteId);
        if ($config['host'] === '' || $config['from_email'] === '') {
            return null; // sin configurar
        }

        $transport = $config['transport'] !== '' ? $config['transport'] : 'smtp';
        switch ($transport) {
            case 'smtp':
            default:
                return new SmtpTransport($config);
        }
    }
}
