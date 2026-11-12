<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Db;
use App\Helpers\View;
use App\Services\PeriodoPonto;
use DateTimeImmutable;
use PDO;
use RuntimeException;

final class HomeController
{
    public function index(): void
    {
        $pdo = Db::tryConnection();
        $hoje = new DateTimeImmutable('today');
        $anoAtual = (int) $hoje->format('Y');
        $mesAtual = (int) $hoje->format('n');
        $erroPeriodo = null;
        try {
            $periodo = PeriodoPonto::selecionar($_GET['data_inicio'] ?? null, $_GET['data_fim'] ?? null, $anoAtual, $mesAtual);
        } catch (RuntimeException $erro) {
            $periodo = PeriodoPonto::selecionar(null, null, $anoAtual, $mesAtual);
            $erroPeriodo = $erro->getMessage();
        }

        $inicio = new DateTimeImmutable($periodo['data_inicio']);
        $fim = new DateTimeImmutable($periodo['data_fim']);
        $quantidadeMeses = ((int) $fim->format('Y') - (int) $inicio->format('Y')) * 12
            + (int) $fim->format('n') - (int) $inicio->format('n') + 1;
        $kpis = array_fill_keys(['esperadas', 'tratadas', 'fechadas', 'enviadas', 'assinadas'], 0);
        $colaboradores = [];

        if ($pdo instanceof PDO) {
            $lista = $pdo->prepare(
                "SELECT c.id, c.matricula, c.nome, loc.municipio, loc.uf, l.codigo AS lotacao_codigo
                 FROM rh_colaboradores c
                 LEFT JOIN rh_colaborador_lotacoes cl ON cl.id = (
                     SELECT vinculo.id FROM rh_colaborador_lotacoes vinculo
                     WHERE vinculo.colaborador_id = c.id AND vinculo.vigencia_inicio <= :fim
                       AND (vinculo.vigencia_fim IS NULL OR vinculo.vigencia_fim >= :inicio)
                     ORDER BY vinculo.vigencia_inicio DESC, vinculo.id DESC LIMIT 1
                 )
                 LEFT JOIN rh_lotacoes l ON l.id = cl.lotacao_id
                 LEFT JOIN rh_localidades loc ON loc.id = l.localidade_id
                 WHERE c.status = 'ativo'
                 ORDER BY c.nome, c.id"
            );
            $lista->execute(['inicio' => $periodo['data_inicio'], 'fim' => $periodo['data_fim']]);
            $colaboradores = $lista->fetchAll();
            $kpis['esperadas'] = count($colaboradores);

            $espelhos = $pdo->prepare(
                "SELECT e.id, e.colaborador_id, e.status
                 FROM ponto_espelhos e
                 INNER JOIN ponto_competencias pc ON pc.id = e.competencia_id
                 INNER JOIN rh_colaboradores c ON c.id = e.colaborador_id AND c.status = 'ativo'
                 WHERE pc.ano * 100 + pc.mes BETWEEN ? AND ?
                 ORDER BY pc.ano DESC, pc.mes DESC"
            );
            $espelhos->execute([(int) $inicio->format('Ym'), (int) $fim->format('Ym')]);
            $espelhosPorPessoa = [];
            foreach ($espelhos as $espelho) {
                $espelhosPorPessoa[(int) $espelho['colaborador_id']][] = $espelho;
            }

            $folhas = $pdo->prepare(
                "SELECT e.colaborador_id, d.status,
                        COALESCE(d.periodo_inicio, DATE(CONCAT(pc.ano, '-', LPAD(pc.mes, 2, '0'), '-01'))) AS inicio,
                        COALESCE(d.periodo_fim, LAST_DAY(CONCAT(pc.ano, '-', LPAD(pc.mes, 2, '0'), '-01'))) AS fim
                 FROM documentos d
                 INNER JOIN ponto_espelhos e ON e.id = d.espelho_id
                 INNER JOIN ponto_competencias pc ON pc.id = e.competencia_id
                 INNER JOIN rh_colaboradores c ON c.id = e.colaborador_id AND c.status = 'ativo'
                 WHERE d.tipo = 'folha_ponto' AND d.status IN ('enviado', 'assinado')
                 HAVING inicio <= :fim AND fim >= :inicio
                 ORDER BY inicio, fim"
            );
            $folhas->execute(['inicio' => $periodo['data_inicio'], 'fim' => $periodo['data_fim']]);
            $folhasPorPessoa = [];
            $assinadasPorPessoa = [];
            foreach ($folhas as $folha) {
                $id = (int) $folha['colaborador_id'];
                $folhasPorPessoa[$id][] = $folha;
                if ($folha['status'] === 'assinado') {
                    $assinadasPorPessoa[$id][] = $folha;
                }
            }

            foreach ($colaboradores as &$pessoa) {
                $id = (int) $pessoa['id'];
                $mensais = $espelhosPorPessoa[$id] ?? [];
                $pessoa['espelho_id'] = $mensais[0]['id'] ?? null;
                $status = array_column($mensais, 'status');
                if (count($status) === $quantidadeMeses) {
                    $kpis['tratadas'] += (int) !array_diff($status, ['em_tratamento', 'conferido', 'fechado', 'enviado', 'assinado']);
                    $kpis['fechadas'] += (int) !array_diff($status, ['fechado', 'enviado', 'assinado']);
                }
                $pessoa['periodo_enviado'] = $this->cobrePeriodo($folhasPorPessoa[$id] ?? [], $periodo);
                $pessoa['periodo_assinado'] = $this->cobrePeriodo($assinadasPorPessoa[$id] ?? [], $periodo);
                $kpis['enviadas'] += (int) $pessoa['periodo_enviado'];
                $kpis['assinadas'] += (int) $pessoa['periodo_assinado'];
            }
            unset($pessoa);
        }

        View::render('ui/dashboard', [
            'pageTitle' => 'Visão geral',
            'pageSubtitle' => 'Assinaturas no período',
            'activeMenu' => 'dashboard',
            'dbOk' => $pdo instanceof PDO,
            'kpis' => $kpis,
            'periodoLabel' => PeriodoPonto::rotulo($periodo),
            'dataInicio' => $periodo['data_inicio'],
            'dataFim' => $periodo['data_fim'],
            'consultaPeriodo' => PeriodoPonto::consulta($periodo),
            'erroPeriodo' => $erroPeriodo,
            'colaboradores' => $colaboradores,
        ]);
    }

    /** @param list<array{inicio:string,fim:string}> $folhas Vias em ordem de início */
    private function cobrePeriodo(array $folhas, array $periodo): bool
    {
        $proximaData = $periodo['data_inicio'];
        foreach ($folhas as $folha) {
            if ($folha['inicio'] > $proximaData) {
                break;
            }
            if ($folha['fim'] >= $periodo['data_fim']) {
                return true;
            }
            if ($folha['fim'] >= $proximaData) {
                $proximaData = (new DateTimeImmutable($folha['fim']))->modify('+1 day')->format('Y-m-d');
            }
        }

        return false;
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
