<?php

namespace App\Services;

/**
 * Genera un resumen corto del texto de un documento.
 *
 * T4.2: implementación heurística (primeros N caracteres + recorte en frase).
 * T6.3: se añadirá un summarizer vía IA (reemplazará este fallback cuando
 *       exista un proveedor IA configurado y activo).
 */
final class DocumentSummarizer
{
    public const MAX_LENGTH = 500;

    /**
     * Genera un resumen ≤ MAX_LENGTH caracteres, terminando en frase completa si es posible.
     */
    public static function summarize(string $text): string
    {
        $clean = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if ($clean === '') return '';

        if (mb_strlen($clean) <= self::MAX_LENGTH) {
            return $clean;
        }

        $slice = mb_substr($clean, 0, self::MAX_LENGTH);
        // Cortar en el último final de frase (., !, ?)
        if (preg_match('/^(.*[\.!\?])\s/us', $slice, $m)) {
            return trim($m[1]);
        }
        // Fallback: cortar en el último espacio
        $lastSpace = mb_strrpos($slice, ' ');
        if ($lastSpace !== false) {
            return trim(mb_substr($slice, 0, $lastSpace)) . '…';
        }
        return $slice . '…';
    }
}
