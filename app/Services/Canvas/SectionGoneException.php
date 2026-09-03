<?php

declare(strict_types=1);

namespace App\Services\Canvas;

/**
 * STUDIO-UX F10 — La sección que la IA estaba editando ya no existe: alguien la
 * borró mientras el modelo trabajaba (entre 7 y 34 segundos). Es un conflicto
 * recuperable, no un fallo: se avisa y no se toca la página.
 */
final class SectionGoneException extends \RuntimeException
{
    public function __construct(public readonly string $sectionId)
    {
        parent::__construct('La sección "' . $sectionId . '" ya no existe.');
    }
}
