<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use DateTimeImmutable;
use PDO;
use Throwable;

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
        return $this->montarConfig($colaboradorId, $data, false);
    }

    /**
     * Resolução para geração de ponto: não bloqueia por data de alocação.
     * Usa lotação vigente; se não houver, a mais próxima do colaborador; por fim qualquer lotação ativa.
     *
     * @return array<string,mixed>|null
     */
    public function resolveParaGeracao(int $colaboradorId, DateTimeImmutable|string $data): ?array
    {
        return $this->montarConfig($colaboradorId, $data, true);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function montarConfig(int $colaboradorId, DateTimeImmutable|string $data, bool $paraGeracao): ?array
    {
        $dia = $data instanceof DateTimeImmutable ? $data : new DateTimeImmutable($data);
        $dataStr = $dia->format('Y-m-d');

        $lotacao = $this->lotacaoVigente($colaboradorId, $dataStr);
        if ($lotacao === null && $paraGeracao) {
            $lotacao = $this->lotacaoMaisProxima($colaboradorId, $dataStr)
                ?? $this->lotacaoAtivaPadrao();
        }
        if ($lotacao === null) {
            return null;
        }

        $excecao = $this->excecaoVigente($colaboradorId, $dataStr);

        if ($excecao !== null) {
            if (!empty($excecao['horario_id'])) {
                $lotacao['horario_id'] = (int) $excecao['horario_id'];
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

        $baseHe = (int) ($lotacao['lotacao_percentual_he'] ?? $lotacao['percentual_he'] ?? 50);
        if ($baseHe < 1) {
            $baseHe = 50;
        }
        $overrideHe = $lotacao['colaborador_percentual_he'] ?? null;
        $lotacao['lotacao_percentual_he'] = $baseHe;
        $lotacao['percentual_he'] = ($overrideHe !== null && $overrideHe !== '')
            ? max(1, min(200, (int) $overrideHe))
            : $baseHe;

        // Modelo RHiD: previsto/tipo do dia vêm do horário (semanal/cíclico) por data.
        try {
            $lotacao = (new HorarioService($this->db))->aplicarDiaNoCfg($lotacao, $dia);
        } catch (Throwable) {
        }

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
              cl.percentual_he AS colaborador_percentual_he,
              {$this->selectLotacaoCampos()}
            FROM rh_colaborador_lotacoes cl
            INNER JOIN rh_lotacoes l ON l.id = cl.lotacao_id
            {$this->joinsLotacao()}
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
     * Qualquer vínculo de lotação do colaborador, mesmo fora da vigência da data.
     * Prefere vínculo aberto e o mais próximo da data informada.
     *
     * @return array<string,mixed>|null
     */
    public function lotacaoMaisProxima(int $colaboradorId, string $data): ?array
    {
        $sql = <<<SQL
            SELECT
              cl.id AS colaborador_lotacao_id,
              cl.vigencia_inicio,
              cl.vigencia_fim,
              cl.escala_ancora_data,
              cl.motivo,
              cl.percentual_he AS colaborador_percentual_he,
              {$this->selectLotacaoCampos()}
            FROM rh_colaborador_lotacoes cl
            INNER JOIN rh_lotacoes l ON l.id = cl.lotacao_id
            {$this->joinsLotacao()}
            WHERE cl.colaborador_id = :colaborador_id
            ORDER BY
              (cl.vigencia_fim IS NULL) DESC,
              ABS(DATEDIFF(cl.vigencia_inicio, :data)) ASC,
              cl.id DESC
            LIMIT 1
        SQL;

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'colaborador_id' => $colaboradorId,
            'data' => $data,
        ]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Fallback absoluto: primeira lotação ativa do sistema.
     *
     * @return array<string,mixed>|null
     */
    public function lotacaoAtivaPadrao(): ?array
    {
        $sql = <<<SQL
            SELECT
              NULL AS colaborador_lotacao_id,
              NULL AS vigencia_inicio,
              NULL AS vigencia_fim,
              NULL AS escala_ancora_data,
              'sistema' AS motivo,
              NULL AS colaborador_percentual_he,
              {$this->selectLotacaoCampos()}
            FROM rh_lotacoes l
            {$this->joinsLotacao()}
            WHERE l.status = 'ativo'
            ORDER BY l.id
            LIMIT 1
        SQL;

        $row = $this->db->query($sql)->fetch();
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

    private function selectLotacaoCampos(): string
    {
        return <<<SQL
              l.*,
              l.percentual_he AS lotacao_percentual_he,
              c.codigo AS contrato_codigo,
              c.nome AS contrato_nome,
              cc.codigo AS centro_custo_codigo,
              cc.nome AS centro_custo_nome,
              os.codigo AS os_codigo,
              os.nome AS os_nome,
              loc.nome AS localidade_nome,
              loc.municipio,
              loc.uf,
              h.codigo AS horario_codigo,
              h.nome AS horario_nome,
              h.tipo AS horario_cadastro_tipo,
              COALESCE(h.trabalha_feriado, 0) AS trabalha_feriado,
              COALESCE(h.adicional_noturno, 0) AS adicional_noturno,
              NULL AS entrada,
              NULL AS intervalo_inicio,
              NULL AS intervalo_fim,
              NULL AS saida,
              NULL AS carga_diaria_min,
              NULL AS carga_semanal_min,
              0 AS trabalha_sabado,
              0 AS trabalha_domingo
        SQL;
    }

    private function joinsLotacao(): string
    {
        return <<<SQL
            INNER JOIN rh_contratos c ON c.id = l.contrato_id
            INNER JOIN rh_centros_custo cc ON cc.id = l.centro_custo_id
            INNER JOIN rh_ordens_servico os ON os.id = l.ordem_servico_id
            INNER JOIN rh_localidades loc ON loc.id = l.localidade_id
            LEFT JOIN rh_horarios h ON h.id = l.horario_id
        SQL;
    }
}
