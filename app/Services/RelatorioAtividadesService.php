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
        $docSelect = $this->tableExists('ra_documentos')
            ? "CASE WHEN r.status IN ('enviado', 'aprovado') THEN (SELECT rd.status FROM ra_documentos rd WHERE rd.relatorio_id = r.id AND rd.status <> 'invalidado' ORDER BY rd.id DESC LIMIT 1) ELSE NULL END AS doc_status,
               CASE WHEN r.status IN ('enviado', 'aprovado') THEN (SELECT rd.portal_token FROM ra_documentos rd WHERE rd.relatorio_id = r.id AND rd.status <> 'invalidado' ORDER BY rd.id DESC LIMIT 1) ELSE NULL END AS portal_token,"
            : "NULL AS doc_status, NULL AS portal_token,";
        $sql = 'SELECT r.*, c.nome, c.matricula, pp.data_inicio, pp.data_fim,
                       os.codigo AS os_codigo,
                       ' . $docSelect . '
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

        $espelhoId = (int) ($relatorio['espelho_id'] ?? 0);
        if ($espelhoId <= 0) {
            $stEsp = $this->db->prepare(
                'SELECT id FROM ponto_espelhos WHERE colaborador_id = :c AND periodo_id = :p LIMIT 1'
            );
            $stEsp->execute([
                'c' => (int) $relatorio['colaborador_id'],
                'p' => (int) $relatorio['periodo_id'],
            ]);
            $espelhoId = (int) $stEsp->fetchColumn();
        }

        $colabIdRel = (int) ($relatorio['colaborador_id'] ?? 0);
        $justColSelect = $this->hasColumn('ponto_dias', 'justificativa_tipo')
            ? 'pd.justificativa_tipo AS pd_justificativa_tipo,'
            : 'NULL AS pd_justificativa_tipo,';
        $statusDiaSelect = $this->hasColumn('ponto_dias', 'status_dia')
            ? 'pd.status_dia AS status_dia,'
            : 'pd.ok_status AS status_dia,';
        $justTableSelect = $this->tableExists('ponto_justificativas')
            ? "(SELECT jt.codigo
                FROM ponto_justificativas pj
                INNER JOIN ponto_justificativa_tipos jt ON jt.id = pj.tipo_id
                WHERE (pj.espelho_id = pd.espelho_id OR pj.espelho_id IN (SELECT pe2.id FROM ponto_espelhos pe2 WHERE pe2.colaborador_id = {$colabIdRel}))
                  AND d.data BETWEEN pj.data_inicio AND pj.data_fim
                  AND jt.status = 'ativo'
                ORDER BY jt.abona_falta DESC, pj.id DESC LIMIT 1) AS tbl_justificativa_tipo,
               (SELECT jt.nome
                FROM ponto_justificativas pj
                INNER JOIN ponto_justificativa_tipos jt ON jt.id = pj.tipo_id
                WHERE (pj.espelho_id = pd.espelho_id OR pj.espelho_id IN (SELECT pe2.id FROM ponto_espelhos pe2 WHERE pe2.colaborador_id = {$colabIdRel}))
                  AND d.data BETWEEN pj.data_inicio AND pj.data_fim
                  AND jt.status = 'ativo'
                ORDER BY jt.abona_falta DESC, pj.id DESC LIMIT 1) AS justificativa_nome,
               (SELECT jt.abona_falta
                FROM ponto_justificativas pj
                INNER JOIN ponto_justificativa_tipos jt ON jt.id = pj.tipo_id
                WHERE (pj.espelho_id = pd.espelho_id OR pj.espelho_id IN (SELECT pe2.id FROM ponto_espelhos pe2 WHERE pe2.colaborador_id = {$colabIdRel}))
                  AND d.data BETWEEN pj.data_inicio AND pj.data_fim
                  AND jt.status = 'ativo'
                ORDER BY jt.abona_falta DESC, pj.id DESC LIMIT 1) AS justificativa_abona_falta,"
            : 'NULL AS tbl_justificativa_tipo, NULL AS justificativa_nome, NULL AS justificativa_abona_falta,';
        $ocorrSelect = $this->tableExists('ponto_ocorrencias')
            ? "(SELECT po.tipo
                FROM ponto_ocorrencias po
                WHERE po.ponto_dia_id = pd.id
                  AND po.tipo IN ('atestado', 'abono', 'falta_justificada', 'ferias', 'dispensa', 'afastamento')
                ORDER BY po.id DESC LIMIT 1) AS ocorrencia_tipo,"
            : 'NULL AS ocorrencia_tipo,';

        $diasSt = $this->db->prepare(
            "SELECT d.*,
                    pd.id AS ponto_dia_id,
                    pd.tipo_dia, pd.ok_status, pd.dia_falta, pd.ocorrencia_resumo,
                    {$justColSelect}
                    {$statusDiaSelect}
                    {$justTableSelect}
                    {$ocorrSelect}
                    pd.ent1 AS ponto_ent1, pd.sai1 AS ponto_sai1,
                    pd.ent2 AS ponto_ent2, pd.sai2 AS ponto_sai2,
                    pd.previsto_entrada AS ponto_previsto_entrada,
                    pd.previsto_saida AS ponto_previsto_saida
             FROM ra_dias d
             LEFT JOIN ponto_dias pd ON pd.espelho_id = :esp AND pd.data = d.data
             WHERE d.relatorio_id = :r
             ORDER BY d.data"
        );
        $diasSt->execute([
            'esp' => $espelhoId,
            'r' => $relatorioId,
        ]);
        $dias = $diasSt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $heSvc = new HeClienteService($this->db);
        foreach ($dias as &$diaRow) {
            // Não usar ?? puro: coluna vazia '' no ponto_dias não pode mascarar a justificativa da tabela.
            $justTipo = trim((string) ($diaRow['pd_justificativa_tipo'] ?? ''));
            if ($justTipo === '') {
                $justTipo = trim((string) ($diaRow['tbl_justificativa_tipo'] ?? ''));
            }
            if ($justTipo === '') {
                $justTipo = trim((string) ($diaRow['ocorrencia_tipo'] ?? ''));
            }
            $diaRow['justificativa_tipo'] = $justTipo !== '' ? $justTipo : null;
            $diaRow['ponto_batidas_label'] = $heSvc->rotuloBatidasPonto($diaRow);
            $diaRow['ponto_ultima_batida'] = $heSvc->ultimaBatidaPonto($diaRow);
            $diaRow['dispensado_preenchimento'] = $this->diaDispensadoDePreenchimento($diaRow);
            $diaRow['motivo_dispensa_label'] = $diaRow['dispensado_preenchimento']
                ? $this->motivoDispensaLabel($diaRow)
                : null;
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

                if ($this->diaDispensadoDePreenchimento($dia)) {
                    if ($descricao === '') {
                        $motivoDispensa = $this->motivoDispensaLabel($dia);
                        $autoDesc = $motivoDispensa !== ''
                            ? ('Ausência / Justificativa registrada no ponto (' . $motivoDispensa . ')')
                            : 'Ausência / Justificativa registrada no ponto';
                        if (!isset($byData[$data])) {
                            $byData[$data] = [
                                'descricao' => $autoDesc,
                                'area_atendimento' => $dia['area_atendimento'] ?? null,
                                'municipio' => $dia['municipio'] ?? null,
                                'projeto_obra' => $dia['projeto_obra'] ?? null,
                                'centro_custo' => $dia['centro_custo'] ?? null,
                                'area_classificada' => !empty($dia['area_classificada']) ? 1 : 0,
                                'ambiente' => $dia['ambiente'] ?? null,
                                'observacoes' => $dia['observacoes'] ?? null,
                                'he_hora_inicio' => $dia['he_hora_inicio'] ?? null,
                                'he_hora_fim' => $dia['he_hora_fim'] ?? null,
                                'he_status' => (string) ($dia['he_status'] ?? 'nenhuma'),
                            ];
                        } else {
                            $byData[$data]['descricao'] = $autoDesc;
                        }
                    }
                    continue;
                }

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
            if ($temHe) {
                $this->sincronizarHeAoEnviar($relatorioId, $dados['dias'], $byData);
            }

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
                    he_status = :he_status';
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

        if ($enviar && $this->tableExists('ra_documentos')) {
            try {
                $dadosEnviados = $this->carregar($relatorioId, $colaboradorId);
                if ($dadosEnviados !== null) {
                    $arquivoPdf = (new RelatorioAtividadesPdf($this->db))->gerar($dadosEnviados);
                    (new RelatorioAssinaturaService($this->db))->gerarLinkPortal($relatorioId, $arquivoPdf, null);
                }
            } catch (\Throwable) {
                // Não impede o envio caso o motor de PDF indisponibilize no momento; pode ser gerado depois.
            }
        }
    }

    public function enviar(int $relatorioId, int $colaboradorId): void
    {
        $dados = $this->carregar($relatorioId, $colaboradorId);
        if ($dados === null) {
            throw new RuntimeException('Relatório não encontrado.');
        }
        $this->salvar(
            $relatorioId,
            $colaboradorId,
            $dados['dias'],
            isset($dados['relatorio']['fiscal_vale']) ? (string) $dados['relatorio']['fiscal_vale'] : null,
            isset($dados['relatorio']['ordem_servico_id']) ? (int) $dados['relatorio']['ordem_servico_id'] : null,
            isset($dados['relatorio']['contrato_id']) ? (int) $dados['relatorio']['contrato_id'] : null,
            true
        );
    }

    /**
     * @param array<string,mixed> $dia
     */
    private function diaDispensadoDePreenchimento(array $dia): bool
    {
        // Falta no cartão: não exige inventar atividade no RA.
        if ((int) ($dia['dia_falta'] ?? 0) === 1) {
            return true;
        }
        // Justificativa que abona falta (férias/atestado/abono etc.) — mesmo com tipo_dia=trabalho.
        if ((int) ($dia['justificativa_abona_falta'] ?? 0) === 1) {
            return true;
        }
        $justTipo = mb_strtolower(trim((string) ($dia['justificativa_tipo'] ?? '')));
        $justNome = trim((string) ($dia['justificativa_nome'] ?? ''));
        $ocorrTipo = mb_strtolower(trim((string) ($dia['ocorrencia_tipo'] ?? '')));
        $ausenciaTipos = [
            'atestado', 'ferias', 'férias', 'abono', 'abona_falta', 'falta_justificada',
            'dispensa', 'afastamento', 'afastado', 'folga_abonada', 'folga_compensada', 'licenca', 'licença',
        ];
        if (in_array($justTipo, $ausenciaTipos, true) || in_array($ocorrTipo, $ausenciaTipos, true)) {
            return true;
        }
        if ($justNome !== '') {
            $nomeLow = mb_strtolower($justNome);
            foreach (['atestado', 'férias', 'ferias', 'abono', 'dispensa', 'afastament', 'licen', 'folga'] as $needle) {
                if (str_contains($nomeLow, $needle)) {
                    return true;
                }
            }
        }
        $statusDia = mb_strtolower(trim((string) ($dia['status_dia'] ?? $dia['ok_status'] ?? '')));
        if (in_array($statusDia, ['abonado', 'falta', 'afastado', 'ferias', 'atestado', 'dispensa', 'justificado', 'folga_abonada'], true)) {
            return true;
        }
        $resumo = mb_strtolower(trim((string) ($dia['ocorrencia_resumo'] ?? '')));
        if ($resumo !== '' && (
            str_starts_with($resumo, 'justificado')
            || str_contains($resumo, 'atestado')
            || str_contains($resumo, 'férias')
            || str_contains($resumo, 'ferias')
            || str_contains($resumo, 'abon')
            || str_contains($resumo, 'dispensa')
            || str_contains($resumo, 'afast')
            || in_array($resumo, [
                'falta',
                'falta justificada',
                'atestado',
                'férias',
                'ferias',
                'abonado',
                'afastado',
                'dispensa',
                'folga abonada',
                'licença',
                'licenca',
            ], true)
        )) {
            return true;
        }
        return false;
    }

    /**
     * @param array<string,mixed> $dia
     */
    private function motivoDispensaLabel(array $dia): string
    {
        $justNome = trim((string) ($dia['justificativa_nome'] ?? ''));
        if ($justNome !== '') {
            return $justNome;
        }
        $resumo = trim((string) ($dia['ocorrencia_resumo'] ?? ''));
        if ($resumo !== '' && mb_strtolower($resumo) !== 'ok') {
            return $resumo;
        }
        $justTipo = mb_strtolower(trim((string) ($dia['justificativa_tipo'] ?? $dia['ocorrencia_tipo'] ?? $dia['status_dia'] ?? '')));
        $map = [
            'atestado' => 'Atestado médico',
            'ferias' => 'Férias',
            'férias' => 'Férias',
            'abono' => 'Folga abonada / Abono',
            'abona_falta' => 'Falta abonada',
            'falta_justificada' => 'Falta justificada',
            'dispensa' => 'Dispensa',
            'afastamento' => 'Afastamento',
            'afastado' => 'Afastamento',
            'abonado' => 'Abonado no ponto',
            'falta' => 'Falta registrada no ponto',
        ];
        if (isset($map[$justTipo])) {
            return $map[$justTipo];
        }
        if ((int) ($dia['dia_falta'] ?? 0) === 1) {
            return 'Falta registrada no ponto';
        }
        return $justTipo !== '' ? ucfirst($justTipo) : 'Ausência / Justificativa no ponto';
    }

    /**
     * Se um dia já estava he_status = 'aplicada' e o colaborador alterou he_hora_inicio / he_hora_fim
     * após uma reabertura:
     * - Se removeu a HE, restaura sai2 = he_sai2_anterior no ponto_dias, remove a ocorrência de HE Cliente e volta he_status = 'nenhuma'.
     * - Se informou novo intervalo de HE, atualiza sai2 = novo he_hora_fim no ponto_dias (preservando he_sai2_anterior),
     *   atualiza os minutos da ocorrência de HE Cliente e recalcula o espelho.
     *
     * @param list<array<string,mixed>> $diasExistentes
     * @param array<string,array<string,mixed>> $byData
     */
    private function sincronizarHeAoEnviar(int $relatorioId, array $diasExistentes, array &$byData): void
    {
        $espelhosRecalcular = [];
        $calc = new CalculoPontoService($this->db);
        foreach ($diasExistentes as $diaAnt) {
            $data = (string) ($diaAnt['data'] ?? '');
            if ($data === '' || !isset($byData[$data])) {
                continue;
            }
            if ((string) ($diaAnt['he_status'] ?? '') !== 'aplicada') {
                continue;
            }
            $antIni = $this->normHoraHe($diaAnt['he_hora_inicio'] ?? null);
            $antFim = $this->normHoraHe($diaAnt['he_hora_fim'] ?? null);
            $novIni = $this->normHoraHe($byData[$data]['he_hora_inicio'] ?? null);
            $novFim = $this->normHoraHe($byData[$data]['he_hora_fim'] ?? null);

            if ($antIni === $novIni && $antFim === $novFim) {
                continue;
            }

            $pontoDiaId = (int) ($diaAnt['he_ponto_dia_id'] ?? $diaAnt['ponto_dia_id'] ?? 0);
            if ($pontoDiaId <= 0) {
                $stPd = $this->db->prepare(
                    'SELECT pd.id
                     FROM ra_relatorios r
                     INNER JOIN ponto_espelhos e ON (e.id = r.espelho_id OR (e.colaborador_id = r.colaborador_id AND e.periodo_id = r.periodo_id))
                     INNER JOIN ponto_dias pd ON pd.espelho_id = e.id AND pd.data = :d
                     WHERE r.id = :r
                     LIMIT 1'
                );
                $stPd->execute(['r' => $relatorioId, 'd' => $data]);
                $pontoDiaId = (int) $stPd->fetchColumn();
            }

            if ($novIni === null || $novFim === null) {
                // Colaborador removeu a HE daquele dia: restaura sai2 original e limpa ocorrência
                $byData[$data]['he_status'] = 'nenhuma';
                if ($pontoDiaId > 0) {
                    $espelhoId = $this->restaurarSai2PontoDia($pontoDiaId, $diaAnt['he_sai2_anterior'] ?? null, $calc);
                    $this->db->prepare(
                        "DELETE FROM ponto_ocorrencias WHERE ponto_dia_id = :dia AND tipo = 'hora_extra' AND observacao LIKE 'HE Cliente%'"
                    )->execute(['dia' => $pontoDiaId]);
                    if ($espelhoId > 0) {
                        $espelhosRecalcular[$espelhoId] = true;
                    }
                }
                $this->db->prepare(
                    'UPDATE ra_dias SET he_aplicada_em = NULL, he_sai2_anterior = NULL WHERE relatorio_id = :r AND data = :d'
                )->execute(['r' => $relatorioId, 'd' => $data]);
            } else {
                // Colaborador ajustou o horário da HE que já estava aplicada: atualiza sai2 no espelho para o novo he_hora_fim
                $byData[$data]['he_status'] = 'aplicada';
                if ($pontoDiaId > 0) {
                    $stCur = $this->db->prepare('SELECT sai2 FROM ponto_dias WHERE id = :id LIMIT 1');
                    $stCur->execute(['id' => $pontoDiaId]);
                    $sai2AtualBanco = $stCur->fetchColumn();
                    $sai2Base = $diaAnt['he_sai2_anterior'] ?? ($sai2AtualBanco !== false ? $sai2AtualBanco : null);

                    $espelhoId = $this->restaurarSai2PontoDia($pontoDiaId, $novFim, $calc);
                    if ($espelhoId > 0) {
                        $espelhosRecalcular[$espelhoId] = true;
                    }

                    $iniTs = strtotime('1970-01-01 ' . $novIni);
                    $fimTs = strtotime('1970-01-01 ' . $novFim);
                    if ($iniTs !== false && $fimTs !== false) {
                        if ($fimTs < $iniTs) {
                            $fimTs += 86400;
                        }
                        $minutos = (int) (($fimTs - $iniTs) / 60);
                        $obs = 'HE Cliente (RA #' . $relatorioId . ') — saída ajustada para ' . substr($novFim, 0, 5);
                        $this->db->prepare(
                            "DELETE FROM ponto_ocorrencias WHERE ponto_dia_id = :dia AND tipo = 'hora_extra' AND observacao LIKE 'HE Cliente%'"
                        )->execute(['dia' => $pontoDiaId]);
                        if ($minutos > 0) {
                            $this->db->prepare(
                                "INSERT INTO ponto_ocorrencias (ponto_dia_id, tipo, minutos, observacao)
                                 VALUES (:dia, 'hora_extra', :min, :obs)"
                            )->execute([
                                'dia' => $pontoDiaId,
                                'min' => $minutos,
                                'obs' => $obs,
                            ]);
                        }
                    }

                    $this->db->prepare(
                        'UPDATE ra_dias
                         SET he_aplicada_em = NOW(),
                             he_ponto_dia_id = :pd,
                             he_sai2_anterior = COALESCE(he_sai2_anterior, :ant)
                         WHERE relatorio_id = :r AND data = :d'
                    )->execute([
                        'pd' => $pontoDiaId,
                        'ant' => $this->normHoraHe($sai2Base),
                        'r' => $relatorioId,
                        'd' => $data,
                    ]);
                }
            }
        }

        foreach (array_keys($espelhosRecalcular) as $espId) {
            $calc->recalcularEspelho((int) $espId);
        }
    }

    private function normHoraHe(mixed $val): ?string
    {
        if ($val === null) {
            return null;
        }
        $s = trim((string) $val);
        if ($s === '') {
            return null;
        }
        if (preg_match('/^\d{2}:\d{2}$/', $s)) {
            return $s . ':00';
        }
        return substr($s, 0, 8);
    }

    private function restaurarSai2PontoDia(int $pontoDiaId, mixed $sai2Alvo, CalculoPontoService $calc): int
    {
        $st = $this->db->prepare('SELECT id, espelho_id, ent1, sai1, ent2 FROM ponto_dias WHERE id = :id LIMIT 1');
        $st->execute(['id' => $pontoDiaId]);
        $pd = $st->fetch(PDO::FETCH_ASSOC);
        if ($pd === false) {
            return 0;
        }
        $sai2Norm = $this->normHoraHe($sai2Alvo);
        $calc->salvarBatidas($pontoDiaId, [
            'ent1' => $pd['ent1'] !== null ? (string) $pd['ent1'] : null,
            'sai1' => $pd['sai1'] !== null ? (string) $pd['sai1'] : null,
            'ent2' => $pd['ent2'] !== null ? (string) $pd['ent2'] : null,
            'sai2' => $sai2Norm,
        ], 'manual', false);
        return (int) $pd['espelho_id'];
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

    /**
     * Executa ação em lote sobre múltiplos relatórios ('aprovar' ou 'gerar_link').
     *
     * @param list<int> $relatorioIds
     * @return array{processados:int,ignorados:int}
     */
    public function acaoLote(array $relatorioIds, string $acao, ?int $usuarioId = null): array
    {
        $processados = 0;
        $ignorados = 0;
        $ids = array_values(array_unique(array_filter(array_map('intval', $relatorioIds), static fn (int $v): bool => $v > 0)));
        foreach ($ids as $rid) {
            try {
                if ($acao === 'aprovar') {
                    $this->aprovar($rid);
                    $processados++;
                } elseif ($acao === 'gerar_link') {
                    $dados = $this->carregar($rid);
                    if ($dados === null || !in_array((string) ($dados['relatorio']['status'] ?? ''), ['enviado', 'aprovado'], true)) {
                        $ignorados++;
                        continue;
                    }
                    $arquivo = (new RelatorioAtividadesPdf($this->db))->gerar($dados);
                    (new RelatorioAssinaturaService($this->db))->gerarLinkPortal($rid, $arquivo, $usuarioId);
                    $processados++;
                } else {
                    $ignorados++;
                }
            } catch (Throwable) {
                $ignorados++;
            }
        }
        return ['processados' => $processados, 'ignorados' => $ignorados];
    }

    public function reabrir(int $relatorioId): void
    {
        $this->db->prepare(
            "UPDATE ra_relatorios SET status = 'rascunho', enviado_em = NULL, aprovado_em = NULL, updated_at = NOW() WHERE id = :id"
        )->execute(['id' => $relatorioId]);

        if ($this->tableExists('ra_documentos')) {
            $this->db->prepare(
                "UPDATE ra_documentos SET status = 'invalidado' WHERE relatorio_id = :id AND status <> 'invalidado'"
            )->execute(['id' => $relatorioId]);
        }
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
                   AND r.status IN ('enviado', 'aprovado')
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

    private function tableExists(string $table): bool
    {
        try {
            $st = $this->db->prepare(
                'SELECT 1 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t LIMIT 1'
            );
            $st->execute(['t' => $table]);
            return (bool) $st->fetchColumn();
        } catch (Throwable) {
            return false;
        }
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
