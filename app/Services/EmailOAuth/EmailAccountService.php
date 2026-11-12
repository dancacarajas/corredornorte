<?php

declare(strict_types=1);

namespace App\Services\EmailOAuth;

use PDO;
use RuntimeException;

final class EmailAccountService
{
    public function __construct(private PDO $db)
    {
    }

    /** @return array<string,mixed> */
    public function configArquivo(): array
    {
        return require dirname(__DIR__, 3) . '/config/email_oauth.php';
    }

    /**
     * Config efetiva (arquivo + overrides do banco).
     *
     * @return array{label:string,client_id:string,client_secret:string,tenant?:string,auth_url:string,token_url:string,userinfo_url:string,send_url:string,scopes:list<string>,redirect_uri:string,configured:bool}
     */
    public function providerConfig(string $provedor): array
    {
        $provedor = $this->assertProvider($provedor);
        $file = $this->configArquivo()['providers'][$provedor] ?? [];
        $row = $this->db->prepare('SELECT * FROM email_oauth_config WHERE provedor = :p');
        $row->execute(['p' => $provedor]);
        $db = $row->fetch(PDO::FETCH_ASSOC) ?: [];

        $clientId = trim((string) ($db['client_id'] ?? '')) ?: (string) ($file['client_id'] ?? '');
        $secretEnc = (string) ($db['client_secret_enc'] ?? '');
        $clientSecret = $secretEnc !== '' ? TokenCrypto::decrypt($secretEnc) : (string) ($file['client_secret'] ?? '');
        $tenant = trim((string) ($db['tenant'] ?? '')) ?: (string) ($file['tenant'] ?? 'common');
        $redirect = trim((string) ($db['redirect_uri'] ?? '')) ?: (string) ($file['redirect_uri'] ?? '');

        $authUrl = (string) ($file['auth_url'] ?? '');
        $tokenUrl = (string) ($file['token_url'] ?? '');
        if ($provedor === 'microsoft') {
            $authUrl = str_replace('{tenant}', rawurlencode($tenant), $authUrl);
            $tokenUrl = str_replace('{tenant}', rawurlencode($tenant), $tokenUrl);
        }

        return [
            'label' => (string) ($file['label'] ?? $provedor),
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'tenant' => $tenant,
            'auth_url' => $authUrl,
            'token_url' => $tokenUrl,
            'userinfo_url' => (string) ($file['userinfo_url'] ?? ''),
            'send_url' => (string) ($file['send_url'] ?? ''),
            'scopes' => array_values($file['scopes'] ?? []),
            'redirect_uri' => $redirect,
            'configured' => $clientId !== '' && $clientSecret !== '',
        ];
    }

    /**
     * @param array{client_id:string,client_secret?:string,tenant?:string,redirect_uri?:string} $data
     */
    public function salvarConfigProvider(string $provedor, array $data): void
    {
        $provedor = $this->assertProvider($provedor);
        $current = $this->providerConfig($provedor);
        $secret = trim((string) ($data['client_secret'] ?? ''));
        if ($secret === '') {
            $secret = $current['client_secret'];
        }

        $this->db->prepare(
            'INSERT INTO email_oauth_config (provedor, client_id, client_secret_enc, tenant, redirect_uri, atualizado_em)
             VALUES (:p, :cid, :sec, :ten, :redir, NOW())
             ON DUPLICATE KEY UPDATE
                client_id = VALUES(client_id),
                client_secret_enc = VALUES(client_secret_enc),
                tenant = VALUES(tenant),
                redirect_uri = VALUES(redirect_uri),
                atualizado_em = NOW()'
        )->execute([
            'p' => $provedor,
            'cid' => trim((string) ($data['client_id'] ?? '')),
            'sec' => TokenCrypto::encrypt($secret),
            'ten' => $provedor === 'microsoft' ? (trim((string) ($data['tenant'] ?? '')) ?: 'common') : null,
            'redir' => trim((string) ($data['redirect_uri'] ?? '')) ?: $current['redirect_uri'],
        ]);
    }

