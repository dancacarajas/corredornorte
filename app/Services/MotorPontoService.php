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
     * Apaga definitivamente o período e tudo que foi gerado nele (sem soft-delete).
     *
     * @return array{periodo_id:int,espelhos:int,documentos:int,ra:int,anexos:int}
     */
    public function excluirPeriodoDefinitivo(string $dataInicio, string $dataFim): array
    {
        $this->validarPeriodo($dataInicio, $dataFim);
        $periodo = $this->buscarPeriodo($dataInicio, $dataFim);
        if ($periodo === null) {
            throw new RuntimeException('Período não encontrado.');
        }
        $periodoId = (int) $periodo['id'];

        $espelhoIds = $this->db->query(
            'SELECT id FROM ponto_espelhos WHERE periodo_id = ' . $periodoId
        )->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $espelhoIds = array_values(array_map('intval', $espelhoIds));
        $inEspelhos = $espelhoIds !== [] ? implode(',', $espelhoIds) : '0';

        $arquivos = [];
        $anexosCount = 0;

        if ($espelhoIds !== [] && $this->tableExists('ponto_justificativas')) {
            $justRows = $this->db->query(
                "SELECT anexo_path FROM ponto_justificativas
                 WHERE espelho_id IN ({$inEspelhos})
                   AND anexo_path IS NOT NULL AND TRIM(anexo_path) <> ''"
            )->fetchAll(PDO::FETCH_COLUMN) ?: [];
            foreach ($justRows as $rel) {
                $arquivos[] = (string) $rel;
                $anexosCount++;
            }
        }

        $docIds = [];
        if ($espelhoIds !== [] && $this->tableExists('documentos')) {
            $docIds = $this->db->query(
                "SELECT DISTINCT id FROM documentos WHERE espelho_id IN ({$inEspelhos})"
            )->fetchAll(PDO::FETCH_COLUMN) ?: [];
            if ($this->tableExists('documentos_espelhos')) {
                $extra = $this->db->query(
                    "SELECT DISTINCT documento_id FROM documentos_espelhos WHERE espelho_id IN ({$inEspelhos})"
                )->fetchAll(PDO::FETCH_COLUMN) ?: [];
                $docIds = array_values(array_unique(array_merge(
                    array_map('intval', $docIds),
                    array_map('intval', $extra)
                )));
            } else {
                $docIds = array_values(array_map('intval', $docIds));
            }
        }
        $inDocs = $docIds !== [] ? implode(',', $docIds) : '0';

        if ($docIds !== []) {
            $paths = $this->db->query(
                "SELECT * FROM documentos WHERE id IN ({$inDocs})"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($paths as $p) {
                if (!empty($p['arquivo_path'])) {
                    $arquivos[] = (string) $p['arquivo_path'];
                }
                if (!empty($p['arquivo_assinado_path'])) {
                    $arquivos[] = (string) $p['arquivo_assinado_path'];
                }
            }
            if ($this->tableExists('documentos_versoes')) {
                $verPaths = $this->db->query(
                    "SELECT arquivo_path FROM documentos_versoes WHERE documento_id IN ({$inDocs})"
                )->fetchAll(PDO::FETCH_COLUMN) ?: [];
                foreach ($verPaths as $rel) {
                    $arquivos[] = (string) $rel;
                }
            }
        }

        $raIds = [];
        if ($this->tableExists('ra_relatorios')) {
            $raIds = array_values(array_map(
                'intval',
                $this->db->query(
                    'SELECT id FROM ra_relatorios WHERE periodo_id = ' . $periodoId
                )->fetchAll(PDO::FETCH_COLUMN) ?: []
            ));
        }
        $inRa = $raIds !== [] ? implode(',', $raIds) : '0';
        if ($raIds !== [] && $this->tableExists('ra_documentos')) {
            $raPaths = $this->db->query(
                "SELECT * FROM ra_documentos WHERE relatorio_id IN ({$inRa})"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($raPaths as $p) {
                if (!empty($p['arquivo_path'])) {
                    $arquivos[] = (string) $p['arquivo_path'];
                }
                if (!empty($p['arquivo_assinado_path'])) {
                    $arquivos[] = (string) $p['arquivo_assinado_path'];
                }
            }
        }

        $this->db->beginTransaction();
        try {
            if ($this->tableExists('alerta_eventos')) {
                if ($espelhoIds !== []) {
                    $this->db->exec("DELETE FROM alerta_eventos WHERE espelho_id IN ({$inEspelhos})");
                }
                if ($docIds !== []) {
                    $this->db->exec("DELETE FROM alerta_eventos WHERE documento_id IN ({$inDocs})");
                }
                $this->db->prepare(
                    'DELETE FROM alerta_eventos WHERE periodo_inicio = :i AND periodo_fim = :f'
                )->execute(['i' => $dataInicio, 'f' => $dataFim]);
            }

            if ($this->tableExists('cobranca_envios')) {
                $this->db->exec('DELETE FROM cobranca_envios WHERE periodo_id = ' . $periodoId);
                if ($docIds !== []) {
                    $this->db->exec("DELETE FROM cobranca_envios WHERE documento_id IN ({$inDocs})");
                }
            }

            if ($docIds !== []) {
                // Quebra auto-referência antes do DELETE.
                $this->db->exec("UPDATE documentos SET substituido_por_id = NULL WHERE id IN ({$inDocs})");
                if ($this->tableExists('emails_envios')) {
                    $this->db->exec("DELETE FROM emails_envios WHERE documento_id IN ({$inDocs})");
                }
                if ($this->tableExists('documentos_versoes')) {
                    $this->db->exec("DELETE FROM documentos_versoes WHERE documento_id IN ({$inDocs})");
                }
                if ($this->tableExists('documentos_espelhos')) {
                    $this->db->exec("DELETE FROM documentos_espelhos WHERE documento_id IN ({$inDocs})");
                    if ($espelhoIds !== []) {
                        $this->db->exec("DELETE FROM documentos_espelhos WHERE espelho_id IN ({$inEspelhos})");
                    }
                }
                if ($this->tableExists('ponto_divergencias')) {
                    $this->db->exec("UPDATE ponto_divergencias SET documento_id = NULL WHERE documento_id IN ({$inDocs})");
                }
                $this->db->exec("DELETE FROM documentos WHERE id IN ({$inDocs})");
            }

            if ($raIds !== []) {
                if ($this->tableExists('ra_documentos')) {
                    $this->db->exec("DELETE FROM ra_documentos WHERE relatorio_id IN ({$inRa})");
                }
                if ($this->tableExists('ra_dias')) {
                    $this->db->exec("DELETE FROM ra_dias WHERE relatorio_id IN ({$inRa})");
                }
                $this->db->exec("DELETE FROM ra_relatorios WHERE id IN ({$inRa})");
            } elseif ($this->tableExists('ra_relatorios')) {
                $this->db->exec('DELETE FROM ra_relatorios WHERE periodo_id = ' . $periodoId);
            }

            if ($espelhoIds !== []) {
                // CASCADE cobre dias, marcações, ocorrências, justificativas, ajustes, fechamentos, divergências.
                $this->db->exec("DELETE FROM ponto_espelhos WHERE id IN ({$inEspelhos})");
            }
            $this->db->exec('DELETE FROM ponto_periodos WHERE id = ' . $periodoId);

            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        $root = dirname(__DIR__, 2);
        foreach (array_unique($arquivos) as $rel) {
            $rel = str_replace('\\', '/', (string) $rel);
            if ($rel === '' || str_contains($rel, '..')) {
                continue;
            }
            $abs = str_starts_with($rel, $root)
                ? $rel
                : $root . '/' . ltrim($rel, '/');
            if (is_file($abs)) {
                @unlink($abs);
            }
        }

        return [
            'periodo_id' => $periodoId,
            'espelhos' => count($espelhoIds),
            'documentos' => count($docIds),
            'ra' => count($raIds),
            'anexos' => $anexosCount,
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
        // DDL (CREATE/ALTER) no MySQL encerra a TX — rodar ANTES do beginTransaction.
        // LotacaoResolver → HorarioService::aplicarDiaNoCfg chama ensureSchema por dia.
        (new HorarioService($this->db))->ensureSchema();
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
            if (in_array($status, ['conferido', 'fechado', 'enviado', 'assinado', 'em_divergencia'], true)) {
                throw new RuntimeException('Espelho fechado/conferido — reabra antes de regenerar.');
            }

            $this->db->prepare('DELETE FROM ponto_dias WHERE espelho_id = :id')->execute(['id' => $espelhoId]);
            $this->ent1Usados[$colaboradorId] = [];
            $this->ent1Anterior[$colaboradorId] = null;

            for ($dia = $inicio; $dia <= $fim; $dia = $dia->add(new DateInterval('P1D'))) {
                $this->gerarDia($espelhoId, $colaboradorId, $dia);
            }

            $this->recalcularTotais($espelhoId);
            if ($this->db->inTransaction()) {
                $this->db->commit();
            }
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        // Pós-commit: não pode cair no rollBack da geração.
        try {
            (new CalculoPontoService($this->db))->aplicarBritanicoNoEspelho($espelhoId);
        } catch (Throwable) {
        }

        try {
            // Sem e-mail aqui: SMTP/OAuth na geração congela o lote em 0%.
            (new RelatorioAtividadesService($this->db))->garantirParaEspelho($espelhoId, false);
        } catch (Throwable) {
            // Ignora se a migração de RA ainda não existir.
        }

        return $espelhoId;
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
            $durIntervaloBase = max(0, $e2Adj - $s1Adj);
            $e1n = $ent1Escolhido;
            $s1n = $e1n + $durManha;
            $e2n = ($e2 < $s1Adj ? $e2 + 1440 : $e2) + $dTarde;
            // Garante que a variação aleatória nunca reduza o intervalo abaixo do previsto (ex.: 60 min CLT Art. 71)
            $minE2n = $s1n + max(1, $durIntervaloBase);
            if ($e2n < $minE2n) {
                $e2n = $minE2n;
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

        // Horário RHiD (semanal/cíclico): tipo do dia já resolvido no LotacaoResolver.
        if (isset($cfg['horario_dia_tipo']) && $cfg['horario_dia_tipo'] !== '') {
            $td = (string) $cfg['horario_dia_tipo'];
            if ($td === 'folga') {
                $horarioTipo = (string) ($cfg['horario_tipo'] ?? 'semanal');
                return $horarioTipo === 'ciclico' ? 'folga_escala' : 'folga';
            }
            return 'trabalho';
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

        $dia = $this->db->prepare('SELECT espelho_id, data, tipo_dia, horas_previstas_min FROM ponto_dias WHERE id = :id');
        $dia->execute(['id' => $pontoDiaId]);
        $diaRow = $dia->fetch();
        if ($diaRow === false) {
            throw new RuntimeException('Dia não encontrado.');
        }
        $espelhoId = (int) $diaRow['espelho_id'];

        $status = $this->espelhoStatus($espelhoId);
        if (in_array($status, ['fechado', 'enviado', 'assinado'], true)) {
            throw new RuntimeException('Espelho fechado.');
        }

        $min = max(0, $minutos);
        $ins = $this->db->prepare(
            'INSERT INTO ponto_ocorrencias (ponto_dia_id, tipo, minutos, observacao, usuario_id)
             VALUES (:dia, :tipo, :minutos, :obs, :usuario)'
        );
        $ins->execute([
            'dia' => $pontoDiaId,
            'tipo' => $tipo,
            'minutos' => $min,
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

        $dow = !empty($diaRow['data']) ? (int) date('w', strtotime((string) $diaRow['data'])) : -1;
        $isHe100 = ($dow === 0 || (string) ($diaRow['tipo_dia'] ?? '') === 'feriado');

        switch ($tipo) {
            case 'falta':
                $minFalta = $min > 0 ? $min : (int) ($diaRow['horas_previstas_min'] ?? 0);
                $this->db->prepare(
                    "UPDATE ponto_dias
                     SET dia_falta = 1,
                         horas_falta_min = COALESCE(horas_falta_min, 0) + :m1,
                         desconto_min = COALESCE(desconto_min, 0) + :m2,
                         ok_status = 'falta',
                         ocorrencia_resumo = :r
                     WHERE id = :id"
                )->execute(['m1' => $minFalta, 'm2' => $minFalta, 'r' => $label, 'id' => $pontoDiaId]);
                break;

            case 'falta_justificada':
            case 'atestado':
                $this->db->prepare(
                    "UPDATE ponto_dias
                     SET dia_falta = 0,
                         horas_falta_min = 0,
                         atraso_bruto_min = 0,
                         desconto_min = 0,
                         ok_status = 'ok',
                         ocorrencia_resumo = :r
                     WHERE id = :id"
                )->execute(['r' => $label, 'id' => $pontoDiaId]);
                break;

            case 'hora_extra':
                $colHe = $isHe100 ? 'he_100_min' : 'he_50_min';
                $this->db->prepare(
                    "UPDATE ponto_dias
                     SET horas_extras_min = COALESCE(horas_extras_min, 0) + :m1,
                         {$colHe} = COALESCE({$colHe}, 0) + :m2,
                         ocorrencia_resumo = :r
                     WHERE id = :id"
                )->execute(['m1' => $min, 'm2' => $min, 'r' => $label, 'id' => $pontoDiaId]);
                break;

            case 'trabalho_feriado':
                $this->db->prepare(
                    'UPDATE ponto_dias
                     SET horas_extras_min = COALESCE(horas_extras_min, 0) + :m1,
                         he_100_min = COALESCE(he_100_min, 0) + :m2,
                         ocorrencia_resumo = :r
                     WHERE id = :id'
                )->execute(['m1' => $min, 'm2' => $min, 'r' => $label, 'id' => $pontoDiaId]);
                break;

            case 'atraso':
                $this->db->prepare(
                    "UPDATE ponto_dias
                     SET atraso_bruto_min = COALESCE(atraso_bruto_min, 0) + :m1,
                         desconto_min = COALESCE(desconto_min, 0) + :m2,
                         ok_status = CASE WHEN :m3 > 5 THEN 'pendente' ELSE ok_status END,
                         ocorrencia_resumo = :r
                     WHERE id = :id"
                )->execute(['m1' => $min, 'm2' => $min, 'm3' => $min, 'r' => $label, 'id' => $pontoDiaId]);
                break;

            case 'saida_antecipada':
                $this->db->prepare(
                    "UPDATE ponto_dias
                     SET horas_falta_min = COALESCE(horas_falta_min, 0) + :m1,
                         desconto_min = COALESCE(desconto_min, 0) + :m2,
                         ok_status = CASE WHEN :m3 > 5 THEN 'pendente' ELSE ok_status END,
                         ocorrencia_resumo = :r
                     WHERE id = :id"
                )->execute(['m1' => $min, 'm2' => $min, 'm3' => $min, 'r' => $label, 'id' => $pontoDiaId]);
                break;

            case 'adicional_noturno':
                $this->db->prepare(
                    'UPDATE ponto_dias
                     SET adn_min = COALESCE(adn_min, 0) + :m,
                         ocorrencia_resumo = :r
                     WHERE id = :id'
                )->execute(['m' => $min, 'r' => $label, 'id' => $pontoDiaId]);
                break;

            case 'folga':
                $this->db->prepare(
                    "UPDATE ponto_dias
                     SET dia_falta = 0,
                         horas_falta_min = 0,
                         desconto_min = 0,
                         ok_status = 'folga',
                         ocorrencia_resumo = :r
                     WHERE id = :id"
                )->execute(['r' => $label, 'id' => $pontoDiaId]);
                break;

            default:
                $this->db->prepare('UPDATE ponto_dias SET ocorrencia_resumo = :r WHERE id = :id')
                    ->execute(['r' => $label, 'id' => $pontoDiaId]);
                break;
        }

        if ($status === 'em_aberto') {
            $this->db->prepare("UPDATE ponto_espelhos SET status = 'em_tratamento' WHERE id = :id")
                ->execute(['id' => $espelhoId]);
        }

        $this->recalcularTotais($espelhoId);
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

    private function aplicarStatus(int $espelhoId, string $novoStatus, ?string $justificativa = null, ?int $usuarioId = null): void
    {
        $permitidos = ['em_aberto','em_tratamento','conferido','fechado','enviado'];
        if (!in_array($novoStatus, $permitidos, true)) {
            throw new RuntimeException('Status inválido.');
        }

        if (in_array($novoStatus, ['conferido', 'fechado'], true)) {
            $justColCond = $this->hasColumn('ponto_dias', 'justificativa_tipo')
                ? "AND (d.justificativa_tipo IS NULL OR d.justificativa_tipo = '')"
                : '';
            $justTableCond = $this->tableExists('ponto_justificativas')
                ? 'AND NOT EXISTS (
                       SELECT 1 FROM ponto_justificativas j
                       WHERE j.espelho_id = d.espelho_id
                         AND d.data BETWEEN j.data_inicio AND j.data_fim
                   )'
                : '';
            $stPend = $this->db->prepare(
                "SELECT COUNT(*)
                 FROM ponto_dias d
                 WHERE d.espelho_id = :id
                   AND d.tipo_dia = 'trabalho'
                   AND COALESCE(d.dia_falta, 0) = 0
                   {$justColCond}
                   {$justTableCond}
                   AND (
                       d.ent1 IS NULL
                       OR (d.sai1 IS NULL AND (d.ent2 IS NOT NULL OR d.sai2 IS NULL))
                       OR (d.sai1 IS NOT NULL AND (
                           (d.ent2 IS NOT NULL AND d.sai2 IS NULL)
                           OR (d.ent2 IS NULL AND d.sai2 IS NOT NULL)
                       ))
                   )"
            );
            $stPend->execute(['id' => $espelhoId]);
            if ((int) $stPend->fetchColumn() > 0) {
                throw new \InvalidArgumentException(
                    'Este cartão possui dias úteis sem batidas preenchidas e sem justificativa. Preencha os previstos ou justifique os dias pendentes antes de conferir/fechar.'
                );
            }

            (new PontoHeAutorizacaoService($this->db))->assertEspelhoPodeConferir($espelhoId);
        }

        $stmt = $this->db->prepare('SELECT status, versao FROM ponto_espelhos WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $espelhoId]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new RuntimeException('Espelho não encontrado.');
        }
        $atual = (string) $row['status'];
        $fechados = ['fechado', 'enviado', 'assinado', 'em_divergencia'];
        $reabrindo = in_array($atual, $fechados, true) && !in_array($novoStatus, $fechados, true);

        if ($reabrindo && $atual !== 'em_divergencia' && ($justificativa === null || trim($justificativa) === '')) {
            throw new RuntimeException('Reabertura exige justificativa.');
        }

        $novaVersao = (int) $row['versao'];
        if ($reabrindo || in_array($novoStatus, ['em_aberto', 'em_tratamento', 'conferido', 'fechado'], true) || $atual === 'em_divergencia') {
            if ($reabrindo) {
                $novaVersao++;
            }
            $this->invalidarDocumentosDoEspelho($espelhoId);
        }

        $divIdsParaResolver = [];
        if (($atual === 'em_divergencia' || in_array($novoStatus, ['conferido', 'fechado'], true)) && $this->tableExists('ponto_divergencias')) {
            $stDiv = $this->db->prepare(
                "SELECT id FROM ponto_divergencias WHERE espelho_id = :eid AND status IN ('aberta', 'em_analise')"
            );
            $stDiv->execute(['eid' => $espelhoId]);
            $divIdsParaResolver = array_map('intval', $stDiv->fetchAll(PDO::FETCH_COLUMN) ?: []);
            if ($divIdsParaResolver !== []) {
                $obsDiv = ($justificativa !== null && trim($justificativa) !== '')
                    ? trim($justificativa)
                    : 'Resolvida automaticamente na conferência/tratamento do ponto.';
                $this->db->prepare(
                    "UPDATE ponto_divergencias
                     SET status = 'resolvida', resolucao_obs = :obs, resolvido_por = :u, resolvido_em = NOW()
                     WHERE espelho_id = :eid AND status IN ('aberta', 'em_analise')"
                )->execute([
                    'obs' => mb_substr($obsDiv, 0, 1000),
                    'u' => $usuarioId,
                    'eid' => $espelhoId,
                ]);
            }
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
            $motivoReabertura = ($justificativa !== null && trim($justificativa) !== '')
                ? trim($justificativa)
                : 'Ajuste de divergência';
            $this->db->prepare(
                'INSERT INTO ponto_ajustes (espelho_id, motivo, usuario_id) VALUES (:e, :m, :u)'
            )->execute([
                'e' => $espelhoId,
                'm' => 'Reabertura: ' . $motivoReabertura,
                'u' => $usuarioId,
            ]);
        }

        foreach ($divIdsParaResolver as $divId) {
            AlertasService::resolverEvento('divergencia:' . $divId);
        }
    }

    private function invalidarDocumentosDoEspelho(int $espelhoId): void
    {
        $this->db->prepare(
            "UPDATE documentos d
             LEFT JOIN documentos_espelhos v ON v.documento_id = d.id AND v.espelho_id = ?
             SET d.status = 'invalidado'
             WHERE (d.espelho_id = ? OR v.espelho_id IS NOT NULL) AND d.status IN ('gerado','enviado','assinado')"
        )->execute([$espelhoId, $espelhoId]);
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
        try {
            $st = $this->db->prepare(
                'SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c LIMIT 1'
            );
            $st->execute(['t' => $table, 'c' => $column]);
            return (bool) $st->fetchColumn();
        } catch (Throwable) {
            return false;
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
        $slot = max(1, min(4, $sequencia));
        $seq = 10 + $slot;
        $horaNorm = trim($horario);
        $horaNorm = ($horaNorm === '' || $horaNorm === '--:--') ? null : $this->normHora($horaNorm);

        $this->db->prepare('DELETE FROM ponto_marcacoes WHERE ponto_dia_id = :d AND sequencia = :s')
            ->execute(['d' => $pontoDiaId, 's' => $seq]);
        if ($horaNorm !== null) {
            $this->db->prepare(
                'INSERT INTO ponto_marcacoes (ponto_dia_id, sequencia, horario, origem) VALUES (:d,:s,:h,:o)'
            )->execute(['d' => $pontoDiaId, 's' => $seq, 'h' => $horaNorm, 'o' => $origem]);
        }

        $colMap = [1 => 'ent1', 2 => 'sai1', 3 => 'ent2', 4 => 'sai2'];
        $col = $colMap[$slot];
        $this->db->prepare("UPDATE ponto_dias SET {$col} = :h WHERE id = :id")
            ->execute(['h' => $horaNorm, 'id' => $pontoDiaId]);

        $calculo = new CalculoPontoService($this->db);
        $calculo->recalcularDia($pontoDiaId);
        $espelhoId = (int) $row['espelho_id'];
        $calculo->atualizarTotaisEspelho($espelhoId);
        if ((string) $row['status'] === 'em_aberto') {
            $this->db->prepare("UPDATE ponto_espelhos SET status = 'em_tratamento' WHERE id = :id AND status = 'em_aberto'")
                ->execute(['id' => $espelhoId]);
        }

        $this->db->prepare(
            'INSERT INTO rh_auditoria (entidade, entidade_id, acao, depois_json, usuario_id)
             VALUES (\'ponto_dias\', :id, \'apontamento\', :j, :u)'
        )->execute([
            'id' => $pontoDiaId,
            'j' => json_encode(['horario' => $horaNorm, 'origem' => $origem, 'sequencia' => $sequencia], JSON_UNESCAPED_UNICODE),
            'u' => $usuarioId,
        ]);
    }
}
