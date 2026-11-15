<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Db;
use App\Helpers\View;
use App\Services\MedicaoService;
use App\Services\PainelExcecoesService;
use App\Services\PeriodoPonto;
use DateTimeImmutable;
use PDO;
use RuntimeException;
use Throwable;

final class HomeController
{
    public function index(): void
    {
        $pdo = Db::tryConnection();
        $dbOk = $pdo instanceof PDO;
        $hoje = new DateTimeImmutable('today');
        $anoAtual = (int) $hoje->format('Y');
        $mesAtual = (int) $hoje->format('n');
        $erroPeriodo = null;
        $periodosGerados = [];

        if ($dbOk) {
            $periodosGerados = (new MedicaoService($pdo))->listarPeriodosGerados(24);
        }

        $getIni = isset($_GET['data_inicio']) ? (string) $_GET['data_inicio'] : null;
        $getFim = isset($_GET['data_fim']) ? (string) $_GET['data_fim'] : null;
        if (($getIni === null || $getIni === '') && ($getFim === null || $getFim === '') && $periodosGerados !== []) {
            $getIni = (string) $periodosGerados[0]['data_inicio'];
            $getFim = (string) $periodosGerados[0]['data_fim'];
        }

        try {
            $periodo = PeriodoPonto::selecionar($getIni, $getFim, $anoAtual, $mesAtual);
        } catch (RuntimeException $erro) {
            if ($periodosGerados !== []) {
                $periodo = [
                    'data_inicio' => (string) $periodosGerados[0]['data_inicio'],
                    'data_fim' => (string) $periodosGerados[0]['data_fim'],
                ];
            } else {
                $periodo = PeriodoPonto::selecionar(null, null, $anoAtual, $mesAtual);
            }
            $erroPeriodo = $erro->getMessage();
        }

        $painel = null;
        if ($dbOk) {
            try {
                $painel = (new PainelExcecoesService($pdo))->carregar(
                    (string) $periodo['data_inicio'],
                    (string) $periodo['data_fim']
                );
            } catch (Throwable $e) {
                $erroPeriodo = $erroPeriodo ?? $e->getMessage();
            }
        }

        View::render('ui/dashboard', [
            'pageTitle' => 'Visão geral',
            'pageSubtitle' => 'Exceções, gargalos e destravamento do fechamento',
            'activeMenu' => 'dashboard',
            'extraCss' => ['css/dashboard.css?v=hero-leitura-20261114'],
            'dbOk' => $dbOk,
            'painel' => $painel,
            'periodoLabel' => PeriodoPonto::rotulo($periodo),
            'dataInicio' => (string) $periodo['data_inicio'],
            'dataFim' => (string) $periodo['data_fim'],
            'consultaPeriodo' => PeriodoPonto::consulta($periodo),
            'erroPeriodo' => $erroPeriodo,
            'periodosGerados' => $periodosGerados,
        ]);
    }

    public function showcase(): void
    {
        ob_start();
        ?>
        <button class="btn btn-secondary btn-sm" type="button"><?= date('d/m/Y') ?></button>
        <button class="btn btn-primary btn-sm" type="button" data-modal-open="demoModal">Demo transferência</button>
        <?php
        $topbarActions = ob_get_clean() ?: '';

        View::render('ui/showcase', [
            'pageTitle' => 'Design System',
            'pageSubtitle' => 'Identidade Arcadis para todas as telas',
            'activeMenu' => 'showcase',
            'topbarActions' => $topbarActions,
        ]);
    }

    public function mapaSistema(): void
    {
        \App\Helpers\Auth::requirePermission('dashboard.ver');

        View::render('ui/mapa_sistema', [
            'pageTitle' => 'Mapa do sistema',
            'pageSubtitle' => 'Como o Arcadis fecha a medição — tour interativo',
            'activeMenu' => 'mapa-sistema',
            'extraCss' => ['css/mapa-sistema.css?v=20261115f'],
            'extraJs' => ['js/mapa-sistema.js?v=20261115f'],
        ]);
    }

    public function modulePlaceholder(): void
    {
        View::render('rh/placeholder', [
            'pageTitle' => 'Módulo',
            'pageSubtitle' => 'Módulo RH',
            'activeMenu' => '',
            'moduleTitle' => 'Módulo',
            'moduleMessage' => 'Em construção.',
        ]);
    }
}
