<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use DateInterval;
use DateTimeImmutable;
use PDO;
use RuntimeException;
use Throwable;

final class MotorPontoService
{
    private PDO $db;
    private LotacaoResolver $resolver;

    /** @var array<int, list<int>> ENT.1 (minutos) já usados na geração corrente */
    private array $ent1Usados = [];

    /** @var array<int, int|null> último ENT.1 por colaborador */
    private array $ent1Anterior = [];

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
        $this->resolver = new LotacaoResolver($this->db);
    }

    public function garantirPeriodo(string $dataInicio, string $dataFim): int
    {
        $this->validarPeriodo($dataInicio, $dataFim);

        $stmt = $this->db->prepare(
            'SELECT id FROM ponto_periodos WHERE data_inicio = :i AND data_fim = :f LIMIT 1'
        );
        $stmt->execute(['i' => $dataInicio, 'f' => $dataFim]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }

        $ins = $this->db->prepare(
            'INSERT INTO ponto_periodos (data_inicio, data_fim, status) VALUES (:i, :f, :status)'
        );
        $ins->execute(['i' => $dataInicio, 'f' => $dataFim, 'status' => 'aberta']);
        return (int) $this->db->lastInsertId();
    }

    public function periodoExiste(string $dataInicio, string $dataFim): bool
    {
        $stmt = $this->db->prepare(
            'SELECT id FROM ponto_periodos WHERE data_inicio = :i AND data_fim = :f LIMIT 1'
        );
        $stmt->execute(['i' => $dataInicio, 'f' => $dataFim]);
        return $stmt->fetchColumn() !== false;
    }

    /** @return array{id:int,data_inicio:string,data_fim:string,status:string,espelhos:int}|null */
    public function buscarPeriodo(string $dataInicio, string $dataFim): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT p.id, p.data_inicio, p.data_fim, p.status,
                    (SELECT COUNT(*) FROM ponto_espelhos e WHERE e.periodo_id = p.id) AS espelhos
             FROM ponto_periodos p
             WHERE p.data_inicio = :i AND p.data_fim = :f
             LIMIT 1'
        );
        $stmt->execute(['i' => $dataInicio, 'f' => $dataFim]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return [
            'id' => (int) $row['id'],
            'data_inicio' => (string) $row['data_inicio'],
            'data_fim' => (string) $row['data_fim'],
            'status' => (string) $row['status'],
            'espelhos' => (int) $row['espelhos'],
        ];
    }

    /**
     * Gera/atualiza espelho do colaborador no período exato.
     */
    public function gerarEspelho(
        int $colaboradorId,
        string $dataInicio,
        string $dataFim,
        ?int $usuarioId = null
    ): int {
        $this->validarPeriodo($dataInicio, $dataFim);
        $periodoId = $this->garantirPeriodo($dataInicio, $dataFim);
        $inicio = new DateTimeImmutable($dataInicio);
        $fim = new DateTimeImmutable($dataFim);

        $this->db->beginTransaction();
        try {
            // Geração não pode travar por data de alocação: usa vigente, senão a mais próxima/padrão.
            $cfgInicio = $this->resolver->resolveParaGeracao($colaboradorId, $inicio);
            if ($cfgInicio === null) {
                throw new RuntimeException('Não há lotação cadastrada no sistema para gerar o espelho.');
            }

            $espelhoId = $this->upsertEspelho($periodoId, $colaboradorId, (int) $cfgInicio['id']);

            // regenera dias se ainda em aberto/tratamento
            $status = $this->espelhoStatus($espelhoId, true);
            if (in_array($status, ['fechado', 'enviado', 'assinado'], true)) {
                throw new RuntimeException('Espelho fechado — reabra antes de regenerar.');
            }

            $this->db->prepare('DELETE FROM ponto_dias WHERE espelho_id = :id')->execute(['id' => $espelhoId]);
            $this->ent1Usados[$colaboradorId] = [];
            $this->ent1Anterior[$colaboradorId] = null;

            for ($dia = $inicio; $dia <= $fim; $dia = $dia->add(new DateInterval('P1D'))) {
                $this->gerarDia($espelhoId, $colaboradorId, $dia);
            }

            $this->recalcularTotais($espelhoId);
            $this->db->commit();

            // Classifica faltas/justificativas mesmo sem horário britânico.
            try {
                (new CalculoPontoService($this->db))->recalcularEspelho($espelhoId);
            } catch (Throwable) {
            }

            // Reaplica HE aprovadas sobre as marcações geradas (também ocorre após o britânico).
            try {
                (new HeAutorizacaoService($this->db))->reaplicarAprovadasNoEspelho($espelhoId);
            } catch (Throwable) {
                // Ignora se a migração de HE ainda não existir.
            }

            // Garante Relatório de Atividades do mesmo período do cartão.
            try {
                (new RelatorioAtividadesService($this->db))->garantirParaEspelho($espelhoId);
            } catch (Throwable) {
                // Ignora se a migração de RA ainda não existir.
            }

            return $espelhoId;
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** @return list<int> */
    public function idsColaboradoresAtivos(): array
    {
        $stmt = $this->db->query("SELECT id FROM rh_colaboradores WHERE status = 'ativo' ORDER BY id");
        return array_map(static fn(array $row): int => (int) $row['id'], $stmt->fetchAll());
    }

    /**
     * Gera espelho ignorando espelho já fechado.
     * Data de alocação não impede geração.
     * @return 'ok'|'skip_fechado'|'skip_lotacao'
     */
    public function gerarEspelhoTolerante(
        int $colaboradorId,
        string $dataInicio,
        string $dataFim,
        ?int $usuarioId = null
    ): string {
        try {
            $this->gerarEspelho($colaboradorId, $dataInicio, $dataFim, $usuarioId);
            return 'ok';
        } catch (RuntimeException $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'fechado')) {
                return 'skip_fechado';
            }
            if (str_contains($msg, 'Não há lotação cadastrada')) {
                return 'skip_lotacao';
            }
            throw $e;
        }
    }

    /** @param (callable(int, int): void)|null $progresso */
    public function gerarPeriodoParaAtivos(
        string $dataInicio,
        string $dataFim,
        ?callable $progresso = null
    ): int {
        $colaboradores = $this->idsColaboradoresAtivos();
        $total = count($colaboradores);
        if ($progresso !== null) {
            $progresso(0, $total);
        }
        $count = 0;
        foreach ($colaboradores as $indice => $colaboradorId) {
            $resultado = $this->gerarEspelhoTolerante($colaboradorId, $dataInicio, $dataFim);
            if ($resultado === 'ok') {
                $count++;
            }
            if ($progresso !== null) {
                $progresso($indice + 1, $total);
            }
        }
        return $count;
    }

    private function validarPeriodo(string $dataInicio, string $dataFim): void
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $dataInicio)
            || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $dataFim)
            || $dataInicio > $dataFim
        ) {
            throw new RuntimeException('Informe datas inicial e final válidas, em ordem.');
        }
        $dias = (int) (new DateTimeImmutable($dataInicio))->diff(new DateTimeImmutable($dataFim))->days + 1;
        if ($dias > PeriodoPonto::MAX_DIAS_PDF) {
            throw new RuntimeException(
                'Selecione um período de no máximo ' . PeriodoPonto::MAX_DIAS_PDF . ' dias para gerar espelhos.'
            );
        }
    }

    private function gerarDia(int $espelhoId, int $colaboradorId, DateTimeImmutable $dia): void
    {
        $data = $dia->format('Y-m-d');
        $cfg = $this->resolver->resolveParaGeracao($colaboradorId, $dia);
        if ($cfg === null) {
            return;
        }

        $feriados = $cfg['feriados'] ?? [];
        $isFeriado = $feriados !== [];
        $trabalhaFeriado = !empty($cfg['trabalha_feriado']);
        $tipoDia = $this->tipoDia($dia, $cfg, $isFeriado);
        // Se trabalha_feriado = 1 (ex.: escala 12x36), feriado não cancela o plantão nem quebra o ciclo.
        $tipoPersistido = ($isFeriado && !$trabalhaFeriado) ? 'feriado' : $tipoDia;
        $dowIso = (int) $dia->format('N'); // 1=seg .. 7=dom
        $feriadoNome = isset($feriados[0]['nome']) ? (string) $feriados[0]['nome'] : '';
        if ($tipoPersistido === 'feriado') {
            $resumo = 'Feriado' . ($feriadoNome !== '' ? ' — ' . $feriadoNome : '');
        } elseif ($tipoPersistido === 'folga_escala') {
            $resumo = 'Folga de escala' . ($isFeriado && $feriadoNome !== '' ? ' (' . $feriadoNome . ')' : '');
        } elseif ($tipoPersistido === 'folga') {
            $resumo = match ($dowIso) {
                6 => 'Folga (sábado)',
                7 => 'Folga (domingo)',
                default => 'Folga',
            };
        } else {
            $resumo = ($isFeriado && $trabalhaFeriado)
                ? ('Plantão em feriado' . ($feriadoNome !== '' ? ' — ' . $feriadoNome : ''))
                : 'Normal';
        }

        $okStatus = match ($tipoPersistido) {
            'feriado' => 'feriado',
            'folga', 'folga_escala' => 'folga',
            default => 'pendente',
        };

        // Folga/feriado não carregam jornada prevista — batidas viram HE (100% só domingo/feriado).
        $temPrevisto = $tipoPersistido === 'trabalho';

        $ins = $this->db->prepare(
            'INSERT INTO ponto_dias
            (espelho_id, data, tipo_dia, previsto_entrada, previsto_intervalo_inicio, previsto_intervalo_fim, previsto_saida, ocorrencia_resumo, ok_status)
            VALUES
            (:espelho_id, :data, :tipo_dia, :pe, :pii, :pif, :ps, :resumo, :ok_status)'
        );
        $ins->execute([
            'espelho_id' => $espelhoId,
            'data' => $data,
            'tipo_dia' => $tipoPersistido,
            'pe' => $temPrevisto ? ($cfg['entrada'] ?? null) : null,
            'pii' => $temPrevisto ? ($cfg['intervalo_inicio'] ?? null) : null,
            'pif' => $temPrevisto ? ($cfg['intervalo_fim'] ?? null) : null,
            'ps' => $temPrevisto ? ($cfg['saida'] ?? null) : null,
            'resumo' => $resumo,
            'ok_status' => $okStatus,
        ]);
        $diaId = (int) $this->db->lastInsertId();

        if ($tipoPersistido === 'trabalho') {
            // Previsto contratual (1-4) + gerado diário editável (5-8)
            $this->inserirMarcacoes($diaId, $this->horariosBase($cfg), 'previsto');
            $app = require dirname(__DIR__, 2) . '/config/app.php';
            if (!empty($app['ponto']['horario_britanico'])) {
                $max = max(1, min(3, (int) ($app['ponto']['variacao_minutos'] ?? 3)));
                $gerados = $this->gerarBatidasDia($cfg, $data, $colaboradorId, $max);
                $this->inserirMarcacoes($diaId, $gerados, 'gerado');
            }
        }
    }

    /**
     * @param array<string,mixed> $cfg
     * @return array{1:?string,2:?string,3:?string,4:?string}
     */
    private function horariosBase(array $cfg): array
    {
        return [
            1 => isset($cfg['entrada']) ? $this->normHora((string) $cfg['entrada']) : null,
            2 => isset($cfg['intervalo_inicio']) ? $this->normHora((string) $cfg['intervalo_inicio']) : null,
            3 => isset($cfg['intervalo_fim']) ? $this->normHora((string) $cfg['intervalo_fim']) : null,
            4 => isset($cfg['saida']) ? $this->normHora((string) $cfg['saida']) : null,
        ];
    }

    /**
     * Gera batidas ±maxMin: ENT.1 nunca repete o dia anterior e evita reuso no mês;
     * duração líquida da jornada é preservada (sem prejuízo).
     *
     * @param array<string,mixed> $cfg
     * @return array{1:?string,2:?string,3:?string,4:?string}
     */
    private function gerarBatidasDia(array $cfg, string $data, int $colaboradorId, int $maxMin = 3): array
    {
        $base = $this->horariosBase($cfg);
        if (!$base[1] || !$base[4]) {
            return $base;
        }

        $e1 = $this->toMin($base[1]);
        $s1 = $base[2] ? $this->toMin($base[2]) : null;
        $e2 = $base[3] ? $this->toMin($base[3]) : null;
        $s2 = $this->toMin($base[4]);

        $dt = new DateTimeImmutable($data);
        $daySeed = ((int) $dt->format('z') * 13) + ($colaboradorId * 7);

        // Deltas possíveis para ENT.1 (−3..+3)
        $deltas = range(-$maxMin, $maxMin);
        $usados = $this->ent1Usados[$colaboradorId] ?? [];
        $anterior = $this->ent1Anterior[$colaboradorId] ?? null;

        $candidatos = [];
        foreach ($deltas as $i => $d) {
            $ent1Min = $e1 + $d;
            // 1) nunca igual ao dia anterior
            if ($anterior !== null && $ent1Min === $anterior) {
                continue;
            }
            // 2) preferir ainda não usados no mês
            $jaUsado = in_array($ent1Min, $usados, true);
            $candidatos[] = [
                'd' => $d,
                'ent1' => $ent1Min,
                'score' => ($jaUsado ? 100 : 0) + (($i + $daySeed) % 17),
            ];
        }
        if ($candidatos === []) {
            // fallback: só evita o anterior
            foreach ($deltas as $d) {
                $ent1Min = $e1 + $d;
                if ($anterior !== null && $ent1Min === $anterior) {
                    continue;
                }
                $candidatos[] = ['d' => $d, 'ent1' => $ent1Min, 'score' => abs($d)];
            }
        }
        usort($candidatos, static fn(array $a, array $b): int => $a['score'] <=> $b['score']);
        $dManha = (int) $candidatos[0]['d'];
        $ent1Escolhido = (int) $candidatos[0]['ent1'];

        // Tarde: offset diferente da manhã, varia com o dia
        $dTarde = $deltas[($daySeed + 3) % count($deltas)];
        if ($dTarde === $dManha) {
            $dTarde = $deltas[($daySeed + 5) % count($deltas)];
        }
        if ($dTarde === $dManha) {
            $dTarde = $dManha >= $maxMin ? $dManha - 1 : $dManha + 1;
        }

        if ($s1 !== null && $e2 !== null) {
            $s1Adj = $s1 < $e1 ? $s1 + 1440 : $s1;
            $e2Adj = $e2 < $s1Adj ? $e2 + 1440 : $e2;
            $s2Adj = $s2 < $e2Adj ? $s2 + 1440 : $s2;
            $durManha = max(0, $s1Adj - $e1);
            $durTarde = max(0, $s2Adj - $e2Adj);
            $e1n = $ent1Escolhido;
            $s1n = $e1n + $durManha;
            $e2n = ($e2 < $s1Adj ? $e2 + 1440 : $e2) + $dTarde;
            if ($e2n <= $s1n) {
                $e2n = $s1n + 1;
            }
            $s2n = $e2n + $durTarde;
        } else {
            $s2Adj = $s2 < $e1 ? $s2 + 1440 : $s2;
            $dur = max(0, $s2Adj - $e1);
            $e1n = $ent1Escolhido;
            $s1n = null;
            $e2n = null;
            $s2n = $e1n + $dur;
        }

        $wrap = static function (int $m): int {
            return (($m % 1440) + 1440) % 1440;
        };

        $this->ent1Usados[$colaboradorId][] = $ent1Escolhido;
        $this->ent1Anterior[$colaboradorId] = $ent1Escolhido;

        return [
            1 => $this->fromMin($wrap($e1n)),
            2 => $s1n !== null ? $this->fromMin($wrap($s1n)) : null,
            3 => $e2n !== null ? $this->fromMin($wrap($e2n)) : null,
            4 => $this->fromMin($wrap($s2n)),
        ];
    }

    /**
     * @param array{1:?string,2:?string,3:?string,4:?string} $marcacoes
     */
    private function inserirMarcacoes(int $diaId, array $marcacoes, string $origem): void
    {
        $offset = $origem === 'gerado' ? 4 : 0;
        $stmt = $this->db->prepare(
            'INSERT INTO ponto_marcacoes (ponto_dia_id, sequencia, horario, origem)
             VALUES (:dia, :seq, :horario, :origem)'
        );
        foreach ($marcacoes as $seq => $hora) {
            if (!$hora) {
                continue;
            }
            $stmt->execute([
                'dia' => $diaId,
                'seq' => (int) $seq + $offset,
                'horario' => $this->normHora($hora),
                'origem' => $origem,
            ]);
        }
    }

    private function normHora(string $hora): string
    {
        $hora = trim($hora);
        if (preg_match('/^\d{2}:\d{2}$/', $hora)) {
            return $hora . ':00';
        }
        return substr($hora, 0, 8);
    }

    private function toMin(string $hora): int
    {
        $p = explode(':', $hora);
        return ((int) ($p[0] ?? 0)) * 60 + ((int) ($p[1] ?? 0));
    }

    private function fromMin(int $min): string
    {
        return sprintf('%02d:%02d:00', intdiv($min, 60), $min % 60);
    }

    /**
     * @param array<string,mixed> $cfg
     */
    private function tipoDia(DateTimeImmutable $dia, array $cfg, bool $isFeriado): string
    {
        if ($isFeriado && empty($cfg['trabalha_feriado'])) {
            return 'feriado';
        }

        $tipoEscala = (string) ($cfg['escala_tipo'] ?? 'seg_sex');
        $dow = (int) $dia->format('N'); // 1=seg .. 7=dom

        return match ($tipoEscala) {
            'seg_sex', '5x2' => ($dow <= 5) ? 'trabalho' : 'folga',
            'seg_sab', '6x1' => ($dow <= 6) ? 'trabalho' : 'folga',
            '12x36' => $this->tipoCiclo12x36($dia, $cfg),
            'ciclo' => $this->tipoCicloCustom($dia, $cfg),
            default => ($dow <= 5) ? 'trabalho' : 'folga',
        };
    }

    /**
     * @param array<string,mixed> $cfg
     */
    private function tipoCiclo12x36(DateTimeImmutable $dia, array $cfg): string
    {
        $ancora = new DateTimeImmutable((string) ($cfg['escala_ancora_data'] ?? $cfg['vigencia_inicio'] ?? $dia->format('Y-m-d')));
        $diff = (int) $ancora->diff($dia)->format('%r%a');
        $idx = (($diff % 2) + 2) % 2;
        // 12x36: 1 trabalho, 1 folga
        return ($idx === 0) ? 'trabalho' : 'folga_escala';
    }

    /**
     * @param array<string,mixed> $cfg
     */
    private function tipoCicloCustom(DateTimeImmutable $dia, array $cfg): string
    {
        $escalaId = (int) ($cfg['escala_id'] ?? 0);
        $stmt = $this->db->prepare('SELECT dia_ciclo, tipo_dia FROM rh_escala_ciclos WHERE escala_id = :id ORDER BY ordem, dia_ciclo');
        $stmt->execute(['id' => $escalaId]);
        $ciclos = $stmt->fetchAll();
        if ($ciclos === []) {
            return 'trabalho';
        }
        $ancora = new DateTimeImmutable((string) ($cfg['escala_ancora_data'] ?? $cfg['vigencia_inicio'] ?? $dia->format('Y-m-d')));
        $diff = (int) $ancora->diff($dia)->format('%r%a');
        $n = count($ciclos);
        $idx = (($diff % $n) + $n) % $n;
        $tipo = (string) $ciclos[$idx]['tipo_dia'];
        return $tipo === 'folga' ? 'folga_escala' : 'trabalho';
    }

    private function upsertEspelho(int $periodoId, int $colaboradorId, int $lotacaoId): int
    {
        $stmt = $this->db->prepare(
            'SELECT id FROM ponto_espelhos WHERE periodo_id = :p AND colaborador_id = :col LIMIT 1'
        );
        $stmt->execute(['p' => $periodoId, 'col' => $colaboradorId]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            $upd = $this->db->prepare('UPDATE ponto_espelhos SET lotacao_id = :l WHERE id = :id');
            $upd->execute(['l' => $lotacaoId, 'id' => (int) $id]);
            return (int) $id;
        }

        $ins = $this->db->prepare(
            'INSERT INTO ponto_espelhos (periodo_id, colaborador_id, lotacao_id, status)
             VALUES (:p, :col, :l, :status)'
        );
        $ins->execute([
            'p' => $periodoId,
            'col' => $colaboradorId,
            'l' => $lotacaoId,
            'status' => 'em_aberto',
        ]);
        return (int) $this->db->lastInsertId();
    }

    private function espelhoStatus(int $espelhoId, bool $bloquear = false): string
    {
        $stmt = $this->db->prepare('SELECT status FROM ponto_espelhos WHERE id = :id' . ($bloquear ? ' FOR UPDATE' : ''));
        $stmt->execute(['id' => $espelhoId]);
        return (string) $stmt->fetchColumn();
    }

    public function recalcularTotais(int $espelhoId): void
    {
        (new CalculoPontoService($this->db))->atualizarTotaisEspelho($espelhoId);
    }

    public function registrarOcorrencia(
        int $pontoDiaId,
        string $tipo,
        int $minutos,
        ?string $observacao,
        ?int $usuarioId
    ): void {
        $tipos = ['falta','falta_justificada','atestado','hora_extra','atraso','saida_antecipada','adicional_noturno','trabalho_feriado','folga','outros'];
        if (!in_array($tipo, $tipos, true)) {
            throw new RuntimeException('Tipo de ocorrência inválido.');
        }

        $dia = $this->db->prepare('SELECT espelho_id FROM ponto_dias WHERE id = :id');
        $dia->execute(['id' => $pontoDiaId]);
        $espelhoId = $dia->fetchColumn();
        if ($espelhoId === false) {
            throw new RuntimeException('Dia não encontrado.');
        }

        $status = $this->espelhoStatus((int) $espelhoId);
        if (in_array($status, ['fechado', 'enviado', 'assinado'], true)) {
            throw new RuntimeException('Espelho fechado.');
        }

        $ins = $this->db->prepare(
            'INSERT INTO ponto_ocorrencias (ponto_dia_id, tipo, minutos, observacao, usuario_id)
             VALUES (:dia, :tipo, :minutos, :obs, :usuario)'
        );
        $ins->execute([
            'dia' => $pontoDiaId,
            'tipo' => $tipo,
            'minutos' => $minutos,
            'obs' => $observacao,
            'usuario' => $usuarioId,
        ]);

        $label = match ($tipo) {
            'falta' => 'Falta',
            'falta_justificada' => 'Falta justificada',
            'atestado' => 'Atestado',
            'hora_extra' => '+HE',
            'atraso' => 'Atraso',
            default => ucfirst(str_replace('_', ' ', $tipo)),
        };
        $this->db->prepare('UPDATE ponto_dias SET ocorrencia_resumo = :r WHERE id = :id')
            ->execute(['r' => $label, 'id' => $pontoDiaId]);

        if ($status === 'em_aberto') {
            $this->db->prepare("UPDATE ponto_espelhos SET status = 'em_tratamento' WHERE id = :id")
                ->execute(['id' => (int) $espelhoId]);
        }

        $this->recalcularTotais((int) $espelhoId);
    }

    public function alterarStatus(int $espelhoId, string $novoStatus, ?string $justificativa, ?int $usuarioId): void
    {
        $transacaoPropria = !$this->db->inTransaction();
        if ($transacaoPropria) {
            $this->db->beginTransaction();
        }
        try {
            $this->aplicarStatus($espelhoId, $novoStatus, $justificativa, $usuarioId);
            if ($transacaoPropria) {
                $this->db->commit();
            }
        } catch (\Throwable $erro) {
            if ($transacaoPropria && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $erro;
        }
    }

    private function aplicarStatus(int $espelhoId, string $novoStatus, ?string $justificativa, ?int $usuarioId): void
    {
        $permitidos = ['em_aberto','em_tratamento','conferido','fechado','enviado'];
        if (!in_array($novoStatus, $permitidos, true)) {
            throw new RuntimeException('Status inválido.');
        }

        $stmt = $this->db->prepare('SELECT status, versao FROM ponto_espelhos WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $espelhoId]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new RuntimeException('Espelho não encontrado.');
        }
        $atual = (string) $row['status'];
        $fechados = ['fechado', 'enviado', 'assinado'];
        $reabrindo = in_array($atual, $fechados, true) && !in_array($novoStatus, $fechados, true);

        if ($reabrindo && ($justificativa === null || trim($justificativa) === '')) {
            throw new RuntimeException('Reabertura exige justificativa.');
        }

        $novaVersao = (int) $row['versao'];
        if ($reabrindo) {
            $novaVersao++;
            // Invalida PDFs anteriores (gerado/enviado/assinado)
            $this->db->prepare(
                "UPDATE documentos d
                 LEFT JOIN documentos_espelhos v ON v.documento_id = d.id AND v.espelho_id = ?
                 SET d.status = 'invalidado'
                 WHERE (d.espelho_id = ? OR v.espelho_id IS NOT NULL) AND d.status IN ('gerado','enviado','assinado')"
            )->execute([$espelhoId, $espelhoId]);
        }

        // Evita mix de collation (utf8mb4_general_ci vs unicode_ci) ao comparar
        // parâmetro PDO com literal string no MySQL da Hostinger.
        $fechar = $novoStatus === 'fechado' ? 1 : 0;
        $upd = $this->db->prepare(
            'UPDATE ponto_espelhos
             SET status = :status,
                 versao = :versao,
                 fechado_em = CASE WHEN :fechar = 1 THEN NOW() ELSE fechado_em END,
                 fechado_por = CASE WHEN :fechar2 = 1 THEN :usuario ELSE fechado_por END
             WHERE id = :id'
        );
        $upd->execute([
            'status' => $novoStatus,
            'versao' => $novaVersao,
            'fechar' => $fechar,
            'fechar2' => $fechar,
            'usuario' => $usuarioId,
            'id' => $espelhoId,
        ]);

        $log = $this->db->prepare(
            'INSERT INTO ponto_fechamentos (espelho_id, status_anterior, status_novo, justificativa, usuario_id)
             VALUES (:e, :a, :n, :j, :u)'
        );
        $log->execute([
            'e' => $espelhoId,
            'a' => $atual,
            'n' => $novoStatus,
            'j' => $justificativa,
            'u' => $usuarioId,
        ]);

        if ($reabrindo) {
            $this->db->prepare(
                'INSERT INTO ponto_ajustes (espelho_id, motivo, usuario_id) VALUES (:e, :m, :u)'
            )->execute([
                'e' => $espelhoId,
                'm' => 'Reabertura: ' . $justificativa,
                'u' => $usuarioId,
            ]);
        }
    }

    /**
     * Registra marcação real (relógio/manual/importado) — nunca confunde com previsto/gerado.
     */
    public function apontar(int $pontoDiaId, int $sequencia, string $horario, string $origem = 'manual', ?int $usuarioId = null): void
    {
        if (!in_array($origem, ['relogio', 'manual', 'importado'], true)) {
            throw new RuntimeException('Origem de apontamento inválida.');
        }
        $dia = $this->db->prepare(
            'SELECT d.espelho_id, e.status FROM ponto_dias d
             INNER JOIN ponto_espelhos e ON e.id = d.espelho_id WHERE d.id = :id'
        );
        $dia->execute(['id' => $pontoDiaId]);
        $row = $dia->fetch();
        if ($row === false) {
            throw new RuntimeException('Dia não encontrado.');
        }
        if (in_array($row['status'], ['fechado', 'enviado', 'assinado'], true)) {
            throw new RuntimeException('Espelho fechado — reabra para apontar.');
        }

        // sequencias reais usam 11-14 para não colidir com previsto(1-4)/gerado(5-8)
        $seq = 10 + max(1, min(4, $sequencia));
        $this->db->prepare('DELETE FROM ponto_marcacoes WHERE ponto_dia_id = :d AND sequencia = :s AND origem IN (\'relogio\',\'manual\',\'importado\')')
            ->execute(['d' => $pontoDiaId, 's' => $seq]);
        $this->db->prepare(
            'INSERT INTO ponto_marcacoes (ponto_dia_id, sequencia, horario, origem) VALUES (:d,:s,:h,:o)'
        )->execute(['d' => $pontoDiaId, 's' => $seq, 'h' => $horario, 'o' => $origem]);

        $this->db->prepare(
            'INSERT INTO rh_auditoria (entidade, entidade_id, acao, depois_json, usuario_id)
             VALUES (\'ponto_dias\', :id, \'apontamento\', :j, :u)'
        )->execute([
            'id' => $pontoDiaId,
            'j' => json_encode(['horario' => $horario, 'origem' => $origem, 'sequencia' => $sequencia], JSON_UNESCAPED_UNICODE),
            'u' => $usuarioId,
        ]);
    }
}
