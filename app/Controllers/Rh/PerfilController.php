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
        Auth::requirePermission('perfis.ver');
        $pdo = Db::tryConnection();
        $perfis = [];
        $dbOk = $pdo instanceof PDO;
        if ($dbOk) {
            $perfis = (new PerfilService($pdo))->listar();
        }

        $podeCriar = Auth::canAny('perfis.criar', 'perfis.operar', 'perfis.gerenciar');
        ob_start();
        if ($podeCriar) {
            ?>
        <a class="btn btn-primary btn-sm" href="/rh/perfis/novo">Novo perfil</a>
            <?php
        }
        $topbarActions = ob_get_clean() ?: '';

        View::render('rh/perfis/index', [
            'pageTitle' => 'Perfis',
            'pageSubtitle' => 'Gestão 360° — Ver, Criar, Editar, Excluir e Operar por tela',
            'activeMenu' => 'perfis',
            'extraCss' => ['css/perfis.css?v=perfis-360-20261116'],
            'topbarActions' => $topbarActions,
            'dbOk' => $dbOk,
            'perfis' => $perfis,
            'flashOk' => $_GET['ok'] ?? null,
            'flashErro' => $_GET['erro'] ?? null,
        ]);
    }

    public function createForm(): void
    {
        Auth::requirePermission('perfis.criar');
        $this->form(null);
    }

    public function editForm(string $id = ''): void
    {
        Auth::requirePermission('perfis.editar');
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
    private function form(?array $perfil, ?string $error = null): void
    {
        $pdo = Db::tryConnection();
        $matriz = [];
        if ($pdo instanceof PDO) {
            $matriz = (new PerfilService($pdo))->matrizParaUi();
        }
        View::render('rh/perfis/form', [
            'pageTitle' => $perfil ? 'Editar perfil' : 'Novo perfil',
            'pageSubtitle' => $perfil ? (string) ($perfil['codigo'] ?? '') : 'Matriz 360° de acesso',
            'activeMenu' => 'perfis',
            'extraCss' => ['css/perfis.css?v=perfis-360-20261116'],
            'perfil' => $perfil,
            'matriz' => $matriz,
            'error' => $error,
        ]);
    }

    public function duplicar(string $id = ''): void
    {
        Auth::requirePermission('perfis.criar');
        $pdo = Db::tryConnection();
        if (!$pdo instanceof PDO) {
            header('Location: /rh/perfis?erro=' . rawurlencode('MySQL indisponível'));
            exit;
        }
        try {
            $origemId = (int) $id;
            $codigo = (string) ($_POST['codigo'] ?? '');
            $nome = (string) ($_POST['nome'] ?? '');
            (new PerfilService($pdo))->duplicar($origemId, $codigo, $nome, Auth::id());
            header('Location: /rh/perfis?ok=criado');
        } catch (Throwable $e) {
            header('Location: /rh/perfis?erro=' . rawurlencode($e->getMessage()));
        }
        exit;
    }

    public function excluir(string $id = ''): void
    {
        Auth::requirePermission('perfis.excluir');
        $pdo = Db::tryConnection();
        if (!$pdo instanceof PDO) {
            header('Location: /rh/perfis?erro=' . rawurlencode('MySQL indisponível'));
            exit;
        }
        try {
            (new PerfilService($pdo))->excluir((int) $id, Auth::id());
            header('Location: /rh/perfis?ok=excluido');
        } catch (Throwable $e) {
            header('Location: /rh/perfis?erro=' . rawurlencode($e->getMessage()));
        }
        exit;
    }

    public function store(): void
    {
        Auth::requirePermission('perfis.criar');
        $this->save(null);
    }

    public function update(string $id = ''): void
    {
        Auth::requirePermission('perfis.editar');
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
            $perfil = $id ? (new PerfilService($pdo))->buscar($id) : [
                'codigo' => $data['codigo'],
                'nome' => $data['nome'],
                'descricao' => $data['descricao'],
                'status' => $data['status'],
                'sistema' => 0,
                'permissao_ids' => $data['permissao_ids'],
            ];
            $this->form($perfil, $e->getMessage());
            return;
        }
        exit;
    }
}
