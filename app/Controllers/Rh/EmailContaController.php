<?php

declare(strict_types=1);

namespace App\Controllers\Rh;

use App\Helpers\Auth;
use App\Helpers\Db;
use App\Helpers\View;
use App\Services\EmailOAuth\AccountMailSender;
use App\Services\EmailOAuth\EmailAccountService;
use App\Services\EmailTemplateService;
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
        $templates = [];
        $dbOk = $pdo instanceof PDO;
        $erro = $_GET['erro'] ?? null;
        $ok = $_GET['ok'] ?? null;
        $editKey = trim((string) ($_GET['tpl'] ?? ''));

        if ($dbOk) {
            try {
                $svc = new EmailAccountService($pdo);
                $contas = $svc->listarContas();
                $gmail = $svc->providerConfig('gmail');
                $microsoft = $svc->providerConfig('microsoft');
                $smtpGmail = $svc->smtpPreset('gmail');
                $smtpMicrosoft = $svc->smtpPreset('microsoft');
                $templates = EmailTemplateService::listForUi($pdo);
            } catch (Throwable $e) {
                $erro = $e->getMessage();
            }
        }

        $templateEdit = null;
        if ($editKey !== '' && $dbOk) {
            try {
                $templateEdit = EmailTemplateService::loadForEdit($pdo, $editKey);
            } catch (Throwable $e) {
                $erro = $e->getMessage();
            }
        }

        View::render('rh/email/index', [
            'pageTitle' => 'Conta de e-mail',
            'pageSubtitle' => 'Conecte com e-mail e senha — sem Client ID',
            'activeMenu' => 'email',
            'extraCss' => ['css/email.css?v=tpl-edit-20261114'],
            'dbOk' => $dbOk,
            'contas' => $contas,
            'gmail' => $gmail,
            'microsoft' => $microsoft,
            'smtpGmail' => $smtpGmail,
            'smtpMicrosoft' => $smtpMicrosoft,
            'templates' => $templates,
            'templateEdit' => $templateEdit,
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
                'smtp_host' => (string) ($_POST['smtp_host'] ?? ''),
                'smtp_port' => (string) ($_POST['smtp_port'] ?? ''),
                'smtp_encryption' => (string) ($_POST['smtp_encryption'] ?? ''),
                'smtp_user' => (string) ($_POST['smtp_user'] ?? ''),
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
            $ativa = $svc->contaAtiva();
            $tpl = EmailTemplateService::render('sistema.email_teste', [
                'destinatario' => $to,
                'contaEmail' => (string) ($ativa['email'] ?? ''),
            ], $pdo);
            $result = (new AccountMailSender($svc))->send(
                $to,
                $tpl['subject'],
                $tpl['text'],
                null,
                $tpl['html']
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

    public function salvarTemplate(): void
    {
        Auth::requirePermission('email.gerenciar');
        $pdo = Db::tryConnection();
        if (!$pdo instanceof PDO) {
            header('Location: /rh/email?erro=' . rawurlencode('MySQL indisponível'));
            exit;
        }
        $chave = (string) ($_POST['chave'] ?? '');
        try {
            EmailTemplateService::save($pdo, $chave, [
                'assunto' => (string) ($_POST['assunto'] ?? ''),
                'titulo' => (string) ($_POST['titulo'] ?? ''),
                'texto' => (string) ($_POST['texto'] ?? ''),
                'corpo_html' => (string) ($_POST['corpo_html'] ?? ''),
                'rodape' => (string) ($_POST['rodape'] ?? ''),
            ], Auth::id());
            header('Location: /rh/email?ok=template_salvo&tpl=' . rawurlencode($chave) . '#templates');
        } catch (Throwable $e) {
            header('Location: /rh/email?erro=' . rawurlencode($e->getMessage()) . '&tpl=' . rawurlencode($chave) . '#templates');
        }
        exit;
    }

    public function resetTemplate(): void
    {
        Auth::requirePermission('email.gerenciar');
        $pdo = Db::tryConnection();
        if (!$pdo instanceof PDO) {
            header('Location: /rh/email?erro=' . rawurlencode('MySQL indisponível'));
            exit;
        }
        $chave = (string) ($_POST['chave'] ?? '');
        try {
            EmailTemplateService::reset($pdo, $chave);
            header('Location: /rh/email?ok=template_reset&tpl=' . rawurlencode($chave) . '#templates');
        } catch (Throwable $e) {
            header('Location: /rh/email?erro=' . rawurlencode($e->getMessage()) . '#templates');
        }
        exit;
    }
}
