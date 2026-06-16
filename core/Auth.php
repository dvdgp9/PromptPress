<?php

namespace Core;

/**
 * Gestión de autenticación basada en sesión.
 */
final class Auth
{
    private const USER_KEY = 'user_id';
    private const SITE_KEY = 'site_id';

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

    public static function role(): ?string
    {
        $id = self::id();
        if ($id === null) return null;
        $row = Database::selectOne('SELECT role FROM users WHERE id = ? LIMIT 1', [$id]);
        return is_string($row['role'] ?? null) ? (string) $row['role'] : null;
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
