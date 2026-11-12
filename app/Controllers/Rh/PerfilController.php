<?php

declare(strict_types=1);

namespace App\Controllers\Rh;

use App\Helpers\Auth;
use App\Helpers\Db;
use App\Helpers\View;
use App\Services\PerfilService;
use PDO;
use Throwable;

final class PerfilController
{
    public function index(): void
    {
        Auth::requirePermission('perfis.gerenciar');
        $pdo = Db::tryConnection();
        $perfis = [];
        $dbOk = $pdo instanceof PDO;
        if ($dbOk) {
            $perfis = (new PerfilService($pdo))->listar();
        }

        ob_start();
        ?>
        <a class="btn btn-primary btn-sm" href="/rh/perfis/novo">Novo perfil</a>
        <?php
        $topbarActions = ob_get_clean() ?: '';

        View::render('rh/perfis/index', [
            'pageTitle' => 'Perfis',
            'pageSubtitle' => 'Papéis e permissões de acesso',
            'activeMenu' => 'perfis',
            'topbarActions' => $topbarActions,
            'dbOk' => $dbOk,
            'perfis' => $perfis,
            'flashOk' => $_GET['ok'] ?? null,
            'flashErro' => $_GET['erro'] ?? null,
        ]);
    }

    public function createForm(): void
    {
        Auth::requirePermission('perfis.gerenciar');
        $this->form(null);
    }

    public function editForm(string $id = ''): void
    {
        Auth::requirePermission('perfis.gerenciar');
        $pdo = Db::tryConnection();
        $perfil = null;
        if ($pdo instanceof PDO) {
            $perfil = (new PerfilService($pdo))->buscar((int) $id);
        }
        if ($perfil === null) {
            header('Location: /rh/perfis?erro=' . rawurlencode('Perfil não encontrado'));
            exit;
        }
        $this->form($perfil);
    }

    /** @param ?array<string,mixed> $perfil */
    private function form(?array $perfil): void
    {
        $pdo = Db::tryConnection();
        $permissoes = [];
        if ($pdo instanceof PDO) {
            $permissoes = (new PerfilService($pdo))->catalogoPermissoes();
        }
        View::render('rh/perfis/form', [
            'pageTitle' => $perfil ? 'Editar perfil' : 'Novo perfil',
            'pageSubtitle' => $perfil ? (string) $perfil['codigo'] : 'Defina o papel e as permissões',
            'activeMenu' => 'perfis',
            'perfil' => $perfil,
            'permissoes' => $permissoes,
            'error' => null,
        ]);
    }

    public function store(): void
    {
        Auth::requirePermission('perfis.gerenciar');
        $this->save(null);
    }

    public function update(string $id = ''): void
    {
        Auth::requirePermission('perfis.gerenciar');
        $this->save((int) $id);
    }

    private function save(?int $id): void
    {
        $pdo = Db::tryConnection();
        if (!$pdo instanceof PDO) {
            header('Location: /rh/perfis?erro=' . rawurlencode('MySQL indisponível'));
            exit;
        }

        $data = [
            'codigo' => (string) ($_POST['codigo'] ?? ''),
            'nome' => (string) ($_POST['nome'] ?? ''),
            'descricao' => (string) ($_POST['descricao'] ?? ''),
            'status' => (string) ($_POST['status'] ?? 'ativo'),
            'permissao_ids' => array_map('intval', (array) ($_POST['permissao_ids'] ?? [])),
        ];

        try {
            $svc = new PerfilService($pdo);
            if ($id === null) {
                $svc->criar($data, Auth::id());
                header('Location: /rh/perfis?ok=criado');
            } else {
                $svc->atualizar($id, $data, Auth::id());
                Auth::refreshSessionAccess();
                header('Location: /rh/perfis?ok=atualizado');
            }
        } catch (Throwable $e) {
            $permissoes = (new PerfilService($pdo))->catalogoPermissoes();
            $perfil = $id ? (new PerfilService($pdo))->buscar($id) : [
                'codigo' => $data['codigo'],
                'nome' => $data['nome'],
                'descricao' => $data['descricao'],
                'status' => $data['status'],
                'sistema' => 0,
                'permissao_ids' => $data['permissao_ids'],
            ];
            View::render('rh/perfis/form', [
                'pageTitle' => $id ? 'Editar perfil' : 'Novo perfil',
                'pageSubtitle' => 'Corrija os dados e salve novamente',
                'activeMenu' => 'perfis',
                'perfil' => $perfil,
                'permissoes' => $permissoes,
                'error' => $e->getMessage(),
            ]);
            return;
        }
        exit;
    }
}
