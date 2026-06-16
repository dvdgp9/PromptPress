<?php

declare(strict_types=1);

namespace App\Services\AI;

use RuntimeException;

/**
 * Error producido al invocar un proveedor de IA.
 *
 * El código HTTP del proveedor (cuando aplica) se preserva en getHttpStatus().
 */
final class AIException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $httpStatus = 0,
        private readonly ?string $providerError = null,
    ) {
        parent::__construct($message);
    }

    public function getHttpStatus(): int { return $this->httpStatus; }
    public function getProviderError(): ?string { return $this->providerError; }
}
