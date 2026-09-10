<?php

namespace Core;

use App\Services\Permissions;

/**
 * Gestión de autenticación basada en sesión.
 */
final class Auth
{
    private const USER_KEY = 'user_id';
    private const SITE_KEY = 'site_id';

    /** @var array{id:?int, row:?array<string,mixed>}|null Usuario memorizado para esta petición. */
    private static ?array $userCache = null;

    public static function check(): bool
    {
        return Session::has(self::USER_KEY);
    }

    public static function id(): ?int
    {
        $id = Session::get(self::USER_KEY);
        return is_numeric($id) ? (int) $id : null;
    }

    public static function siteId(): ?int
    {
        $id = Session::get(self::SITE_KEY);
        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * Fila del usuario en sesión, memorizada para esta petición.
     *
     * EQUIPO T3 — Desde que el guard de capacidades corre en CADA petición del
     * panel, el rol se pregunta varias veces (guard, navegación, escritorio).
     * Dentro de una misma petición no cambia, y si cambia (alguien se edita a
     * sí mismo) el redirect posterior trae una petición nueva.
     *
     * @return array<string,mixed>|null
     */
    public static function user(): ?array
    {
        $id = self::id();
        if ($id === null) {
            self::$userCache = ['id' => null, 'row' => null];
            return null;
        }
        if (self::$userCache !== null && self::$userCache['id'] === $id) {
            return self::$userCache['row'];
        }
        $row = Database::selectOne('SELECT id, username, email, role FROM users WHERE id = ? LIMIT 1', [$id]);
        self::$userCache = ['id' => $id, 'row' => $row];
        return $row;
    }

    public static function role(): ?string
    {
        $row = self::user();
        return is_string($row['role'] ?? null) ? (string) $row['role'] : null;
    }

    /**
     * Nombre de quien está dentro, para pintarlo en la barra superior.
     *
     * EQUIPO T6 — Antes el layout dependía de que cada controlador le pasara
     * `$userName`, y solo se lo pasaba el escritorio: en TODAS las demás
     * pantallas la barra ponía «Admin» a secas. Con un único usuario llamado
     * `admin` nadie lo notaba; con equipo, cada editor veía el nombre de otro.
     */
    public static function username(): ?string
    {
        $row = self::user();
        return is_string($row['username'] ?? null) ? (string) $row['username'] : null;
    }

    /** ¿El usuario en sesión tiene esta capacidad? Atajo para vistas y controladores. */
    public static function can(string $capability): bool
    {
        return Permissions::roleHas(self::role(), $capability);
    }

    /**
     * Middleware: corta con 403 si el rol no alcanza para la ruta pedida.
     *
     * Va colgado del grupo `/admin` y de los módulos, igual que
     * `requireOnboarding()`: lee la ruta de la petición en vez de declararse
     * ruta por ruta, que con ~200 rutas sería imposible de mantener.
     *
     * Esta es la ÚNICA barrera que cuenta. Ocultar entradas del menú es
     * cortesía; lo que impide entrar es esto.
     */
    public static function requireCapability(): bool
    {
        $path = Request::path();
        if (Permissions::allows(self::role(), $path)) {
            return true;
        }

        // Buena parte del panel habla por fetch: un 403 en HTML dentro de un
        // `await response.json()` se ve como un error de sintaxis y manda a
        // quien lo depure al sitio equivocado.
        if (self::wantsJson()) {
            Response::json(['ok' => false, 'error' => __('common.access_denied')], 403);
        }
        Response::forbidden(__('common.access_denied'));
    }

    private static function wantsJson(): bool
    {
        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch') {
            return true;
        }
        $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
        return str_contains($accept, 'application/json');
    }

    /** Credential check: busca user por username o email, valida password con password_verify. */
    public static function attempt(string $identifier, string $password): ?array
    {
        $user = Database::selectOne(
            'SELECT id, username, email, password_hash, role FROM users WHERE username = ? OR email = ? LIMIT 1',
            [$identifier, $identifier]
        );
        if (!$user) {
            return null;
        }
        if (!password_verify($password, $user['password_hash'])) {
            return null;
        }
        // Rehash si el algoritmo por defecto cambió
        if (password_needs_rehash($user['password_hash'], PASSWORD_BCRYPT)) {
            $newHash = password_hash($password, PASSWORD_BCRYPT);
            Database::execute('UPDATE users SET password_hash = ? WHERE id = ?', [$newHash, $user['id']]);
        }
        return $user;
    }

    /**
     * Inicia sesión del usuario. Si $siteId es null, toma el primer sitio del usuario.
     */
    public static function login(int $userId, ?int $siteId = null): void
    {
        Session::regenerate();
        Session::set(self::USER_KEY, $userId);

        if ($siteId === null) {
            // Single-tenant MVP: asume 1 sitio. Si hay varios, toma el primero.
            try {
                $site = Database::selectOne('SELECT id FROM sites ORDER BY id LIMIT 1');
                if ($site) {
                    $siteId = (int) $site['id'];
                }
            } catch (\Throwable $e) {
                // sin BD todavía, no crítico
            }
        }
        if ($siteId !== null) {
            Session::set(self::SITE_KEY, $siteId);
        }
        CSRF::renew();
    }

    public static function logout(): void
    {
        Session::forget(self::USER_KEY);
        Session::forget(self::SITE_KEY);
        self::$userCache = null;
        Session::regenerate();
        CSRF::renew();
    }

    /** Middleware: redirige a login si no está autenticado. Devuelve false para que el router corte. */
    public static function requireAuth(): bool
    {
        if (!self::check()) {
            Response::redirect(base_url('admin/login'));
            return false;
        }
        return true;
    }

    public static function requireOnboarding(): bool
    {
        $path = Request::path();
        if (!str_starts_with($path, '/admin')) return true;
        if (str_starts_with($path, '/admin/onboarding') || $path === '/admin/logout') return true;
        if (preg_match('#^/admin/pages/ai/templates/[^/]+/preview$#', $path)) return true;

        $siteId = self::siteId();
        if ($siteId === null) return true;

        $flag = Database::selectOne(
            'SELECT setting_value FROM settings WHERE site_id = ? AND setting_key = ? LIMIT 1',
            [$siteId, 'onboarding_completed_at']
        );
        if (!empty($flag['setting_value'])) return true;

        if (self::shouldSoftCompleteOnboarding($siteId)) {
            Database::execute(
                'INSERT INTO settings (site_id, setting_key, setting_value, is_encrypted)
                 VALUES (?, ?, ?, 0)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), is_encrypted = 0',
                [$siteId, 'onboarding_completed_at', date('c')]
            );
            return true;
        }

        Response::redirect(base_url('admin/onboarding'));
        return false;
    }

    private static function shouldSoftCompleteOnboarding(int $siteId): bool
    {
        $pages = Database::selectOne('SELECT COUNT(*) AS n FROM pages WHERE site_id = ?', [$siteId]);
        if ((int) ($pages['n'] ?? 0) < 1) return false;
        $memory = Database::selectOne(
            'SELECT COUNT(*) AS n FROM site_memory WHERE site_id = ? AND TRIM(field_value) <> ""',
            [$siteId]
        );
        return (int) ($memory['n'] ?? 0) > 0;
    }
}
