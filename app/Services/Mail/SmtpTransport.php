<?php

declare(strict_types=1);

namespace App\Services\Mail;

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * Transporte SMTP basado en PHPMailer. El usuario configura los datos de un
 * buzón que ya tiene (su correo de hosting, Gmail, etc.) desde el panel.
 */
final class SmtpTransport implements MailTransportInterface
{
    private string $host;
    private int $port;
    private string $user;
    private string $pass;
    private string $encryption; // 'tls' | 'ssl' | 'none'
    private string $fromEmail;
    private string $fromName;

    /** @param array{host:string,port:int,user:string,pass:string,encryption:string,from_email:string,from_name:string} $config */
    public function __construct(array $config)
    {
        $this->host       = (string) ($config['host'] ?? '');
        $this->port       = (int) ($config['port'] ?? 587);
        $this->user       = (string) ($config['user'] ?? '');
        $this->pass       = (string) ($config['pass'] ?? '');
        $this->encryption = (string) ($config['encryption'] ?? 'tls');
        $this->fromEmail  = (string) ($config['from_email'] ?? '');
        $this->fromName   = (string) ($config['from_name'] ?? '');
    }

    public function name(): string
    {
        return 'smtp';
    }

    public function send(MailMessage $message): void
    {
        if ($this->host === '') {
            throw new MailException('Falta el servidor de correo (host SMTP).');
        }
        if ($this->fromEmail === '') {
            throw new MailException('Falta la dirección de remitente.');
        }

        $mail = new PHPMailer(true); // true = lanza excepciones
        try {
            $mail->isSMTP();
            $mail->Host = $this->host;
            $mail->Port = $this->port > 0 ? $this->port : 587;
            $mail->CharSet = PHPMailer::CHARSET_UTF8;
            $mail->Timeout = 15;

            if ($this->user !== '') {
                $mail->SMTPAuth = true;
                $mail->Username = $this->user;
                $mail->Password = $this->pass;
            }

            if ($this->encryption === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($this->encryption === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } else {
                $mail->SMTPSecure = '';
                $mail->SMTPAutoTLS = false;
            }

            $mail->setFrom($this->fromEmail, $this->fromName !== '' ? $this->fromName : $this->fromEmail);
            $mail->addAddress($message->toEmail, $message->toName);
            if ($message->replyToEmail !== null && $message->replyToEmail !== '') {
                $mail->addReplyTo($message->replyToEmail, (string) $message->replyToName);
            }

            foreach ($message->attachments as $att) {
                $mail->addStringAttachment($att['content'], $att['filename'], PHPMailer::ENCODING_BASE64, $att['mime']);
            }

            $mail->Subject = $message->subject;
            if ($message->html !== '') {
                $mail->isHTML(true);
                $mail->Body = $message->html;
                $mail->AltBody = $message->text !== '' ? $message->text : strip_tags($message->html);
            } else {
                $mail->isHTML(false);
                $mail->Body = $message->text;
            }

            $mail->send();
        } catch (PHPMailerException $e) {
            // ErrorInfo trae el detalle más útil de PHPMailer.
            $detail = $mail->ErrorInfo !== '' ? $mail->ErrorInfo : $e->getMessage();
            throw new MailException(self::friendly($detail), 0, $e);
        }
    }

    /**
     * Traduce errores técnicos de PHPMailer/SMTP a un diagnóstico accionable.
     */
    private static function friendly(string $raw): string
    {
        $low = strtolower($raw);
        if (str_contains($low, 'could not authenticate') || str_contains($low, 'authentication')) {
            return 'El usuario o la contraseña del correo no son correctos. Si usas Gmail/Outlook con verificación en dos pasos, necesitas una "contraseña de aplicación".';
        }
        if (str_contains($low, 'connect') || str_contains($low, 'timed out') || str_contains($low, 'timeout')) {
            return 'No se pudo conectar con el servidor de correo. Revisa el servidor y el puerto; algunos hostings bloquean el 465 (prueba 587) o al revés.';
        }
        if (str_contains($low, 'tls') || str_contains($low, 'ssl') || str_contains($low, 'certificate')) {
            return 'Problema con el cifrado de la conexión. Prueba a cambiar entre TLS (587) y SSL (465).';
        }
        return 'No se pudo enviar el correo: ' . $raw;
    }
}
