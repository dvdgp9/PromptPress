<?php

namespace App\Services;

use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\Element\TextBreak;
use PhpOffice\PhpWord\Element\TextRun;
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
            $out .= self::walkBlocks($section->getElements());
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
     * Elementos de bloque (párrafos, títulos, tablas, cuadros de texto): uno
     * por línea.
     *
     * DOCX-TABLES — En PhpWord una tabla NO tiene `getElements()` ni
     * `getText()`: tiene filas → celdas, y las celdas sí son contenedores. Sin
     * este caso se perdían enteras; un briefing con las respuestas en tablas
     * se quedaba en las preguntas.
     */
    private static function walkBlocks(array $elements): string
    {
        $out = '';
        foreach ($elements as $el) {
            if ($el instanceof Table) {
                foreach ($el->getRows() as $row) {
                    $out .= self::rowText($row->getCells());
                }
                continue;
            }
            // Un párrafo: sus trozos van seguidos, el salto va al final.
            if ($el instanceof TextRun) {
                $out .= self::inlineText($el->getElements()) . "\n";
                continue;
            }
            // Otros contenedores de bloque (cuadros de texto…).
            if (method_exists($el, 'getElements')) {
                $out .= self::walkBlocks((array) $el->getElements());
                continue;
            }
            $text = self::leafText($el);
            if ($text !== '') {
                $out .= $text . "\n";
            }
        }
        return $out;
    }

    /**
     * Una fila de tabla. Si todas sus celdas son de una línea, la fila sale en
     * UNA línea con ` | ` entre celdas: en un formulario de casillas
     * («X | Profesional y cercano») es lo que dice qué marca va con qué opción.
     * Si alguna celda tiene varias líneas (etiqueta + valor), cada celda sale
     * como bloque.
     *
     * @param array<int,object> $cells
     */
    private static function rowText(array $cells): string
    {
        $texts = [];
        foreach ($cells as $cell) {
            $text = trim(self::walkBlocks($cell->getElements()));
            if ($text !== '') {
                $texts[] = $text;
            }
        }
        if ($texts === []) {
            return '';
        }
        $multiline = false;
        foreach ($texts as $text) {
            if (str_contains($text, "\n")) {
                $multiline = true;
                break;
            }
        }
        return implode($multiline ? "\n" : ' | ', $texts) . "\n";
    }

    /**
     * Trozos de un mismo párrafo (cada cambio de formato es un trozo): se
     * concatenan SIN salto. Antes iban uno por línea y «¿Qué debería hacer?»
     * salía partido en tres.
     */
    private static function inlineText(array $elements): string
    {
        $out = '';
        foreach ($elements as $el) {
            if ($el instanceof TextBreak) {
                $out .= "\n";
                continue;
            }
            if (method_exists($el, 'getElements')) {
                $out .= self::inlineText((array) $el->getElements());
                continue;
            }
            $out .= self::leafText($el);
        }
        return $out;
    }

    /** Texto de una hoja. Un título puede devolver un TextRun en vez de string. */
    private static function leafText(object $el): string
    {
        if (!method_exists($el, 'getText')) {
            return '';
        }
        $text = $el->getText();
        if (is_string($text)) {
            return $text;
        }
        if (is_object($text) && method_exists($text, 'getElements')) {
            return self::inlineText((array) $text->getElements());
        }
        return '';
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
