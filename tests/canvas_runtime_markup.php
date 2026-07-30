<?php

declare(strict_types=1);

/**
 * Regresión: el Studio guarda el DOM VIVO de la sección, así que el HTML que
 * llega al servidor trae el andamiaje que pp-ux.js inyecta (track, flechas,
 * `data-pp-ux-ready`, clases `pp-ux-*`). Si eso se persiste, al recargar
 * `initSlider` sale antes de enganchar listeners y el carrusel queda congelado:
 * tira horizontal con flechas muertas y sin poder llegar a las fotos que quedan
 * fuera de pantalla.
 */

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Services\Canvas\CanvasService;

$failed = 0;
function check_rt(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . $detail . PHP_EOL;
    }
}

// HTML tal y como lo mandaba el Studio tras una edición.
$dirty = '<section data-pp-section="galeria" class="cv-sec">'
    . '<h2>Nuestro taller</h2>'
    . '<div data-pp-behavior="slider" data-pp-slider="single" data-pp-ux-ready="1" class="cv-gal pp-ux-slider pp-ux-slider--single pp-ux-slider--at-start">'
    . '<div class="pp-ux-slider__track">'
    . '<div class="cv-slide"><img src="/a.jpg" alt="Foto 1"><p>Pie 1</p></div>'
    . '<div class="cv-slide"><img src="/b.jpg" alt="Foto 2"><p>Pie 2</p></div>'
    . '<div class="cv-slide"><img src="/c.jpg" alt="Foto 3"><p>Pie 3</p></div>'
    . '</div>'
    . '<button type="button" class="pp-ux-slider__arrow pp-ux-slider__arrow--prev"><svg></svg></button>'
    . '<button type="button" class="pp-ux-slider__arrow pp-ux-slider__arrow--next"><svg></svg></button>'
    . '<div class="pp-ux-slider__dots"><button class="pp-ux-slider__dot is-current"></button></div>'
    . '</div>'
    . '<div data-pp-behavior="reveal" class="cv-card pp-ux-reveal pp-ux-in">Texto</div>'
    . '<span data-pp-behavior="counter" data-pp-counter-raw="120">37</span>'
    . '</section>';

$clean = CanvasService::normalizeEditedSectionHtml($dirty);

check_rt('quita data-pp-ux-ready', !str_contains($clean, 'pp-ux-ready'), $clean);
check_rt('quita el track inyectado', !str_contains($clean, 'pp-ux-slider__track'));
check_rt('quita las flechas inyectadas', !str_contains($clean, 'pp-ux-slider__arrow'));
check_rt('quita los puntos inyectados', !str_contains($clean, 'pp-ux-slider__dot'));
check_rt('no deja ninguna clase pp-ux-*', !str_contains($clean, 'pp-ux-'), $clean);

check_rt('conserva el comportamiento declarado', str_contains($clean, 'data-pp-behavior="slider"'));
check_rt('conserva la disposición elegida', str_contains($clean, 'data-pp-slider="single"'));
check_rt('conserva reveal', str_contains($clean, 'data-pp-behavior="reveal"'));
check_rt('conserva las clases del autor', str_contains($clean, 'cv-gal') && str_contains($clean, 'cv-slide') && str_contains($clean, 'cv-card'));
check_rt('conserva las 3 fotos', substr_count($clean, '<img') === 3, 'imgs=' . substr_count($clean, '<img'));
check_rt('conserva los pies de foto', substr_count($clean, '<p>Pie') === 3);

// Los slides tienen que quedar como hijos DIRECTOS del contenedor del slider:
// si se quedaran anidados en un div extra, pp-ux volvería a envolverlos y cada
// edición añadiría una capa más.
check_rt(
    'los slides vuelven a colgar del contenedor',
    preg_match('/data-pp-behavior="slider"[^>]*>\s*<div class="cv-slide"/', $clean) === 1,
    $clean
);

check_rt('restaura la cifra final del contador', str_contains($clean, '>120<') && !str_contains($clean, '>37<'));
check_rt('no deja data-pp-counter-raw', !str_contains($clean, 'counter-raw'));

// Idempotencia: limpiar dos veces no debe cambiar nada.
check_rt('idempotente', CanvasService::cleanRuntimeBehaviorMarkup($clean) === $clean);

// Un HTML que nunca pasó por pp-ux se queda igual.
$pristine = '<section data-pp-section="x"><div data-pp-behavior="slider"><div><img src="/a.jpg" alt=""></div></div></section>';
check_rt('no toca el HTML limpio', CanvasService::cleanRuntimeBehaviorMarkup($pristine) === $pristine);

echo PHP_EOL . ($failed === 0 ? 'TODO OK' : $failed . ' fallos') . PHP_EOL;
exit($failed === 0 ? 0 : 1);
