<?php

declare(strict_types=1);

namespace App\Modules;

use Core\Auth;
use Core\Database;
use Core\Response;
use Core\Router;

/**
 * ModuleRegistry — sistema de módulos de PromptPress (FEAT-3).
 *
 * PromptPress es un monolito sin API de plugins. En lugar de construir esa
 * infraestructura, cada funcionalidad grande (Analytics, Booking, Commerce)
 * vive aislada en `app/Modules/<Nombre>/` con sus propios controllers, servicios,
 * rutas y vistas, y se ACTIVA POR SITIO mediante un flag en la tabla `settings`
 * (`module_<key>_enabled`). Los módulos nacen desactivados: una instalación
 * existente que se actualice no cambia de comportamiento hasta que alguien
 * activa un módulo desde el panel.
 *
 * Cómo se enganchan las rutas:
 *   - Cada módulo con rutas propias define `app/Modules/<Ucfirst(key)>/routes.php`
 *     que devuelve un callable `function (Router $router, string $key, array $adminMiddlewares)`.
 *   - `registerRoutes()` los incluye todos; cada ruta se protege con el
 *     middleware `requireEnabled($key)`, que responde 404 si el módulo está
 *     apagado para el sitio activo. Así las rutas existen siempre pero solo
 *     responden cuando el módulo está activo.
 *
 * El autoloader no necesita cambios: `App\` ya mapea a `app/`, de modo que
 * `App\Modules\Hello\HelloController` se resuelve a `app/Modules/Hello/HelloController.php`.
 */
final class ModuleRegistry
{
    /**
     * Catálogo de módulos.
     *   - available:false → todavía no implementado; se muestra como "próximamente"
     *     y no puede activarse. Se irá poniendo a true conforme se construyan.
     *
     * @var array<string, array{label:string, description:string, available:bool}>
     */
    public const MODULES = [
        'hello' => [
            'label'       => 'Módulo de prueba',
            'description' => 'Módulo de demostración para validar el sistema de módulos. Sin efecto en el sitio; puedes activarlo y desactivarlo con seguridad.',
            'available'   => true,
        ],
        'analytics' => [
            'label'       => 'Analítica propia',
            'description' => 'Estadísticas de visitas sin cookies ni Google Analytics: el dato es tuyo y respeta la privacidad.',
            'available'   => true,
        ],
        'booking' => [
            'label'       => 'Reservas y calendarios',
            'description' => 'Permite a tus clientes reservar citas y servicios, con calendario embebible también en webs externas.',
            'available'   => true,
        ],
        'commerce' => [
            'label'       => 'PromptCommerce',
            'description' => 'Tienda online: catálogo, carrito y pagos (Stripe o transferencia).',
            'available'   => true,
        ],
    ];

    public static function exists(string $key): bool
    {
        return isset(self::MODULES[$key]);
    }

    public static function isAvailable(string $key): bool
    {
        return (bool) (self::MODULES[$key]['available'] ?? false);
    }

    /** Clave de setting que guarda el flag de activación de un módulo. */
    public static function settingKey(string $key): string
    {
        return 'module_' . $key . '_enabled';
    }

    /**
     * ¿Está el módulo activo para este sitio? Un módulo no disponible
     * (available:false) nunca se considera activo aunque exista el flag.
     */
    public static function isEnabled(int $siteId, string $key): bool
    {
        if (!self::exists($key) || !self::isAvailable($key)) {
            return false;
        }
        $row = Database::selectOne(
            'SELECT setting_value FROM settings WHERE site_id = ? AND setting_key = ? LIMIT 1',
            [$siteId, self::settingKey($key)]
        );
        return $row !== null && (string) $row['setting_value'] === '1';
    }

    /** Activa o desactiva un módulo para un sitio. */
    public static function setEnabled(int $siteId, string $key, bool $on): void
    {
        Database::execute(
            'INSERT INTO settings (site_id, setting_key, setting_value, is_encrypted)
             VALUES (?, ?, ?, 0)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), is_encrypted = 0',
            [$siteId, self::settingKey($key), $on ? '1' : '0']
        );
    }

    /**
     * Estado de todos los módulos para un sitio, para pintar las tarjetas.
     *
     * @return array<int, array{key:string, label:string, description:string, available:bool, enabled:bool}>
     */
    public static function statusFor(int $siteId): array
    {
        $out = [];
        foreach (self::MODULES as $key => $meta) {
            $out[] = [
                'key'         => $key,
                'label'       => $meta['label'],
                'description' => $meta['description'],
                'available'   => (bool) $meta['available'],
                'enabled'     => self::isEnabled($siteId, $key),
            ];
        }
        return $out;
    }

    /**
     * Sitio efectivo para rutas de módulo: la sesión admin si existe y, si no
     * (visitante público, sin sesión), el sitio público — el primero de la BD,
     * el mismo criterio que PublicPageController::requireSiteId(). Sin este
     * fallback, las rutas públicas de módulos (collect de Analytics, API de
     * Booking) devolverían 404 a cualquier visitante real.
     */
    public static function resolveSiteId(): ?int
    {
        $siteId = Auth::siteId();
        if ($siteId !== null) {
            return $siteId;
        }
        $site = Database::selectOne('SELECT id FROM sites ORDER BY id ASC LIMIT 1');
        return $site !== null ? (int) $site['id'] : null;
    }

    /**
     * Middleware de ruta: deja pasar solo si el módulo está activo para el
     * sitio actual; si no, responde 404 y corta (devuelve false).
     */
    public static function requireEnabled(string $key): callable
    {
        return static function () use ($key): bool {
            $siteId = self::resolveSiteId();
            if ($siteId === null || !self::isEnabled($siteId, $key)) {
                Response::notFound();
                return false;
            }
            return true;
        };
    }

    /**
     * Incluye y registra las rutas de cada módulo que declare `routes.php`.
     * Se llama una vez desde `app/routes.php`.
     */
    public static function registerRoutes(Router $router, array $adminMiddlewares): void
    {
        foreach (array_keys(self::MODULES) as $key) {
            $file = __DIR__ . '/' . ucfirst($key) . '/routes.php';
            if (!is_file($file)) {
                continue;
            }
            $register = require $file;
            if (is_callable($register)) {
                $register($router, $key, $adminMiddlewares);
            }
        }
    }
}
