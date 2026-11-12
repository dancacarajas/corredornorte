<?php

declare(strict_types=1);

namespace App\Controllers\Rh;

use App\Helpers\Auth;
use App\Helpers\Db;
use App\Helpers\View;
use App\Services\PerfilService;
use App\Services\UsuarioService;
use PDO;
use Throwable;

final class UsuarioController
{
    public function index(): void
    {
        Auth::requirePermission('usuarios.gerenciar');
        $pdo = Db::tryConnection();
        $usuarios = [];
        $dbOk = $pdo instanceof PDO;
        if ($dbOk) {
            $usuarios = (new UsuarioService($pdo))->listar($_GET['q'] ?? null);
        }

        ob_start();
        ?>
        <a class="btn btn-primary btn-sm" href="/rh/usuarios/novo">Novo usuário</a>
        <?php
        $topbarActions = ob_get_clean() ?: '';

        View::render('rh/usuarios/index', [
            'pageTitle' => 'Usuários',
            'pageSubtitle' => 'Contas de acesso ao Arcadis RH',
            'activeMenu' => 'usuarios',
            'topbarActions' => $topbarActions,
            'dbOk' => $dbOk,
            'usuarios' => $usuarios,
            'busca' => (string) ($_GET['q'] ?? ''),
            'flashOk' => $_GET['ok'] ?? null,
            'flashErro' => $_GET['erro'] ?? null,
        ]);
    }

    public function createForm(): void
    {
        Auth::requirePermission('usuarios.gerenciar');
        $this->form(null);
    }

    public function editForm(string $id = ''): void
    {
        Auth::requirePermission('usuarios.gerenciar');
        $pdo = Db::tryConnection();
        $usuario = null;
        if ($pdo instanceof PDO) {
            $usuario = (new UsuarioService($pdo))->buscar((int) $id);
        }
        if ($usuario === null) {
            header('Location: /rh/usuarios?erro=' . rawurlencode('Usuário não encontrado'));
            exit;
        }
        $this->form($usuario);
    }

    /** @param ?array<string,mixed> $usuario */
    private function form(?array $usuario): void
    {
        $pdo = Db::tryConnection();
        $perfis = [];
        if ($pdo instanceof PDO) {
            $perfis = (new PerfilService($pdo))->listar(true);
        }
        View::render('rh/usuarios/form', [
            'pageTitle' => $usuario ? 'Editar usuário' : 'Novo usuário',
            'pageSubtitle' => $usuario ? (string) $usuario['email'] : 'Crie a conta e vincule os perfis',
            'activeMenu' => 'usuarios',
            'usuario' => $usuario,
            'perfis' => $perfis,
            'error' => null,
        ]);
    }

    public function store(): void
    {
        Auth::requirePermission('usuarios.gerenciar');
        $this->save(null);
    }

    public function update(string $id = ''): void
    {
        Auth::requirePermission('usuarios.gerenciar');
        $this->save((int) $id);
    }

    private function save(?int $id): void
    {
        $pdo = Db::tryConnection();
        if (!$pdo instanceof PDO) {
            header('Location: /rh/usuarios?erro=' . rawurlencode('MySQL indisponível'));
            exit;
        }

        $data = [
            'nome' => (string) ($_POST['nome'] ?? ''),
            'email' => (string) ($_POST['email'] ?? ''),
            'senha' => (string) ($_POST['senha'] ?? ''),
            'status' => (string) ($_POST['status'] ?? 'ativo'),
            'perfil_ids' => array_map('intval', (array) ($_POST['perfil_ids'] ?? [])),
        ];

        try {
            $svc = new UsuarioService($pdo);
            if ($id === null) {
                $svc->criar($data, Auth::id());
                header('Location: /rh/usuarios?ok=criado');
            } else {
                $svc->atualizar($id, $data, Auth::id());
                if (Auth::id() === $id) {
                    Auth::refreshSessionAccess();
                    $_SESSION['user']['name'] = trim($data['nome']);
                    $_SESSION['user']['email'] = strtolower(trim($data['email']));
                }
                header('Location: /rh/usuarios?ok=atualizado');
            }
        } catch (Throwable $e) {
            $perfis = (new PerfilService($pdo))->listar(true);
            $usuario = $id ? (new UsuarioService($pdo))->buscar($id) : [
                'nome' => $data['nome'],
                'email' => $data['email'],
                'status' => $data['status'],
                'perfis' => array_map(static fn (int $pid): array => ['id' => $pid], $data['perfil_ids']),
            ];
            View::render('rh/usuarios/form', [
                'pageTitle' => $id ? 'Editar usuário' : 'Novo usuário',
                'pageSubtitle' => 'Corrija os dados e salve novamente',
                'activeMenu' => 'usuarios',
                'usuario' => $usuario,
                'perfis' => $perfis,
                'error' => $e->getMessage(),
            ]);
            return;
        }
        exit;
    }
}
