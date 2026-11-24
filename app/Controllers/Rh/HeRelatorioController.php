<?php

declare(strict_types=1);

namespace App\Controllers\Rh;

use App\Helpers\Auth;
use App\Helpers\Csrf;
use App\Helpers\Db;
use App\Helpers\View;
use App\Services\HeRelatorioExcelExporter;
use App\Services\HeRelatorioService;
use App\Services\MedicaoService;
use App\Services\PerfilService;
use PDO;
use RuntimeException;
use Throwable;

final class HeRelatorioController
{
    public function index(): void
    {
        Auth::requirePermission('he_relatorio.ver');
        $pdo = Db::tryConnection();
        $dbOk = $pdo instanceof PDO;
        $periodos = [];
        $relatorio = null;
        $erroCalc = null;
        $colaboradores = [];
        $pendentes = [];

        [$dataInicio, $dataFim] = $this->periodoPadrao();
        $dataInicio = (string) ($_GET['data_inicio'] ?? $dataInicio);
        $dataFim = (string) ($_GET['data_fim'] ?? $dataFim);
        $gerou = isset($_GET['data_inicio'], $_GET['data_fim']);

        if ($dbOk) {
            try {
                (new PerfilService($pdo))->ensureCatalog();
                $svc = new HeRelatorioService($pdo);
                $svc->ensureSchema();
                $periodos = (new MedicaoService($pdo))->listarPeriodosGerados(24);
                // Abre já no último período da Medição (sem exigir clique em Gerar).
                if (!$gerou && $periodos !== []) {
                    $dataInicio = (string) $periodos[0]['data_inicio'];
                    $dataFim = (string) $periodos[0]['data_fim'];
                    $gerou = true;
                }
                $colaboradores = $svc->lookupColaboradoresAtivos();
                $pendentes = $svc->listarPendentesAgrupados();

                if (
                    $gerou
                    && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $dataInicio)
                    && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $dataFim)
                ) {
                    $relatorio = $svc->gerar($dataInicio, $dataFim);
                    $pendentes = $relatorio['pendentes'];
                }
            } catch (Throwable $e) {
                $erroCalc = $e->getMessage();
            }
        }

        $qs = http_build_query(['data_inicio' => $dataInicio, 'data_fim' => $dataFim]);
        $labelPeriodo = '';
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $dataInicio) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $dataFim)) {
            $labelPeriodo = date('d/m/Y', strtotime($dataInicio) ?: time())
                . ' a '
                . date('d/m/Y', strtotime($dataFim) ?: time());
        }

        View::render('rh/relatorio_he/index', [
            'pageTitle' => 'Relatório de HE',
            'pageSubtitle' => 'Horas extras do ponto já conferido na Medição',
            'activeMenu' => 'relatorio-he',
            'dbOk' => $dbOk,
            'periodos' => $periodos,
            'dataInicio' => $dataInicio,
            'dataFim' => $dataFim,
            'labelPeriodo' => $labelPeriodo,
            'gerou' => $gerou,
            'relatorio' => $relatorio,
            'pendentes' => $pendentes,
            'colaboradores' => $colaboradores,
            'erroCalc' => $erroCalc,
            'qs' => $qs,
            'podeOperar' => Auth::canAny('he_relatorio.operar', 'medicao.operar'),
            'flash' => $_SESSION['he_flash'] ?? null,
            'error' => $_SESSION['he_error'] ?? null,
        ]);
        unset($_SESSION['he_flash'], $_SESSION['he_error']);
    }

    public function exportarExcel(): void
    {
        Auth::requirePermission('he_relatorio.operar');
        $pdo = Db::tryConnection();
        if (!$pdo instanceof PDO) {
            $_SESSION['he_error'] = 'Banco indisponível.';
            header('Location: /rh/relatorio-he');
            exit;
        }

        $dataInicio = (string) ($_GET['data_inicio'] ?? '');
        $dataFim = (string) ($_GET['data_fim'] ?? '');
        try {
            $dados = (new HeRelatorioService($pdo))->gerar($dataInicio, $dataFim);
            $path = (new HeRelatorioExcelExporter())->gerarArquivo($dados);
        } catch (Throwable $e) {
            $_SESSION['he_error'] = $e->getMessage();
            header('Location: /rh/relatorio-he?' . http_build_query([
                'data_inicio' => $dataInicio,
                'data_fim' => $dataFim,
            ]));
            exit;
        }

        $filename = sprintf('relatorio_he_%s_a_%s.xlsx', $dataInicio, $dataFim);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . (string) filesize($path));
        header('Cache-Control: no-store');
        readfile($path);
        @unlink($path);
        exit;
    }

    public function salvarPendente(): void
    {
        Auth::requirePermission('he_relatorio.operar');
        Csrf::requireValid();
        $pdo = Db::tryConnection();
        $qs = http_build_query([
            'data_inicio' => (string) ($_POST['data_inicio'] ?? ''),
            'data_fim' => (string) ($_POST['data_fim'] ?? ''),
        ]);
        try {
            if (!$pdo instanceof PDO) {
                throw new RuntimeException('Banco indisponível.');
            }
            (new HeRelatorioService($pdo))->salvarPendente([
                'colaborador_id' => (int) ($_POST['colaborador_id'] ?? 0),
                'competencia' => (string) ($_POST['competencia'] ?? ''),
                'adn' => $_POST['adn'] ?? 0,
                'he_diurno_50' => $_POST['he_diurno_50'] ?? 0,
                'he_diurno_60' => $_POST['he_diurno_60'] ?? 0,
                'he_diurno_100' => $_POST['he_diurno_100'] ?? 0,
                'he_noturno_50' => $_POST['he_noturno_50'] ?? 0,
                'he_noturno_60' => $_POST['he_noturno_60'] ?? 0,
                'he_noturno_100' => $_POST['he_noturno_100'] ?? 0,
                'observacao' => $_POST['observacao'] ?? '',
            ], Auth::id());
            $_SESSION['he_flash'] = 'Saldo de HE pendente salvo.';
        } catch (Throwable $e) {
            $_SESSION['he_error'] = $e->getMessage();
        }
        header('Location: /rh/relatorio-he?' . $qs . '&aba=saldo');
        exit;
    }

    public function excluirPendente(string $id = '0'): void
    {
        Auth::requirePermission('he_relatorio.operar');
        Csrf::requireValid();
        $pdo = Db::tryConnection();
        $qs = http_build_query([
            'data_inicio' => (string) ($_POST['data_inicio'] ?? ''),
            'data_fim' => (string) ($_POST['data_fim'] ?? ''),
        ]);
        try {
            if (!$pdo instanceof PDO) {
                throw new RuntimeException('Banco indisponível.');
            }
            (new HeRelatorioService($pdo))->excluirPendente((int) $id);
            $_SESSION['he_flash'] = 'Saldo removido.';
        } catch (Throwable $e) {
            $_SESSION['he_error'] = $e->getMessage();
        }
        header('Location: /rh/relatorio-he?' . $qs . '&aba=saldo');
        exit;
    }

    /** @return array{0:string,1:string} */
    private function periodoPadrao(): array
    {
        $fim = date('Y-m-d');
        $ini = date('Y-m-01');

        return [$ini, $fim];
    }
}
