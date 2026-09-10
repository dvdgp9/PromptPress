<?php

declare(strict_types=1);

namespace App\Services;

/**
 * EQUIPO T2 — Quién puede entrar dónde.
 *
 * Modelo puro: no lee sesión, ni base de datos, ni traducciones. Recibe un rol
 * y una ruta y contesta sí o no. Así se puede probar entero sin levantar nada.
 *
 * DOS IDEAS Y NADA MÁS:
 *
 * 1. Las capacidades son las MISMAS áreas en las que ya está agrupada la
 *    navegación (`AdminNavigation::groupDefinitions()`). No inventamos un
 *    segundo mapa conceptual del panel: repartimos el que ya existe.
 *
 * 2. El área de una ruta la decide su PRIMER SEGMENTO tras `/admin`, y solo
 *    ese. Con ~200 rutas admin, cualquier cosa más fina se pudre en tres
 *    meses. De regalo, distingue sin esfuerzo `/admin/forms` (los mensajes que
 *    llegan) de `/admin/formularios` (el constructor), que son cosas muy
 *    distintas con nombres casi iguales.
 *
 * DENEGAR ES EL DEFAULT: un segmento que no esté en `SEGMENT_CAPABILITY` no
 * pasa para nadie que no sea admin. Eso deja una trampa evidente — una ruta
 * nueva dentro de seis meses se rompería en silencio para editores y
 * redactores — y por eso existe `tests/permissions_coverage.php`, que recorre
 * las rutas registradas de verdad y falla si alguna se quedó sin área. El aviso
 * llega al escribir la ruta, no cuando un cliente se queja.
 */
final class Permissions
{
    public const ROLE_ADMIN    = 'admin';
    public const ROLE_EDITOR   = 'editor';
    public const ROLE_REDACTOR = 'redactor';

    /** @var string[] Orden de más a menos permisos; lo usa la UI para listar. */
    public const ROLES = [self::ROLE_ADMIN, self::ROLE_EDITOR, self::ROLE_REDACTOR];

    /** Áreas repartibles. `dashboard` y `account` no se reparten: son de todos. */
    public const CAP_CONTENT    = 'content';     // Páginas, blog, Studio, medios, recursos
    public const CAP_FORMS      = 'forms';       // Constructor de formularios
    public const CAP_ASSISTANT  = 'assistant';   // Asistente, memoria del sitio, documentos
    public const CAP_APPEARANCE = 'appearance';  // Diseño, header y pie
    public const CAP_VISIBILITY = 'visibility';  // SEO, marketing, analítica
    public const CAP_CLIENTS    = 'clients';     // Mensajes recibidos, reservas, tienda
    public const CAP_SETTINGS   = 'settings';    // Ajustes, módulos, privacidad, gasto IA, usuarios

    /** @var string[] Todas las capacidades repartibles, en orden de presentación. */
    public const CAPABILITIES = [
        self::CAP_CONTENT,
        self::CAP_FORMS,
        self::CAP_ASSISTANT,
        self::CAP_APPEARANCE,
        self::CAP_VISIBILITY,
        self::CAP_CLIENTS,
        self::CAP_SETTINGS,
    ];

    /**
     * La matriz, escrita una sola vez.
     *
     * El REDACTOR se queda sin el constructor de formularios (montar un
     * formulario es configurar, no redactar) pero conserva medios dentro de
     * `content`: sin biblioteca de imágenes no se puede escribir una página.
     *
     * @var array<string, string[]>
     */
    private const ROLE_CAPABILITIES = [
        self::ROLE_ADMIN => self::CAPABILITIES,
        self::ROLE_EDITOR => [
            self::CAP_CONTENT,
            self::CAP_FORMS,
            self::CAP_ASSISTANT,
            self::CAP_APPEARANCE,
            self::CAP_VISIBILITY,
        ],
        self::ROLE_REDACTOR => [
            self::CAP_CONTENT,
        ],
    ];

