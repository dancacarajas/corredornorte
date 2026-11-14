<?php

declare(strict_types=1);

namespace App\Controllers\Rh;

use App\Helpers\Db;
use App\Helpers\View;
use App\Services\VrService;
use PDO;
use RuntimeException;
use Throwable;

final class ValeRefeicaoController
{
    public function index(): void
    {
        $pdo = Db::tryConnection();
        $hoje = new \DateTimeImmutable('today');
        $ref = ((int) $hoje->format('j') >= 16) ? $hoje : $hoje->modify('-1 month');
        $defaultInicio = $ref->format('Y-m-16');
        $defaultFim = $ref->modify('+1 month')->format('Y-m-15');
        $dataInicio = (string) ($_GET['data_inicio'] ?? $defaultInicio);
        $dataFim = (string) ($_GET['data_fim'] ?? $defaultFim);
        $editarId = (int) ($_GET['editar'] ?? 0);
        $verId = (int) ($_GET['ver'] ?? 0);
        $dbOk = $pdo instanceof PDO;
        $configs = [];
        $contratos = [];
        $calculo = [];
        $cfgAtiva = null;
        $editando = null;
        $visualizando = null;
        $erroCalc = null;

        if ($dbOk) {
            $svc = new VrService($pdo);
            try {
                $configs = $svc->listarConfigs();
                $contratos = $svc->listarContratos();
                $cfgAtiva = $svc->configAtiva($dataInicio);
                if ($editarId > 0) {
                    $editando = $svc->obterConfig($editarId);
                }
                if ($verId > 0) {
                    $visualizando = $svc->obterConfig($verId);
                }
                if (
                    preg_match('/^\d{4}-\d{2}-\d{2}$/D', $dataInicio)
                    && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $dataFim)
                    && $cfgAtiva !== null
                ) {
                    $calculo = $svc->calcularPeriodo($dataInicio, $dataFim);
                }
            } catch (Throwable $e) {
                $erroCalc = $e->getMessage();
            }
        }

        View::render('rh/vale_refeicao/index', [
            'pageTitle' => 'Vale Refeição',
            'pageSubtitle' => 'Valor por dia Remoto e cálculo por período',
            'activeMenu' => 'vr',
            'dbOk' => $dbOk,
            'configs' => $configs,
            'contratos' => $contratos,
            'cfgAtiva' => $cfgAtiva,
            'editando' => $editando,
            'visualizando' => $visualizando,
            'calculo' => $calculo,
            'dataInicio' => $dataInicio,
            'dataFim' => $dataFim,
            'erroCalc' => $erroCalc,
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
                $id > 0 ? $id : null
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

    /**
     * Escapa prefixos perigosos em células CSV contra CSV Formula Injection (=, +, -, @, tab, CR).
     */
    public static function csvSafeCell(string $value): string
    {
        $trimmed = ltrim($value);
        if ($trimmed !== '' && in_array($trimmed[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }
        return $value;
    }

    public function exportarCsv(): void
    {
        $pdo = Db::tryConnection();
        $dataInicio = trim((string) ($_GET['data_inicio'] ?? ''));
        $dataFim = trim((string) ($_GET['data_fim'] ?? ''));
        if (
            !$pdo instanceof PDO
            || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $dataInicio)
            || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $dataFim)
        ) {
            $_SESSION['vr_error'] = 'Informe um período válido para exportar CSV.';
            header('Location: /rh/vale-refeicao');
            exit;
        }

        try {
            $svc = new VrService($pdo);
            $calculo = $svc->calcularPeriodo($dataInicio, $dataFim);
        } catch (Throwable $e) {
            $_SESSION['vr_error'] = $e->getMessage();
            header('Location: /rh/vale-refeicao?' . http_build_query(['data_inicio' => $dataInicio, 'data_fim' => $dataFim]));
            exit;
        }

        $filename = sprintf('vale_refeicao_%s_a_%s.csv', $dataInicio, $dataFim);
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('X-Content-Type-Options: nosniff');

        $out = fopen('php://output', 'wb');
        if ($out === false) {
            exit;
        }
        // UTF-8 BOM para abertura limpa no Excel Windows PT-BR
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Colaborador', 'Matrícula', 'Status Relatório', 'Dias Remoto', 'Valor Dia (R$)', 'Total VR (R$)'], ';');
        foreach ($calculo as $row) {
            fputcsv($out, [
                self::csvSafeCell((string) ($row['nome'] ?? '')),
                self::csvSafeCell((string) ($row['matricula'] ?? '')),
                self::csvSafeCell((string) ($row['status'] ?? '')),
                (string) ((int) ($row['dias_remoto'] ?? 0)),
                number_format((float) ($row['valor_dia'] ?? 0), 2, ',', '.'),
                number_format((float) ($row['valor_vr'] ?? 0), 2, ',', '.'),
            ], ';');
        }
        fclose($out);
        exit;
    }
}

