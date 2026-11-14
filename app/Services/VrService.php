<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use PDO;
use RuntimeException;

final class VrService
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    /** @return list<array{id:int,codigo:string,nome:string}> */
    public function listarContratos(): array
    {
        $rows = $this->db->query(
            "SELECT id, codigo, nome FROM rh_contratos WHERE status = 'ativo' ORDER BY nome"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'codigo' => (string) $r['codigo'],
            'nome' => (string) $r['nome'],
        ], $rows);
    }

    /**
     * @return array{
     *   id:int,valor_dia:string,tipo:string,vigencia_inicio:string,vigencia_fim:?string,
     *   todos_contratos:bool,ativo:bool,contrato_ids:list<int>,contratos_label:string
     * }|null
     */
    public function obterConfig(int $id): ?array
    {
        $st = $this->db->prepare('SELECT * FROM vr_config WHERE id = :id LIMIT 1');
        $st->execute(['id' => $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return $this->hidratarConfig($row);
    }

    /**
     * Config vigente genérica (Todos) — usada no resumo da tela.
     * @return array{id:int,valor_dia:string,tipo:string,vigencia_inicio:string,vigencia_fim:?string,todos_contratos:bool}|null
     */
    public function configAtiva(?string $dataRef = null): ?array
    {
        $dataRef = $dataRef ?? date('Y-m-d');
        $st = $this->db->prepare(
            'SELECT * FROM vr_config
             WHERE ativo = 1
               AND vigencia_inicio <= :d
               AND (vigencia_fim IS NULL OR vigencia_fim = \'0000-00-00\' OR vigencia_fim >= :d2)
             ORDER BY vigencia_inicio DESC, id DESC
             LIMIT 1'
        );
        $st->execute(['d' => $dataRef, 'd2' => $dataRef]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->hidratarConfig($row);
    }

    /** @return list<array<string,mixed>> */
    public function listarConfigs(): array
    {
        $rows = $this->db->query(
            'SELECT * FROM vr_config ORDER BY vigencia_inicio DESC, id DESC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->hidratarConfig($row);
        }
        return $out;
    }

    /**
     * @param list<int> $contratoIds
     */
    public function salvarConfig(
        float $valorDia,
        string $tipo,
        string $vigenciaInicio,
        ?string $vigenciaFim,
        bool $todosContratos,
        array $contratoIds,
        ?int $id = null
    ): int {
        $this->validarPayload($valorDia, $tipo, $vigenciaInicio, $vigenciaFim, $todosContratos, $contratoIds);

        $this->db->beginTransaction();
        try {
            $params = [
                'v' => number_format($valorDia, 2, '.', ''),
                't' => $tipo,
                'i' => $vigenciaInicio,
                'f' => $this->normalizarFim($vigenciaFim),
                'tc' => $todosContratos ? 1 : 0,
            ];
            if ($id !== null && $id > 0) {
                $params['id'] = $id;
                $this->db->prepare(
                    'UPDATE vr_config
                     SET valor_dia = :v, tipo = :t, vigencia_inicio = :i, vigencia_fim = :f,
                         todos_contratos = :tc, ativo = 1
                     WHERE id = :id'
                )->execute($params);
                $configId = $id;
                $this->db->prepare('DELETE FROM vr_config_contratos WHERE config_id = :id')->execute(['id' => $configId]);
            } else {
                $this->db->prepare(
                    'INSERT INTO vr_config (valor_dia, tipo, vigencia_inicio, vigencia_fim, todos_contratos, ativo)
                     VALUES (:v, :t, :i, :f, :tc, 1)'
                )->execute($params);
                $configId = (int) $this->db->lastInsertId();
            }

            if (!$todosContratos) {
                $ins = $this->db->prepare(
                    'INSERT INTO vr_config_contratos (config_id, contrato_id) VALUES (:c, :ct)'
                );
                foreach (array_unique($contratoIds) as $ctId) {
                    $ins->execute(['c' => $configId, 'ct' => (int) $ctId]);
                }
            }
            $this->db->commit();
            return $configId;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function excluirConfig(int $id): void
    {
        $st = $this->db->prepare('DELETE FROM vr_config WHERE id = :id');
        $st->execute(['id' => $id]);
        if ($st->rowCount() < 1) {
            throw new RuntimeException('Configuração não encontrada.');
        }
    }

    /**
     * VR = valor_dia × dias Remoto do relatório no período (regra + alocação por contrato).
     * @return list<array<string,mixed>>
     */
    public function calcularPeriodo(string $dataInicio, string $dataFim): array
    {
        $configs = $this->configsVigentes($dataInicio);
        if ($configs === []) {
            throw new RuntimeException('Cadastre uma configuração de Vale Refeição vigente.');
        }

        $sql = 'SELECT r.id AS relatorio_id, r.status, r.contrato_id, c.id AS colaborador_id, c.nome, c.matricula,
                       pp.data_inicio, pp.data_fim,
                       SUM(CASE WHEN d.ambiente = \'remoto\' THEN 1 ELSE 0 END) AS dias_remoto_todos,
                       SUM(CASE WHEN d.ambiente = \'remoto\' AND WEEKDAY(d.data) < 5 THEN 1 ELSE 0 END) AS dias_remoto_uteis,
                       SUM(CASE WHEN d.ambiente = \'site\' THEN 1 ELSE 0 END) AS dias_site,
                       SUM(CASE WHEN d.ambiente = \'viagem\' THEN 1 ELSE 0 END) AS dias_viagem
                FROM ra_relatorios r
                INNER JOIN rh_colaboradores c ON c.id = r.colaborador_id
                INNER JOIN ponto_periodos pp ON pp.id = r.periodo_id
                INNER JOIN ra_dias d ON d.relatorio_id = r.id
                WHERE pp.data_inicio = :i AND pp.data_fim = :f
                GROUP BY r.id, r.status, r.contrato_id, c.id, c.nome, c.matricula, pp.data_inicio, pp.data_fim
                ORDER BY c.nome';
        $st = $this->db->prepare($sql);
        $st->execute(['i' => $dataInicio, 'f' => $dataFim]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $contratoId = isset($row['contrato_id']) && $row['contrato_id'] !== null
                ? (int) $row['contrato_id']
                : null;
            $cfg = $this->resolverConfig($configs, $contratoId);
            if ($cfg === null) {
                continue;
            }
            $dias = $cfg['tipo'] === 'dias_uteis'
                ? (int) $row['dias_remoto_uteis']
                : (int) $row['dias_remoto_todos'];
            $valorDia = (float) $cfg['valor_dia'];
            $out[] = [
                'relatorio_id' => (int) $row['relatorio_id'],
                'status' => (string) $row['status'],
                'colaborador_id' => (int) $row['colaborador_id'],
                'nome' => (string) $row['nome'],
                'matricula' => (string) $row['matricula'],
                'contrato_id' => $contratoId,
                'data_inicio' => (string) $row['data_inicio'],
                'data_fim' => (string) $row['data_fim'],
                'dias_remoto' => $dias,
                'dias_site' => (int) $row['dias_site'],
                'dias_viagem' => (int) $row['dias_viagem'],
                'valor_dia' => $valorDia,
                'tipo_calculo' => $cfg['tipo'],
                'config_id' => $cfg['id'],
                'valor_vr' => round($valorDia * $dias, 2),
            ];
        }
        return $out;
    }

    /**
     * @param list<array<string,mixed>> $configs
     * @return array<string,mixed>|null
     */
    private function resolverConfig(array $configs, ?int $contratoId): ?array
    {
        $especifica = null;
        $geral = null;
        foreach ($configs as $cfg) {
            if (!empty($cfg['todos_contratos'])) {
                if ($geral === null) {
                    $geral = $cfg;
                }
                continue;
            }
            if ($contratoId !== null && in_array($contratoId, $cfg['contrato_ids'], true)) {
                if ($especifica === null) {
                    $especifica = $cfg;
                }
            }
        }
        return $especifica ?? $geral;
    }

    /** @return list<array<string,mixed>> */
    private function configsVigentes(string $dataRef): array
    {
        $st = $this->db->prepare(
            'SELECT * FROM vr_config
             WHERE ativo = 1
               AND vigencia_inicio <= :d
               AND (vigencia_fim IS NULL OR vigencia_fim = \'0000-00-00\' OR vigencia_fim >= :d2)
             ORDER BY vigencia_inicio DESC, id DESC'
        );
        $st->execute(['d' => $dataRef, 'd2' => $dataRef]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->hidratarConfig($row);
        }
        return $out;
    }

    /** @param array<string,mixed> $row */
    private function hidratarConfig(array $row): array
    {
        $id = (int) $row['id'];
        $todos = !isset($row['todos_contratos']) || (int) $row['todos_contratos'] === 1;
        $contratoIds = [];
        $labels = [];
        if (!$todos) {
            $st = $this->db->prepare(
                'SELECT ct.id, ct.codigo, ct.nome
                 FROM vr_config_contratos cc
                 INNER JOIN rh_contratos ct ON ct.id = cc.contrato_id
                 WHERE cc.config_id = :id
                 ORDER BY ct.nome'
            );
            $st->execute(['id' => $id]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $ct) {
                $contratoIds[] = (int) $ct['id'];
                $labels[] = trim((string) $ct['codigo'] . ' — ' . (string) $ct['nome']);
            }
        }
        $fim = $this->normalizarFim(isset($row['vigencia_fim']) ? (string) $row['vigencia_fim'] : null);

        return [
            'id' => $id,
            'valor_dia' => (string) $row['valor_dia'],
            'tipo' => (string) $row['tipo'],
            'vigencia_inicio' => (string) $row['vigencia_inicio'],
            'vigencia_fim' => $fim,
            'todos_contratos' => $todos,
            'ativo' => !empty($row['ativo']),
            'contrato_ids' => $contratoIds,
            'contratos_label' => $todos ? 'Todos' : ($labels !== [] ? implode(', ', $labels) : '—'),
        ];
    }

    /**
     * @param list<int> $contratoIds
     */
    private function validarPayload(
        float $valorDia,
        string $tipo,
        string $vigenciaInicio,
        ?string $vigenciaFim,
        bool $todosContratos,
        array $contratoIds
    ): void {
        if ($valorDia <= 0) {
            throw new RuntimeException('Informe um valor por dia válido.');
        }
        if (!in_array($tipo, ['dias_trabalhados', 'dias_uteis'], true)) {
            throw new RuntimeException('Tipo de cálculo inválido.');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $vigenciaInicio)) {
            throw new RuntimeException('Vigência inicial inválida.');
        }
        $fim = $this->normalizarFim($vigenciaFim);
        if ($fim !== null && $fim < $vigenciaInicio) {
            throw new RuntimeException('Vigência final deve ser após o início.');
        }
        if (!$todosContratos) {
            $ids = array_values(array_filter(array_map('intval', $contratoIds), static fn (int $i): bool => $i > 0));
            if ($ids === []) {
                throw new RuntimeException('Selecione ao menos um contrato ou marque Todos.');
            }
        }
    }

    private function normalizarFim(?string $vigenciaFim): ?string
    {
        if ($vigenciaFim === null) {
            return null;
        }
        $vigenciaFim = trim($vigenciaFim);
        if ($vigenciaFim === '' || $vigenciaFim === '0000-00-00' || str_starts_with($vigenciaFim, '0000-')) {
            return null;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $vigenciaFim)) {
            return null;
        }
        return $vigenciaFim;
    }
}
