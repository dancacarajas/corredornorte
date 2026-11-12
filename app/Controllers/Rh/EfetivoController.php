<?php

declare(strict_types=1);

namespace App\Controllers\Rh;

use App\Helpers\Auth;
use App\Helpers\Db;
use App\Helpers\View;
use App\Services\ModeloEfetivoService;
use PDO;
use Throwable;

final class EfetivoController
{
    public function index(): void
    {
        $pdo = Db::tryConnection();
        $dbOk = $pdo instanceof PDO;
        $filters = $this->filtersFromRequest();

        $rows = [];
        $kpis = ['ativos' => 0, 'contratos' => 0, 'centros' => 0, 'localidades' => 0];
        $lookups = ['contratos' => [], 'centros' => [], 'oss' => [], 'localidades' => []];

        if ($dbOk) {
            $kpis['ativos'] = (int) $pdo->query("SELECT COUNT(*) FROM rh_colaboradores WHERE status = 'ativo'")->fetchColumn();
            $kpis['contratos'] = (int) $pdo->query("SELECT COUNT(*) FROM rh_contratos WHERE status = 'ativo'")->fetchColumn();
            $kpis['centros'] = (int) $pdo->query("SELECT COUNT(*) FROM rh_centros_custo WHERE status = 'ativo'")->fetchColumn();
            $kpis['localidades'] = (int) $pdo->query("SELECT COUNT(*) FROM rh_localidades WHERE status = 'ativo'")->fetchColumn();

            $lookups['contratos'] = $pdo->query("SELECT id, codigo, nome FROM rh_contratos WHERE status='ativo' ORDER BY codigo")->fetchAll();
            $lookups['centros'] = $pdo->query("SELECT id, codigo, nome, contrato_id FROM rh_centros_custo WHERE status='ativo' ORDER BY codigo")->fetchAll();
            $lookups['oss'] = $pdo->query("SELECT id, codigo, nome, centro_custo_id FROM rh_ordens_servico WHERE status='ativo' ORDER BY codigo")->fetchAll();
            $lookups['localidades'] = $pdo->query("SELECT id, municipio, uf, nome FROM rh_localidades WHERE status='ativo' ORDER BY municipio")->fetchAll();

            $sql = <<<SQL
                SELECT
                  c.id, c.matricula, c.nome, c.status, c.cargo, c.funcao,
                  ct.id AS contrato_id, ct.codigo AS contrato_codigo,
                  cc.id AS centro_custo_id, cc.codigo AS cc_codigo,
                  os.id AS ordem_servico_id, os.codigo AS os_codigo,
                  loc.id AS localidade_id, loc.municipio, loc.uf,
                  j.codigo AS jornada_codigo, e.codigo AS escala_codigo
                FROM rh_colaboradores c
                LEFT JOIN rh_colaborador_lotacoes cl
                  ON cl.colaborador_id = c.id AND cl.vigencia_fim IS NULL
                LEFT JOIN rh_lotacoes l ON l.id = cl.lotacao_id
                LEFT JOIN rh_contratos ct ON ct.id = l.contrato_id
                LEFT JOIN rh_centros_custo cc ON cc.id = l.centro_custo_id
                LEFT JOIN rh_ordens_servico os ON os.id = l.ordem_servico_id
                LEFT JOIN rh_localidades loc ON loc.id = l.localidade_id
                LEFT JOIN rh_jornadas j ON j.id = l.jornada_id
                LEFT JOIN rh_escalas e ON e.id = l.escala_id
                WHERE 1=1
            SQL;
            $params = [];
            if ($filters['q'] !== '') {
                $sql .= ' AND (c.nome LIKE :q OR c.matricula LIKE :q2 OR c.cpf LIKE :q3)';
                $like = '%' . $filters['q'] . '%';
                $params['q'] = $like;
                $params['q2'] = $like;
                $params['q3'] = $like;
            }
            if ($filters['contrato_id'] > 0) {
                $sql .= ' AND ct.id = :contrato_id';
                $params['contrato_id'] = $filters['contrato_id'];
            }
            if ($filters['centro_custo_id'] > 0) {
                $sql .= ' AND cc.id = :cc_id';
                $params['cc_id'] = $filters['centro_custo_id'];
            }
            if ($filters['ordem_servico_id'] > 0) {
                $sql .= ' AND os.id = :os_id';
                $params['os_id'] = $filters['ordem_servico_id'];
            }
            if ($filters['localidade_id'] > 0) {
                $sql .= ' AND loc.id = :loc_id';
                $params['loc_id'] = $filters['localidade_id'];
            }
            if ($filters['status'] !== '') {
                $sql .= ' AND c.status = :status';
                $params['status'] = $filters['status'];
            }
            $sql .= ' ORDER BY c.nome LIMIT 500';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
        }

        $exportQs = http_build_query(array_filter($filters, static fn ($v) => $v !== '' && $v !== 0));
        $topbarActions = '<a class="btn btn-secondary btn-sm" href="/rh/efetivo/exportar' . ($exportQs !== '' ? '?' . $exportQs : '') . '">Exportar efetivo</a>'
            . '<a class="btn btn-secondary btn-sm" href="/rh/importacoes/modelo">Baixar modelo matriz</a>'
            . '<a class="btn btn-secondary btn-sm" href="/rh/calendarios">Feriados</a>'
            . '<a class="btn btn-secondary btn-sm" href="/rh/importacoes/nova">Importar Excel</a>'
            . '<a class="btn btn-primary btn-sm" href="/rh/colaboradores/novo">Novo colaborador</a>';

        View::render('rh/efetivo/index', [
            'pageTitle' => 'Efetivo',
            'pageSubtitle' => 'Fonte de verdade do RH operacional',
            'activeMenu' => 'efetivo',
            'topbarActions' => $topbarActions,
            'dbOk' => $dbOk,
            'kpis' => $kpis,
            'rows' => $rows,
            'filters' => $filters,
            'lookups' => $lookups,
            'exportQuery' => $exportQs,
        ]);
    }

    public function exportar(): void
    {
        Auth::requireLogin();
        if (!Auth::canAny('efetivo.ver', 'efetivo.editar', 'importacoes.gerenciar')) {
            Auth::requirePermission('efetivo.ver');
        }
        $pdo = Db::tryConnection();
        if (!$pdo instanceof PDO) {
            http_response_code(503);
            echo 'MySQL indisponível';
            return;
        }

        try {
            $path = (new ModeloEfetivoService())->exportar($pdo, $this->filtersFromRequest());
        } catch (Throwable $e) {
            http_response_code(500);
            echo 'Falha ao exportar: ' . $e->getMessage();
            return;
        }

        $filename = 'efetivo_' . date('Ymd_His') . '.xlsx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . (string) filesize($path));
        header('Cache-Control: no-store');
        readfile($path);
        @unlink($path);
        exit;
    }

    /** @return array{q:string,contrato_id:int,centro_custo_id:int,ordem_servico_id:int,localidade_id:int,status:string} */
    private function filtersFromRequest(): array
    {
        return [
            'q' => trim((string) ($_GET['q'] ?? '')),
            'contrato_id' => (int) ($_GET['contrato_id'] ?? 0),
            'centro_custo_id' => (int) ($_GET['centro_custo_id'] ?? 0),
            'ordem_servico_id' => (int) ($_GET['ordem_servico_id'] ?? 0),
            'localidade_id' => (int) ($_GET['localidade_id'] ?? 0),
            'status' => trim((string) ($_GET['status'] ?? '')),
        ];
    }
}
