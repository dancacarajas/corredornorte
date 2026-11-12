<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Auth;
use App\Helpers\View;

final class AuthController
{
    public function showLogin(): void
    {
        Auth::start();
        if (Auth::check()) {
            header('Location: /');
            exit;
        }

        View::render('auth/login', [
            'pageTitle' => 'Entrar',
            'error' => null,
        ], null);
    }

    public function login(): void
    {
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if (Auth::attempt($email, $password)) {
            header('Location: /');
            exit;
        }

        View::render('auth/login', [
            'pageTitle' => 'Entrar',
            'error' => 'E-mail ou senha inválidos.',
            'email' => $email,
        ], null);
    }

    public function logout(): void
    {
        Auth::logout();
        header('Location: /login');
        exit;
    }
}