    public function buildAuthorizeUrl(string $provedor, string $state): string
    {
        $cfg = $this->providerConfig($provedor);
        if (!$cfg['configured']) {
            throw new RuntimeException('Configure Client ID e Client Secret antes de conectar.');
        }

        $params = [
            'client_id' => $cfg['client_id'],
            'redirect_uri' => $cfg['redirect_uri'],
            'response_type' => 'code',
            'scope' => implode(' ', $cfg['scopes']),
            'state' => $state,
            'access_type' => 'offline',
            'prompt' => 'consent',
        ];
        if ($provedor === 'microsoft') {
            $params['response_mode'] = 'query';
            unset($params['access_type']);
        }

        return $cfg['auth_url'] . '?' . http_build_query($params);
    }

    /**
     * @return array{access_token:string,refresh_token:?string,expires_in:?int,scope:?string}
     */
    public function exchangeCode(string $provedor, string $code): array
    {
        $cfg = $this->providerConfig($provedor);
        $res = OAuthHttp::request('POST', $cfg['token_url'], [], [
            'code' => $code,
            'client_id' => $cfg['client_id'],
            'client_secret' => $cfg['client_secret'],
            'redirect_uri' => $cfg['redirect_uri'],
            'grant_type' => 'authorization_code',
        ], true);

        if ($res['status'] >= 400 || !is_array($res['json']) || empty($res['json']['access_token'])) {
            $msg = is_array($res['json'])
                ? (string) ($res['json']['error_description'] ?? $res['json']['error'] ?? $res['body'])
                : $res['body'];
            throw new RuntimeException('Falha ao obter token: ' . $msg);
        }

        return [
            'access_token' => (string) $res['json']['access_token'],
            'refresh_token' => isset($res['json']['refresh_token']) ? (string) $res['json']['refresh_token'] : null,
            'expires_in' => isset($res['json']['expires_in']) ? (int) $res['json']['expires_in'] : null,
            'scope' => isset($res['json']['scope']) ? (string) $res['json']['scope'] : null,
        ];
    }

    /**
     * @return array{email:string,nome:?string}
     */
    public function fetchProfile(string $provedor, string $accessToken): array
    {
        $cfg = $this->providerConfig($provedor);
        $res = OAuthHttp::request('GET', $cfg['userinfo_url'], [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/json',
        ]);
        if ($res['status'] >= 400 || !is_array($res['json'])) {
            throw new RuntimeException('Não foi possível ler o perfil da conta.');
        }
        $j = $res['json'];
        if ($provedor === 'gmail') {
            return [
                'email' => (string) ($j['email'] ?? ''),
                'nome' => isset($j['name']) ? (string) $j['name'] : null,
            ];
        }

        return [
            'email' => (string) ($j['mail'] ?? $j['userPrincipalName'] ?? ''),
            'nome' => isset($j['displayName']) ? (string) $j['displayName'] : null,
        ];
    }

    /**
     * Presets SMTP automáticos por provedor.
     *
     * @return array{host:string,port:int,encryption:string,label:string,hint:string}
     */
    public function smtpPreset(string $provedor): array
    {
        $provedor = $this->assertProvider($provedor);
        if ($provedor === 'gmail') {
            return [
                'host' => 'smtp.gmail.com',
                'port' => 587,
                'encryption' => 'tls',
                'label' => 'Gmail SMTP',
                'hint' => 'Use a senha de app do Google (Conta Google → Segurança → Senhas de app). A senha normal costuma ser bloqueada.',
            ];
        }

        return [
            'host' => 'smtp.office365.com',
            'port' => 587,
            'encryption' => 'tls',
            'label' => 'Microsoft SMTP',
            'hint' => 'A Microsoft costuma bloquear usuário/senha (erro 535). O caminho que funciona hoje é OAuth.',
        ];
    }

    /**
     * Conecta com usuário e senha (SMTP) e valida autenticação na hora.
     *
     * @param array{email:string,password:string,nome?:string} $dados
     */
    public function conectarSmtp(string $provedor, array $dados, ?int $userId): int
    {
        $provedor = $this->assertProvider($provedor);
        $email = strtolower(trim((string) ($dados['email'] ?? '')));
        $password = (string) ($dados['password'] ?? '');
        $nome = trim((string) ($dados['nome'] ?? ''));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Informe um e-mail válido.');
        }
        if ($password === '') {
            throw new RuntimeException('Informe a senha.');
        }
        $domain = strtolower((string) substr(strrchr($email, '@') ?: '', 1));
        if ($domain === '' || str_ends_with($domain, '.local') || in_array($domain, ['localhost', 'example.com', 'test'], true)) {
            throw new RuntimeException(
                'Use o e-mail real da Microsoft/Gmail (ex.: nome@outlook.com ou nome@empresa.com). '
                . 'admin@arcadis.local é só login do sistema e não autentica no Outlook.'
            );
        }
        if ($provedor === 'gmail' && !preg_match('/^(gmail\.com|googlemail\.com)$/', $domain) && !str_contains($domain, '.')) {
            throw new RuntimeException('Para Gmail informe uma conta @gmail.com (ou Google Workspace).');
        }

