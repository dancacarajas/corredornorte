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

final class ContaController
{
    public function perfil(): void
    {
        Auth::requireLogin();
        $pdo = Db::tryConnection();
        if (!$pdo instanceof PDO) {
            View::render('conta/perfil', [
                'pageTitle' => 'Meu perfil',
                'pageSubtitle' => 'Conta',
                'activeMenu' => 'conta',
                'extraCss' => ['css/conta.css?v=conta-20261114'],
                'usuario' => [
                    'id' => (int) Auth::id(),
                    'nome' => (string) (Auth::user()['name'] ?? ''),
                    'email' => (string) (Auth::user()['email'] ?? ''),
                    'telefone' => '',
                    'avatar_path' => null,
                    'last_login_at' => null,
                ],
                'flashOk' => null,
                'flashErro' => 'MySQL indisponível.',
            ]);
            return;
        }

        $svc = new ContaService($pdo);
        $usuario = $svc->buscarUsuario((int) Auth::id());
        if ($usuario === null) {
            Auth::logout();
            header('Location: /login');
            exit;
        }

        View::render('conta/perfil', [
            'pageTitle' => 'Meu perfil',
            'pageSubtitle' => 'Dados da conta, senha e foto',
            'activeMenu' => 'conta',
            'extraCss' => ['css/conta.css?v=conta-20261114'],
            'usuario' => $usuario,
            'flashOk' => $_GET['ok'] ?? null,
            'flashErro' => $_GET['erro'] ?? null,
        ]);
    }

    public function atualizarPerfil(): void
    {
        Auth::requireLogin();
        $pdo = Db::tryConnection();
        if (!$pdo instanceof PDO) {
            $this->redirectErro('MySQL indisponível.');
        }

        try {
            $svc = new ContaService($pdo);
            $svc->atualizarPerfil(
                (int) Auth::id(),
                (string) ($_POST['nome'] ?? ''),
                (string) ($_POST['email'] ?? ''),
                (string) ($_POST['telefone'] ?? ''),
                (string) ($_POST['senha_atual'] ?? '')
            );
            Auth::refreshSessionProfile();
            header('Location: /conta?ok=perfil');
        } catch (RuntimeException $e) {
            $this->redirectErro($e->getMessage());
        } catch (Throwable $e) {
            $this->redirectErro('Não foi possível salvar o perfil.');
        }
        exit;
    }

    public function alterarSenha(): void
    {
        Auth::requireLogin();
        $pdo = Db::tryConnection();
        if (!$pdo instanceof PDO) {
            $this->redirectErro('MySQL indisponível.');
        }

        try {
            $svc = new ContaService($pdo);
            $svc->alterarSenha(
                (int) Auth::id(),
                (string) ($_POST['senha_atual'] ?? ''),
                (string) ($_POST['nova_senha'] ?? ''),
                (string) ($_POST['nova_senha_confirmacao'] ?? '')
            );
            header('Location: /conta?ok=senha');
        } catch (RuntimeException $e) {
            $this->redirectErro($e->getMessage());
        } catch (Throwable) {
            $this->redirectErro('Não foi possível alterar a senha.');
        }
        exit;
    }

    public function uploadAvatar(): void
    {
        Auth::requireLogin();
        $pdo = Db::tryConnection();
        if (!$pdo instanceof PDO) {
            $this->redirectErro('MySQL indisponível.');
        }

        try {
            if (empty($_FILES['avatar']) || !is_array($_FILES['avatar'])) {
                throw new RuntimeException('Selecione uma imagem.');
            }
            $svc = new ContaService($pdo);
            $svc->salvarAvatar((int) Auth::id(), $_FILES['avatar']);
            Auth::refreshSessionProfile();
            header('Location: /conta?ok=avatar');
        } catch (RuntimeException $e) {
            $this->redirectErro($e->getMessage());
        } catch (Throwable) {
            $this->redirectErro('Não foi possível enviar a foto.');
        }
        exit;
    }

    public function removerAvatar(): void
    {
        Auth::requireLogin();
        $pdo = Db::tryConnection();
        if (!$pdo instanceof PDO) {
            $this->redirectErro('MySQL indisponível.');
        }

        try {
            $svc = new ContaService($pdo);
            $svc->removerAvatar((int) Auth::id());
            Auth::refreshSessionProfile();
            header('Location: /conta?ok=avatar_removido');
        } catch (Throwable) {
            $this->redirectErro('Não foi possível remover a foto.');
        }
        exit;
    }

    public function solicitarReset(): void
    {
        Auth::requireLogin();
        $pdo = Db::tryConnection();
        if (!$pdo instanceof PDO) {
            $this->redirectErro('MySQL indisponível.');
        }

        try {
            $email = (string) (Auth::user()['email'] ?? '');
            $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
            (new ContaService($pdo))->solicitarResetSenha($email, $ip);
            header('Location: /conta?ok=reset_enviado');
        } catch (Throwable $e) {
            $this->redirectErro($e->getMessage());
        }
        exit;
    }

    public function avatar(): void
    {
        Auth::requireLogin();
        $pdo = Db::tryConnection();
        if (!$pdo instanceof PDO) {
            http_response_code(404);
            exit;
        }

        $svc = new ContaService($pdo);
        $usuarioId = (int) ($_GET['id'] ?? Auth::id());
        if ($usuarioId <= 0) {
            $usuarioId = (int) Auth::id();
        }

        // Só o próprio usuário ou quem gerencia usuários pode ver avatar de terceiros
        if ($usuarioId !== (int) Auth::id() && !Auth::can('usuarios.gerenciar')) {
            http_response_code(403);
            exit;
        }

        $user = $svc->buscarUsuario($usuarioId);
        $abs = $svc->caminhoAbsolutoAvatar($user['avatar_path'] ?? null);
        if ($abs === null) {
            http_response_code(404);
            exit;
        }

        $mime = match (strtolower((string) pathinfo($abs, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };
        header('Content-Type: ' . $mime);
        header('Cache-Control: private, max-age=3600');
        header('Content-Length: ' . (string) filesize($abs));
        readfile($abs);
        exit;
    }

    private function redirectErro(string $msg): void
    {
        header('Location: /conta?erro=' . rawurlencode($msg));
        exit;
    }
}
