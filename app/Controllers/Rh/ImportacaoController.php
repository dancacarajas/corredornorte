<?php

declare(strict_types=1);

namespace App\Controllers\Rh;

use App\Helpers\Auth;
use App\Helpers\Db;
use App\Helpers\View;
use App\Services\ImportacaoEfetivoService;
use App\Services\ModeloEfetivoService;
use PDO;
use Throwable;

final class ImportacaoController
{
    public function index(): void
    {
        Auth::requirePermission('importacoes.ver');
        $pdo = Db::tryConnection();
        $imports = [];
        if ($pdo instanceof PDO) {
            $imports = $pdo->query(
                'SELECT * FROM rh_importacoes ORDER BY id DESC LIMIT 30'
            )->fetchAll();
        }

        $podeOperar = Auth::canAny('importacoes.operar', 'importacoes.gerenciar');
        ob_start();
        ?>
        <a class="btn btn-secondary btn-sm" href="/rh/importacoes/modelo">Baixar modelo matriz</a>
        <?php if ($podeOperar): ?>
        <a class="btn btn-primary btn-sm" href="/rh/importacoes/nova">Nova importação</a>
        <?php endif; ?>
        <?php
        $topbarActions = ob_get_clean() ?: '';

        View::render('rh/importacoes/index', [
            'pageTitle' => 'Importações',
            'pageSubtitle' => 'Planilha mestre com prévia antes de gravar',
            'activeMenu' => 'importacoes',
            'topbarActions' => $topbarActions,
            'dbOk' => $pdo instanceof PDO,
            'imports' => $imports,
        ]);
    }

    public function create(): void
    {
        Auth::requirePermission('importacoes.operar');
        $pdo = Db::tryConnection();
        View::render('rh/importacoes/upload', [
            'pageTitle' => 'Importar efetivo',
            'pageSubtitle' => 'Upload → validação → prévia → confirmação',
            'activeMenu' => 'importacoes',
            'dbOk' => $pdo instanceof PDO,
            'error' => null,
        ]);
    }

    public function baixarModelo(): void
    {
        Auth::requireLogin();
        if (!Auth::canAny('importacoes.gerenciar', 'importacoes.ver', 'importacoes.operar', 'efetivo.ver', 'efetivo.editar')) {
            Auth::requirePermission('importacoes.ver');
        }
        try {
            $path = (new ModeloEfetivoService())->gerarArquivo();
        } catch (Throwable $e) {
            http_response_code(500);
            echo 'Falha ao gerar modelo: ' . View::e($e->getMessage());
            return;
        }

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="modelo_matriz_efetivo.xlsx"');
        header('Content-Length: ' . (string) filesize($path));
        header('Cache-Control: no-store');
        readfile($path);
        exit;
    }

    public function store(): void
    {
        Auth::requirePermission('importacoes.operar');
        $pdo = Db::tryConnection();
        if (!$pdo instanceof PDO) {
            header('Location: /rh/importacoes');
            exit;
        }

        try {
            $info = \App\Helpers\UploadGuard::validate(
                $_FILES['arquivo'] ?? [],
                ['xlsx', 'xls'],
                [
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'application/vnd.ms-excel',
                    'application/zip',
                    'application/x-zip-compressed',
                    'application/CDFV2',
                ],
                26214400
            );
        } catch (Throwable $e) {
            View::render('rh/importacoes/upload', [
                'pageTitle' => 'Importar efetivo',
                'pageSubtitle' => 'Falha no upload',
                'activeMenu' => 'importacoes',
                'dbOk' => true,
                'error' => $e->getMessage(),
            ]);
            return;
        }

        $tmp = (string) $_FILES['arquivo']['tmp_name'];
        $name = (string) $_FILES['arquivo']['name'];
        $destDir = dirname(__DIR__, 3) . '/storage/uploads';
        if (!is_dir($destDir)) {
            mkdir($destDir, 0775, true);
        }
        $safeBase = preg_replace('/[^a-zA-Z0-9._-]/', '_', pathinfo($name, PATHINFO_FILENAME)) ?: 'planilha';
        $dest = $destDir . '/' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '_' . $safeBase . '.' . $info['ext'];
        if (!move_uploaded_file($tmp, $dest)) {
            View::render('rh/importacoes/upload', [
                'pageTitle' => 'Importar efetivo',
                'pageSubtitle' => 'Falha no upload',
                'activeMenu' => 'importacoes',
                'dbOk' => true,
                'error' => 'Não foi possível salvar o arquivo.',
            ]);
            return;
        }

        try {
            $service = new ImportacaoEfetivoService($pdo);
            $result = $service->processarUpload($dest, $name);
            header('Location: /rh/importacoes/' . $result['importacao_id']);
            exit;
        } catch (Throwable $e) {
            View::render('rh/importacoes/upload', [
                'pageTitle' => 'Importar efetivo',
                'pageSubtitle' => 'Validação falhou',
                'activeMenu' => 'importacoes',
                'dbOk' => true,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function show(string $id = '0'): void
    {
        $pdo = Db::tryConnection();
        $importId = (int) $id;
        if (!$pdo instanceof PDO || $importId <= 0) {
            header('Location: /rh/importacoes');
            exit;
        }

        $stmt = $pdo->prepare('SELECT * FROM rh_importacoes WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $importId]);
        $import = $stmt->fetch();
        if ($import === false) {
            http_response_code(404);
            View::render('ui/not-found', ['pageTitle' => 'Importação não encontrada', 'activeMenu' => 'importacoes']);
            return;
        }

        $linhas = $pdo->prepare(
            "SELECT * FROM rh_importacoes_linhas WHERE importacao_id = :id AND acao IN ('atualizar','criar','erro') ORDER BY linha LIMIT 200"
        );
        $linhas->execute(['id' => $importId]);

        View::render('rh/importacoes/preview', [
            'pageTitle' => 'Prévia da importação',
            'pageSubtitle' => (string) $import['arquivo_nome'],
            'activeMenu' => 'importacoes',
            'import' => $import,
            'linhas' => $linhas->fetchAll(),
        ]);
    }

    public function confirm(string $id = '0'): void
    {
        Auth::requirePermission('importacoes.operar');
        $pdo = Db::tryConnection();
        $importId = (int) $id;
        if (!$pdo instanceof PDO || $importId <= 0) {
            header('Location: /rh/importacoes');
            exit;
        }

        try {
            $service = new ImportacaoEfetivoService($pdo);
            $aplicadas = $service->confirmar($importId, Auth::id());
            header('Location: /rh/importacoes/' . $importId . '?ok=' . $aplicadas);
        } catch (Throwable $e) {
            header('Location: /rh/importacoes/' . $importId . '?erro=' . rawurlencode($e->getMessage()));
        }
        exit;
    }
}
