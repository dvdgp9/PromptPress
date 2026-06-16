<?php

namespace App\Services;

use PhpOffice\PhpWord\IOFactory as PhpWordIOFactory;
use Smalot\PdfParser\Parser as PdfParser;

/**
 * Extrae texto plano de archivos PDF, DOCX y TXT.
 *
 * Uso:
 *   $text = TextExtractor::extract('/abs/path/file.pdf', 'pdf');
 *
 * Lanza \RuntimeException si no puede extraer (corrupto, unsupported, etc.).
 */
final class TextExtractor
{
    /** Tipos soportados (deben coincidir con ENUM de la BD). */
    public const SUPPORTED_TYPES = ['pdf', 'docx', 'txt'];

    /**
     * @param string $path  Ruta absoluta al archivo
     * @param string $type  'pdf' | 'docx' | 'txt'
     * @return string       Texto plano (puede venir con múltiples saltos de línea)
     */
    public static function extract(string $path, string $type): string
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException("Archivo no legible: $path");
        }

        $text = match ($type) {
            'pdf'  => self::extractPdf($path),
            'docx' => self::extractDocx($path),
            'txt'  => self::extractTxt($path),
            default => throw new \InvalidArgumentException("Tipo no soportado: $type"),
        };

        return self::normalize($text);
    }

    // ----------------------------------------------------------------------
    private static function extractPdf(string $path): string
    {
        $parser = new PdfParser();
        $pdf = $parser->parseFile($path);
        return (string) $pdf->getText();
    }

    private static function extractDocx(string $path): string
    {
        $phpWord = PhpWordIOFactory::load($path, 'Word2007');
        $out = '';
        foreach ($phpWord->getSections() as $section) {
            $out .= self::walkElements($section->getElements());
        }
        return $out;
    }

    private static function extractTxt(string $path): string
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException('No se pudo leer el archivo de texto.');
        }
        // Intentar convertir a UTF-8 si viene en otra codificación
        $enc = mb_detect_encoding($raw, ['UTF-8', 'ISO-8859-1', 'Windows-1252', 'ASCII'], true);
        if ($enc && $enc !== 'UTF-8') {
            $raw = mb_convert_encoding($raw, 'UTF-8', $enc);
        }
        return $raw;
    }

    /**
     * Recorre elementos de PhpWord recursivamente concatenando texto.
     * Preferimos recursión (contenedores) sobre getText() agregado para evitar duplicación.
     */
    private static function walkElements(array $elements): string
    {
        $out = '';
        foreach ($elements as $el) {
            // Si es contenedor (Section, TextRun, Table, Row, Cell) → recursar en sus hijos
            if (method_exists($el, 'getElements')) {
                $children = $el->getElements();
                if (is_array($children) && count($children) > 0) {
                    $out .= self::walkElements($children);
                    $out .= "\n";
                    continue;
                }
            }
            // Hoja: extraer texto si está disponible
            if (method_exists($el, 'getText')) {
                $t = $el->getText();
                if (is_string($t) && $t !== '') {
                    $out .= $t . "\n";
                }
            }
        }
        return $out;
    }

    /**
     * Normaliza el texto: elimina caracteres de control raros, colapsa espacios.
     */
    private static function normalize(string $text): string
    {
        // Eliminar caracteres de control (salvo \n, \t)
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? $text;
        // Colapsar 3+ saltos de línea en 2
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
        // Colapsar espacios/tabs múltiples (respetando saltos)
        $text = preg_replace("/[ \t]+/", ' ', $text) ?? $text;
        return trim($text);
    }
}
