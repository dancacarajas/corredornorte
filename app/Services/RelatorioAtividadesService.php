<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use DateInterval;
use DateTimeImmutable;
use PDO;
use RuntimeException;
use Throwable;

final class RelatorioAtividadesService
{
    private PDO $db;
    private LotacaoResolver $resolver;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
        $this->resolver = new LotacaoResolver($this->db);
    }

    public function garantirParaEspelho(int $espelhoId): ?int
    {
        $stmt = $this->db->prepare(
            'SELECT e.id, e.colaborador_id, e.periodo_id, e.lotacao_id,
                    pp.data_inicio, pp.data_fim,
                    c.funcao, c.gestor_id, g.nome AS gestor_nome
             FROM ponto_espelhos e
             INNER JOIN ponto_periodos pp ON pp.id = e.periodo_id
             INNER JOIN rh_colaboradores c ON c.id = e.colaborador_id
             LEFT JOIN rh_colaboradores g ON g.id = c.gestor_id
             WHERE e.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $espelhoId]);
        $esp = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($esp === false) {
            return null;
        }

        $colaboradorId = (int) $esp['colaborador_id'];
        $periodoId = (int) $esp['periodo_id'];
        $inicio = (string) $esp['data_inicio'];
        $fim = (string) $esp['data_fim'];

        $existente = $this->db->prepare(
            'SELECT id, status FROM ra_relatorios
             WHERE colaborador_id = :c AND periodo_id = :p LIMIT 1'
        );
        $existente->execute(['c' => $colaboradorId, 'p' => $periodoId]);
        $row = $existente->fetch(PDO::FETCH_ASSOC);

        if ($row !== false) {
            $relatorioId = (int) $row['id'];
            if (in_array((string) $row['status'], ['enviado', 'aprovado'], true)) {
                return $relatorioId;
            }
            $this->db->prepare(
                'UPDATE ra_relatorios SET espelho_id = :e WHERE id = :id'
            )->execute(['e' => $espelhoId, 'id' => $relatorioId]);
            $this->garantirDias($relatorioId, $inicio, $fim, $colaboradorId);
            return $relatorioId;
        }

        $cfg = $this->resolver->resolveParaGeracao($colaboradorId, new DateTimeImmutable($inicio));
        $ins = $this->db->prepare(
            'INSERT INTO ra_relatorios
             (colaborador_id, periodo_id, espelho_id, ordem_servico_id, contrato_id, gestor_nome, status)
             VALUES (:c, :p, :e, :os, :ct, :g, \'rascunho\')'
        );
        $ins->execute([
            'c' => $colaboradorId,
            'p' => $periodoId,
            'e' => $espelhoId,
            'os' => isset($cfg['ordem_servico_id']) ? (int) $cfg['ordem_servico_id'] : null,
            'ct' => isset($cfg['contrato_id']) ? (int) $cfg['contrato_id'] : null,
            'g' => $esp['gestor_nome'] ?? null,
        ]);
        $relatorioId = (int) $this->db->lastInsertId();
        $this->garantirDias($relatorioId, $inicio, $fim, $colaboradorId, $cfg);
        return $relatorioId;
    }

    public function backfillColaborador(int $colaboradorId): int
    {
        $st = $this->db->prepare(
            'SELECT e.id FROM ponto_espelhos e
             WHERE e.colaborador_id = :c
             ORDER BY e.id DESC'
        );
        $st->execute(['c' => $colaboradorId]);
        $n = 0;
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $espelhoId) {
            if ($this->garantirParaEspelho((int) $espelhoId) !== null) {
                $n++;
            }
        }
        return $n;
    }

    public function backfillPeriodo(int $periodoId): int
    {
        $st = $this->db->prepare('SELECT id FROM ponto_espelhos WHERE periodo_id = :p');
        $st->execute(['p' => $periodoId]);
        $n = 0;
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $espelhoId) {
            if ($this->garantirParaEspelho((int) $espelhoId) !== null) {
                $n++;
            }
        }
        return $n;
    }

    /** @return list<array<string,mixed>> */
    public function listarDoColaborador(int $colaboradorId): array
    {
        $this->backfillColaborador($colaboradorId);
        $st = $this->db->prepare(
            'SELECT r.*, pp.data_inicio, pp.data_fim,
                    os.codigo AS os_codigo, os.nome AS os_nome,
                    ct.codigo AS contrato_codigo, ct.nome AS contrato_nome,
                    (SELECT COUNT(*) FROM ra_dias d WHERE d.relatorio_id = r.id AND d.ambiente = \'remoto\') AS dias_remoto,
                    (SELECT COUNT(*) FROM ra_dias d WHERE d.relatorio_id = r.id AND d.ambiente = \'site\') AS dias_site,
                    (SELECT COUNT(*) FROM ra_dias d WHERE d.relatorio_id = r.id AND d.ambiente = \'viagem\') AS dias_viagem
             FROM ra_relatorios r
             INNER JOIN ponto_periodos pp ON pp.id = r.periodo_id
             LEFT JOIN rh_ordens_servico os ON os.id = r.ordem_servico_id
             LEFT JOIN rh_contratos ct ON ct.id = r.contrato_id
             WHERE r.colaborador_id = :c
             ORDER BY pp.data_inicio DESC, r.id DESC'
        );
        $st->execute(['c' => $colaboradorId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<array<string,mixed>> */
    public function listarRh(?string $status = null, ?string $dataInicio = null, ?string $dataFim = null, int $limit = 200, ?string $busca = null): array
    {
        $where = ['1=1'];
        $params = [];
        if ($status !== null && $status !== '') {
            $where[] = 'r.status = :st';
            $params['st'] = $status;
        }
        if ($dataInicio !== null && $dataInicio !== '' && $dataFim !== null && $dataFim !== '') {
            $where[] = 'pp.data_inicio = :ini AND pp.data_fim = :fim';
            $params['ini'] = $dataInicio;
            $params['fim'] = $dataFim;
        }
        if ($busca !== null && trim($busca) !== '') {
            $like = '%' . trim($busca) . '%';
            $where[] = '(c.nome LIKE :q_nome OR c.matricula LIKE :q_mat)';
            $params['q_nome'] = $like;
            $params['q_mat'] = $like;
        }
        $sql = 'SELECT r.*, c.nome, c.matricula, pp.data_inicio, pp.data_fim,
                       os.codigo AS os_codigo,
                       (SELECT COUNT(*) FROM ra_dias d WHERE d.relatorio_id = r.id AND d.ambiente = \'remoto\') AS dias_remoto,
                       (SELECT COUNT(*) FROM ra_dias d WHERE d.relatorio_id = r.id AND d.ambiente = \'site\') AS dias_site,
                       (SELECT COUNT(*) FROM ra_dias d WHERE d.relatorio_id = r.id AND d.ambiente = \'viagem\') AS dias_viagem
                FROM ra_relatorios r
                INNER JOIN rh_colaboradores c ON c.id = r.colaborador_id
                INNER JOIN ponto_periodos pp ON pp.id = r.periodo_id
                LEFT JOIN rh_ordens_servico os ON os.id = r.ordem_servico_id
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY pp.data_inicio DESC, c.nome
                LIMIT ' . max(1, $limit);
        $st = $this->db->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array{relatorio:array<string,mixed>,dias:list<array<string,mixed>>,contadores:array<string,int>}|null */
    public function carregar(int $relatorioId, ?int $colaboradorId = null): ?array
    {
        $heFluxoSelect = $this->hasColumn('rh_colaboradores', 'he_fluxo')
            ? "COALESCE(c.he_fluxo, 'off') AS he_fluxo, c.gestor_cliente_id, gc.nome AS gestor_cliente_nome,"
            : "'off' AS he_fluxo, NULL AS gestor_cliente_id, NULL AS gestor_cliente_nome,";
        $heFluxoJoin = $this->hasColumn('rh_colaboradores', 'gestor_cliente_id')
            ? 'LEFT JOIN rh_gestores_cliente gc ON gc.id = c.gestor_cliente_id'
            : '';
        $sql = 'SELECT r.*, c.nome, c.matricula, c.funcao, c.cargo,
                       ' . $heFluxoSelect . '
                       pp.data_inicio, pp.data_fim,
                       os.codigo AS os_codigo, os.nome AS os_nome,
                       ct.codigo AS contrato_codigo, ct.nome AS contrato_nome
                FROM ra_relatorios r
                INNER JOIN rh_colaboradores c ON c.id = r.colaborador_id
                INNER JOIN ponto_periodos pp ON pp.id = r.periodo_id
                ' . $heFluxoJoin . '
                LEFT JOIN rh_ordens_servico os ON os.id = r.ordem_servico_id
                LEFT JOIN rh_contratos ct ON ct.id = r.contrato_id
                WHERE r.id = :id';
        $params = ['id' => $relatorioId];
        if ($colaboradorId !== null) {
            $sql .= ' AND r.colaborador_id = :c';
            $params['c'] = $colaboradorId;
        }
        $sql .= ' LIMIT 1';
        $st = $this->db->prepare($sql);
        $st->execute($params);
        $relatorio = $st->fetch(PDO::FETCH_ASSOC);
        if ($relatorio === false) {
            return null;
        }

        $diasSt = $this->db->prepare(
            'SELECT d.*,
                    pd.tipo_dia, pd.ok_status,
                    pd.ent1 AS ponto_ent1, pd.sai1 AS ponto_sai1,
                    pd.ent2 AS ponto_ent2, pd.sai2 AS ponto_sai2,
                    pd.previsto_entrada AS ponto_previsto_entrada,
                    pd.previsto_saida AS ponto_previsto_saida
             FROM ra_dias d
             LEFT JOIN ponto_dias pd ON pd.espelho_id = :esp AND pd.data = d.data
             WHERE d.relatorio_id = :r
             ORDER BY d.data'
        );
        $diasSt->execute([
            'esp' => (int) ($relatorio['espelho_id'] ?? 0),
            'r' => $relatorioId,
        ]);
        $dias = $diasSt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $heSvc = new HeClienteService($this->db);
        foreach ($dias as &$diaRow) {
            $diaRow['ponto_batidas_label'] = $heSvc->rotuloBatidasPonto($diaRow);
            $diaRow['ponto_ultima_batida'] = $heSvc->ultimaBatidaPonto($diaRow);
        }
        unset($diaRow);

        $contadores = ['remoto' => 0, 'site' => 0, 'viagem' => 0, 'classificada' => 0];
        foreach ($dias as $dia) {
            $amb = $dia['ambiente'] ?? null;
            if (is_string($amb) && isset($contadores[$amb])) {
                $contadores[$amb]++;
            }
            if (!empty($dia['area_classificada'])) {
                $contadores['classificada']++;
            }
        }

        return ['relatorio' => $relatorio, 'dias' => $dias, 'contadores' => $contadores];
    }

    /**
     * @param list<array<string,mixed>> $diasPayload
     */
    public function salvar(
        int $relatorioId,
        int $colaboradorId,
        array $diasPayload,
        ?string $fiscalVale = null,
        ?int $ordemServicoId = null,
        ?int $contratoId = null,
        bool $enviar = false
    ): void {
        $dados = $this->carregar($relatorioId, $colaboradorId);
        if ($dados === null) {
            throw new RuntimeException('Relatório não encontrado.');
        }
        $rel = $dados['relatorio'];
        $statusAtual = (string) $rel['status'];
        if (in_array($statusAtual, ['aprovado', 'assinado'], true)) {
            throw new RuntimeException('Relatório aprovado/assinado não pode ser alterado.');
        }
        if ($statusAtual === 'enviado') {
            throw new RuntimeException('Relatório já enviado. Peça ao RH para reabrir se precisar corrigir.');
        }

        $byData = [];
        $heSvc = new HeClienteService($this->db);
        $usaHeCliente = $heSvc->colaboradorUsaFluxoCliente($colaboradorId);
        foreach ($diasPayload as $item) {
            $data = (string) ($item['data'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $data)) {
                continue;
            }
            $ambiente = $item['ambiente'] ?? null;
            if ($ambiente === '') {
                $ambiente = null;
            }
            if ($ambiente !== null && !in_array($ambiente, ['remoto', 'site', 'viagem'], true)) {
                throw new RuntimeException('Ambiente inválido em ' . $data);
            }
            $statusHeAtual = 'nenhuma';
            $diaExistenteRef = null;
            foreach ($dados['dias'] as $diaExistente) {
                if ((string) ($diaExistente['data'] ?? '') === $data) {
                    $statusHeAtual = (string) ($diaExistente['he_status'] ?? 'nenhuma');
                    $diaExistenteRef = $diaExistente;
                    break;
                }
            }
            $heCampos = $usaHeCliente
                ? $heSvc->normalizarCamposHeDia(
                    $item,
                    $statusHeAtual,
                    is_array($diaExistenteRef) ? $diaExistenteRef : null,
                    $data
                )
                : ['he_hora_inicio' => null, 'he_hora_fim' => null, 'he_status' => 'nenhuma'];

            $byData[$data] = [
                'descricao' => trim((string) ($item['descricao'] ?? '')) ?: null,
                'area_atendimento' => trim((string) ($item['area_atendimento'] ?? '')) ?: null,
                'municipio' => trim((string) ($item['municipio'] ?? '')) ?: null,
                'projeto_obra' => trim((string) ($item['projeto_obra'] ?? '')) ?: null,
                'centro_custo' => trim((string) ($item['centro_custo'] ?? '')) ?: null,
                'area_classificada' => !empty($item['area_classificada']) ? 1 : 0,
                'ambiente' => $ambiente,
                'observacoes' => trim((string) ($item['observacoes'] ?? '')) ?: null,
                'he_hora_inicio' => $heCampos['he_hora_inicio'],
                'he_hora_fim' => $heCampos['he_hora_fim'],
                'he_status' => $heCampos['he_status'],
            ];
        }

        if ($enviar) {
            foreach ($dados['dias'] as $dia) {
                $tipo = (string) ($dia['tipo_dia'] ?? 'trabalho');
                if ($tipo !== 'trabalho') {
                    continue;
                }
                $data = (string) $dia['data'];
                $vals = $byData[$data] ?? [];
                $descricao = trim((string) ($vals['descricao'] ?? $dia['descricao'] ?? ''));
                $projeto = trim((string) ($vals['projeto_obra'] ?? $dia['projeto_obra'] ?? ''));
                $ambiente = $vals['ambiente'] ?? $dia['ambiente'] ?? null;
                $rotuloDia = date('d/m/Y', strtotime($data));
                if ($descricao === '') {
                    throw new RuntimeException(
                        'Informe a descrição das atividades em todos os dias de trabalho, inclusive ' . $rotuloDia . '.'
                    );
                }
                if ($projeto === '') {
                    throw new RuntimeException(
                        'Informe o projeto/obra em todos os dias de trabalho, inclusive ' . $rotuloDia . '.'
                    );
                }
                if ($ambiente === null || $ambiente === '') {
                    throw new RuntimeException(
                        'Informe o ambiente (Remoto/Site/Viagem) em todos os dias de trabalho, inclusive ' . $rotuloDia . '.'
                    );
                }
            }
        }

        $this->db->beginTransaction();
        try {
            $updCab = $this->db->prepare(
                'UPDATE ra_relatorios
                 SET fiscal_vale = :f,
                     ordem_servico_id = COALESCE(:os, ordem_servico_id),
                     contrato_id = COALESCE(:ct, contrato_id),
                     status = :st,
                     enviado_em = CASE WHEN :env = 1 THEN NOW() ELSE enviado_em END,
                     updated_at = NOW()
                 WHERE id = :id AND colaborador_id = :c'
            );
            $updCab->execute([
                'f' => $fiscalVale !== null && trim($fiscalVale) !== '' ? trim($fiscalVale) : null,
                'os' => $ordemServicoId && $ordemServicoId > 0 ? $ordemServicoId : null,
                'ct' => $contratoId && $contratoId > 0 ? $contratoId : null,
                'st' => $enviar ? 'enviado' : 'rascunho',
                'env' => $enviar ? 1 : 0,
                'id' => $relatorioId,
                'c' => $colaboradorId,
            ]);

            $temHe = $this->hasRaHeColumns();
            $updDiaSql = 'UPDATE ra_dias SET
                    descricao = :descricao,
                    area_atendimento = :area_atendimento,
                    municipio = :municipio,
                    projeto_obra = :projeto_obra,
                    centro_custo = :centro_custo,
                    area_classificada = :area_classificada,
                    ambiente = :ambiente,
                    observacoes = :observacoes';
            if ($temHe) {
                $updDiaSql .= ',
                    he_hora_inicio = :he_hora_inicio,
                    he_hora_fim = :he_hora_fim,
                    he_status = CASE
                        WHEN he_status = \'aplicada\' THEN \'aplicada\'
                        ELSE :he_status
                    END';
            }
            $updDiaSql .= ', updated_at = NOW() WHERE relatorio_id = :r AND data = :data';
            $updDia = $this->db->prepare($updDiaSql);
            foreach ($byData as $data => $vals) {
                $params = [
                    'descricao' => $vals['descricao'],
                    'area_atendimento' => $vals['area_atendimento'],
                    'municipio' => $vals['municipio'],
                    'projeto_obra' => $vals['projeto_obra'],
                    'centro_custo' => $vals['centro_custo'],
                    'area_classificada' => $vals['area_classificada'],
                    'ambiente' => $vals['ambiente'],
                    'observacoes' => $vals['observacoes'],
                    'r' => $relatorioId,
                    'data' => $data,
                ];
                if ($temHe) {
                    $params['he_hora_inicio'] = $vals['he_hora_inicio'];
                    $params['he_hora_fim'] = $vals['he_hora_fim'];
                    $params['he_status'] = $vals['he_status'];
                }
                $updDia->execute($params);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function aprovar(int $relatorioId): void
    {
        $st = $this->db->prepare('SELECT status FROM ra_relatorios WHERE id = :id');
        $st->execute(['id' => $relatorioId]);
        $status = $st->fetchColumn();
        if ($status === false) {
            throw new RuntimeException('Relatório não encontrado.');
        }
        if ((string) $status !== 'enviado') {
            throw new RuntimeException('Só é possível aprovar relatórios enviados.');
        }
        $this->db->prepare(
            "UPDATE ra_relatorios SET status = 'aprovado', aprovado_em = NOW() WHERE id = :id"
        )->execute(['id' => $relatorioId]);
    }

    public function reabrir(int $relatorioId): void
    {
        $this->db->prepare(
            "UPDATE ra_relatorios SET status = 'rascunho', enviado_em = NULL, aprovado_em = NULL WHERE id = :id"
        )->execute(['id' => $relatorioId]);
    }

    public function contarAbertos(int $colaboradorId): int
    {
        $st = $this->db->prepare(
            "SELECT COUNT(*) FROM ra_relatorios WHERE colaborador_id = :c AND status = 'rascunho'"
        );
        $st->execute(['c' => $colaboradorId]);
        return (int) $st->fetchColumn();
    }

    /**
     * Mapa relatorio_id => true para RAs já assinados eletronicamente.
     *
     * @return array<int,true>
     */
    public function idsAssinadosDoColaborador(int $colaboradorId): array
    {
        $mapa = [];
        foreach ($this->assinaturasDoColaborador($colaboradorId) as $relatorioId => $_meta) {
            $mapa[(int) $relatorioId] = true;
        }
        return $mapa;
    }

    /**
     * Assinaturas de RA do colaborador: relatorio_id => meta.
     *
     * @return array<int,array{assinatura_em:?string}>
     */
    public function assinaturasDoColaborador(int $colaboradorId): array
    {
        if ($colaboradorId <= 0 || !$this->hasColumn('ra_documentos', 'relatorio_id')) {
            return [];
        }
        try {
            $st = $this->db->prepare(
                "SELECT d.relatorio_id, d.assinatura_em
                 FROM ra_documentos d
                 INNER JOIN ra_relatorios r ON r.id = d.relatorio_id
                 WHERE r.colaborador_id = :c
                   AND d.status = 'assinado'
                 ORDER BY d.assinatura_em DESC, d.id DESC"
            );
            $st->execute(['c' => $colaboradorId]);
        } catch (Throwable) {
            return [];
        }
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $rid = (int) ($row['relatorio_id'] ?? 0);
            if ($rid <= 0 || isset($out[$rid])) {
                continue;
            }
            $out[$rid] = [
                'assinatura_em' => $row['assinatura_em'] !== null ? (string) $row['assinatura_em'] : null,
            ];
        }
        return $out;
    }

    /** @param array<string,mixed>|null $cfg */
    private function garantirDias(
        int $relatorioId,
        string $inicio,
        string $fim,
        int $colaboradorId,
        ?array $cfg = null
    ): void {
        if ($cfg === null) {
            $cfg = $this->resolver->resolveParaGeracao($colaboradorId, new DateTimeImmutable($inicio)) ?? [];
        }
        $municipio = isset($cfg['municipio']) ? (string) $cfg['municipio'] : null;
        if ($municipio !== null && isset($cfg['uf']) && $cfg['uf'] !== '') {
            $municipio .= '/' . $cfg['uf'];
        }
        $cc = isset($cfg['centro_custo_codigo']) ? (string) $cfg['centro_custo_codigo'] : null;
        $area = isset($cfg['os_nome']) ? (string) $cfg['os_nome'] : null;

        $cursor = new DateTimeImmutable($inicio);
        $ultimo = new DateTimeImmutable($fim);
        $ins = $this->db->prepare(
            'INSERT IGNORE INTO ra_dias
             (relatorio_id, data, area_atendimento, municipio, centro_custo)
             VALUES (:r, :d, :a, :m, :cc)'
        );
        while ($cursor <= $ultimo) {
            $ins->execute([
                'r' => $relatorioId,
                'd' => $cursor->format('Y-m-d'),
                'a' => $area,
                'm' => $municipio,
                'cc' => $cc,
            ]);
            $cursor = $cursor->add(new DateInterval('P1D'));
        }
    }

    private function hasRaHeColumns(): bool
    {
        return $this->hasColumn('ra_dias', 'he_hora_inicio') && $this->hasColumn('ra_dias', 'he_status');
    }

    private function hasColumn(string $table, string $column): bool
    {
        $st = $this->db->prepare(
            'SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c LIMIT 1'
        );
        $st->execute(['t' => $table, 'c' => $column]);
        return (bool) $st->fetchColumn();
    }
}