        $preset = $this->smtpPreset($provedor);
        $hosts = [$preset['host']];
        if ($provedor === 'microsoft') {
            // Contas pessoais Outlook.com às vezes usam outro host
            $hosts[] = 'smtp-mail.outlook.com';
        }

        $test = ['ok' => false, 'erro' => null];
        $hostUsado = $preset['host'];
        foreach (array_unique($hosts) as $host) {
            $hostUsado = $host;
            $test = (new SmtpMailSender($this))->testAuth(
                $host,
                $preset['port'],
                $preset['encryption'],
                $email,
                $password
            );
            if ($test['ok']) {
                $preset['host'] = $host;
                break;
            }
        }
        if (!$test['ok']) {
            $raw = (string) ($test['erro'] ?? '');
            if ($provedor === 'microsoft' && (str_contains($raw, '535') || str_contains($raw, '5.7.3'))) {
                throw new RuntimeException(
                    'A Microsoft recusou usuário/senha (SMTP 535). '
                    . 'Isso é bloqueio de autenticação básica no Outlook/Microsoft 365 — não é falha do Arcadis. '
                    . 'Solução: use OAuth no card Microsoft (Client ID + Secret → Salvar e conectar OAuth). '
                    . 'Se for conta corporativa, o admin ainda precisa liberar SMTP AUTH ou autorizar o app no Entra ID.'
                );
            }
            if ($provedor === 'gmail' && (str_contains($raw, '535') || str_contains($raw, '5.7.'))) {
                throw new RuntimeException(
                    'O Gmail recusou a senha. Use senha de app (Conta Google → Segurança → Senhas de app), não a senha normal.'
                );
            }
            throw new RuntimeException('Não autenticou no SMTP (' . $hostUsado . '). ' . $raw);
        }

        $this->db->exec('UPDATE email_contas SET ativo = 0');

        $existing = $this->db->prepare('SELECT id FROM email_contas WHERE provedor = :p AND email = :e');
        $existing->execute(['p' => $provedor, 'e' => $email]);
        $row = $existing->fetch(PDO::FETCH_ASSOC);

        $params = [
            'n' => $nome !== '' ? $nome : $email,
            'host' => $preset['host'],
            'port' => $preset['port'],
            'enc' => $preset['encryption'],
            'user' => $email,
            'pw' => TokenCrypto::encrypt($password),
            'u' => $userId,
        ];

        if ($row) {
            $this->db->prepare(
                "UPDATE email_contas SET
                    auth_mode = 'smtp',
                    nome_exibicao = :n,
                    access_token_enc = NULL,
                    refresh_token_enc = NULL,
                    token_expires_at = NULL,
                    scopes = 'smtp',
                    smtp_host = :host,
                    smtp_port = :port,
                    smtp_encryption = :enc,
                    smtp_user = :user,
                    password_enc = :pw,
                    status = 'conectado',
                    ativo = 1,
                    ultimo_erro = NULL,
                    conectado_por = :u,
                    conectado_em = NOW(),
                    atualizado_em = NOW()
                 WHERE id = :id"
            )->execute($params + ['id' => (int) $row['id']]);

            return (int) $row['id'];
        }

