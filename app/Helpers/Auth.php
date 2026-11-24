<?php

declare(strict_types=1);

namespace App\Helpers;

use App\Config\Database;
use App\Helpers\EscopoAcesso;
use PDO;
use Throwable;

final class Auth
{
    private static ?bool $activeChecked = null;

    public static function start(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
                || ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443');
            $appCfg = [];
            try {
                $appCfg = require dirname(__DIR__, 2) . '/config/app.php';
            } catch (Throwable) {
                $appCfg = [];
            }
            $forceSecure = (($appCfg['env'] ?? '') === 'production') || $https;
            session_set_cookie_params([
                'httponly' => true,
                'samesite' => 'Lax',
                'secure' => $forceSecure,
            ]);
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

        session_regenerate_id(true);
        self::$activeChecked = true;
        $acesso = self::carregarAcesso($pdo, (int) $user['id']);
        $contratoIds = in_array('admin', $acesso['perfis'], true)
            ? null
            : EscopoAcesso::carregarContratoIds($pdo, (int) $user['id']);
        $_SESSION['user'] = [
            'id' => (int) $user['id'],
            'name' => (string) $user['nome'],
            'email' => (string) $user['email'],
            'telefone' => (string) ($user['telefone'] ?? ''),
            'avatar' => trim((string) ($user['avatar_path'] ?? '')),
            'role' => $acesso['role'],
            'perfis' => $acesso['perfis'],
            'permissoes' => $acesso['permissoes'],
            // null = irrestrito; lista = só esses contratos
            'contrato_ids' => ($contratoIds === [] || $contratoIds === null) ? null : $contratoIds,
        ];

        try {
            $ip = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
            if (str_contains($ip, ',')) {
                $ip = trim(explode(',', $ip)[0]);
            }
            $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
            (new \App\Services\AtividadeService($pdo))->abrirSessao(
                (int) $user['id'],
                session_id(),
                $ip !== '' ? $ip : null,
                $ua !== '' ? $ua : null
            );
        } catch (Throwable) {
        }

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
            if (in_array('admin', $acesso['perfis'], true)) {
                $_SESSION['user']['contrato_ids'] = null;
            } else {
                $ids = EscopoAcesso::carregarContratoIds($pdo, $id);
                $_SESSION['user']['contrato_ids'] = $ids === [] ? null : $ids;
            }
        } catch (Throwable) {
        }
    }

    /**
     * Recarrega nome/e-mail/telefone/avatar na sessão (após edição do perfil).
     */
    public static function refreshSessionProfile(): void
    {
        self::start();
        $id = self::id();
        if ($id === null) {
            return;
        }
        try {
            $pdo = Database::connection();
            $st = $pdo->prepare(
                'SELECT nome, email, telefone, avatar_path FROM usuarios WHERE id = :id LIMIT 1'
            );
            $st->execute(['id' => $id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                return;
            }
            $_SESSION['user']['name'] = (string) $row['nome'];
            $_SESSION['user']['email'] = (string) $row['email'];
            $_SESSION['user']['telefone'] = (string) ($row['telefone'] ?? '');
            $_SESSION['user']['avatar'] = trim((string) ($row['avatar_path'] ?? ''));
            self::refreshSessionAccess();
        } catch (Throwable) {
        }
    }

    public static function avatarUrl(?array $user = null): ?string
    {
        $user ??= self::user();
        $path = trim((string) ($user['avatar'] ?? ''));
        if ($path === '') {
            return null;
        }
        $id = (int) ($user['id'] ?? 0);

        return '/conta/avatar?id=' . $id . '&v=' . substr(sha1($path), 0, 8);
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

        // Permissões vêm só do que está marcado no(s) perfil(is).
        // Novas permissões do catálogo são concedidas ao admin na migração (ensureCatalog),
        // mas remoções manuais no formulário de perfis passam a valer.

        return [
            'role' => $role,
            'perfis' => $perfis,
            'permissoes' => $permissoes,
        ];
    }

    public static function check(): bool
    {
        self::start();
        if (!isset($_SESSION['user']['id'])) {
            return false;
        }
        if (self::$activeChecked === null) {
            self::$activeChecked = true;
            try {
                $pdo = Database::connection();
                $st = $pdo->prepare("SELECT status FROM usuarios WHERE id = :id LIMIT 1");
                $st->execute(['id' => (int) $_SESSION['user']['id']]);
                $status = $st->fetchColumn();
                if ($status !== 'ativo') {
                    $_SESSION = [];
                    self::$activeChecked = false;
                    return false;
                }
                $acesso = self::carregarAcesso($pdo, (int) $_SESSION['user']['id']);
                $_SESSION['user']['role'] = $acesso['role'];
                $_SESSION['user']['perfis'] = $acesso['perfis'];
                $_SESSION['user']['permissoes'] = $acesso['permissoes'];
                if (!array_key_exists('avatar', $_SESSION['user'])) {
                    $st2 = $pdo->prepare(
                        'SELECT nome, email, telefone, avatar_path FROM usuarios WHERE id = :id LIMIT 1'
                    );
                    $st2->execute(['id' => (int) $_SESSION['user']['id']]);
                    $row = $st2->fetch(PDO::FETCH_ASSOC);
                    if ($row !== false) {
                        $_SESSION['user']['name'] = (string) $row['nome'];
                        $_SESSION['user']['email'] = (string) $row['email'];
                        $_SESSION['user']['telefone'] = (string) ($row['telefone'] ?? '');
                        $_SESSION['user']['avatar'] = trim((string) ($row['avatar_path'] ?? ''));
                    } else {
                        $_SESSION['user']['avatar'] = '';
                    }
                }
            } catch (Throwable) {
                // Se DB estiver indisponível, mantém sessão atual sem derrubar o fallback
            }
        }

        return self::$activeChecked && isset($_SESSION['user']['id']);
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

        $lista = $user['permissoes'] ?? [];
        if (in_array($permissao, $lista, true)) {
            return true;
        }

        // Atalhos legado: cadastros.ver/editar liberam os cadastros granulares do menu.
        foreach (self::aliasesPermissao($permissao) as $alias) {
            if (in_array($alias, $lista, true)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private static function aliasesPermissao(string $permissao): array
    {
        static $map = null;
        if ($map === null) {
            $map = self::buildAliasMap();
        }

        return $map[$permissao] ?? [];
    }

    /**
     * Ponte legado ↔ matriz 360° (Ver/Criar/Editar/Excluir/Operar).
     *
     * @return array<string, list<string>>
     */
    private static function buildAliasMap(): array
    {
        $map = [];
        $add = static function (string $code, string ...$aliases) use (&$map): void {
            $map[$code] = array_values(array_unique(array_merge($map[$code] ?? [], $aliases)));
        };

        foreach (\App\Services\PerfilService::mapaCadastrosMenu() as $meta) {
            $ver = $meta['ver'];
            $criar = $meta['criar'];
            $editar = $meta['editar'];
            $excluir = $meta['excluir'];
            // Granulares aceitam atalho legado e ações “mais fortes” do mesmo recurso.
            $add($ver, 'cadastros.ver', 'cadastros.editar', $criar, $editar, $excluir);
            $add($criar, 'cadastros.editar', $editar);
            $add($editar, 'cadastros.editar');
            $add($excluir, 'cadastros.editar', $editar);
        }
        $add('cadastros.ver', ...\App\Services\PerfilService::codigosCadastrosVer());
        $add('cadastros.editar', ...\App\Services\PerfilService::codigosCadastrosEditar());

        // Pacotes legado ↔ ações 360 do mesmo módulo.
        $pacotes = [
            'usuarios.gerenciar' => ['usuarios.ver', 'usuarios.criar', 'usuarios.editar', 'usuarios.excluir', 'usuarios.operar'],
            'perfis.gerenciar' => ['perfis.ver', 'perfis.criar', 'perfis.editar', 'perfis.excluir', 'perfis.operar'],
            'email.gerenciar' => ['email.ver', 'email.operar'],
            'importacoes.gerenciar' => ['importacoes.ver', 'importacoes.operar'],
            'alertas.gerenciar' => ['alertas_config.ver', 'alertas_config.editar', 'alertas_config.operar'],
            'ponto.excluir_periodo' => ['ponto.excluir'],
            'folha.enviar' => ['folha.operar'],
            'medicao.editar' => ['medicao.operar'],
            'vr.editar' => ['vr.criar', 'vr.editar', 'vr.excluir'],
            'entregas.gerir' => ['entregas.operar', 'entregas.ver'],
        ];
        foreach ($pacotes as $legado => $acoes) {
            $add($legado, ...$acoes);
            foreach ($acoes as $acao) {
                $add($acao, $legado);
            }
        }

        // Leitura implícita quando há ação de escrita/operação no mesmo recurso.
        $implicitos = [
            'efetivo.ver' => ['efetivo.criar', 'efetivo.editar', 'efetivo.excluir', 'efetivo.operar'],
            'ponto.ver' => ['ponto.editar', 'ponto.excluir', 'ponto.operar'],
            'folha.ver' => ['folha.operar', 'folha.enviar'],
            'medicao.ver' => ['medicao.operar', 'medicao.editar'],
            'ra.ver' => ['ra.editar', 'ra.operar'],
            'vr.ver' => ['vr.criar', 'vr.editar', 'vr.excluir'],
            'vr_relatorio.ver' => ['vr_relatorio.operar'],
            'justificativa_tipos.ver' => ['justificativa_tipos.criar', 'justificativa_tipos.editar', 'justificativa_tipos.excluir', 'ponto.editar'],
            'alertas.ver' => ['alertas.operar'],
            'alertas_config.ver' => ['alertas_config.editar', 'alertas_config.operar', 'alertas.gerenciar'],
            'entregas.ver' => ['entregas.operar', 'entregas.gerir'],
            'mapa.ver' => ['dashboard.ver'],
            'dashboard.ver' => ['mapa.ver'],
        ];
        foreach ($implicitos as $ver => $fortes) {
            $add($ver, ...$fortes);
        }

        return $map;
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
            $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
            if (str_contains($accept, 'application/json')) {
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode([
                    'ok' => false,
                    'type' => 'error',
                    'message' => 'Seu perfil não tem permissão para esta ação (' . $permissao . ').',
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
            View::render('ui/forbidden', [
                'pageTitle' => 'Acesso negado',
                'pageSubtitle' => 'Seu perfil não tem permissão para esta área',
                'activeMenu' => '',
                'permissao' => $permissao,
            ]);
            exit;
        }
    }

    public static function requireAnyPermission(string ...$permissoes): void
    {
        self::requireLogin();
        if (self::canAny(...$permissoes)) {
            return;
        }
        self::requirePermission($permissoes[0] ?? 'acesso');
    }

    public static function logout(): void
    {
        self::start();
        try {
            $uid = self::id();
            $sid = session_id();
            if ($uid !== null && $sid !== '') {
                $pdo = Database::connection();
                (new \App\Services\AtividadeService($pdo))->encerrarSessao($sid, 'logout');
            }
        } catch (Throwable) {
        }
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
