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
        $dataInicio = (string) ($_GET['data_inicio'] ?? '2026-10-16');
        $dataFim = (string) ($_GET['data_fim'] ?? '2026-11-15');
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
}
