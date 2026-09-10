<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;
use Core\RememberToken;

/**
 * EQUIPO T5 — Altas, bajas y cambios de las cuentas del panel.
 *
 * Todo lo que puede dejar el panel sin dueño vive AQUÍ, no en la vista: son
 * tres reglas y las tres tienen test. Una vista puede esconder un botón; solo
 * el servicio puede impedir la operación.
 *
 *   1. Nadie se borra a sí mismo.
 *   2. Nadie se quita a sí mismo el rol de administrador.
 *   3. Nunca queda el sitio con cero administradores.
 *
 * La tercera es la que de verdad importa: las dos primeras se pueden esquivar
 * entre dos administradores que se degraden el uno al otro, y sin la tercera el
 * panel quedaría cerrado para siempre sin tocar la base de datos a mano.
 *
 * Los errores se devuelven como CLAVES de traducción, no como frases: el
 * servicio no sabe en qué idioma está mirando quien llama.
 */
final class UserService
{
    public const MIN_PASSWORD = 8;

    /** @return array<int, array<string, mixed>> */
    public static function all(): array
    {
        return Database::select(
            'SELECT id, username, email, role, language, created_at, updated_at
             FROM users ORDER BY FIELD(role, "admin", "editor", "redactor"), username ASC'
        );
    }

    /** @return array<string, mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::selectOne(
            'SELECT id, username, email, role, language, created_at, updated_at FROM users WHERE id = ? LIMIT 1',
            [$id]
        );
    }

    public static function countAdmins(): int
    {
        $row = Database::selectOne('SELECT COUNT(*) AS n FROM users WHERE role = ?', [Permissions::ROLE_ADMIN]);
        return (int) ($row['n'] ?? 0);
    }

    /**
     * ¿Es este el último administrador que queda?
     *
     * Se pregunta antes de borrarlo o de bajarle el rol.
     */
    public static function isLastAdmin(int $id): bool
    {
        $user = self::find($id);
        if ($user === null || ($user['role'] ?? '') !== Permissions::ROLE_ADMIN) {
            return false;
        }
        return self::countAdmins() <= 1;
    }

    /**
     * Valida los datos de un alta o una edición.
     *
     * @param array<string, mixed> $input username, email, role, password, password_confirm
     * @param ?int $existingId  id que se está editando (para no chocar consigo mismo)
     * @return string[] claves de traducción de los errores encontrados
     */
    public static function validate(array $input, ?int $existingId = null): array
    {
        $errors = [];

        $username = trim((string) ($input['username'] ?? ''));
        $email    = trim((string) ($input['email'] ?? ''));
        $role     = (string) ($input['role'] ?? '');
        $password = (string) ($input['password'] ?? '');
        $confirm  = (string) ($input['password_confirm'] ?? '');

        if ($username === '' || mb_strlen($username) < 3 || mb_strlen($username) > 50) {
            $errors[] = 'users.err.username_len';
        } elseif (preg_match('/^[a-zA-Z0-9._-]+$/', $username) !== 1) {
            // Sin espacios ni acentos: es lo que se teclea en el login, y un
            // nombre con espacios invita a errores que parecen «no me deja entrar».
            $errors[] = 'users.err.username_chars';
        }

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 255) {
            $errors[] = 'users.err.email';
        }

        if (!in_array($role, Permissions::ROLES, true)) {
            $errors[] = 'users.err.role';
        }

        // En un alta la contraseña es obligatoria; en una edición, dejarla en
        // blanco significa «no la toques».
        $passwordRequired = $existingId === null;
        if ($passwordRequired || $password !== '' || $confirm !== '') {
            if (mb_strlen($password) < self::MIN_PASSWORD) {
                $errors[] = 'users.err.password_len';
            } elseif ($password !== $confirm) {
                $errors[] = 'users.err.password_match';
            }
        }

