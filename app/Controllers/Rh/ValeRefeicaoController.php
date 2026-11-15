<?php

declare(strict_types=1);

namespace App\Controllers\Rh;

use App\Helpers\Db;
use App\Helpers\View;
use App\Services\VrExcelExporter;
use App\Services\VrPdfRenderer;
use App\Services\VrService;
use PDO;
use RuntimeException;
use Throwable;

final class ValeRefeicaoController
{
    public function index(): void
    {
        $pdo = Db::tryConnection();
        $editarId = (int) ($_GET['editar'] ?? 0);
        $verId = (int) ($_GET['ver'] ?? 0);
        $dbOk = $pdo instanceof PDO;
        $configs = [];
        $contratos = [];
        $cfgAtiva = null;
        $editando = null;
        $visualizando = null;

        if ($dbOk) {
            $svc = new VrService($pdo);
            try {
                $configs = $svc->listarConfigs();
                $contratos = $svc->listarContratos();
                $cfgAtiva = $svc->configAtiva(date('Y-m-d'));
                if ($editarId > 0) {
                    $editando = $svc->obterConfig($editarId);
                }
                if ($verId > 0) {
                    $visualizando = $svc->obterConfig($verId);
                }
            } catch (Throwable $e) {
                $_SESSION['vr_error'] = $e->getMessage();
            }
        }

        View::render('rh/vale_refeicao/index', [
            'pageTitle' => 'Vale Refeição · Configuração',
            'pageSubtitle' => 'Cadastre regras gerais ou por contrato',
            'activeMenu' => 'vr-config',
            'extraCss' => ['css/vr.css?v=vr-ui-20261114'],
            'dbOk' => $dbOk,
            'configs' => $configs,
            'contratos' => $contratos,
            'cfgAtiva' => $cfgAtiva,
            'editando' => $editando,
            'visualizando' => $visualizando,
            'flash' => $_SESSION['vr_flash'] ?? null,
            'error' => $_SESSION['vr_error'] ?? null,
        ]);
        unset($_SESSION['vr_flash'], $_SESSION['vr_error']);
    }

