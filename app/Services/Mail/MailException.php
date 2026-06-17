<?php

declare(strict_types=1);

namespace App\Services\Mail;

use RuntimeException;

/**
 * Error de envío de correo. El mensaje debe ser legible para mostrarse en el
 * panel (se evita filtrar credenciales o trazas internas).
 */
final class MailException extends RuntimeException
{
}