        // Unicidad. El índice de la tabla es la garantía real; esto existe para
        // dar un mensaje legible en vez de un error de base de datos.
        if ($username !== '') {
            $clash = $existingId === null
                ? Database::selectOne('SELECT id FROM users WHERE username = ? LIMIT 1', [$username])
                : Database::selectOne('SELECT id FROM users WHERE username = ? AND id <> ? LIMIT 1', [$username, $existingId]);
            if ($clash !== null) {
                $errors[] = 'users.err.username_taken';
            }
        }
        if ($email !== '') {
            $clash = $existingId === null
                ? Database::selectOne('SELECT id FROM users WHERE email = ? LIMIT 1', [$email])
                : Database::selectOne('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1', [$email, $existingId]);
            if ($clash !== null) {
                $errors[] = 'users.err.email_taken';
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $input
     * @return int id del usuario creado
     */
    public static function create(array $input): int
    {
        Database::execute(
            'INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, ?)',
            [
                trim((string) $input['username']),
                trim((string) $input['email']),
                password_hash((string) $input['password'], PASSWORD_BCRYPT),
                (string) $input['role'],
            ]
        );
        return (int) Database::lastInsertId();
    }

    /**
     * Guarda los cambios de una cuenta existente.
     *
     * Cambiar el rol o la contraseña TIRA las sesiones recordadas de esa
     * persona. Sin esto, a quien acabas de bajar de administrador le siguen
     * valiendo los permisos viejos en cada navegador donde marcó «mantener la
     * sesión iniciada», que es justo lo contrario de lo que quería quien hizo
     * el cambio.
     *
     * @param array<string, mixed> $input
     */
    public static function update(int $id, array $input): void
    {
        $before = self::find($id);
        $role = (string) $input['role'];
        $password = (string) ($input['password'] ?? '');

        Database::execute(
            'UPDATE users SET username = ?, email = ?, role = ? WHERE id = ?',
            [trim((string) $input['username']), trim((string) $input['email']), $role, $id]
        );

        if ($password !== '') {
            self::setPassword($id, $password);
        }

        $roleChanged = $before !== null && ($before['role'] ?? '') !== $role;
        if ($roleChanged) {
            RememberToken::revokeAllFor($id);
        }
    }

    /** ¿Es esta la contraseña actual de esta cuenta? */
    public static function verifyPassword(int $id, string $plain): bool
    {
        $row = Database::selectOne('SELECT password_hash FROM users WHERE id = ? LIMIT 1', [$id]);
        if ($row === null) {
            return false;
        }
        return password_verify($plain, (string) $row['password_hash']);
    }

    /**
     * Cambia los datos que una persona puede tocar de su propia cuenta.
     *
     * Deliberadamente NO toca el rol: el formulario de perfil no lo ofrece, y
     * si algún día lo ofreciera por error, esto seguiría sin cambiarlo.
     *
     * @param array<string, mixed> $input
     */
    public static function updateOwnProfile(int $id, array $input): void
    {
        Database::execute(
            'UPDATE users SET username = ?, email = ? WHERE id = ?',
            [trim((string) $input['username']), trim((string) $input['email']), $id]
        );
    }

    /** Cambia la contraseña y tira las sesiones recordadas de esa cuenta. */
    public static function setPassword(int $id, string $password): void
    {
        Database::execute(
            'UPDATE users SET password_hash = ? WHERE id = ?',
            [password_hash($password, PASSWORD_BCRYPT), $id]
        );
        RememberToken::revokeAllFor($id);
    }

    /**
     * Borra una cuenta. Sus `auth_tokens` caen solos por la clave foránea
     * (`ON DELETE CASCADE`, SESION-RECUERDA).
     */
    public static function delete(int $id): void
    {
        Database::execute('DELETE FROM users WHERE id = ?', [$id]);
    }

    // -----------------------------------------------------------------------
    // Las tres reglas. Devuelven la clave del error, o null si la operación
    // es legítima.
    // -----------------------------------------------------------------------

    public static function blocksDelete(int $id, int $actorId): ?string
    {
        if ($id === $actorId) {
            return 'users.err.self_delete';
        }
        if (self::find($id) === null) {
            return 'users.err.not_found';
        }
        if (self::isLastAdmin($id)) {
            return 'users.err.last_admin';
        }
        return null;
    }

    public static function blocksRoleChange(int $id, int $actorId, string $newRole): ?string
    {
        $user = self::find($id);
        if ($user === null) {
            return 'users.err.not_found';
        }
        $currentRole = (string) ($user['role'] ?? '');
        if ($currentRole === $newRole) {
            return null;   // no hay cambio que impedir
        }
        if ($id === $actorId && $currentRole === Permissions::ROLE_ADMIN) {
            return 'users.err.self_demote';
        }
        if ($currentRole === Permissions::ROLE_ADMIN && self::countAdmins() <= 1) {
            return 'users.err.last_admin';
        }
        return null;
    }
}
