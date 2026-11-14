<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use DateTimeImmutable;
use PDO;
use RuntimeException;
use Throwable;

final class HeAutorizacaoService
{
    private PDO $db;
    private LotacaoResolver $resolver;
    private MotorPontoService $motor;
    private CalculoPontoService $calc;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
        $this->resolver = new LotacaoResolver($this->db);
        $this->motor = new MotorPontoService($this->db);
        $this->calc = new CalculoPontoService($this->db);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function buscarColaboradorPorCpf(string $cpf): ?array
    {
        $cpf = preg_replace('/\D+/', '', $cpf) ?: '';
        if (strlen($cpf) !== 11) {
            return null;
        }
        $stmt = $this->db->prepare(
            "SELECT id, nome, cpf, matricula, gestor_id, status
             FROM rh_colaboradores
             WHERE cpf = :cpf
             LIMIT 1"
        );
        $stmt->execute(['cpf' => $cpf]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function ehGestor(int $colaboradorId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM rh_colaboradores
             WHERE gestor_id = :id AND status = 'ativo'"
        );
        $stmt->execute(['id' => $colaboradorId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * @return array{contrato_id:?int,ordem_servico_id:?int,contrato_codigo:?string,os_codigo:?string}
     */
    public function prefillLotacao(int $colaboradorId, string $data): array
    {
        $cfg = $this->resolver->resolveParaGeracao($colaboradorId, $data);
        if ($cfg === null) {
            return [
                'contrato_id' => null,
                'ordem_servico_id' => null,
                'contrato_codigo' => null,
                'os_codigo' => null,
            ];
        }
        return [
            'contrato_id' => isset($cfg['contrato_id']) ? (int) $cfg['contrato_id'] : null,
            'ordem_servico_id' => isset($cfg['ordem_servico_id']) ? (int) $cfg['ordem_servico_id'] : null,
            'contrato_codigo' => isset($cfg['contrato_codigo']) ? (string) $cfg['contrato_codigo'] : null,
            'os_codigo' => isset($cfg['os_codigo']) ? (string) $cfg['os_codigo'] : null,
        ];
    }

    /** @return list<array{id:int,codigo:string,nome:string}> */
    public function listarContratos(): array
    {
        $rows = $this->db->query(
            "SELECT id, codigo, nome FROM rh_contratos WHERE status = 'ativo' ORDER BY nome"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(static fn(array $r): array => [
            'id' => (int) $r['id'],
            'codigo' => (string) $r['codigo'],
            'nome' => (string) $r['nome'],
        ], $rows);
    }

    /**
     * @return list<array{id:int,codigo:string,nome:string,contrato_id:int}>
     */
    public function listarOrdensServico(?int $contratoId = null): array
    {
        if ($contratoId !== null && $contratoId > 0) {
            $stmt = $this->db->prepare(
                "SELECT os.id, os.codigo, os.nome, cc.contrato_id
                 FROM rh_ordens_servico os
                 INNER JOIN rh_centros_custo cc ON cc.id = os.centro_custo_id
                 WHERE os.status = 'ativo' AND cc.contrato_id = :c
                 ORDER BY os.nome"
            );
            $stmt->execute(['c' => $contratoId]);
        } else {
            $stmt = $this->db->query(
                "SELECT os.id, os.codigo, os.nome, cc.contrato_id
                 FROM rh_ordens_servico os
                 INNER JOIN rh_centros_custo cc ON cc.id = os.centro_custo_id
                 WHERE os.status = 'ativo'
                 ORDER BY os.nome"
            );
        }
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(static fn(array $r): array => [
            'id' => (int) $r['id'],
            'codigo' => (string) $r['codigo'],
            'nome' => (string) $r['nome'],
            'contrato_id' => (int) $r['contrato_id'],
        ], $rows);
    }

    /**
     * @param array{
     *   relatorio_atividade:string,data:string,hora_inicial:string,hora_final:string,
     *   contrato_id:int,ordem_servico_id:int,modalidade:string
     * } $dados
     */
    public function solicitar(int $colaboradorId, array $dados): int
    {
        $colab = $this->db->prepare('SELECT id, gestor_id, status FROM rh_colaboradores WHERE id = :id');
        $colab->execute(['id' => $colaboradorId]);
        $row = $colab->fetch(PDO::FETCH_ASSOC);
        if ($row === false || (string) $row['status'] !== 'ativo') {
            throw new RuntimeException('Colaborador não encontrado ou inativo.');
        }
        $gestorId = (int) ($row['gestor_id'] ?? 0);
        if ($gestorId <= 0) {
            throw new RuntimeException('Seu cadastro não possui gestor. Solicite ao RH o vínculo do gestor.');
        }

        $relatorio = trim((string) ($dados['relatorio_atividade'] ?? ''));
        $data = trim((string) ($dados['data'] ?? ''));
        $horaIni = $this->normHora((string) ($dados['hora_inicial'] ?? ''));
        $horaFim = $this->normHora((string) ($dados['hora_final'] ?? ''));
        $contratoId = (int) ($dados['contrato_id'] ?? 0);
        $osId = (int) ($dados['ordem_servico_id'] ?? 0);
        $modalidade = (string) ($dados['modalidade'] ?? '');

        if ($relatorio === '') {
            throw new RuntimeException('Informe o relatório da atividade.');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
            throw new RuntimeException('Data inválida.');
        }
        if ($horaIni === null || $horaFim === null) {
            throw new RuntimeException('Informe hora inicial e hora final.');
        }
        $minutos = $this->diffMinutos($horaIni, $horaFim);
        if ($minutos <= 0) {
            throw new RuntimeException('A hora final deve ser posterior à hora inicial.');
        }
        if (!in_array($modalidade, ['viagem', 'presencial', 'remoto'], true)) {
            throw new RuntimeException('Selecione a modalidade (viagem, presencial ou remoto).');
        }
        if ($contratoId <= 0 || $osId <= 0) {
            throw new RuntimeException('Selecione contrato e frente de serviço.');
        }

        $dup = $this->db->prepare(
            "SELECT id FROM he_solicitacoes
             WHERE colaborador_id = :c AND data = :d AND status = 'pendente'
             LIMIT 1"
        );
        $dup->execute(['c' => $colaboradorId, 'd' => $data]);
        if ($dup->fetchColumn() !== false) {
            throw new RuntimeException('Já existe uma solicitação pendente para esta data.');
        }

        $ins = $this->db->prepare(
            'INSERT INTO he_solicitacoes
            (colaborador_id, gestor_id, relatorio_atividade, data, hora_inicial, hora_final, minutos_he,
             contrato_id, ordem_servico_id, modalidade, status)
             VALUES
            (:colaborador_id, :gestor_id, :relatorio, :data, :hora_inicial, :hora_final, :minutos,
             :contrato_id, :ordem_servico_id, :modalidade, \'pendente\')'
        );
        $ins->execute([
            'colaborador_id' => $colaboradorId,
            'gestor_id' => $gestorId,
            'relatorio' => $relatorio,
            'data' => $data,
            'hora_inicial' => $horaIni,
            'hora_final' => $horaFim,
            'minutos' => $minutos,
            'contrato_id' => $contratoId,
            'ordem_servico_id' => $osId,
            'modalidade' => $modalidade,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Ajusta o cartão (sai2 + ocorrência HE). Não chamar dentro de outra transação
     * (garantirPontoDia pode gerar espelho com transação própria).
     * @return array{ponto_dia_id:int,sai2_anterior:?string}
     */
    public function aplicarExtraNoCartao(
        int $colaboradorId,
        string $data,
        string $horaFinal,
        int $minutos,
        string $observacao
    ): array {
        $horaFinalNorm = $this->normHora($horaFinal);
        if ($horaFinalNorm === null) {
            throw new RuntimeException('Hora final inválida.');
        }
        if ($minutos <= 0) {
            throw new RuntimeException('Minutos de HE inválidos.');
        }
        $pontoDiaId = $this->garantirPontoDia($colaboradorId, $data);
        return $this->gravarExtraNoDia($pontoDiaId, $horaFinalNorm, $minutos, $observacao);
    }

    /**
     * @return array{ponto_dia_id:int,sai2_anterior:?string}
     */
    private function gravarExtraNoDia(
        int $pontoDiaId,
        string $horaFinalNorm,
        int $minutos,
        string $observacao
    ): array {
        $batidas = $this->batidasAtuais($pontoDiaId);
        $sai2Anterior = $batidas['sai2'];
        $batidas['sai2'] = $horaFinalNorm;
        $this->calc->salvarBatidas($pontoDiaId, $batidas, 'manual', true);
        $this->db->prepare(
            "INSERT INTO ponto_ocorrencias (ponto_dia_id, tipo, minutos, observacao)
             VALUES (:dia, 'hora_extra', :min, :obs)"
        )->execute([
            'dia' => $pontoDiaId,
            'min' => $minutos,
            'obs' => $observacao,
        ]);

        return [
            'ponto_dia_id' => $pontoDiaId,
            'sai2_anterior' => $sai2Anterior,
        ];
    }

    public function aprovar(int $solicitacaoId, int $gestorId): void
    {
        $sol = $this->buscar($solicitacaoId);
        if ($sol === null) {
            throw new RuntimeException('Solicitação não encontrada.');
        }
        if ((int) $sol['gestor_id'] !== $gestorId) {
            throw new RuntimeException('Você não é o gestor desta solicitação.');
        }
        if ((string) $sol['status'] !== 'pendente') {
            throw new RuntimeException('Somente solicitações pendentes podem ser aprovadas.');
        }

        $colaboradorId = (int) $sol['colaborador_id'];
        $data = (string) $sol['data'];
        $horaFinal = substr((string) $sol['hora_final'], 0, 8);
        $minutos = (int) $sol['minutos_he'];
        $horaFinalNorm = $this->normHora($horaFinal);
        if ($horaFinalNorm === null) {
            throw new RuntimeException('Hora final inválida.');
        }

        $pontoDiaId = $this->garantirPontoDia($colaboradorId, $data);

        $this->db->beginTransaction();
        try {
            $sol = $this->bloquearSolicitacao($solicitacaoId);
            if ((string) $sol['status'] !== 'pendente') {
                throw new RuntimeException('Somente solicitações pendentes podem ser aprovadas.');
            }

            $resultado = $this->gravarExtraNoDia(
                $pontoDiaId,
                $horaFinalNorm,
                $minutos,
                'HE autorizada #' . $solicitacaoId . ' — saída ajustada para ' . substr($horaFinalNorm, 0, 5)
            );

            $this->db->prepare(
                "UPDATE he_solicitacoes
                 SET status = 'aprovada',
                     ponto_dia_id = :dia,
                     sai2_anterior = :sai2,
                     aprovado_em = NOW(),
                     aprovado_por_colaborador_id = :gestor,
                     motivo_recusa = NULL
                 WHERE id = :id"
            )->execute([
                'dia' => $resultado['ponto_dia_id'],
                'sai2' => $resultado['sai2_anterior'],
                'gestor' => $gestorId,
                'id' => $solicitacaoId,
            ]);

            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function recusar(int $solicitacaoId, int $gestorId, string $motivo = ''): void
    {
        $motivo = trim($motivo);
        $this->db->beginTransaction();
        try {
            $sol = $this->bloquearSolicitacao($solicitacaoId);
            if ((int) $sol['gestor_id'] !== $gestorId) {
                throw new RuntimeException('Você não é o gestor desta solicitação.');
            }
            if ((string) $sol['status'] !== 'pendente') {
                throw new RuntimeException('Somente solicitações pendentes podem ser recusadas.');
            }

            $this->db->prepare(
                "UPDATE he_solicitacoes
                 SET status = 'recusada',
                     motivo_recusa = :motivo,
                     aprovado_em = NOW(),
                     aprovado_por_colaborador_id = :gestor
                 WHERE id = :id"
            )->execute([
                'motivo' => $motivo !== '' ? $motivo : null,
                'gestor' => $gestorId,
                'id' => $solicitacaoId,
            ]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function cancelar(int $solicitacaoId, int $colaboradorId): void
    {
        $sol = $this->buscar($solicitacaoId);
        if ($sol === null || (int) $sol['colaborador_id'] !== $colaboradorId) {
            throw new RuntimeException('Solicitação não encontrada.');
        }
        if ((string) $sol['status'] !== 'pendente') {
            throw new RuntimeException('Somente solicitações pendentes podem ser canceladas.');
        }
        $this->db->prepare(
            "UPDATE he_solicitacoes SET status = 'cancelada' WHERE id = :id"
        )->execute(['id' => $solicitacaoId]);
    }

    /**
     * Reaplica HE aprovadas no espelho (após regeneração / horário britânico).
     */
    public function reaplicarAprovadasNoEspelho(int $espelhoId): void
    {
        $meta = $this->db->prepare(
            'SELECT e.colaborador_id, pp.data_inicio, pp.data_fim
             FROM ponto_espelhos e
             INNER JOIN ponto_periodos pp ON pp.id = e.periodo_id
             WHERE e.id = :id'
        );
        $meta->execute(['id' => $espelhoId]);
        $row = $meta->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return;
        }

        $colaboradorId = (int) $row['colaborador_id'];
        $inicio = (string) $row['data_inicio'];
        $fim = (string) $row['data_fim'];

        $stmt = $this->db->prepare(
            "SELECT * FROM he_solicitacoes
             WHERE colaborador_id = :c
               AND status = 'aprovada'
               AND data BETWEEN :ini AND :fim
             ORDER BY data, id"
        );
        $stmt->execute(['c' => $colaboradorId, 'ini' => $inicio, 'fim' => $fim]);
        $sols = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($sols === []) {
            return;
        }

        foreach ($sols as $sol) {
            $this->aplicarSai2NoDia(
                $espelhoId,
                (string) $sol['data'],
                substr((string) $sol['hora_final'], 0, 8),
                (int) $sol['id']
            );
        }

        $this->calc->atualizarTotaisEspelho($espelhoId);
    }

    /** @return list<array<string,mixed>> */
    public function listarDoColaborador(int $colaboradorId, int $limit = 50): array
    {
        $stmt = $this->db->prepare(
            "SELECT s.*, g.nome AS gestor_nome, ct.codigo AS contrato_codigo, os.codigo AS os_codigo
             FROM he_solicitacoes s
             INNER JOIN rh_colaboradores g ON g.id = s.gestor_id
             INNER JOIN rh_contratos ct ON ct.id = s.contrato_id
             INNER JOIN rh_ordens_servico os ON os.id = s.ordem_servico_id
             WHERE s.colaborador_id = :id
             ORDER BY s.created_at DESC
             LIMIT {$limit}"
        );
        $stmt->execute(['id' => $colaboradorId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<array<string,mixed>> */
    public function listarPendentesDoGestor(int $gestorId): array
    {
        $stmt = $this->db->prepare(
            "SELECT s.*, c.nome AS colaborador_nome, c.matricula,
                    ct.codigo AS contrato_codigo, os.codigo AS os_codigo, os.nome AS os_nome
             FROM he_solicitacoes s
             INNER JOIN rh_colaboradores c ON c.id = s.colaborador_id
             INNER JOIN rh_contratos ct ON ct.id = s.contrato_id
             INNER JOIN rh_ordens_servico os ON os.id = s.ordem_servico_id
             WHERE s.gestor_id = :id AND s.status = 'pendente'
             ORDER BY s.data, s.created_at"
        );
        $stmt->execute(['id' => $gestorId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<array<string,mixed>> */
    public function listarTodas(int $limit = 100, ?string $status = null): array
    {
        $sql = "SELECT s.*, c.nome AS colaborador_nome, c.matricula, g.nome AS gestor_nome,
                       ct.codigo AS contrato_codigo, os.codigo AS os_codigo
                FROM he_solicitacoes s
                INNER JOIN rh_colaboradores c ON c.id = s.colaborador_id
                INNER JOIN rh_colaboradores g ON g.id = s.gestor_id
                INNER JOIN rh_contratos ct ON ct.id = s.contrato_id
                INNER JOIN rh_ordens_servico os ON os.id = s.ordem_servico_id";
        $params = [];
        if ($status !== null && $status !== '') {
            $sql .= ' WHERE s.status = :st';
            $params['st'] = $status;
        }
        $sql .= " ORDER BY s.created_at DESC LIMIT {$limit}";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<string,mixed>|null */
    public function buscar(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM he_solicitacoes WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @return array<string,mixed> */
    private function bloquearSolicitacao(int $id): array
    {
        $stmt = $this->db->prepare('SELECT * FROM he_solicitacoes WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new RuntimeException('Solicitação não encontrada.');
        }
        return $row;
    }

    private function garantirPontoDia(int $colaboradorId, string $data): int
    {
        $stmt = $this->db->prepare(
            'SELECT d.id, d.espelho_id, e.status
             FROM ponto_dias d
             INNER JOIN ponto_espelhos e ON e.id = d.espelho_id
             INNER JOIN ponto_periodos pp ON pp.id = e.periodo_id
             WHERE e.colaborador_id = :c AND d.data = :data
             LIMIT 1'
        );
        $stmt->execute(['c' => $colaboradorId, 'data' => $data]);
        $existente = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existente !== false) {
            $diaId = (int) $existente['id'];
            $batidas = $this->batidasAtuais($diaId);
            if ($batidas['ent1'] === null && $batidas['sai2'] === null) {
                $this->calc->aplicarBritanicoNoEspelho((int) $existente['espelho_id']);
            }
            return $diaId;
        }

        $periodo = $this->db->prepare(
            'SELECT pp.data_inicio, pp.data_fim
             FROM ponto_periodos pp
             INNER JOIN ponto_espelhos e ON e.periodo_id = pp.id
             WHERE e.colaborador_id = :c
               AND :data BETWEEN pp.data_inicio AND pp.data_fim
             ORDER BY pp.id DESC
             LIMIT 1'
        );
        $periodo->execute(['c' => $colaboradorId, 'data' => $data]);
        $p = $periodo->fetch(PDO::FETCH_ASSOC);
        if ($p === false) {
            throw new RuntimeException(
                'Não há cartão de ponto gerado que cubra a data ' . $data . '. Gere o período no RH antes de autorizar HE.'
            );
        }

        $espelhoId = $this->motor->gerarEspelho(
            $colaboradorId,
            (string) $p['data_inicio'],
            (string) $p['data_fim']
        );
        $this->calc->aplicarBritanicoNoEspelho($espelhoId);

        $stmt->execute(['c' => $colaboradorId, 'data' => $data]);
        $criado = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($criado === false) {
            throw new RuntimeException('Não foi possível localizar o dia de ponto após a geração.');
        }
        return (int) $criado['id'];
    }

    /**
     * @return array{ent1:?string,sai1:?string,ent2:?string,sai2:?string}
     */
    private function batidasAtuais(int $pontoDiaId): array
    {
        $stmt = $this->db->prepare('SELECT ent1, sai1, ent2, sai2 FROM ponto_dias WHERE id = :id');
        $stmt->execute(['id' => $pontoDiaId]);
        $dia = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($dia === false) {
            return ['ent1' => null, 'sai1' => null, 'ent2' => null, 'sai2' => null];
        }

        $fmt = static function (?string $v): ?string {
            if ($v === null || $v === '') {
                return null;
            }
            return substr($v, 0, 8);
        };

        $out = [
            'ent1' => $fmt($dia['ent1'] ?? null),
            'sai1' => $fmt($dia['sai1'] ?? null),
            'ent2' => $fmt($dia['ent2'] ?? null),
            'sai2' => $fmt($dia['sai2'] ?? null),
        ];

        if ($out['ent1'] !== null || $out['sai2'] !== null) {
            return $out;
        }

        // Fallback: marcações geradas/previstas
        $marc = $this->db->prepare(
            "SELECT sequencia, horario, origem FROM ponto_marcacoes
             WHERE ponto_dia_id = :d AND origem IN ('gerado','previsto')
             ORDER BY FIELD(origem,'gerado','previsto'), sequencia"
        );
        $marc->execute(['d' => $pontoDiaId]);
        $map = [];
        foreach ($marc->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $seq = (int) $row['sequencia'];
            $slot = $seq >= 5 ? $seq - 4 : $seq;
            if ($slot >= 1 && $slot <= 4 && !isset($map[$slot])) {
                $map[$slot] = substr((string) $row['horario'], 0, 8);
            }
        }

        return [
            'ent1' => $map[1] ?? null,
            'sai1' => $map[2] ?? null,
            'ent2' => $map[3] ?? null,
            'sai2' => $map[4] ?? null,
        ];
    }

    private function aplicarSai2NoDia(int $espelhoId, string $data, string $horaFinal, int $solicitacaoId): void
    {
        $stmt = $this->db->prepare(
            'SELECT id FROM ponto_dias WHERE espelho_id = :e AND data = :d LIMIT 1'
        );
        $stmt->execute(['e' => $espelhoId, 'd' => $data]);
        $diaId = (int) $stmt->fetchColumn();
        if ($diaId <= 0) {
            return;
        }

        $batidas = $this->batidasAtuais($diaId);
        $batidas['sai2'] = $horaFinal;
        $this->calc->salvarBatidas($diaId, $batidas, 'manual', false);

        $this->db->prepare(
            'UPDATE he_solicitacoes SET ponto_dia_id = :dia WHERE id = :id'
        )->execute(['dia' => $diaId, 'id' => $solicitacaoId]);
    }

    private function normHora(string $hora): ?string
    {
        $hora = trim($hora);
        if (preg_match('/^\d{2}:\d{2}$/', $hora)) {
            return $hora . ':00';
        }
        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $hora)) {
            return $hora;
        }
        return null;
    }

    private function diffMinutos(string $ini, string $fim): int
    {
        $a = $this->toMin($ini);
        $b = $this->toMin($fim);
        return $b - $a;
    }

    private function toMin(string $hora): int
    {
        $p = array_map('intval', explode(':', substr($hora, 0, 8)));
        return ($p[0] ?? 0) * 60 + ($p[1] ?? 0);
    }
}