        $this->db->prepare(
            "INSERT INTO email_contas
                (provedor, auth_mode, email, nome_exibicao, access_token_enc, refresh_token_enc, scopes,
                 smtp_host, smtp_port, smtp_encryption, smtp_user, password_enc,
                 status, ativo, conectado_por, conectado_em, atualizado_em)
             VALUES
                (:p, 'smtp', :e, :n, NULL, NULL, 'smtp',
                 :host, :port, :enc, :user, :pw,
                 'conectado', 1, :u, NOW(), NOW())"
        )->execute($params + [
            'p' => $provedor,
            'e' => $email,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * @param array{access_token:string,refresh_token:?string,expires_in:?int,scope:?string} $tokens
     * @param array{email:string,nome:?string} $profile
     */
    public function upsertConta(string $provedor, array $tokens, array $profile, ?int $userId): int
    {
        $provedor = $this->assertProvider($provedor);
        $email = strtolower(trim($profile['email']));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('E-mail da conta OAuth inválido.');
        }

        $expires = null;
        if (!empty($tokens['expires_in'])) {
            $expires = date('Y-m-d H:i:s', time() + (int) $tokens['expires_in'] - 60);
        }

        // Só uma conta ativa no sistema
        $this->db->exec('UPDATE email_contas SET ativo = 0');

        $existing = $this->db->prepare('SELECT id, refresh_token_enc FROM email_contas WHERE provedor = :p AND email = :e');
        $existing->execute(['p' => $provedor, 'e' => $email]);
        $row = $existing->fetch(PDO::FETCH_ASSOC);

        $refresh = $tokens['refresh_token'];
        if (($refresh === null || $refresh === '') && $row) {
            $refresh = TokenCrypto::decrypt((string) $row['refresh_token_enc']);
        }

        if ($row) {
            $this->db->prepare(
                "UPDATE email_contas SET
                    auth_mode = 'oauth',
                    nome_exibicao = :n,
                    access_token_enc = :at,
                    refresh_token_enc = :rt,
                    token_expires_at = :exp,
                    scopes = :sc,
                    password_enc = NULL,
                    smtp_host = NULL,
                    smtp_port = NULL,
                    smtp_encryption = NULL,
                    smtp_user = NULL,
                    status = 'conectado',
                    ativo = 1,
                    ultimo_erro = NULL,
                    conectado_por = :u,
                    conectado_em = NOW(),
                    atualizado_em = NOW()
                 WHERE id = :id"
            )->execute([
                'n' => $profile['nome'],
                'at' => TokenCrypto::encrypt($tokens['access_token']),
                'rt' => TokenCrypto::encrypt((string) $refresh),
                'exp' => $expires,
                'sc' => $tokens['scope'],
                'u' => $userId,
                'id' => (int) $row['id'],
            ]);

            return (int) $row['id'];
        }

        $this->db->prepare(
            "INSERT INTO email_contas
                (provedor, auth_mode, email, nome_exibicao, access_token_enc, refresh_token_enc, token_expires_at, scopes, status, ativo, conectado_por, conectado_em, atualizado_em)
             VALUES
                (:p, 'oauth', :e, :n, :at, :rt, :exp, :sc, 'conectado', 1, :u, NOW(), NOW())"
        )->execute([
            'p' => $provedor,
            'e' => $email,
            'n' => $profile['nome'],
            'at' => TokenCrypto::encrypt($tokens['access_token']),
            'rt' => TokenCrypto::encrypt((string) $refresh),
            'exp' => $expires,
            'sc' => $tokens['scope'],
            'u' => $userId,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /** @return list<array<string,mixed>> */
    public function listarContas(): array
    {
        return $this->db->query(
            'SELECT id, provedor, auth_mode, email, nome_exibicao, smtp_host, token_expires_at, status, ativo, ultimo_erro, conectado_em, atualizado_em
             FROM email_contas
             ORDER BY ativo DESC, id DESC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return ?array<string,mixed> */
    public function contaAtiva(): ?array
    {
        $row = $this->db->query(
            "SELECT * FROM email_contas WHERE ativo = 1 AND status IN ('conectado','expirado','erro') ORDER BY id DESC LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function definirAtiva(int $id): void
    {
        $this->db->exec('UPDATE email_contas SET ativo = 0');
        $st = $this->db->prepare("UPDATE email_contas SET ativo = 1, status = 'conectado', atualizado_em = NOW() WHERE id = :id");
        $st->execute(['id' => $id]);
    }

    public function desconectar(int $id): void
    {
        $this->db->prepare(
            "UPDATE email_contas SET
                status = 'desconectado',
                ativo = 0,
                access_token_enc = NULL,
                refresh_token_enc = NULL,
                password_enc = NULL,
                atualizado_em = NOW()
             WHERE id = :id"
        )->execute(['id' => $id]);
    }

    /**
     * Garante access token válido (refresh se necessário).
     *
     * @return array{conta:array<string,mixed>,access_token:string}
     */
    public function accessTokenPronto(?int $contaId = null): array
    {
        if ($contaId !== null) {
            $st = $this->db->prepare('SELECT * FROM email_contas WHERE id = :id');
            $st->execute(['id' => $contaId]);
            $conta = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } else {
            $conta = $this->contaAtiva();
        }
        if ($conta === null) {
            throw new RuntimeException('Nenhuma conta de e-mail conectada.');
        }
        if (($conta['status'] ?? '') === 'desconectado') {
            throw new RuntimeException('Conta desconectada.');
        }
        if (($conta['auth_mode'] ?? 'oauth') === 'smtp') {
            throw new RuntimeException('Conta SMTP ativa — use o envio SMTP.');
        }

        $access = TokenCrypto::decrypt((string) $conta['access_token_enc']);
        $expiresAt = $conta['token_expires_at'] ?? null;
        $precisaRefresh = $access === ''
            || ($expiresAt !== null && strtotime((string) $expiresAt) <= time() + 30);

        if ($precisaRefresh) {
            $access = $this->refreshAccessToken($conta);
            $st = $this->db->prepare('SELECT * FROM email_contas WHERE id = :id');
            $st->execute(['id' => (int) $conta['id']]);
            $conta = $st->fetch(PDO::FETCH_ASSOC) ?: $conta;
        }

        return ['conta' => $conta, 'access_token' => $access];
    }

    /** @param array<string,mixed> $conta */
    private function refreshAccessToken(array $conta): string
    {
        $refresh = TokenCrypto::decrypt((string) ($conta['refresh_token_enc'] ?? ''));
        if ($refresh === '') {
            $this->marcarErro((int) $conta['id'], 'Refresh token ausente — reconecte a conta.');
            throw new RuntimeException('Sessão OAuth expirada. Reconecte a conta.');
        }

        $provedor = (string) $conta['provedor'];
        $cfg = $this->providerConfig($provedor);
        $res = OAuthHttp::request('POST', $cfg['token_url'], [], [
            'client_id' => $cfg['client_id'],
            'client_secret' => $cfg['client_secret'],
            'refresh_token' => $refresh,
            'grant_type' => 'refresh_token',
        ], true);

        if ($res['status'] >= 400 || empty($res['json']['access_token'])) {
            $msg = is_array($res['json'])
                ? (string) ($res['json']['error_description'] ?? $res['json']['error'] ?? 'refresh falhou')
                : 'refresh falhou';
            $this->marcarErro((int) $conta['id'], $msg);
            throw new RuntimeException('Não foi possível renovar o token. Reconecte a conta.');
        }

        $expires = null;
        if (!empty($res['json']['expires_in'])) {
            $expires = date('Y-m-d H:i:s', time() + (int) $res['json']['expires_in'] - 60);
        }
        $newRefresh = isset($res['json']['refresh_token'])
            ? (string) $res['json']['refresh_token']
            : $refresh;

        $this->db->prepare(
            "UPDATE email_contas SET
                access_token_enc = :at,
                refresh_token_enc = :rt,
                token_expires_at = :exp,
                status = 'conectado',
                ultimo_erro = NULL,
                atualizado_em = NOW()
             WHERE id = :id"
        )->execute([
            'at' => TokenCrypto::encrypt((string) $res['json']['access_token']),
            'rt' => TokenCrypto::encrypt($newRefresh),
            'exp' => $expires,
            'id' => (int) $conta['id'],
        ]);

        return (string) $res['json']['access_token'];
    }

    public function marcarErro(int $id, string $msg): void
    {
        $this->db->prepare(
            "UPDATE email_contas SET status = 'erro', ultimo_erro = :e, atualizado_em = NOW() WHERE id = :id"
        )->execute(['e' => mb_substr($msg, 0, 1000), 'id' => $id]);
    }

    public function logEnvio(?int $contaId, ?string $provedor, string $to, string $subject, bool $ok, ?string $erro): void
    {
        $this->db->prepare(
            'INSERT INTO email_envios_log (conta_id, provedor, destinatario, assunto, status, erro_mensagem)
             VALUES (:c, :p, :d, :a, :s, :e)'
        )->execute([
            'c' => $contaId,
            'p' => $provedor,
            'd' => mb_substr($to, 0, 255),
            'a' => mb_substr($subject, 0, 255),
            's' => $ok ? 'enviado' : 'erro',
            'e' => $erro,
        ]);
    }

    private function assertProvider(string $provedor): string
    {
        $provedor = strtolower(trim($provedor));
        if (!in_array($provedor, ['gmail', 'microsoft'], true)) {
            throw new RuntimeException('Provedor inválido.');
        }

        return $provedor;
    }
}
