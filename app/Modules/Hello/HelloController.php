<?php

declare(strict_types=1);

namespace App\Modules\Hello;

use Core\Response;

/**
 * Módulo de prueba (Hello) — valida el sistema de módulos de F0.1.
 *
 * No aporta funcionalidad real: existe para comprobar que un módulo se activa
 * y desactiva por sitio y que sus rutas devuelven 404 cuando está apagado.
 * Se puede eliminar cuando el patrón esté consolidado.
 */
final class HelloController
{
    /** GET /admin/modules/hello — página mínima protegida por el módulo. */
    public function index(array $params = []): void
    {
        Response::html(
            '<!doctype html><meta charset="utf-8"><title>Módulo Hello</title>'
            . '<div style="font-family:system-ui;padding:2rem;max-width:640px;margin:auto">'
            . '<h1>Módulo de prueba activo</h1>'
            . '<p>Si ves esta página, el módulo <strong>hello</strong> está activado para este sitio. '
            . 'Desactívalo desde <a href="' . e(base_url('admin/modules')) . '">Módulos</a> y esta ruta pasará a 404.</p></div>'
        );
    }

    /** GET /_module/hello/ping — endpoint público para verificar el guard. */
    public function ping(array $params = []): void
    {
        Response::json(['module' => 'hello', 'ok' => true]);
    }
}