    /**
     * Primer segmento tras `/admin` → capacidad que hace falta.
     *
     * @var array<string, string>
     */
    private const SEGMENT_CAPABILITY = [
        // Contenido
        'pages'       => self::CAP_CONTENT,
        'posts'       => self::CAP_CONTENT,
        'media'       => self::CAP_CONTENT,
        'sections'    => self::CAP_CONTENT,
        'canvas'      => self::CAP_CONTENT,
        'links'       => self::CAP_CONTENT,
        'resources'   => self::CAP_CONTENT,   // módulo Resources

        // Constructor de formularios (ojo: NO es `forms`)
        'formularios' => self::CAP_FORMS,

        // Asistente y conocimiento
        'assistant'   => self::CAP_ASSISTANT,
        'memory'      => self::CAP_ASSISTANT,
        'documents'   => self::CAP_ASSISTANT,

        // Apariencia
        'design'      => self::CAP_APPEARANCE,
        'chrome'      => self::CAP_APPEARANCE,

        // Visibilidad
        'seo'         => self::CAP_VISIBILITY,
        'marketing'   => self::CAP_VISIBILITY,
        'analytics'   => self::CAP_VISIBILITY, // módulo Analytics

        // Clientes: datos de personas reales
        'forms'       => self::CAP_CLIENTS,    // mensajes recibidos
        'booking'     => self::CAP_CLIENTS,    // módulo Booking
        'commerce'    => self::CAP_CLIENTS,    // módulo Commerce

        // Configuración del sitio
        'settings'    => self::CAP_SETTINGS,
        'modules'     => self::CAP_SETTINGS,
        'privacy'     => self::CAP_SETTINGS,
        'ai'          => self::CAP_SETTINGS,   // claves y gasto de IA
        'users'       => self::CAP_SETTINGS,   // gestión del equipo
        'onboarding'  => self::CAP_SETTINGS,   // rehace la identidad del sitio entero
        '_dev'        => self::CAP_SETTINGS,   // utilidades de desarrollo
    ];

    /**
     * Segmentos que cualquiera con sesión puede pisar, sea cual sea su rol.
     *
     * @var string[]
     */
    private const OPEN_SEGMENTS = [
        '',         // el propio /admin — el escritorio
        'profile',  // mi cuenta
        'login',    // vive fuera del grupo autenticado; aquí todavía no hay rol
        'logout',
    ];

    /** @return string[] */
    public static function capabilitiesFor(?string $role): array
    {
        return self::ROLE_CAPABILITIES[$role] ?? [];
    }

    public static function roleHas(?string $role, string $capability): bool
    {
        return in_array($capability, self::capabilitiesFor($role), true);
    }

    public static function isRole(?string $role): bool
    {
        return $role !== null && in_array($role, self::ROLES, true);
    }

    /**
     * Capacidad que exige una ruta del panel.
     *
     * Devuelve `null` cuando la ruta no está mapeada (y entonces solo pasa el
     * admin) y cadena vacía cuando es de todos.
     */
    public static function capabilityForPath(string $path): ?string
    {
        $segment = self::adminSegment($path);
        if ($segment === null) {
            return '';   // fuera de /admin: aquí no mandamos nosotros
        }
        if (in_array($segment, self::OPEN_SEGMENTS, true)) {
            return '';
        }
        return self::SEGMENT_CAPABILITY[$segment] ?? null;
    }

    /** ¿Este rol puede pedir esta ruta? */
    public static function allows(?string $role, string $path): bool
    {
        if (!self::isRole($role)) {
            return false;
        }
        $capability = self::capabilityForPath($path);
        if ($capability === '') {
            return true;
        }
        if ($capability === null) {
            // Ruta sin área: solo el admin, para que una ruta nueva nunca se
            // abra sola a quien no debe.
            return $role === self::ROLE_ADMIN;
        }
        return self::roleHas($role, $capability);
    }

    /**
     * Primer segmento tras `/admin`, o `null` si la ruta no es del panel.
     * `/admin` y `/admin/` devuelven cadena vacía (el escritorio).
     */
    public static function adminSegment(string $path): ?string
    {
        $parsed = parse_url($path, PHP_URL_PATH);
        $path = is_string($parsed) && $parsed !== '' ? $parsed : $path;
        $path = '/' . trim($path, '/');

        if ($path === '/admin') {
            return '';
        }
        if (!str_starts_with($path, '/admin/')) {
            return null;
        }
        $rest = substr($path, strlen('/admin/'));
        $slash = strpos($rest, '/');
        return $slash === false ? $rest : substr($rest, 0, $slash);
    }
}
