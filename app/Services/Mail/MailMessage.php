<?php

declare(strict_types=1);

namespace App\Services\Mail;

/**
 * MailMessage — un correo a enviar, independiente del transporte.
 *
 * El remitente (from) NO vive aquí: lo decide el transporte a partir de la
 * configuración del sitio, para que un mismo mensaje pueda enviarse por
 * cualquier transporte sin reconfigurar el remitente en cada llamada.
 */
final class MailMessage
{
    public string $toEmail;
    public string $toName;
    public string $subject;
    /** Cuerpo en texto plano (siempre presente: fallback para clientes sin HTML). */
    public string $text;
    /** Cuerpo HTML opcional. Si está vacío, el correo se envía solo como texto. */
    public string $html;
    public ?string $replyToEmail;
    public ?string $replyToName;
    /** @var array<int, array{content:string, filename:string, mime:string}> adjuntos en memoria */
    public array $attachments = [];

    public function attach(string $content, string $filename, string $mime): void
    {
        $this->attachments[] = ['content' => $content, 'filename' => $filename, 'mime' => $mime];
    }

    public function __construct(
        string $toEmail,
        string $subject,
        string $text,
        string $html = '',
        string $toName = '',
        ?string $replyToEmail = null,
        ?string $replyToName = null
    ) {
        $this->toEmail = $toEmail;
        $this->subject = $subject;
        $this->text = $text;
        $this->html = $html;
        $this->toName = $toName;
        $this->replyToEmail = $replyToEmail;
        $this->replyToName = $replyToName;
    }
}
