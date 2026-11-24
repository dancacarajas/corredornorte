<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Helpers\DiaUtil;
use PDO;
use RuntimeException;

final class VrService
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
        $this->ensureNomeColumn();
        $this->sanitizarDatasZero();
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
     *   id:int,nome:string,valor_dia:string,tipo:string,vigencia_inicio:string,vigencia_fim:?string,
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
     * @return array{id:int,nome:string,valor_dia:string,tipo:string,vigencia_inicio:string,vigencia_fim:?string,todos_contratos:bool}|null
     */
    public function configAtiva(?string $dataRef = null): ?array
    {
        $dataRef = $dataRef ?? date('Y-m-d');
        $st = $this->db->prepare(
            'SELECT * FROM vr_config
             WHERE ativo = 1
               AND vigencia_inicio <= :d
               AND (vigencia_fim IS NULL OR vigencia_fim >= :d2)
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
        ?int $id = null,
        string $nome = ''
    ): int {
        $this->validarPayload($valorDia, $tipo, $vigenciaInicio, $vigenciaFim, $todosContratos, $contratoIds);
        $nome = $this->normalizarNome($nome, $todosContratos, $contratoIds, $valorDia);

        $this->db->beginTransaction();
        try {
            $params = [
                'n' => $nome,
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
                     SET nome = :n, valor_dia = :v, tipo = :t, vigencia_inicio = :i, vigencia_fim = :f,
                         todos_contratos = :tc, ativo = 1
                     WHERE id = :id'
                )->execute($params);
                $configId = $id;
                $this->db->prepare('DELETE FROM vr_config_contratos WHERE config_id = :id')->execute(['id' => $configId]);
            } else {
                $this->db->prepare(
                    'INSERT INTO vr_config (nome, valor_dia, tipo, vigencia_inicio, vigencia_fim, todos_contratos, ativo)
                     VALUES (:n, :v, :t, :i, :f, :tc, 1)'
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
     * VR = valor_dia × dias Remoto do RA enviado/aprovado no período.
     * Ignora rascunho; exige cruzamento com ponto (dia útil sem falta/afastamento/abono).
     *
     * @return list<array<string,mixed>>
     */
    public function calcularPeriodo(string $dataInicio, string $dataFim): array
    {
        $configs = $this->configsVigentesNoPeriodo($dataInicio, $dataFim);
        if ($configs === []) {
            throw new RuntimeException('Cadastre uma configuração de Vale Refeição vigente.');
        }

        $condJustCol = $this->hasColumn('ponto_dias', 'justificativa_tipo')
            ? " AND COALESCE(pd.justificativa_tipo, '') NOT IN ('ferias', 'atestado', 'abona_falta', 'dispensa', 'afastamento')"
            : '';
        $condJustTbl = $this->tableExists('ponto_justificativas')
            ? " AND NOT EXISTS (
                    SELECT 1 FROM ponto_justificativas pj
                    INNER JOIN ponto_justificativa_tipos jt ON jt.id = pj.tipo_id
                    WHERE (pj.espelho_id = pd.espelho_id
                           OR pj.espelho_id IN (
                               SELECT pe2.id FROM ponto_espelhos pe2
                               WHERE pe2.colaborador_id = ra.colaborador_id
                           ))
                      AND rd.data BETWEEN pj.data_inicio AND pj.data_fim
                      AND jt.status = 'ativo'
                      AND (
                          jt.abona_falta = 1
                          OR LOWER(jt.codigo) IN ('ferias', 'férias', 'atestado', 'atst', 'abona_falta', 'abono', 'dispensa', 'afastamento', 'folga_compensada')
                      )
                )"
            : '';
        // Remoto só conta com trabalho efetivo no ponto (total_trab_min > 0), sem falta integral nem justificativa abonadora.
        // Permite feriado/folga/folga_escala trabalhados para 'dias_trabalhados'; 'dias_uteis' restringe a dias úteis sem feriado.
        $condDiaValido = 'pd.id IS NOT NULL'
            . " AND pd.tipo_dia IN ('trabalho', 'feriado', 'folga', 'folga_escala')"
            . ' AND COALESCE(pd.total_trab_min, 0) > 0'
            . ' AND COALESCE(pd.dia_falta, 0) = 0'
            . " AND LOWER(COALESCE(pd.ocorrencia_resumo, '')) NOT LIKE 'justificado%'"
            . $condJustCol
            . $condJustTbl;

        $sql = "SELECT ra.id AS relatorio_id, ra.status, ra.contrato_id, c.id AS colaborador_id, c.nome, c.matricula,
                       pp.data_inicio, pp.data_fim,
                       rd.data AS dia_data, rd.ambiente,
                       pd.tipo_dia, pd.ocorrencia_resumo,
                       CASE WHEN {$condDiaValido} THEN 1 ELSE 0 END AS dia_valido
                FROM ra_relatorios ra
                INNER JOIN rh_colaboradores c ON c.id = ra.colaborador_id
                INNER JOIN ponto_periodos pp ON pp.id = ra.periodo_id
                INNER JOIN ra_dias rd ON rd.relatorio_id = ra.id
                LEFT JOIN ponto_espelhos pe ON pe.id = COALESCE(
                    NULLIF(ra.espelho_id, 0),
                    (SELECT pe2.id FROM ponto_espelhos pe2
                     WHERE pe2.colaborador_id = ra.colaborador_id AND pe2.periodo_id = ra.periodo_id
                     ORDER BY pe2.id DESC LIMIT 1)
                )
                LEFT JOIN ponto_dias pd ON pd.espelho_id = pe.id AND pd.data = rd.data
                WHERE pp.data_inicio = :i AND pp.data_fim = :f
                  AND ra.status IN ('enviado', 'aprovado')
                ORDER BY c.nome, ra.id, rd.data";
        $st = $this->db->prepare($sql);
        $st->execute(['i' => $dataInicio, 'f' => $dataFim]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $porRelatorio = [];
        foreach ($rows as $row) {
            $relId = (int) $row['relatorio_id'];
            if (!isset($porRelatorio[$relId])) {
                $contratoId = isset($row['contrato_id']) && $row['contrato_id'] !== null
                    ? (int) $row['contrato_id']
                    : null;
                $porRelatorio[$relId] = [
                    'relatorio_id' => $relId,
                    'status' => (string) $row['status'],
                    'colaborador_id' => (int) $row['colaborador_id'],
                    'nome' => (string) $row['nome'],
                    'matricula' => (string) $row['matricula'],
                    'contrato_id' => $contratoId,
                    'data_inicio' => (string) $row['data_inicio'],
                    'data_fim' => (string) $row['data_fim'],
                    'dias_remoto' => 0,
                    'dias_site' => 0,
                    'dias_viagem' => 0,
                    'valor_centavos' => 0,
                    'cfg_aplicada' => null,
                ];
            }

            $ambiente = (string) ($row['ambiente'] ?? '');
            if ($ambiente === 'site') {
                $porRelatorio[$relId]['dias_site']++;
                continue;
            }
            if ($ambiente === 'viagem') {
                $porRelatorio[$relId]['dias_viagem']++;
                continue;
            }
            if ($ambiente !== 'remoto' || (int) ($row['dia_valido'] ?? 0) !== 1) {
                continue;
            }

            $diaData = (string) ($row['dia_data'] ?? '');
            $contratoId = $porRelatorio[$relId]['contrato_id'];
            $cfgDia = $this->resolverConfigParaData($configs, $contratoId, $diaData);
            if ($cfgDia === null) {
                continue;
            }

            if ($cfgDia['tipo'] === 'dias_uteis') {
                $tipoDia = (string) ($row['tipo_dia'] ?? '');
                $ocorrencia = (string) ($row['ocorrencia_resumo'] ?? '');
                if ($tipoDia !== 'trabalho' || !$this->isDiaUtilParaVr($diaData, $ocorrencia)) {
                    continue;
                }
            }

            $porRelatorio[$relId]['dias_remoto']++;
            $porRelatorio[$relId]['valor_centavos'] += $this->paraCentavos((string) $cfgDia['valor_dia']);
            $porRelatorio[$relId]['cfg_aplicada'] = $cfgDia;
        }

        $out = [];
        foreach ($porRelatorio as $item) {
            $contratoId = $item['contrato_id'];
            $cfgRef = $item['cfg_aplicada']
                ?? $this->resolverConfigParaData($configs, $contratoId, $dataFim)
                ?? $this->resolverConfig($configs, $contratoId);
            if ($cfgRef === null) {
                continue;
            }

            $out[] = [
                'relatorio_id' => $item['relatorio_id'],
                'status' => $item['status'],
                'colaborador_id' => $item['colaborador_id'],
                'nome' => $item['nome'],
                'matricula' => $item['matricula'],
                'contrato_id' => $contratoId,
                'data_inicio' => $item['data_inicio'],
                'data_fim' => $item['data_fim'],
                'dias_remoto' => $item['dias_remoto'],
                'dias_site' => $item['dias_site'],
                'dias_viagem' => $item['dias_viagem'],
                'valor_dia' => (float) $cfgRef['valor_dia'],
                'tipo_calculo' => $cfgRef['tipo'],
                'config_id' => $cfgRef['id'],
                'config_nome' => (string) ($cfgRef['nome'] ?? ''),
                'config_alocacao' => (string) ($cfgRef['contratos_label'] ?? ''),
                'valor_vr' => round($item['valor_centavos'] / 100, 2),
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

    /**
     * Resolve a configuração vigente em uma data específica, respeitando precedência
     * de contrato específico sobre configuração geral.
     *
     * @param list<array<string,mixed>> $configs
     * @return array<string,mixed>|null
     */
    private function resolverConfigParaData(array $configs, ?int $contratoId, string $data): ?array
    {
        $vigentes = [];
        foreach ($configs as $cfg) {
            $ini = (string) ($cfg['vigencia_inicio'] ?? '');
            $fim = $cfg['vigencia_fim'] ?? null;
            if ($ini !== '' && $ini > $data) {
                continue;
            }
            if ($fim !== null && $fim !== '' && (string) $fim < $data) {
                continue;
            }
            $vigentes[] = $cfg;
        }
        if ($vigentes === []) {
            return null;
        }
        return $this->resolverConfig($vigentes, $contratoId);
    }

    private function isDiaUtilParaVr(string $data, string $ocorrenciaResumo): bool
    {
        if (!DiaUtil::isUtil($data, $this->db)) {
            return false;
        }
        $resumo = mb_strtolower(trim($ocorrenciaResumo));
        if (str_starts_with($resumo, 'plantão em feriado') || str_starts_with($resumo, 'plantao em feriado')) {
            return false;
        }
        return true;
    }

    private function paraCentavos(string $valor): int
    {
        $norm = str_replace(',', '.', trim($valor));
        if (!is_numeric($norm)) {
            return 0;
        }
        return (int) round((float) $norm * 100);
    }

    /** @return list<array<string,mixed>> */
    private function configsVigentesNoPeriodo(string $dataInicio, string $dataFim): array
    {
        $st = $this->db->prepare(
            'SELECT * FROM vr_config
             WHERE ativo = 1
               AND vigencia_inicio <= :fim
               AND (vigencia_fim IS NULL OR vigencia_fim >= :ini)
             ORDER BY vigencia_inicio DESC, id DESC'
        );
        $st->execute(['fim' => $dataFim, 'ini' => $dataInicio]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->hidratarConfig($row);
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function configsVigentes(string $dataRef): array
    {
        return $this->configsVigentesNoPeriodo($dataRef, $dataRef);
    }

    /**
     * MySQL 8 (NO_ZERO_DATE) rejeita literal/comparação '0000-00-00'.
     * Converte vigência aberta legada para NULL.
     */
    private function sanitizarDatasZero(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        try {
            $this->db->exec(
                "UPDATE vr_config
                 SET vigencia_fim = NULL
                 WHERE vigencia_fim IS NOT NULL
                   AND CAST(vigencia_fim AS CHAR(10)) IN ('0000-00-00', '0')"
            );
        } catch (\Throwable) {
            // Tabela ainda não migrada / sem permissão — queries já não usam o literal zero.
        }
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
        $alocacao = $todos ? 'Todos' : ($labels !== [] ? implode(', ', $labels) : '—');
        $nome = trim((string) ($row['nome'] ?? ''));
        if ($nome === '') {
            $nome = $todos
                ? 'VR geral — Todos'
                : ('VR — ' . ($labels !== [] ? $labels[0] : 'contratos'));
        }

        return [
            'id' => $id,
            'nome' => $nome,
            'valor_dia' => (string) $row['valor_dia'],
            'tipo' => (string) $row['tipo'],
            'vigencia_inicio' => (string) $row['vigencia_inicio'],
            'vigencia_fim' => $fim,
            'todos_contratos' => $todos,
            'ativo' => !empty($row['ativo']),
            'contrato_ids' => $contratoIds,
            'contratos_label' => $alocacao,
        ];
    }

    private function ensureNomeColumn(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        try {
            $cols = $this->db->query('SHOW COLUMNS FROM vr_config')->fetchAll(PDO::FETCH_COLUMN) ?: [];
            if (!in_array('nome', $cols, true)) {
                $this->db->exec(
                    "ALTER TABLE vr_config
                     ADD COLUMN nome VARCHAR(120) NOT NULL DEFAULT '' AFTER id"
                );
            }
            $this->db->exec(
                "UPDATE vr_config
                 SET nome = CASE
                     WHEN todos_contratos = 1 THEN CONCAT('VR geral — R$ ', FORMAT(valor_dia, 2))
                     ELSE CONCAT('VR contratos — R$ ', FORMAT(valor_dia, 2))
                 END
                 WHERE TRIM(COALESCE(nome, '')) = ''"
            );
        } catch (\Throwable) {
            // Ambiente sem tabela ainda.
        }
    }

    /**
     * @param list<int> $contratoIds
     */
    private function normalizarNome(string $nome, bool $todos, array $contratoIds, float $valorDia): string
    {
        $nome = trim(preg_replace('/\s+/u', ' ', $nome) ?? $nome);
        if ($nome !== '') {
            return mb_substr($nome, 0, 120);
        }
        if ($todos) {
            return 'VR geral — R$ ' . number_format($valorDia, 2, ',', '.');
        }
        $qtd = count(array_filter(array_map('intval', $contratoIds), static fn (int $i): bool => $i > 0));

        return 'VR ' . $qtd . ' contrato(s) — R$ ' . number_format($valorDia, 2, ',', '.');
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

    private function tableExists(string $table): bool
    {
        try {
            $st = $this->db->prepare(
                'SELECT 1 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t LIMIT 1'
            );
            $st->execute(['t' => $table]);
            return (bool) $st->fetchColumn();
        } catch (\Throwable) {
            return false;
        }
    }

    private function hasColumn(string $table, string $column): bool
    {
        try {
            $st = $this->db->prepare(
                'SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c LIMIT 1'
            );
            $st->execute(['t' => $table, 'c' => $column]);
            return (bool) $st->fetchColumn();
        } catch (\Throwable) {
            return false;
        }
    }
}
