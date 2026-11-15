<?php

declare(strict_types=1);

namespace App\Controllers\Rh;

use App\Helpers\Db;
use App\Helpers\View;
use App\Services\RelatorioAtividadesExcel;
use App\Services\RelatorioAtividadesPdf;
use App\Services\RelatorioAtividadesService;
use PDO;
use RuntimeException;
use Throwable;

final class RelatorioAtividadesController
{
    public function index(): void
    {
        $pdo = Db::tryConnection();
        $status = trim((string) ($_GET['status'] ?? ''));
        $dataInicio = (string) ($_GET['data_inicio'] ?? '');
        $dataFim = (string) ($_GET['data_fim'] ?? '');
        $q = trim((string) ($_GET['q'] ?? ''));
        $lista = [];
        $dbOk = $pdo instanceof PDO;
        if ($dbOk) {
            try {
                $lista = (new RelatorioAtividadesService($pdo))->listarRh(
                    $status !== '' ? $status : null,
                    preg_match('/^\d{4}-\d{2}-\d{2}$/D', $dataInicio) ? $dataInicio : null,
                    preg_match('/^\d{4}-\d{2}-\d{2}$/D', $dataFim) ? $dataFim : null,
                    200,
                    $q !== '' ? $q : null
                );
            } catch (Throwable) {
                $lista = [];
            }
        }

        View::render('rh/relatorios_atividades/index', [
            'pageTitle' => 'Relatórios de Atividades',
            'pageSubtitle' => 'Acompanhe preenchimento e status por período',
            'activeMenu' => 'ra',
            'dbOk' => $dbOk,
            'relatorios' => $lista,
            'status' => $status,
            'dataInicio' => $dataInicio,
            'dataFim' => $dataFim,
            'q' => $q,
            'flash' => $_SESSION['ra_flash'] ?? null,
            'error' => $_SESSION['ra_error'] ?? null,
        ]);
        unset($_SESSION['ra_flash'], $_SESSION['ra_error']);
    }

    public function show(string $id = '0'): void
    {
        $pdo = Db::tryConnection();
        $relatorioId = (int) $id;
        if (!$pdo instanceof PDO || $relatorioId <= 0) {
            header('Location: /rh/relatorios-atividades');
            exit;
        }
        $dados = (new RelatorioAtividadesService($pdo))->carregar($relatorioId);
        if ($dados === null) {
            http_response_code(404);
            View::render('ui/not-found', ['pageTitle' => 'Relatório não encontrado', 'activeMenu' => 'ra']);
            return;
        }
        $ini = (string) ($dados['relatorio']['data_inicio'] ?? '');
        $fim = (string) ($dados['relatorio']['data_fim'] ?? '');
        View::render('rh/relatorios_atividades/show', [
            'pageTitle' => 'Relatório de Atividades',
            'pageSubtitle' => ($ini && $fim)
                ? date('d/m/Y', strtotime($ini)) . ' a ' . date('d/m/Y', strtotime($fim))
                : null,
            'activeMenu' => 'ra',
            'extraCss' => ['css/portal.css?v=portal-ra-20261114'],
            'dados' => $dados,
            'flash' => $_SESSION['ra_flash'] ?? null,
            'error' => $_SESSION['ra_error'] ?? null,
        ]);
        unset($_SESSION['ra_flash'], $_SESSION['ra_error']);
    }

    public function pdf(string $id = '0'): void
    {
        $this->exportarArquivo((int) $id, 'pdf');
    }

    public function excel(string $id = '0'): void
    {
        $this->exportarArquivo((int) $id, 'excel');
    }

    private function exportarArquivo(int $relatorioId, string $tipo): void
    {
        $pdo = Db::tryConnection();
        if (!$pdo instanceof PDO || $relatorioId <= 0) {
            header('Location: /rh/relatorios-atividades');
            exit;
        }
        try {
            $dados = (new RelatorioAtividadesService($pdo))->carregar($relatorioId);
            if ($dados === null) {
                throw new RuntimeException('Relatório não encontrado.');
            }
            if ($tipo === 'excel') {
                $arquivo = (new RelatorioAtividadesExcel())->gerarArquivo($dados);
                $nome = 'relatorio_atividades_' . $relatorioId . '.xlsx';
                header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            } else {
                $arquivo = (new RelatorioAtividadesPdf())->gerarArquivo($dados);
                $nome = 'relatorio_atividades_' . $relatorioId . '.pdf';
                header('Content-Type: application/pdf');
            }
            header('Content-Disposition: inline; filename="' . $nome . '"');
            header('Content-Length: ' . (string) filesize($arquivo));
            readfile($arquivo);
            exit;
        } catch (Throwable $e) {
            $_SESSION['ra_error'] = $e->getMessage();
            header('Location: /rh/relatorios-atividades/' . $relatorioId);
            exit;
        }
    }

    public function aprovar(string $id = '0'): void
    {
        $pdo = Db::tryConnection();
        $relatorioId = (int) $id;
        try {
            if (!$pdo instanceof PDO || $relatorioId <= 0) {
                throw new RuntimeException('Relatório inválido.');
            }
            (new RelatorioAtividadesService($pdo))->aprovar($relatorioId);
            $_SESSION['ra_flash'] = 'Relatório aprovado.';
        } catch (Throwable $e) {
            $_SESSION['ra_error'] = $e->getMessage();
        }
        header('Location: /rh/relatorios-atividades/' . $relatorioId);
        exit;
    }

    public function reabrir(string $id = '0'): void
    {
        $pdo = Db::tryConnection();
        $relatorioId = (int) $id;
        try {
            if (!$pdo instanceof PDO || $relatorioId <= 0) {
                throw new RuntimeException('Relatório inválido.');
            }
            (new RelatorioAtividadesService($pdo))->reabrir($relatorioId);
            $_SESSION['ra_flash'] = 'Relatório reaberto para edição do colaborador.';
        } catch (Throwable $e) {
            $_SESSION['ra_error'] = $e->getMessage();
        }
        header('Location: /rh/relatorios-atividades/' . $relatorioId);
        exit;
    }

    public function acaoLote(): void
    {
        Auth::requireLogin();
        Csrf::requireValid();
        $ids = is_array($_POST['relatorio_ids'] ?? null) ? $_POST['relatorio_ids'] : [];
        $acao = trim((string) ($_POST['acao'] ?? ''));
        if ($ids === []) {
            $_SESSION['rh_error'] = 'Selecione ao menos um relatório para executar a ação em lote.';
            header('Location: /rh/relatorios-atividades');
            exit;
        }
        try {
            $res = (new RelatorioAtividadesService())->acaoLote($ids, $acao, Auth::id());
            $proc = (int) ($res['processados'] ?? 0);
            $ign = (int) ($res['ignorados'] ?? 0);
            $rotuloAcao = $acao === 'gerar_link' ? 'PDF(s) + link(s) gerados' : 'relatório(s) aprovado(s)';
            $_SESSION['rh_flash'] = sprintf('%d %s.%s', $proc, $rotuloAcao, $ign > 0 ? " ({$ign} ignorado(s) por status incompatível)." : '');
        } catch (Throwable $e) {
            $_SESSION['rh_error'] = $e->getMessage();
        }
        header('Location: /rh/relatorios-atividades');
        exit;
    }
}
