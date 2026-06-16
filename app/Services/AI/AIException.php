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
    private int $httpStatus;
    private ?string $providerError;

    public function __construct(string $message, int $httpStatus = 0, ?string $providerError = null)
    {
        $this->httpStatus = $httpStatus;
        $this->providerError = $providerError;
        parent::__construct($message);
    }

    public function getHttpStatus(): int { return $this->httpStatus; }
    public function getProviderError(): ?string { return $this->providerError; }
}