    public function relatorio(): void
    {
        [$dataInicio, $dataFim] = $this->periodoPadrao();
        $dataInicio = (string) ($_GET['data_inicio'] ?? $dataInicio);
        $dataFim = (string) ($_GET['data_fim'] ?? $dataFim);
        $pdo = Db::tryConnection();
        $dbOk = $pdo instanceof PDO;
        $calculo = [];
        $periodos = [];
        $erroCalc = null;
        $gerou = isset($_GET['data_inicio'], $_GET['data_fim']);

        if ($dbOk) {
            try {
                $periodos = (new \App\Services\MedicaoService($pdo))->listarPeriodosGerados(24);
                if (!$gerou && $periodos !== []) {
                    $dataInicio = (string) $periodos[0]['data_inicio'];
                    $dataFim = (string) $periodos[0]['data_fim'];
                }
            } catch (Throwable) {
                $periodos = [];
            }

            if (
                $gerou
                && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $dataInicio)
                && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $dataFim)
            ) {
                try {
                    $calculo = (new VrService($pdo))->calcularPeriodo($dataInicio, $dataFim);
                } catch (Throwable $e) {
                    $erroCalc = $e->getMessage();
                }
            }
        }

        $qs = http_build_query(['data_inicio' => $dataInicio, 'data_fim' => $dataFim]);
        $labelPeriodo = '';
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $dataInicio) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $dataFim)) {
            $labelPeriodo = date('d/m/Y', strtotime($dataInicio) ?: time())
                . ' a '
                . date('d/m/Y', strtotime($dataFim) ?: time());
        }

        View::render('rh/vale_refeicao/relatorio', [
            'pageTitle' => 'Vale Refeição · Relatório',
            'pageSubtitle' => 'Visualizar na tela, PDF ou Excel',
            'activeMenu' => 'vr-relatorio',
            'extraCss' => ['css/vr.css?v=vr-ui-20261114'],
            'dbOk' => $dbOk,
            'calculo' => $calculo,
            'periodos' => $periodos,
            'dataInicio' => $dataInicio,
            'dataFim' => $dataFim,
            'labelPeriodo' => $labelPeriodo,
            'gerou' => $gerou,
            'erroCalc' => $erroCalc,
            'qs' => $qs,
            'flash' => $_SESSION['vr_flash'] ?? null,
            'error' => $_SESSION['vr_error'] ?? null,
        ]);
        unset($_SESSION['vr_flash'], $_SESSION['vr_error']);
    }

    public function salvarConfig(): void
    {
        $pdo = Db::tryConnection();
        try {
            if (!$pdo instanceof PDO) {
                throw new RuntimeException('Banco indisponível.');
            }
            $id = (int) ($_POST['id'] ?? 0);
            $nome = (string) ($_POST['nome'] ?? '');
            $valor = (float) str_replace(',', '.', (string) ($_POST['valor_dia'] ?? '0'));
            $tipo = (string) ($_POST['tipo'] ?? 'dias_trabalhados');
            $ini = (string) ($_POST['vigencia_inicio'] ?? date('Y-m-d'));
            $fim = trim((string) ($_POST['vigencia_fim'] ?? '')) ?: null;
            $todos = isset($_POST['todos_contratos']) && (string) $_POST['todos_contratos'] === '1';
            $contratoIds = array_map('intval', (array) ($_POST['contrato_ids'] ?? []));
            (new VrService($pdo))->salvarConfig(
                $valor,
                $tipo,
                $ini,
                $fim,
                $todos,
                $contratoIds,
                $id > 0 ? $id : null,
                $nome
            );
            $_SESSION['vr_flash'] = $id > 0 ? 'Configuração atualizada.' : 'Configuração salva.';
        } catch (Throwable $e) {
            $_SESSION['vr_error'] = $e->getMessage();
        }
        header('Location: /rh/vale-refeicao');
        exit;
    }

    public function excluirConfig(string $id = '0'): void
    {
        $pdo = Db::tryConnection();
        try {
            if (!$pdo instanceof PDO) {
                throw new RuntimeException('Banco indisponível.');
            }
            $configId = (int) $id;
            if ($configId < 1) {
                throw new RuntimeException('Configuração inválida.');
            }
            (new VrService($pdo))->excluirConfig($configId);
            $_SESSION['vr_flash'] = 'Configuração excluída.';
        } catch (Throwable $e) {
            $_SESSION['vr_error'] = $e->getMessage();
        }
        header('Location: /rh/vale-refeicao');
        exit;
    }

    public function exportarPdf(): void
    {
        [$dataInicio, $dataFim, $calculo] = $this->carregarCalculoOuRedirecionar();
        $path = sys_get_temp_dir() . '/vr_' . bin2hex(random_bytes(6)) . '.pdf';
        try {
            (new VrPdfRenderer())->render($calculo, [
                'data_inicio' => $dataInicio,
                'data_fim' => $dataFim,
                'emitido' => date('d/m/Y H:i'),
            ], $path);
        } catch (Throwable $e) {
            $_SESSION['vr_error'] = $e->getMessage();
            header('Location: /rh/vale-refeicao/relatorio?' . http_build_query([
                'data_inicio' => $dataInicio,
                'data_fim' => $dataFim,
            ]));
            exit;
        }

        $filename = sprintf('vale_refeicao_%s_a_%s.pdf', $dataInicio, $dataFim);
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $filename . '"');
        header('Content-Length: ' . (string) filesize($path));
        header('Cache-Control: no-store');
        readfile($path);
        @unlink($path);
        exit;
    }

    public function exportarExcel(): void
    {
        [$dataInicio, $dataFim, $calculo] = $this->carregarCalculoOuRedirecionar();
        try {
            $path = (new VrExcelExporter())->gerarArquivo($calculo, [
                'data_inicio' => $dataInicio,
                'data_fim' => $dataFim,
            ]);
        } catch (Throwable $e) {
            $_SESSION['vr_error'] = $e->getMessage();
            header('Location: /rh/vale-refeicao/relatorio?' . http_build_query([
                'data_inicio' => $dataInicio,
                'data_fim' => $dataFim,
            ]));
            exit;
        }

        $filename = sprintf('vale_refeicao_%s_a_%s.xlsx', $dataInicio, $dataFim);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . (string) filesize($path));
        header('Cache-Control: no-store');
        readfile($path);
        @unlink($path);
        exit;
    }

    /** @deprecated Mantido por compatibilidade de links antigos → Excel */
    public function exportarCsv(): void
    {
        $this->exportarExcel();
    }

    /**
     * @return array{0:string,1:string}
     */
    private function periodoPadrao(): array
    {
        $hoje = new \DateTimeImmutable('today');
        $ref = ((int) $hoje->format('j') >= 16) ? $hoje : $hoje->modify('-1 month');

        return [$ref->format('Y-m-16'), $ref->modify('+1 month')->format('Y-m-15')];
    }

    /**
     * @return array{0:string,1:string,2:list<array<string,mixed>>}
     */
    private function carregarCalculoOuRedirecionar(): array
    {
        $pdo = Db::tryConnection();
        $dataInicio = trim((string) ($_GET['data_inicio'] ?? ''));
        $dataFim = trim((string) ($_GET['data_fim'] ?? ''));
        if (
            !$pdo instanceof PDO
            || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $dataInicio)
            || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $dataFim)
        ) {
            $_SESSION['vr_error'] = 'Informe um período válido.';
            header('Location: /rh/vale-refeicao/relatorio');
            exit;
        }

        try {
            $calculo = (new VrService($pdo))->calcularPeriodo($dataInicio, $dataFim);
        } catch (Throwable $e) {
            $_SESSION['vr_error'] = $e->getMessage();
            header('Location: /rh/vale-refeicao/relatorio?' . http_build_query([
                'data_inicio' => $dataInicio,
                'data_fim' => $dataFim,
            ]));
            exit;
        }

        return [$dataInicio, $dataFim, $calculo];
    }
}
