<?php

declare(strict_types=1);

namespace App\Services\Canvas;

use RuntimeException;

/**
 * EDIT-LOCK L4 — Se intentó guardar sobre una versión que ya no es la actual.
 *
 * Lleva las dos versiones para que quien la capture pueda decir algo útil, y
 * sobre todo para que el navegador sepa que su copia está vieja y debe
 * recargar en vez de reintentar.
 */
final class CanvasConflictException extends RuntimeException
{
    public function __construct(
        public readonly int $currentVersionId,
        public readonly int $baseVersionId
    ) {
        parent::__construct('canvas_conflict');
    }
}
