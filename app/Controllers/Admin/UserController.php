<?php

namespace App\Controllers\Admin;

use App\Services\Permissions;
use App\Services\UserService;
use Core\Auth;
use Core\CSRF;
use Core\Request;
use Core\Response;
use Core\Session;
use Core\View;

/**
 * EQUIPO T5 — El equipo que entra al panel.
 *
 * Toda la pantalla vive tras la capacidad `settings`, o sea solo administrador.
 * El guard del router ya lo garantiza (`/admin/users` → `settings`), así que
 * aquí NO se vuelve a comprobar el rol: repetirlo invita a que un día alguien
 * arregle solo una de las dos copias.
 *
 * Lo que sí se comprueba en cada acción son las reglas que pueden dejar el
 * panel sin dueño, y esas viven en `UserService`.
 */
class UserController
{
    public function index(): void
    {
        View::send('admin/users/index', [
            'users'       => UserService::all(),
            'currentId'   => Auth::id(),
            'adminCount'  => UserService::countAdmins(),
            'roles'       => Permissions::ROLES,
            'csrf'        => CSRF::token(),
        ]);
    }

    public function create(): void
    {
        View::send('admin/users/form', [
            'user'   => null,
            'roles'  => Permissions::ROLES,
            'errors' => [],
            'csrf'   => CSRF::token(),
        ]);
    }

    public function store(): void
    {
        CSRF::check();

        $input = self::input();
        $errors = UserService::validate($input);
        if ($errors !== []) {
            View::send('admin/users/form', [
                'user'   => null,
                'roles'  => Permissions::ROLES,
                'errors' => array_map(static fn(string $key): string => __($key), $errors),
                'old'    => $input,
                'csrf'   => CSRF::token(),
            ]);
            return;
        }

        UserService::create($input);
        Session::flash('success', __('users.flash.created', ['nombre' => $input['username']]));
        Response::redirect(base_url('admin/users'));
    }

    public function edit(array $params = []): void
    {
        $user = UserService::find((int) ($params['id'] ?? 0));
        if ($user === null) {
            Response::notFound(__('users.err.not_found'));
        }

        View::send('admin/users/form', [
            'user'   => $user,
            'roles'  => Permissions::ROLES,
            'errors' => [],
            'csrf'   => CSRF::token(),
        ]);
    }

    public function update(array $params = []): void
    {
        CSRF::check();

        $id = (int) ($params['id'] ?? 0);
        $user = UserService::find($id);
        if ($user === null) {
            Response::notFound(__('users.err.not_found'));
        }

        $input = self::input();
        $errors = UserService::validate($input, $id);

        // Las reglas de rol se comprueban aparte de la validación de formato:
        // los datos pueden ser impecables y la operación seguir siendo la que
        // deja el sitio sin administradores.
        $blocked = UserService::blocksRoleChange($id, (int) Auth::id(), (string) $input['role']);
        if ($blocked !== null) {
            $errors[] = $blocked;
        }

        if ($errors !== []) {
            View::send('admin/users/form', [
                'user'   => $user,
                'roles'  => Permissions::ROLES,
                'errors' => array_map(static fn(string $key): string => __($key), $errors),
                'old'    => $input,
                'csrf'   => CSRF::token(),
            ]);
            return;
        }

        UserService::update($id, $input);
        Session::flash('success', __('users.flash.updated', ['nombre' => $input['username']]));
        Response::redirect(base_url('admin/users'));
    }

    public function destroy(array $params = []): void
    {
        CSRF::check();

        $id = (int) ($params['id'] ?? 0);
        $blocked = UserService::blocksDelete($id, (int) Auth::id());
        if ($blocked !== null) {
            Session::flash('error', __($blocked));
            Response::redirect(base_url('admin/users'));
        }

        $user = UserService::find($id);
        UserService::delete($id);
        Session::flash('success', __('users.flash.deleted', ['nombre' => (string) ($user['username'] ?? '')]));
        Response::redirect(base_url('admin/users'));
    }

    /** @return array<string, mixed> */
    private static function input(): array
    {
        return [
            'username'         => trim((string) Request::post('username', '')),
            'email'            => trim((string) Request::post('email', '')),
            'role'             => (string) Request::post('role', ''),
            'password'         => (string) Request::post('password', ''),
            'password_confirm' => (string) Request::post('password_confirm', ''),
        ];
    }
}
