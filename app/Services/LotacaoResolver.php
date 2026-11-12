<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use DateTimeImmutable;
use PDO;

/**
 * Resolve a configuração operacional do colaborador em uma data.
 * Prioridade: exceção individual > lotação vigente > calendário composto.
 */
final class LotacaoResolver
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    /**
     * @return array<string,mixed>|null
     */
    public function resolve(int $colaboradorId, DateTimeImmutable|string $data): ?array
    {
        $dia = $data instanceof DateTimeImmutable ? $data : new DateTimeImmutable($data);
        $dataStr = $dia->format('Y-m-d');

        $lotacao = $this->lotacaoVigente($colaboradorId, $dataStr);
        if ($lotacao === null) {
            return null;
        }

        $excecao = $this->excecaoVigente($colaboradorId, $dataStr);

        if ($excecao !== null) {
            if (!empty($excecao['jornada_id'])) {
                $lotacao['jornada_id'] = (int) $excecao['jornada_id'];
                $lotacao['jornada'] = $this->fetchById('rh_jornadas', (int) $excecao['jornada_id']);
            }
            if (!empty($excecao['escala_id'])) {
                $lotacao['escala_id'] = (int) $excecao['escala_id'];
                $lotacao['escala'] = $this->fetchById('rh_escalas', (int) $excecao['escala_id']);
            }
            if (!empty($excecao['calendario_id'])) {
                $lotacao['calendario_id'] = (int) $excecao['calendario_id'];
            }
            $lotacao['excecao'] = $excecao;
        } else {
            $lotacao['excecao'] = null;
        }

        $lotacao['feriados'] = $this->calendarioComposto(
            (string) ($lotacao['uf'] ?? ''),
            (int) ($lotacao['localidade_id'] ?? 0),
            (int) ($lotacao['contrato_id'] ?? 0),
            isset($lotacao['calendario_id']) ? (int) $lotacao['calendario_id'] : null,
            $dataStr
        );

        return $lotacao;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function lotacaoVigente(int $colaboradorId, string $data): ?array
    {
        $sql = <<<SQL
            SELECT
              cl.id AS colaborador_lotacao_id,
              cl.vigencia_inicio,
              cl.vigencia_fim,
              cl.escala_ancora_data,
              cl.motivo,
              l.*,
              c.codigo AS contrato_codigo,
              c.nome AS contrato_nome,
              cc.codigo AS centro_custo_codigo,
              cc.nome AS centro_custo_nome,
              os.codigo AS os_codigo,
              os.nome AS os_nome,
              loc.nome AS localidade_nome,
              loc.municipio,
              loc.uf,
              j.codigo AS jornada_codigo,
              j.nome AS jornada_nome,
              j.entrada, j.intervalo_inicio, j.intervalo_fim, j.saida,
              j.carga_diaria_min, j.carga_semanal_min,
              j.trabalha_sabado, j.trabalha_domingo, j.trabalha_feriado, j.adicional_noturno,
              e.codigo AS escala_codigo,
              e.nome AS escala_nome,
              e.tipo AS escala_tipo
            FROM rh_colaborador_lotacoes cl
            INNER JOIN rh_lotacoes l ON l.id = cl.lotacao_id
            INNER JOIN rh_contratos c ON c.id = l.contrato_id
            INNER JOIN rh_centros_custo cc ON cc.id = l.centro_custo_id
            INNER JOIN rh_ordens_servico os ON os.id = l.ordem_servico_id
            INNER JOIN rh_localidades loc ON loc.id = l.localidade_id
            INNER JOIN rh_jornadas j ON j.id = l.jornada_id
            INNER JOIN rh_escalas e ON e.id = l.escala_id
            WHERE cl.colaborador_id = :colaborador_id
              AND cl.vigencia_inicio <= :data
              AND (cl.vigencia_fim IS NULL OR cl.vigencia_fim >= :data2)
            ORDER BY cl.vigencia_inicio DESC
            LIMIT 1
        SQL;

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'colaborador_id' => $colaboradorId,
            'data' => $data,
            'data2' => $data,
        ]);

        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function excecaoVigente(int $colaboradorId, string $data): ?array
    {
        $sql = <<<SQL
            SELECT *
            FROM rh_colaborador_excecoes
            WHERE colaborador_id = :colaborador_id
              AND vigencia_inicio <= :data
              AND vigencia_fim >= :data2
            ORDER BY vigencia_inicio DESC
            LIMIT 1
        SQL;

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'colaborador_id' => $colaboradorId,
            'data' => $data,
            'data2' => $data,
        ]);

        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * União Nacional + Estadual + Municipal + Contrato/Lotação para a data.
     *
     * @return list<array<string,mixed>>
     */
    public function calendarioComposto(
        string $uf,
        int $localidadeId,
        int $contratoId,
        ?int $calendarioLotacaoId,
        string $data
    ): array {
        $sql = <<<SQL
            SELECT f.*, cal.codigo AS calendario_codigo, cal.escopo
            FROM rh_feriados f
            INNER JOIN rh_calendarios cal ON cal.id = f.calendario_id
            WHERE cal.status = 'ativo'
              AND (
                (f.recorrente_ano = 0 AND f.data = :data)
                OR (f.recorrente_ano = 1 AND DATE_FORMAT(f.data, '%m-%d') = DATE_FORMAT(:data2, '%m-%d'))
              )
              AND (
                cal.escopo = 'nacional'
                OR (cal.escopo = 'estadual' AND cal.uf = :uf)
                OR (cal.escopo = 'municipal' AND cal.localidade_id = :localidade_id)
                OR (cal.escopo = 'contrato' AND (cal.contrato_id = :contrato_id OR cal.id = :calendario_id))
              )
            ORDER BY FIELD(cal.escopo, 'nacional','estadual','municipal','contrato'), f.nome
        SQL;

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'data' => $data,
            'data2' => $data,
            'uf' => $uf,
            'localidade_id' => $localidadeId,
            'contrato_id' => $contratoId,
            'calendario_id' => $calendarioLotacaoId ?? 0,
        ]);

        return $stmt->fetchAll();
    }

    /**
     * @return array<string,mixed>|null
     */
    private function fetchById(string $table, int $id): ?array
    {
        $allowed = ['rh_jornadas', 'rh_escalas', 'rh_calendarios'];
        if (!in_array($table, $allowed, true)) {
            return null;
        }

        $stmt = $this->db->prepare("SELECT * FROM {$table} WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }
}
