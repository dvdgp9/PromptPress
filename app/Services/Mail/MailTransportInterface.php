<?php

declare(strict_types=1);

namespace App\Services\Mail;

/**
 * Contrato de un transporte de correo (SMTP hoy; API tipo Brevo/Resend mañana).
 *
 * Mismo patrón que `AIProviderInterface`: la app habla con esta interfaz y un
 * factory decide la implementación concreta según los ajustes del sitio. Así,
 * añadir un transporte nuevo es crear una clase, sin tocar el resto.
 */
interface MailTransportInterface
{
    /**
     * Envía el mensaje. Debe lanzar MailException si falla (no devolver false),
     * para que MailService registre el error con un diagnóstico legible.
     *
     * @throws MailException
     */
    public function send(MailMessage $message): void;

    /** Identificador corto del transporte para el log (p. ej. "smtp"). */
    public function name(): string;
}
