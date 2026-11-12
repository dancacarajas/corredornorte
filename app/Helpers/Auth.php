<?php

declare(strict_types=1);

namespace App\Helpers;

use App\Config\Database;
use PDO;
use Throwable;

final class Auth
{
    public static function start(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

    public static function attempt(string $email, string $password): bool
    {
        self::start();
        try {
            $pdo = Database::connection();
        } catch (Throwable) {
            return false;
        }

        $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE email = :email AND status = 'ativo' LIMIT 1");
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user === false || !password_verify($password, (string) $user['senha_hash'])) {
            return false;
        }

        $acesso = self::carregarAcesso($pdo, (int) $user['id']);
        $_SESSION['user'] = [
            'id' => (int) $user['id'],
            'name' => (string) $user['nome'],
            'email' => (string) $user['email'],
            'role' => $acesso['role'],
            'perfis' => $acesso['perfis'],
            'permissoes' => $acesso['permissoes'],
        ];

        return true;
    }

    /**
     * Recarrega perfis/permissões na sessão (após edição do próprio usuário).
     */
    public static function refreshSessionAccess(): void
    {
        self::start();
        $id = self::id();
        if ($id === null) {
            return;
        }
        try {
            $pdo = Database::connection();
            $acesso = self::carregarAcesso($pdo, $id);
            $_SESSION['user']['role'] = $acesso['role'];
            $_SESSION['user']['perfis'] = $acesso['perfis'];
            $_SESSION['user']['permissoes'] = $acesso['permissoes'];
        } catch (Throwable) {
        }
    }

    /**
     * @return array{role:string,perfis:list<string>,permissoes:list<string>}
     */
    private static function carregarAcesso(PDO $pdo, int $usuarioId): array
    {
        $perfis = [];
        try {
            $st = $pdo->prepare(
                "SELECT p.codigo, p.nome
                 FROM usuario_perfis up
                 INNER JOIN perfis p ON p.id = up.perfil_id
                 WHERE up.usuario_id = :id AND p.status = 'ativo'
                 ORDER BY p.sistema DESC, p.nome"
            );
            $st->execute(['id' => $usuarioId]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $row) {
                $perfis[] = (string) $row['codigo'];
            }
            $role = $rows !== [] ? (string) $rows[0]['nome'] : 'Sem perfil';
        } catch (Throwable) {
            return ['role' => 'Usuário', 'perfis' => [], 'permissoes' => []];
        }

        $permissoes = [];
        try {
            $st = $pdo->prepare(
                "SELECT DISTINCT pe.codigo
                 FROM usuario_perfis up
                 INNER JOIN perfis p ON p.id = up.perfil_id AND p.status = 'ativo'
                 INNER JOIN perfil_permissoes pp ON pp.perfil_id = p.id
                 INNER JOIN permissoes pe ON pe.id = pp.permissao_id
                 WHERE up.usuario_id = :id"
            );
            $st->execute(['id' => $usuarioId]);
            $permissoes = array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
        } catch (Throwable) {
            $permissoes = [];
        }

        // Admin legado: perfil admin = todas as permissões conhecidas
        if (in_array('admin', $perfis, true)) {
            try {
                $all = $pdo->query('SELECT codigo FROM permissoes')->fetchAll(PDO::FETCH_COLUMN) ?: [];
                $permissoes = array_values(array_unique(array_merge($permissoes, array_map('strval', $all))));
            } catch (Throwable) {
            }
        }

        return [
            'role' => $role,
            'perfis' => $perfis,
            'permissoes' => $permissoes,
        ];
    }

    public static function check(): bool
    {
        self::start();

        return isset($_SESSION['user']['id']);
    }

    public static function user(): ?array
    {
        self::start();

        return $_SESSION['user'] ?? null;
    }

    public static function id(): ?int
    {
        $user = self::user();

        return $user ? (int) $user['id'] : null;
    }

    /** @return list<string> */
    public static function permissoes(): array
    {
        $user = self::user();

        return array_values($user['permissoes'] ?? []);
    }

    public static function can(string $permissao): bool
    {
        if (!self::check()) {
            return false;
        }
        $user = self::user() ?? [];
        // Sessão antiga (antes do módulo de perfis): recarrega acesso
        if (!isset($user['permissoes']) || !isset($user['perfis'])) {
            self::refreshSessionAccess();
            $user = self::user() ?? [];
        }
        $perfis = $user['perfis'] ?? [];
        if (in_array('admin', $perfis, true)) {
            return true;
        }

        return in_array($permissao, $user['permissoes'] ?? [], true);
    }

    public static function canAny(string ...$permissoes): bool
    {
        foreach ($permissoes as $p) {
            if (self::can($p)) {
                return true;
            }
        }

        return false;
    }

    public static function requirePermission(string $permissao): void
    {
        self::requireLogin();
        if (!self::can($permissao)) {
            http_response_code(403);
            View::render('ui/forbidden', [
                'pageTitle' => 'Acesso negado',
                'pageSubtitle' => 'Seu perfil não tem permissão para esta área',
                'activeMenu' => '',
                'permissao' => $permissao,
            ]);
            exit;
        }
    }

    public static function logout(): void
    {
        self::start();
        $_SESSION = [];
        session_destroy();
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            header('Location: /login');
            exit;
        }
    }
}
