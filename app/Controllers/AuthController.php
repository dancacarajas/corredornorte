<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Auth;
use App\Helpers\Db;
use App\Helpers\View;
use App\Services\ContaService;
use PDO;
use RuntimeException;
use Throwable;

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
            'ok' => $_GET['ok'] ?? null,
        ], null);
    }

    public function login(): void
    {
        Auth::start();
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $ip = $this->clientIp();

        $pdo = Db::tryConnection();
        if ($pdo instanceof PDO) {
            $conta = new ContaService($pdo);
            if ($conta->loginBloqueado($email, $ip)) {
                View::render('auth/login', [
                    'pageTitle' => 'Entrar',
                    'error' => 'Muitas tentativas falhas. Aguarde alguns minutos e tente novamente.',
                    'email' => $email,
                ], null);
                return;
            }
        }

        if (Auth::attempt($email, $password)) {
            if ($pdo instanceof PDO) {
                $conta = new ContaService($pdo);
                $conta->registrarTentativaLogin($email, $ip, true);
                $uid = Auth::id();
                if ($uid !== null) {
                    $conta->marcarUltimoLogin($uid);
                }
            }
            header('Location: /');
            exit;
        }

        if ($pdo instanceof PDO) {
            (new ContaService($pdo))->registrarTentativaLogin($email, $ip, false);
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

    public function showForgot(): void
    {
        Auth::start();
        if (Auth::check()) {
            header('Location: /');
            exit;
        }

        View::render('auth/forgot', [
            'pageTitle' => 'Recuperar senha',
            'error' => null,
            'ok' => null,
            'email' => '',
        ], null);
    }

    public function forgot(): void
    {
        Auth::start();
        $email = trim((string) ($_POST['email'] ?? ''));
        $pdo = Db::tryConnection();
        $mensagem = 'Se o e-mail estiver cadastrado, enviaremos um link para redefinir a senha.';

        if ($pdo instanceof PDO) {
            try {
                $mensagem = (new ContaService($pdo))->solicitarResetSenha($email, $this->clientIp());
            } catch (Throwable $e) {
                error_log('forgot password: ' . $e->getMessage());
            }
        }

        View::render('auth/forgot', [
            'pageTitle' => 'Recuperar senha',
            'error' => null,
            'ok' => $mensagem,
            'email' => $email,
        ], null);
    }

    public function showReset(string $token = ''): void
    {
        Auth::start();
        if (Auth::check()) {
            header('Location: /');
            exit;
        }

        $pdo = Db::tryConnection();
        $info = null;
        if ($pdo instanceof PDO) {
            $info = (new ContaService($pdo))->validarTokenReset($token);
        }

        if ($info === null) {
            View::render('auth/reset', [
                'pageTitle' => 'Redefinir senha',
                'error' => 'Este link é inválido ou expirou. Solicite um novo.',
                'token' => '',
                'tokenValido' => false,
                'nome' => null,
            ], null);
            return;
        }

        View::render('auth/reset', [
            'pageTitle' => 'Redefinir senha',
            'error' => null,
            'token' => $token,
            'tokenValido' => true,
            'nome' => $info['nome'],
        ], null);
    }

    public function reset(string $token = ''): void
    {
        Auth::start();
        $pdo = Db::tryConnection();
        if (!$pdo instanceof PDO) {
            View::render('auth/reset', [
                'pageTitle' => 'Redefinir senha',
                'error' => 'MySQL indisponível.',
                'token' => $token,
                'tokenValido' => false,
                'nome' => null,
            ], null);
            return;
        }

        try {
            (new ContaService($pdo))->redefinirSenhaComToken(
                $token,
                (string) ($_POST['senha'] ?? ''),
                (string) ($_POST['senha_confirmacao'] ?? '')
            );
            header('Location: /login?ok=senha_redefinida');
            exit;
        } catch (RuntimeException $e) {
            $info = (new ContaService($pdo))->validarTokenReset($token);
            View::render('auth/reset', [
                'pageTitle' => 'Redefinir senha',
                'error' => $e->getMessage(),
                'token' => $token,
                'tokenValido' => $info !== null,
                'nome' => $info['nome'] ?? null,
            ], null);
        } catch (Throwable) {
            View::render('auth/reset', [
                'pageTitle' => 'Redefinir senha',
                'error' => 'Não foi possível redefinir a senha.',
                'token' => $token,
                'tokenValido' => false,
                'nome' => null,
            ], null);
        }
    }

    private function clientIp(): string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        if (strlen($ip) > 45) {
            $ip = substr($ip, 0, 45);
        }

        return $ip;
    }
}
