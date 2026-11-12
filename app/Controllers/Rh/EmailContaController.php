<?php

declare(strict_types=1);

namespace App\Controllers\Rh;

use App\Helpers\Auth;
use App\Helpers\Db;
use App\Helpers\View;
use App\Services\EmailOAuth\AccountMailSender;
use App\Services\EmailOAuth\EmailAccountService;
use PDO;
use Throwable;

final class EmailContaController
{
    public function index(): void
    {
        Auth::requirePermission('email.gerenciar');
        $pdo = Db::tryConnection();
        $contas = [];
        $gmail = null;
        $microsoft = null;
        $smtpGmail = null;
        $smtpMicrosoft = null;
        $dbOk = $pdo instanceof PDO;
        $erro = $_GET['erro'] ?? null;
        $ok = $_GET['ok'] ?? null;

        if ($dbOk) {
            try {
                $svc = new EmailAccountService($pdo);
                $contas = $svc->listarContas();
                $gmail = $svc->providerConfig('gmail');
                $microsoft = $svc->providerConfig('microsoft');
                $smtpGmail = $svc->smtpPreset('gmail');
                $smtpMicrosoft = $svc->smtpPreset('microsoft');
            } catch (Throwable $e) {
                $erro = $e->getMessage();
            }
        }

        View::render('rh/email/index', [
            'pageTitle' => 'Conta de e-mail',
            'pageSubtitle' => 'Conecte Gmail ou Microsoft (OAuth ou usuário/senha)',
            'activeMenu' => 'email',
            'dbOk' => $dbOk,
            'contas' => $contas,
            'gmail' => $gmail,
            'microsoft' => $microsoft,
            'smtpGmail' => $smtpGmail,
            'smtpMicrosoft' => $smtpMicrosoft,
            'flashOk' => $ok,
            'flashErro' => $erro,
        ]);
    }

    public function conectarSmtp(): void
    {
        $pdo = Db::tryConnection();
        if (!$pdo instanceof PDO) {
            header('Location: /rh/email?erro=' . rawurlencode('MySQL indisponível'));
            exit;
        }
        $provedor = (string) ($_POST['provedor'] ?? '');
        try {
            (new EmailAccountService($pdo))->conectarSmtp($provedor, [
                'email' => (string) ($_POST['email'] ?? ''),
                'password' => (string) ($_POST['password'] ?? ''),
                'nome' => (string) ($_POST['nome'] ?? ''),
            ], Auth::id());
            header('Location: /rh/email?ok=smtp');
        } catch (Throwable $e) {
            header('Location: /rh/email?erro=' . rawurlencode($e->getMessage()));
        }
        exit;
    }

    public function salvarConfig(): void
    {
        $pdo = Db::tryConnection();
        if (!$pdo instanceof PDO) {
            header('Location: /rh/email?erro=' . rawurlencode('MySQL indisponível'));
            exit;
        }
        $provedor = (string) ($_POST['provedor'] ?? '');
        $conectarAgora = !empty($_POST['conectar_agora']);
        try {
            $svc = new EmailAccountService($pdo);
            $svc->salvarConfigProvider($provedor, [
                'client_id' => (string) ($_POST['client_id'] ?? ''),
                'client_secret' => (string) ($_POST['client_secret'] ?? ''),
                'tenant' => (string) ($_POST['tenant'] ?? 'common'),
                'redirect_uri' => (string) ($_POST['redirect_uri'] ?? ''),
            ]);
            if ($conectarAgora) {
                $cfg = $svc->providerConfig($provedor);
                if (!$cfg['configured']) {
                    header('Location: /rh/email?erro=' . rawurlencode('Informe Client ID e Client Secret para ativar o OAuth.'));
                    exit;
                }
                $state = bin2hex(random_bytes(16));
                $_SESSION['email_oauth_state'] = $state;
                $_SESSION['email_oauth_provider'] = $provedor;
                header('Location: ' . $svc->buildAuthorizeUrl($provedor, $state));
                exit;
            }
            header('Location: /rh/email?ok=' . rawurlencode('config_' . $provedor) . '#' . rawurlencode($provedor));
        } catch (Throwable $e) {
            header('Location: /rh/email?erro=' . rawurlencode($e->getMessage()));
        }
        exit;
    }

