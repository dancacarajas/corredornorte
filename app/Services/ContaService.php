<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\AppUrl;
use App\Helpers\UploadGuard;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Conta do usuário logado: perfil, senha, avatar e recuperação de senha.
 */
final class ContaService
{
    public const AVATAR_MAX_BYTES = 2_097_152; // 2 MB
    public const RESET_TTL_SECONDS = 3600; // 1 h
    public const LOGIN_MAX_FAILS = 8;
    public const LOGIN_WINDOW_SECONDS = 900; // 15 min
    public const SENHA_MIN = 8;

    public function __construct(private PDO $db)
    {
        $this->ensureSchema();
    }

    public function ensureSchema(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        try {
            $this->ensureColumn('usuarios', 'telefone', 'VARCHAR(32) NULL');
            $this->ensureColumn('usuarios', 'avatar_path', 'VARCHAR(255) NULL');
            $this->ensureColumn('usuarios', 'last_login_at', 'DATETIME NULL');
            $this->ensureColumn('usuarios', 'password_changed_at', 'DATETIME NULL');

            $this->db->exec(
                "CREATE TABLE IF NOT EXISTS password_reset_tokens (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    usuario_id BIGINT UNSIGNED NOT NULL,
                    token_hash CHAR(64) NOT NULL,
                    expires_at DATETIME NOT NULL,
                    used_at DATETIME NULL,
                    request_ip VARCHAR(45) NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY uk_prt_token (token_hash),
                    KEY idx_prt_usuario (usuario_id),
                    KEY idx_prt_expires (expires_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );

            $this->db->exec(
                "CREATE TABLE IF NOT EXISTS login_attempts (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    email VARCHAR(190) NOT NULL,
                    ip VARCHAR(45) NOT NULL,
                    sucesso TINYINT(1) NOT NULL DEFAULT 0,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY idx_login_email_created (email, created_at),
                    KEY idx_login_ip_created (ip, created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
        } catch (Throwable) {
            // ambiente sem DDL: segue com o que existir
        }
    }

    private function ensureColumn(string $table, string $column, string $definition): void
    {
        $st = $this->db->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c'
        );
        $st->execute(['t' => $table, 'c' => $column]);
        if ((int) $st->fetchColumn() > 0) {
            return;
        }
        $tbl = str_replace('`', '``', $table);
        $col = str_replace('`', '``', $column);
        $this->db->exec("ALTER TABLE `{$tbl}` ADD COLUMN `{$col}` {$definition}");
    }

    public function avatarsDir(): string
    {
        $dir = dirname(__DIR__, 2) . '/storage/uploads/avatars';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        return $dir;
    }

    /** @return ?array<string,mixed> */
    public function buscarUsuario(int $id): ?array
    {
        $st = $this->db->prepare(
            'SELECT id, nome, email, telefone, avatar_path, status, last_login_at, password_changed_at, created_at, updated_at
             FROM usuarios WHERE id = :id LIMIT 1'
        );
        $st->execute(['id' => $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function loginBloqueado(string $email, string $ip): bool
    {
        $email = strtolower(trim($email));
        try {
            $secs = (int) self::LOGIN_WINDOW_SECONDS;
            $st = $this->db->prepare(
                "SELECT COUNT(*) FROM login_attempts
                 WHERE sucesso = 0
                   AND created_at >= (NOW() - INTERVAL {$secs} SECOND)
                   AND (email = :email OR ip = :ip)"
            );
            $st->execute(['email' => $email, 'ip' => $ip]);

            return (int) $st->fetchColumn() >= self::LOGIN_MAX_FAILS;
        } catch (Throwable) {
            return false;
        }
    }

    public function registrarTentativaLogin(string $email, string $ip, bool $sucesso): void
    {
        try {
            $this->db->prepare(
                'INSERT INTO login_attempts (email, ip, sucesso) VALUES (:e, :ip, :s)'
            )->execute([
                'e' => strtolower(trim($email)),
                'ip' => $ip,
                's' => $sucesso ? 1 : 0,
            ]);
            if ($sucesso) {
                $this->db->prepare(
                    'DELETE FROM login_attempts WHERE email = :e AND sucesso = 0'
                )->execute(['e' => strtolower(trim($email))]);
            }
        } catch (Throwable) {
        }
    }

    public function marcarUltimoLogin(int $usuarioId): void
    {
        try {
            $this->db->prepare('UPDATE usuarios SET last_login_at = NOW() WHERE id = :id')
                ->execute(['id' => $usuarioId]);
        } catch (Throwable) {
        }
    }

    /**
     * @return array{ok:bool,mensagem:string}
     */
    public function atualizarPerfil(int $usuarioId, string $nome, string $email, ?string $telefone): array
    {
        $nome = trim($nome);
        $email = strtolower(trim($email));
        $telefone = $telefone !== null ? trim($telefone) : null;
        if ($telefone === '') {
            $telefone = null;
        }

        if ($nome === '' || mb_strlen($nome) < 3) {
            throw new RuntimeException('Informe o nome completo (mín. 3 caracteres).');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('E-mail inválido.');
        }
        if ($telefone !== null && mb_strlen($telefone) > 32) {
            throw new RuntimeException('Telefone muito longo.');
        }

        $st = $this->db->prepare('SELECT id FROM usuarios WHERE email = :e AND id <> :id LIMIT 1');
        $st->execute(['e' => $email, 'id' => $usuarioId]);
        if ($st->fetchColumn()) {
            throw new RuntimeException('Já existe outra conta com este e-mail.');
        }

        $this->db->prepare(
            'UPDATE usuarios SET nome = :n, email = :e, telefone = :t WHERE id = :id'
        )->execute(['n' => $nome, 'e' => $email, 't' => $telefone, 'id' => $usuarioId]);

        return ['ok' => true, 'mensagem' => 'Perfil atualizado.'];
    }

    public function alterarSenha(int $usuarioId, string $senhaAtual, string $novaSenha, string $confirmacao): void
    {
        if ($novaSenha !== $confirmacao) {
            throw new RuntimeException('A confirmação da nova senha não confere.');
        }
        $this->assertSenhaForte($novaSenha);

        $st = $this->db->prepare('SELECT senha_hash, email, nome FROM usuarios WHERE id = :id LIMIT 1');
        $st->execute(['id' => $usuarioId]);
        $user = $st->fetch(PDO::FETCH_ASSOC);
        if ($user === false) {
            throw new RuntimeException('Usuário não encontrado.');
        }
        if (!password_verify($senhaAtual, (string) $user['senha_hash'])) {
            throw new RuntimeException('Senha atual incorreta.');
        }
        if (password_verify($novaSenha, (string) $user['senha_hash'])) {
            throw new RuntimeException('A nova senha deve ser diferente da atual.');
        }

        $this->definirSenha($usuarioId, $novaSenha);
        $this->enviarAvisoSenhaAlterada(
            (string) $user['email'],
            (string) $user['nome']
        );
    }

    public function definirSenha(int $usuarioId, string $novaSenha): void
    {
        $this->assertSenhaForte($novaSenha);
        $this->db->prepare(
            'UPDATE usuarios SET senha_hash = :h, password_changed_at = NOW() WHERE id = :id'
        )->execute([
            'h' => password_hash($novaSenha, PASSWORD_DEFAULT),
            'id' => $usuarioId,
        ]);
        $this->db->prepare(
            'UPDATE password_reset_tokens SET used_at = NOW()
             WHERE usuario_id = :id AND used_at IS NULL'
        )->execute(['id' => $usuarioId]);
    }

    public function assertSenhaForte(string $senha): void
    {
        if (strlen($senha) < self::SENHA_MIN) {
            throw new RuntimeException('A senha deve ter pelo menos ' . self::SENHA_MIN . ' caracteres.');
        }
        if (!preg_match('/[A-Za-z]/', $senha) || !preg_match('/\d/', $senha)) {
            throw new RuntimeException('A senha deve combinar letras e números.');
        }
    }

    /**
     * @param array<string,mixed> $file $_FILES['avatar']
     */
    public function salvarAvatar(int $usuarioId, array $file): string
    {
        $meta = UploadGuard::validate(
            $file,
            ['jpg', 'jpeg', 'png', 'webp'],
            ['image/jpeg', 'image/png', 'image/webp'],
            self::AVATAR_MAX_BYTES
        );

        $atual = $this->buscarUsuario($usuarioId);
        if ($atual === null) {
            throw new RuntimeException('Usuário não encontrado.');
        }

        $ext = $meta['ext'] === 'jpeg' ? 'jpg' : $meta['ext'];
        $nomeArquivo = 'u' . $usuarioId . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $destinoAbs = $this->avatarsDir() . '/' . $nomeArquivo;
        if (!move_uploaded_file((string) $file['tmp_name'], $destinoAbs)) {
            throw new RuntimeException('Não foi possível salvar o avatar.');
        }
        @chmod($destinoAbs, 0644);

        $rel = 'avatars/' . $nomeArquivo;
        $this->db->prepare('UPDATE usuarios SET avatar_path = :p WHERE id = :id')
            ->execute(['p' => $rel, 'id' => $usuarioId]);

        $antigo = trim((string) ($atual['avatar_path'] ?? ''));
        if ($antigo !== '' && $antigo !== $rel) {
            $this->apagarAvatarArquivo($antigo);
        }

        return $rel;
    }

    public function removerAvatar(int $usuarioId): void
    {
        $atual = $this->buscarUsuario($usuarioId);
        if ($atual === null) {
            return;
        }
        $path = trim((string) ($atual['avatar_path'] ?? ''));
        $this->db->prepare('UPDATE usuarios SET avatar_path = NULL WHERE id = :id')
            ->execute(['id' => $usuarioId]);
        if ($path !== '') {
            $this->apagarAvatarArquivo($path);
        }
    }

    public function caminhoAbsolutoAvatar(?string $relPath): ?string
    {
        $rel = trim((string) $relPath);
        if ($rel === '' || str_contains($rel, '..') || !str_starts_with($rel, 'avatars/')) {
            return null;
        }
        $abs = dirname(__DIR__, 2) . '/storage/uploads/' . $rel;
        if (!is_file($abs)) {
            return null;
        }

        return $abs;
    }

    private function apagarAvatarArquivo(string $rel): void
    {
        $abs = $this->caminhoAbsolutoAvatar($rel);
        if ($abs !== null) {
            @unlink($abs);
        }
    }

    /**
     * Sempre retorna mensagem genérica (anti-enumeration).
     */
    public function solicitarResetSenha(string $email, ?string $ip = null): string
    {
        $msg = 'Se o e-mail estiver cadastrado, enviaremos um link para redefinir a senha.';
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $msg;
        }

        $st = $this->db->prepare(
            "SELECT id, nome, email, status FROM usuarios WHERE email = :e LIMIT 1"
        );
        $st->execute(['e' => $email]);
        $user = $st->fetch(PDO::FETCH_ASSOC);
        if ($user === false || (string) $user['status'] !== 'ativo') {
            return $msg;
        }

        $usuarioId = (int) $user['id'];
        $this->db->prepare(
            'UPDATE password_reset_tokens SET used_at = NOW()
             WHERE usuario_id = :id AND used_at IS NULL'
        )->execute(['id' => $usuarioId]);

        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        $ttl = (int) self::RESET_TTL_SECONDS;
        $this->db->prepare(
            "INSERT INTO password_reset_tokens (usuario_id, token_hash, expires_at, request_ip)
             VALUES (:u, :h, DATE_ADD(NOW(), INTERVAL {$ttl} SECOND), :ip)"
        )->execute([
            'u' => $usuarioId,
            'h' => $hash,
            'ip' => $ip,
        ]);

        $resetUrl = AppUrl::base() . '/redefinir-senha/' . $token;
        try {
            $tpl = EmailTemplateService::render('auth.redefinir_senha', [
                'nome' => (string) $user['nome'],
                'portalUrl' => $resetUrl,
                'cta_label' => 'REDEFINIR SENHA',
                'validade' => '1 hora',
            ], $this->db);
            $send = (new MailService())->send(
                (string) $user['email'],
                $tpl['subject'],
                $tpl['text'],
                null,
                $tpl['html']
            );
            if (empty($send['ok'])) {
                // token já criado — operador vê mensagem genérica; log interno
                error_log('Falha ao enviar e-mail de reset: ' . (string) ($send['erro'] ?? 'desconhecido'));
            }
        } catch (Throwable $e) {
            error_log('Falha ao enviar e-mail de reset: ' . $e->getMessage());
        }

        return $msg;
    }

    /** @return ?array{id:int,usuario_id:int,nome:string,email:string} */
    public function validarTokenReset(string $token): ?array
    {
        $token = trim($token);
        if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $hash = hash('sha256', $token);
        $st = $this->db->prepare(
            "SELECT t.id, t.usuario_id, u.nome, u.email, u.status
             FROM password_reset_tokens t
             INNER JOIN usuarios u ON u.id = t.usuario_id
             WHERE t.token_hash = :h
               AND t.used_at IS NULL
               AND t.expires_at > NOW()
             LIMIT 1"
        );
        $st->execute(['h' => $hash]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row === false || (string) $row['status'] !== 'ativo') {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'usuario_id' => (int) $row['usuario_id'],
            'nome' => (string) $row['nome'],
            'email' => (string) $row['email'],
        ];
    }

    public function redefinirSenhaComToken(string $token, string $novaSenha, string $confirmacao): void
    {
        if ($novaSenha !== $confirmacao) {
            throw new RuntimeException('A confirmação da nova senha não confere.');
        }
        $this->assertSenhaForte($novaSenha);
        $info = $this->validarTokenReset($token);
        if ($info === null) {
            throw new RuntimeException('Link inválido ou expirado. Solicite um novo.');
        }

        $this->definirSenha($info['usuario_id'], $novaSenha);
        $this->db->prepare(
            'UPDATE password_reset_tokens SET used_at = NOW() WHERE id = :id'
        )->execute(['id' => $info['id']]);

        $this->enviarAvisoSenhaAlterada($info['email'], $info['nome']);
    }

    /**
     * Admin dispara reset para um usuário (e-mail).
     */
    public function enviarResetParaUsuario(int $usuarioId, ?string $ip = null): string
    {
        $user = $this->buscarUsuario($usuarioId);
        if ($user === null) {
            throw new RuntimeException('Usuário não encontrado.');
        }
        if ((string) $user['status'] !== 'ativo') {
            throw new RuntimeException('Não é possível enviar reset para usuário inativo.');
        }

        return $this->solicitarResetSenha((string) $user['email'], $ip);
    }

    public function enviarBoasVindas(int $usuarioId, ?string $senhaTemporaria = null): void
    {
        $user = $this->buscarUsuario($usuarioId);
        if ($user === null) {
            return;
        }
        $loginUrl = AppUrl::base() . '/login';
        try {
            $tpl = EmailTemplateService::render('auth.boas_vindas', [
                'nome' => (string) $user['nome'],
                'email' => (string) $user['email'],
                'portalUrl' => $loginUrl,
                'cta_label' => 'ACESSAR O SISTEMA',
                'senha_temp' => $senhaTemporaria ?? '',
            ], $this->db);
            (new MailService())->send(
                (string) $user['email'],
                $tpl['subject'],
                $tpl['text'],
                null,
                $tpl['html']
            );
        } catch (Throwable $e) {
            error_log('Falha ao enviar boas-vindas: ' . $e->getMessage());
        }
    }

    private function enviarAvisoSenhaAlterada(string $email, string $nome): void
    {
        try {
            $tpl = EmailTemplateService::render('auth.senha_alterada', [
                'nome' => $nome,
                'portalUrl' => AppUrl::base() . '/login',
                'cta_label' => 'ENTRAR',
            ], $this->db);
            (new MailService())->send($email, $tpl['subject'], $tpl['text'], null, $tpl['html']);
        } catch (Throwable $e) {
            error_log('Falha ao enviar aviso de senha: ' . $e->getMessage());
        }
    }
}
