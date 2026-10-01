<?php

declare(strict_types=1);

/**
 * DOCX-TABLES — Un briefing en Word cuyas respuestas van en tablas.
 *
 * Caso real (01/10/2026): un briefing de cliente con cada respuesta en una
 * tabla de una celda bajo su pregunta. `TextExtractor` solo recorría elementos
 * con `getElements()`/`getText()`, y en PhpWord una tabla no tiene ninguno de
 * los dos (tiene `getRows()` → `getCells()`): de 25.400 caracteres salían
 * 3.300, solo las preguntas. «Rellenar con IA» contestaba «Campos rellenados»
 * con todo vacío.
 *
 * Y aunque se leyera entero, el onboarding mandaba a la IA solo los primeros
 * 9.000 caracteres de cada documento: el corte caía en la sección 3 de 10.
 *
 * El briefing real NO es fixture (datos de un cliente): aquí se genera uno con
 * la misma forma.
 */

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Controllers\Admin\OnboardingController;
use App\Services\AI\Actions;
use App\Services\TextExtractor;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;

$failed = 0;
function check_doc(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 400) . PHP_EOL;
    }
}

// ---------------------------------------------------------------------------
// 1. El extractor lee las tablas
// ---------------------------------------------------------------------------

$word = new PhpWord();
$section = $word->addSection();
$section->addTitle('Brief de cliente', 1);
$section->addText('Qué hace la empresa');
$table = $section->addTable();
$table->addRow();
$table->addCell(9000)->addText('RESPUESTA-UNA-CELDA: clínica de medicina estética.');
$section->addText('Datos de contacto');
$grid = $section->addTable();
$grid->addRow();
$grid->addCell(4000)->addText('Email');
$grid->addCell(4000)->addText('RESPUESTA-REJILLA-EMAIL hola@example.com');
$grid->addRow();
$cell = $grid->addCell(4000);
$cell->addText('Teléfono');
$cell->addText('RESPUESTA-PARRAFO-2 600 000 000');
$inner = $grid->addCell(4000)->addTable();
$inner->addRow();
$inner->addCell(2000)->addText('RESPUESTA-TABLA-ANIDADA');
// Casillas: [marca | opción] por fila. Cada fila en UNA línea, o no se sabe
// qué marca va con qué opción.
$section->addText('Tono de comunicación');
$checks = $section->addTable();
foreach ([['X', 'Profesional y cercano'], ['☐', 'Técnico y preciso'], ['X', 'Sereno / de confianza']] as [$mark, $label]) {
    $checks->addRow();
    $checks->addCell(500)->addText($mark);
    $checks->addCell(8000)->addText($label);
}
$run = $section->addTextRun();
$run->addText('Texto ');
$run->addText('con formato', ['bold' => true]);

$tmp = sys_get_temp_dir() . '/pp-docx-' . bin2hex(random_bytes(4)) . '.docx';
IOFactory::createWriter($word, 'Word2007')->save($tmp);
$text = TextExtractor::extract($tmp, 'docx');
@unlink($tmp);

check_doc('lee las preguntas (párrafos)', str_contains($text, 'Qué hace la empresa'), $text);
check_doc('lee una tabla de una celda', str_contains($text, 'RESPUESTA-UNA-CELDA'), $text);
check_doc('lee las celdas de una rejilla', str_contains($text, 'RESPUESTA-REJILLA-EMAIL'), $text);
check_doc('lee varios párrafos de una celda', str_contains($text, 'RESPUESTA-PARRAFO-2'), $text);
check_doc('lee tablas dentro de tablas', str_contains($text, 'RESPUESTA-TABLA-ANIDADA'), $text);
check_doc('no pega la etiqueta con su valor', !str_contains($text, 'EmailRESPUESTA'), $text);
check_doc('una casilla marcada va con su opción', str_contains($text, 'X | Profesional y cercano'), $text);
check_doc('y la desmarcada con la suya', str_contains($text, '☐ | Técnico y preciso'), $text);
check_doc('una celda de varias líneas no se aplasta', str_contains($text, "Teléfono\nRESPUESTA-PARRAFO-2"), $text);
check_doc('el título también',str_contains($text, 'Brief de cliente'), $text);
check_doc('y el texto con formato', str_contains($text, 'Texto con formato'), $text);

// ---------------------------------------------------------------------------
// 2. «Rellenar con IA» manda el documento entero (dentro de un tope amplio)
// ---------------------------------------------------------------------------

$combine = new ReflectionMethod(OnboardingController::class, 'combineDocumentTexts');
$combine->setAccessible(true);

// Un briefing de ~25.000 caracteres con lo importante al final.
$long = str_repeat('Contexto del negocio. ', 1100) . 'PALABRAS-CLAVE-AL-FINAL';
$one = (string) $combine->invoke(null, [['id' => 1, 'title' => 'Briefing', 'text' => $long, 'summary' => '']]);
check_doc('un briefing largo llega entero', str_contains($one, 'PALABRAS-CLAVE-AL-FINAL'), (string) mb_strlen($one));

// Varios documentos: todos aportan algo y el total no se dispara.
$docs = [];
for ($i = 1; $i <= 3; $i++) {
    $docs[] = ['id' => $i, 'title' => "Doc {$i}", 'text' => "INICIO-DOC-{$i} " . str_repeat('x', 80000), 'summary' => ''];
}
$many = (string) $combine->invoke(null, $docs);
check_doc('con varios, cada uno entra', str_contains($many, 'INICIO-DOC-1') && str_contains($many, 'INICIO-DOC-3'));
check_doc('y el total respeta el tope', mb_strlen($many) <= OnboardingController::PROFILE_TEXT_BUDGET + 400, (string) mb_strlen($many));

// La respuesta tiene sitio para ocho campos bien rellenos.
$def = (array) Actions::get(Actions::EXTRACT_BUSINESS_PROFILE);
check_doc('la respuesta tiene margen', (int) ($def['options']['max_tokens'] ?? 0) >= 3000, json_encode($def['options'] ?? []));

echo PHP_EOL . ($failed === 0 ? 'ALL PASS' : $failed . ' FAILED') . PHP_EOL;
exit($failed === 0 ? 0 : 1);
