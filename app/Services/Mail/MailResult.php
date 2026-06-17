<?php

declare(strict_types=1);

namespace App\Services\Mail;

/**
 * Resultado de un intento de envío. La UI lo usa para mostrar éxito o un error
 * legible sin tener que capturar excepciones.
 */
final class MailResult
{
    public bool $ok;
    public string $transport;
    public ?string $error;

    private function __construct(bool $ok, string $transport, ?string $error)
    {
        $this->ok = $ok;
        $this->transport = $transport;
        $this->error = $error;
    }

    public static function success(string $transport): self
    {
        return new self(true, $transport, null);
    }

    public static function failure(string $error, string $transport): self
    {
        return new self(false, $transport, $error);
    }
}
