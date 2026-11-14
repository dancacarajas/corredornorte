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
        $periodosGerados = [];

        if ($pdo instanceof PDO) {
            $periodosGerados = $this->listarPeriodosGerados($pdo);
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
                 INNER JOIN ponto_periodos pp ON pp.id = e.periodo_id
                 INNER JOIN rh_colaboradores c ON c.id = e.colaborador_id AND c.status = 'ativo'
                 WHERE pp.data_inicio <= ? AND pp.data_fim >= ?
                 ORDER BY pp.data_inicio DESC, pp.data_fim DESC"
            );
            $espelhos->execute([$periodo['data_fim'], $periodo['data_inicio']]);
            $espelhosPorPessoa = [];
            foreach ($espelhos as $espelho) {
                $espelhosPorPessoa[(int) $espelho['colaborador_id']][] = $espelho;
            }

            $folhas = $pdo->prepare(
                "SELECT e.colaborador_id, d.status,
                        COALESCE(d.periodo_inicio, pp.data_inicio) AS inicio,
                        COALESCE(d.periodo_fim, pp.data_fim) AS fim
                 FROM documentos d
                 INNER JOIN ponto_espelhos e ON e.id = d.espelho_id
                 INNER JOIN ponto_periodos pp ON pp.id = e.periodo_id
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
            'periodosGerados' => $periodosGerados,
        ]);
    }

    /**
     * Períodos com pelo menos um espelho gerado (mais recentes primeiro).
     * @return list<array{id:int,data_inicio:string,data_fim:string,status:string,espelhos:int,label:string}>
     */
    private function listarPeriodosGerados(PDO $pdo): array
    {
        $rows = $pdo->query(
            "SELECT pp.id, pp.data_inicio, pp.data_fim, pp.status,
                    COUNT(e.id) AS espelhos
             FROM ponto_periodos pp
             INNER JOIN ponto_espelhos e ON e.periodo_id = pp.id
             GROUP BY pp.id, pp.data_inicio, pp.data_fim, pp.status
             ORDER BY pp.data_inicio DESC, pp.data_fim DESC, pp.id DESC
             LIMIT 24"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $out = [];
        foreach ($rows as $row) {
            $ini = (string) $row['data_inicio'];
            $fim = (string) $row['data_fim'];
            $out[] = [
                'id' => (int) $row['id'],
                'data_inicio' => $ini,
                'data_fim' => $fim,
                'status' => (string) $row['status'],
                'espelhos' => (int) $row['espelhos'],
                'label' => date('d/m/Y', strtotime($ini)) . ' a ' . date('d/m/Y', strtotime($fim)),
            ];
        }
        return $out;
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
