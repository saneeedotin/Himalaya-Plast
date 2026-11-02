<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Flash;
use App\Core\View;

final class AuthController
{
    public function showLogin(): void
    {
        if (Auth::check()) {
            redirect('/');
        }
        View::render('auth/login', ['title' => 'Sign in', 'email' => ''], 'bare');
    }

    public function login(): void
    {
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if ($email === '' || $password === '') {
            $result = ['ok' => false, 'error' => 'Enter your email and password.'];
        } else {
            $result = Auth::attempt($email, $password);
        }

        if (!$result['ok']) {
            http_response_code(401);
            View::render('auth/login', ['title' => 'Sign in', 'email' => $email, 'error' => $result['error']], 'bare');
            return;
        }

        session_regenerate_id(true);
        redirect(Auth::takeNext());
    }

    public function logout(): void
    {
        Auth::logout();
        session_regenerate_id(true);
        Flash::success('You have been signed out.');
        redirect('/login');
    }
}
