<?php

declare(strict_types=1);

/**
 * STUDIO-2 B1/B2 — Memoria de conversación y marcado del elemento elegido.
 * Sin red ni IA: se ejercitan las piezas puras del pipeline de chat.
 */

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Controllers\Admin\CanvasController;
use App\Services\Canvas\CanvasChatService;

$failed = 0;
function check(string $name, bool $ok): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) $failed++;
}

// ---------------------------------------------------------------- B2: marcado
$section = '<section data-pp-section="hero" class="lx-hero">'
    . '<div class="lx-hero__overlay">'
    . '<h1 class="t">Primero</h1>'
    . '<h1 class="t">Segundo</h1>'
    . '<a href="/x" class="pp-btn">Botón</a>'
    . '</div>'
    . '</section>';

// 0 = overlay (único hijo de la sección); 1 = segundo <h1> dentro del overlay.
$marked = CanvasChatService::markTarget($section, '0.1');
check('marca el nodo del camino', preg_match('/<h1 class="t" data-pp-target="1">Segundo<\/h1>/', $marked) === 1);
check('no marca a sus hermanos', substr_count($marked, 'data-pp-target') === 1);
check('conserva el texto del hermano', str_contains($marked, '>Primero<'));

$markedLink = CanvasChatService::markTarget($section, '0.2');
check('marca un enlace', preg_match('/<a href="\/x" class="pp-btn" data-pp-target="1">/', $markedLink) === 1);

// Caminos imposibles: se devuelve el HTML intacto (mejor sin marca que mal puesta).
check('camino fuera de rango no marca', CanvasChatService::markTarget($section, '0.9') === $section);
check('camino vacío no marca', CanvasChatService::markTarget($section, '') === $section);
check('camino basura no marca', CanvasChatService::markTarget($section, 'a.b') === $section);

// La marca nunca debe llegar a la página guardada.
check('stripTargetMarks limpia', CanvasChatService::stripTargetMarks('<h1 data-pp-target="1">A</h1>') === '<h1>A</h1>');
check('stripTargetMarks con comillas simples', CanvasChatService::stripTargetMarks("<h1 data-pp-target='1'>A</h1>") === '<h1>A</h1>');
check('stripTargetMarks no toca lo demás', CanvasChatService::stripTargetMarks('<h1 data-pp-section="x">A</h1>') === '<h1 data-pp-section="x">A</h1>');

// ------------------------------------------------------------- B1: la memoria
$parse = new ReflectionMethod(CanvasController::class, 'parseChatHistory');
$hist = $parse->invoke(null, json_encode([
    ['q' => 'pon el titular en azul', 'a' => 'Hecho, titular azul', 'scope' => 'Hero'],
    ['q' => 'ahora más grande', 'a' => 'Titular más grande', 'scope' => 'Hero'],
]));
check('memoria parseada', count($hist) === 2 && $hist[1]['q'] === 'ahora más grande');
check('memoria conserva el ámbito', $hist[0]['scope'] === 'Hero');
check('memoria ignora json inválido', $parse->invoke(null, 'no soy json') === []);
check('memoria ignora vacío', $parse->invoke(null, '') === []);
check('memoria descarta turnos sin petición', count($parse->invoke(null, json_encode([['a' => 'solo respuesta']]))) === 0);

$muchos = [];
for ($i = 0; $i < 9; $i++) $muchos[] = ['q' => 'cambio ' . $i, 'a' => 'ok'];
$acotada = $parse->invoke(null, json_encode($muchos));
check('memoria acotada a 4 turnos', count($acotada) === 4 && $acotada[3]['q'] === 'cambio 8');

$largo = $parse->invoke(null, json_encode([['q' => str_repeat('x', 900), 'a' => str_repeat('y', 900)]]));
check('memoria recorta longitudes', mb_strlen($largo[0]['q']) === 300 && mb_strlen($largo[0]['a']) === 300);

// El bloque de contexto etiqueta la petición actual y no reordena los turnos.
$block = new ReflectionMethod(CanvasChatService::class, 'historyBlock');
$text = $block->invoke(null, $hist);
check('bloque marca la petición actual', str_contains($text, 'PETICIÓN ACTUAL'));
check('bloque avisa de que no son pendientes', str_contains($text, 'no vuelvas a aplicarlas'));
check('bloque respeta el orden', mb_strpos($text, 'titular en azul') < mb_strpos($text, 'ahora más grande'));
check('bloque vacío sin turnos', $block->invoke(null, []) === '');

// ------------------------------------------- B4: errores que dicen qué ha pasado
$msg = new ReflectionMethod(CanvasController::class, 'chatErrorMessage');
$err = static fn (string $text, int $status = 0) => new \App\Services\AI\AIException($text, $status);

$timeoutPagina = $msg->invoke(null, $err('cURL error 28: Operation timed out'), false);
$timeoutSeccion = $msg->invoke(null, $err('cURL error 28: Operation timed out'), true);
check('timeout se nombra como tal', str_contains($timeoutPagina, 'tardado demasiado') && str_contains($timeoutSeccion, 'tardado demasiado'));
check('timeout de página sugiere seleccionar parte', str_contains($timeoutPagina, 'Selecciona primero una parte'));
check('timeout de sección no sugiere seleccionar', !str_contains($timeoutSeccion, 'Selecciona primero una parte'));

$cortada = $msg->invoke(null, $err('La edición no contiene el sobre de texto esperado. Respuesta: <pp-html>...'), false);
check('respuesta cortada se explica', str_contains($cortada, 'cortada'));
check('cortada en página menciona el tamaño', str_contains($cortada, 'esta página es grande'));

check('401 manda a Ajustes de IA', str_contains($msg->invoke(null, $err('unauthorized', 401), true), 'Ajustes de IA'));
check('429 habla de límite', str_contains($msg->invoke(null, $err('rate limited', 429), true), 'límite'));
check('5xx habla de proveedor no disponible', str_contains($msg->invoke(null, $err('bad gateway', 502), true), 'no está disponible'));
check('desconocido cae en el genérico', str_contains($msg->invoke(null, $err('algo raro'), true), 'no devolvió un cambio válido'));

echo $failed === 0 ? "\nOK\n" : "\n{$failed} FALLOS\n";
exit($failed === 0 ? 0 : 1);