    public function conectar(string $provedor = ''): void
    {
        $pdo = Db::tryConnection();
        if (!$pdo instanceof PDO) {
            header('Location: /rh/email?erro=' . rawurlencode('MySQL indisponível'));
            exit;
        }
        try {
            $svc = new EmailAccountService($pdo);
            $state = bin2hex(random_bytes(16));
            $_SESSION['email_oauth_state'] = $state;
            $_SESSION['email_oauth_provider'] = $provedor;
            $url = $svc->buildAuthorizeUrl($provedor, $state);
            header('Location: ' . $url);
        } catch (Throwable $e) {
            header('Location: /rh/email?erro=' . rawurlencode($e->getMessage()));
        }
        exit;
    }

    public function callback(string $provedor = ''): void
    {
        $pdo = Db::tryConnection();
        if (!$pdo instanceof PDO) {
            header('Location: /rh/email?erro=' . rawurlencode('MySQL indisponível'));
            exit;
        }

        $error = (string) ($_GET['error'] ?? '');
        if ($error !== '') {
            $desc = (string) ($_GET['error_description'] ?? $error);
            header('Location: /rh/email?erro=' . rawurlencode($desc));
            exit;
        }

        $state = (string) ($_GET['state'] ?? '');
        $expected = (string) ($_SESSION['email_oauth_state'] ?? '');
        $expectedProvider = (string) ($_SESSION['email_oauth_provider'] ?? '');
        unset($_SESSION['email_oauth_state'], $_SESSION['email_oauth_provider']);

        if ($state === '' || !hash_equals($expected, $state) || $expectedProvider !== $provedor) {
            header('Location: /rh/email?erro=' . rawurlencode('Estado OAuth inválido. Tente novamente.'));
            exit;
        }

        $code = (string) ($_GET['code'] ?? '');
        if ($code === '') {
            header('Location: /rh/email?erro=' . rawurlencode('Código de autorização ausente.'));
            exit;
        }

        try {
            $svc = new EmailAccountService($pdo);
            $tokens = $svc->exchangeCode($provedor, $code);
            $profile = $svc->fetchProfile($provedor, $tokens['access_token']);
            $svc->upsertConta($provedor, $tokens, $profile, Auth::id());
            header('Location: /rh/email?ok=conectado');
        } catch (Throwable $e) {
            header('Location: /rh/email?erro=' . rawurlencode($e->getMessage()));
        }
        exit;
    }

    public function desconectar(): void
    {
        $pdo = Db::tryConnection();
        if (!$pdo instanceof PDO) {
            header('Location: /rh/email?erro=' . rawurlencode('MySQL indisponível'));
            exit;
        }
        $id = (int) ($_POST['id'] ?? 0);
        try {
            (new EmailAccountService($pdo))->desconectar($id);
            header('Location: /rh/email?ok=desconectado');
        } catch (Throwable $e) {
            header('Location: /rh/email?erro=' . rawurlencode($e->getMessage()));
        }
        exit;
    }

    public function ativar(): void
    {
        $pdo = Db::tryConnection();
        if (!$pdo instanceof PDO) {
            header('Location: /rh/email?erro=' . rawurlencode('MySQL indisponível'));
            exit;
        }
        $id = (int) ($_POST['id'] ?? 0);
        try {
            (new EmailAccountService($pdo))->definirAtiva($id);
            header('Location: /rh/email?ok=ativa');
        } catch (Throwable $e) {
            header('Location: /rh/email?erro=' . rawurlencode($e->getMessage()));
        }
        exit;
    }

    public function testar(): void
    {
        $pdo = Db::tryConnection();
        if (!$pdo instanceof PDO) {
            header('Location: /rh/email?erro=' . rawurlencode('MySQL indisponível'));
            exit;
        }
        $to = trim((string) ($_POST['destinatario'] ?? ''));
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $user = Auth::user();
            $to = (string) ($user['email'] ?? '');
        }
        if ($to === '') {
            header('Location: /rh/email?erro=' . rawurlencode('Informe um destinatário para o teste.'));
            exit;
        }

        try {
            $svc = new EmailAccountService($pdo);
            $result = (new AccountMailSender($svc))->send(
                $to,
                'Arcadis RH — teste de conexão de e-mail',
                "Este é um e-mail de teste do módulo Conta de e-mail do Arcadis RH.\n\nSe você recebeu, a conta está funcionando."
            );
            if ($result['ok']) {
                header('Location: /rh/email?ok=' . rawurlencode('teste'));
            } else {
                header('Location: /rh/email?erro=' . rawurlencode((string) $result['erro']));
            }
        } catch (Throwable $e) {
            header('Location: /rh/email?erro=' . rawurlencode($e->getMessage()));
        }
        exit;
    }
}
