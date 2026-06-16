<?php

namespace App\Controllers\Admin;

use Core\Auth;
use Core\CSRF;
use Core\Request;
use Core\Response;
use Core\Session;
use Core\View;

/**
 * Login / Logout del panel admin.
 */
class AuthController
{
    public function showLogin(array $params = []): void
    {
        if (Auth::check()) {
            Response::redirect(base_url('admin/'));
        }

        $error = Session::flash('login_error');
        $identifier = Session::flash('login_identifier') ?? '';

        View::send('admin/auth/login', [
            'error'      => $error,
            'identifier' => (string) $identifier,
            'csrf'       => CSRF::token(),
        ]);
    }

    public function login(array $params = []): void
    {
        CSRF::check();

        $identifier = trim((string) Request::post('identifier', ''));
        $password   = (string) Request::post('password', '');

        if ($identifier === '' || $password === '') {
            Session::flash('login_error', 'Usuario y contraseña son obligatorios.');
            Session::flash('login_identifier', $identifier);
            Response::redirect(base_url('admin/login'));
        }

        $user = Auth::attempt($identifier, $password);
        if ($user === null) {
            // Delay anti-bruteforce básico
            usleep(300_000);
            Session::flash('login_error', 'Credenciales incorrectas.');
            Session::flash('login_identifier', $identifier);
            Response::redirect(base_url('admin/login'));
        }

        Auth::login((int) $user['id']);
        Session::flash('success', '¡Bienvenido, ' . $user['username'] . '!');
        Response::redirect(base_url('admin/'));
    }

    public function logout(array $params = []): void
    {
        // Acepta POST (con CSRF) o GET (conveniencia desde el botón del topbar)
        if (Request::method() === 'POST') {
            CSRF::check();
        }
        Auth::logout();
        Session::flash('success', 'Sesión cerrada correctamente.');
        Response::redirect(base_url('admin/login'));
    }
}
