<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use DateTimeImmutable;
use PDO;
use Throwable;

/**
 * Motor de Conformidade Trabalhista Brasileira (CLT + CF/88 + Súmulas/OJs TST + Lei 11.788/2008 + Portaria MTP 671/2021).
 *
 * Avalia um dia em edição (pré-salvamento) ou o espelho inteiro, considerando a lotação/horário/escala do colaborador,
 * justificativas, admissão/demissão e dias vizinhos (interjornada, DSR 35h, 7 dias consecutivos, domingos e ponto britânico).
 */
final class ConformidadeCltService
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    /**
     * Garante tabela leve de auditoria para exceções CLT confirmadas conscientemente pelo RH.
     */
    public static function ensureSchema(PDO $pdo): void
    {
        try {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS ponto_clt_excecoes (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    espelho_id INT UNSIGNED NOT NULL,
                    ponto_dia_id INT UNSIGNED NOT NULL,
                    data_referencia DATE NOT NULL,
                    codigos_json TEXT NOT NULL,
                    justificativa TEXT NOT NULL,
                    usuario_id INT UNSIGNED NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_clt_exc_espelho (espelho_id),
                    INDEX idx_clt_exc_dia (ponto_dia_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        } catch (Throwable) {
            // Compatibilidade com SQLite em testes isolados
            try {
                $pdo->exec(
                    "CREATE TABLE IF NOT EXISTS ponto_clt_excecoes (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        espelho_id INTEGER NOT NULL,
                        ponto_dia_id INTEGER NOT NULL,
                        data_referencia TEXT NOT NULL,
                        codigos_json TEXT NOT NULL,
                        justificativa TEXT NOT NULL,
                        usuario_id INTEGER NULL,
                        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
                    )"
                );
            } catch (Throwable) {
            }
        }
    }

    /**
     * Registra na trilha de auditoria que o usuário do RH confirmou um lançamento com alerta CLT mediante justificativa.
     *
     * @param list<string> $codigos
     */
    public function registrarExcecaoAuditada(
        int $espelhoId,
        int $pontoDiaId,
        string $dataReferencia,
        array $codigos,
        string $justificativa,
        ?int $usuarioId
    ): void {
        $just = trim($justificativa);
        if ($just === '') {
            return;
        }
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO ponto_clt_excecoes (espelho_id, ponto_dia_id, data_referencia, codigos_json, justificativa, usuario_id)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $espelhoId,
                $pontoDiaId,
                $dataReferencia,
                json_encode(array_values(array_unique($codigos)), JSON_UNESCAPED_UNICODE),
                $just,
                $usuarioId,
            ]);
        } catch (Throwable) {
        }
    }

    /**
     * Avalia o espelho inteiro (para badges por dia e resumo no topo da tela de tratamento do ponto).
     *
     * @param list<array<string,mixed>> $dias
     * @param list<array<string,mixed>> $justificativas
     * @param array<string,mixed> $espelho
     * @return array{
     *   por_dia: array<int, list<array<string,mixed>>>,
     *   resumo: array{total: int, bloqueios: int, alertas: int, dias_afetados: int, contexto_escala: string}
     * }
     */
    public function avaliarEspelho(array $espelho, array $dias, array $justificativas = []): array
    {
        $colab = $this->carregarColaborador((int) ($espelho['colaborador_id'] ?? 0));
        $lotacao = $this->resolverContextoLotacao(
            (int) ($espelho['colaborador_id'] ?? 0),
            (string) (($dias[0]['data'] ?? null) ?: date('Y-m-d'))
        );
        $justPorData = $this->indexarJustificativasPorData($justificativas);
        $turnoAuthPorData = [];
        try {
            $turnoAuthPorData = (new PontoTurnoAutorizacaoService($this->db))
                ->mapPorDataDoEspelho((int) ($espelho['id'] ?? 0));
        } catch (Throwable) {
            $turnoAuthPorData = [];
        }

        $porDia = [];
        $bloqueios = 0;
        $alertas = 0;

        $ctxEscala = $this->descreverEscala($lotacao);
        $ordered = $this->ordenarDias($dias);
        $totalDias = count($ordered);

        // Pré-calcula sequência de dias trabalhados, domingos consecutivos e ponto britânico
        $seqTrab = [];
        $runTrab = 0;
        $domingosSeguidos = [];
        $runDom = 0;
        $britanicoStreak = [];
        $lastSig = null;
        $runBrit = 0;

        for ($i = 0; $i < $totalDias; $i++) {
            $d = $ordered[$i];
            $worked = $this->diaTemTrabalho($d);
            if ($worked) {
                $runTrab++;
            } else {
                $runTrab = 0;
            }
            $seqTrab[$i] = $runTrab;

            $dow = (int) date('w', strtotime((string) $d['data']));
            if ($dow === 0) {
                if ($worked) {
                    $runDom++;
                } else {
                    $runDom = 0;
                }
            }
            $domingosSeguidos[$i] = $dow === 0 && $worked ? $runDom : 0;

            $sig = $this->assinaturaHorarios($d);
            if ($sig !== null) {
                if ($sig === $lastSig) {
                    $runBrit++;
                } else {
                    $lastSig = $sig;
                    $runBrit = 1;
                }
            } else {
                $lastSig = null;
                $runBrit = 0;
            }
            $britanicoStreak[$i] = $runBrit;
        }

        // Soma semanal (ISO week) para CLT-12, CLT-16, CLT-18
        $semanasMin = [];
        $semanasHeMin = [];
        foreach ($ordered as $d) {
            $wk = date('o-W', strtotime((string) $d['data']));
            $m = $this->apurarDiaSimples($d);
            $semanasMin[$wk] = ($semanasMin[$wk] ?? 0) + $m['trab'];
            $heDia = max(0, $m['trab'] - (int) ($d['horas_previstas_min'] ?? 0));
            $semanasHeMin[$wk] = ($semanasHeMin[$wk] ?? 0) + $heDia;
        }

        for ($i = 0; $i < $totalDias; $i++) {
            $d = $ordered[$i];
            $diaId = (int) ($d['id'] ?? 0);
            $prev = $i > 0 ? $ordered[$i - 1] : null;
            $prev2 = $i > 1 ? $ordered[$i - 2] : null;
            $next = $i + 1 < $totalDias ? $ordered[$i + 1] : null;
            $next2 = $i + 2 < $totalDias ? $ordered[$i + 2] : null;

            $wk = date('o-W', strtotime((string) $d['data']));
            $lotacaoDia = $lotacao;
            $turnoDia = $turnoAuthPorData[(string) $d['data']] ?? null;
            if (is_array($turnoDia)) {
                $lotacaoDia['turno_autorizado'] = $turnoDia;
                if ((int) ($turnoDia['adicional_noturno'] ?? 0) === 1) {
                    $lotacaoDia['adicional_noturno'] = 1;
                }
            }
            $violacoes = $this->avaliarDiaCompleto(
                $d,
                $prev,
                $prev2,
                $next,
                $next2,
                $colab,
                $lotacaoDia,
                $justPorData[(string) $d['data']] ?? [],
                $seqTrab[$i],
                $domingosSeguidos[$i],
                $britanicoStreak[$i],
                $semanasMin[$wk] ?? 0,
                $semanasHeMin[$wk] ?? 0
            );

            if ($violacoes !== []) {
                $porDia[$diaId] = $violacoes;
                foreach ($violacoes as $v) {
                    if ($v['severidade'] === 'bloqueio') {
                        $bloqueios++;
                    } else {
                        $alertas++;
                    }
                }
            }
        }

        return [
            'por_dia' => $porDia,
            'resumo' => [
                'total' => $bloqueios + $alertas,
                'bloqueios' => $bloqueios,
                'alertas' => $alertas,
                'dias_afetados' => count($porDia),
                'contexto_escala' => $ctxEscala,
            ],
        ];
    }

    /**
     * Avalia o salvamento proposto de um dia específico (com as novas batidas ainda antes de commitar).
     *
     * @param array{ent1:?string,sai1:?string,ent2:?string,sai2:?string} $batidasPropostas
     * @return array{
     *   has_violations: bool,
     *   has_bloqueios: bool,
     *   violacoes: list<array<string,mixed>>,
     *   data: string,
     *   contexto_escala: string
     * }
     */
    public function avaliarAlteracaoDia(int $espelhoId, int $pontoDiaId, array $batidasPropostas): array
    {
        $espelhoStmt = $this->db->prepare('SELECT * FROM ponto_espelhos WHERE id = ? LIMIT 1');
        $espelhoStmt->execute([$espelhoId]);
        $espelho = $espelhoStmt->fetch(PDO::FETCH_ASSOC);
        if ($espelho === false) {
            return ['has_violations' => false, 'has_bloqueios' => false, 'violacoes' => [], 'data' => '', 'contexto_escala' => ''];
        }

        $diasStmt = $this->db->prepare('SELECT * FROM ponto_dias WHERE espelho_id = ? ORDER BY data ASC');
        $diasStmt->execute([$espelhoId]);
        $dias = $diasStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $targetData = '';
        foreach ($dias as &$d) {
            if ((int) $d['id'] === $pontoDiaId) {
                $d['ent1'] = $batidasPropostas['ent1'] ?? null;
                $d['sai1'] = $batidasPropostas['sai1'] ?? null;
                $d['ent2'] = $batidasPropostas['ent2'] ?? null;
                $d['sai2'] = $batidasPropostas['sai2'] ?? null;
                $targetData = (string) $d['data'];
                break;
            }
        }
        unset($d);

        if ($targetData === '') {
            return ['has_violations' => false, 'has_bloqueios' => false, 'violacoes' => [], 'data' => '', 'contexto_escala' => ''];
        }

        // Busca justificativas do colaborador para o período
        $justificativas = [];
        try {
            $jStmt = $this->db->prepare(
                'SELECT j.*, t.nome AS tipo_nome, t.abona_falta
                 FROM ponto_justificativas j
                 INNER JOIN ponto_justificativa_tipos t ON t.id = j.tipo_id
                 WHERE j.espelho_id = ?'
            );
            $jStmt->execute([$espelhoId]);
            $justificativas = $jStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
        }

        $avaliacao = $this->avaliarEspelho($espelho, $dias, $justificativas);
        $violacoes = $avaliacao['por_dia'][$pontoDiaId] ?? [];

        // Também verifica se a alteração no dia atual causou violação de interjornada no dia SEGUINTE
        $nextDiaId = null;
        $nextDiaData = null;
        foreach ($dias as $idx => $row) {
            if ((int) $row['id'] === $pontoDiaId && isset($dias[$idx + 1])) {
                $nextDiaId = (int) $dias[$idx + 1]['id'];
                $nextDiaData = (string) $dias[$idx + 1]['data'];
                break;
            }
        }
        if ($nextDiaId !== null && !empty($avaliacao['por_dia'][$nextDiaId])) {
            foreach ($avaliacao['por_dia'][$nextDiaId] as $vNext) {
                if (in_array($vNext['codigo'], ['CLT-01', 'CLT-02', 'CLT-03'], true)) {
                    $vNext['titulo'] .= ' (impacto no dia ' . date('d/m', strtotime((string) $nextDiaData)) . ')';
                    $violacoes[] = $vNext;
                }
            }
        }

        $hasBloqueios = false;
        foreach ($violacoes as $v) {
            if (($v['severidade'] ?? '') === 'bloqueio') {
                $hasBloqueios = true;
                break;
            }
        }

        return [
            'has_violations' => $violacoes !== [],
            'has_bloqueios' => $hasBloqueios,
            'violacoes' => $violacoes,
            'data' => $targetData,
            'contexto_escala' => $avaliacao['resumo']['contexto_escala'],
        ];
    }

    /**
     * Avalia todas as 22 regras CLT aplicáveis a um dia específico no contexto da lotação e dias vizinhos.
     *
     * @param array<string,mixed> $dia
     * @param array<string,mixed>|null $prev
     * @param array<string,mixed>|null $prev2
     * @param array<string,mixed>|null $next
     * @param array<string,mixed>|null $next2
     * @param array<string,mixed> $colab
     * @param array<string,mixed> $lotacao
     * @param list<array<string,mixed>> $justDia
     * @return list<array<string,mixed>>
     */
    private function avaliarDiaCompleto(
        array $dia,
        ?array $prev,
        ?array $prev2,
        ?array $next,
        ?array $next2,
        array $colab,
        array $lotacao,
        array $justDia,
        int $diasConsecutivos,
        int $domingosConsecutivos,
        int $britanicoStreak,
        int $semanaTrabMin,
        int $semanaHeMin
    ): array {
        $violacoes = [];
        $data = (string) ($dia['data'] ?? '');
        $dataBr = $data !== '' ? date('d/m/Y', strtotime($data)) : '';
        $e1 = $this->normHm($dia['ent1'] ?? null);
        $s1 = $this->normHm($dia['sai1'] ?? null);
        $e2 = $this->normHm($dia['ent2'] ?? null);
        $s2 = $this->normHm($dia['sai2'] ?? null);

        $batidasPreenchidas = array_values(array_filter([$e1, $s1, $e2, $s2], static fn($v) => $v !== null));
        $temBatida = $batidasPreenchidas !== [];

        // Perfil do colaborador e da lotação/escala
        $escalaNome = strtolower(trim((string) ($lotacao['horario_nome'] ?? $lotacao['escala_nome'] ?? $lotacao['nome'] ?? '')));
        $tipoHorario = strtolower(trim((string) ($lotacao['horario_tipo'] ?? '')));
        $is12x36 = str_contains($escalaNome, '12x36') || str_contains($escalaNome, '12 x 36')
            || ((int) ($dia['horas_previstas_min'] ?? 0) >= 660 && $tipoHorario === 'ciclico');
        $isRevezamento = str_contains($escalaNome, 'revezamento') || str_contains($escalaNome, 'turno');
        $trabalhaFeriado = (int) ($lotacao['trabalha_feriado'] ?? 0) === 1;
        $permiteAdn = (int) ($lotacao['adicional_noturno'] ?? 0) === 1;
        $intervaloPrevMin = $this->duracaoIntervaloPrevisto($lotacao);
        $minIntraLegal = ($intervaloPrevMin >= 30 && $intervaloPrevMin < 60) ? $intervaloPrevMin : 60;
        $maxIntraLegal = max(120, $intervaloPrevMin);

        $cargoTexto = strtolower(trim((string) (($colab['cargo'] ?? '') . ' ' . ($colab['funcao'] ?? '') . ' ' . ($colab['tipo_contrato'] ?? ''))));
        $isEstagiario = str_contains($cargoTexto, 'estagi');
        $isAprendiz = str_contains($cargoTexto, 'aprendiz');
        $isMenor18 = $this->ehMenorDe18($colab['data_nascimento'] ?? null, $data);
        $isInsalubre = !empty($colab['insalubridade']) || str_contains(strtolower((string) ($lotacao['nome'] ?? '')), 'insalubr');
        $isTempoParcial30 = str_contains($cargoTexto, 'parcial 30') || str_contains($cargoTexto, '30h');
        $isTempoParcial26 = str_contains($cargoTexto, 'parcial 26') || str_contains($cargoTexto, '26h');
        $sexo = strtoupper(trim((string) ($colab['sexo'] ?? '')));
        $isMulher = $sexo === 'F' || str_starts_with($sexo, 'FEM');

        // ============================================================
        // CLT-21: Inconsistência cronológica, batida ímpar ou horários iguais
        // ============================================================
        if ($temBatida) {
            $qtd = count($batidasPreenchidas);
            if ($qtd === 1 || $qtd === 3) {
                $violacoes[] = $this->mk(
                    'CLT-21',
                    'bloqueio',
                    'Batida ímpar / incompleta no dia ' . $dataBr,
                    'Portaria MTP nº 671/2021 · CLT Art. 74',
                    "Foram informadas {$qtd} marcação(ões) neste dia. Uma jornada válida precisa ter pares completos (Entrada e Saída).",
                    'Preencha a marcação faltante ou remova o horário incompleto antes de continuar.'
                );
            }
            if ($e1 !== null && $s1 !== null && $e1 === $s1) {
                $violacoes[] = $this->mk(
                    'CLT-21',
                    'bloqueio',
                    'Entrada 1 e Saída 1 idênticas (' . $e1 . ')',
                    'Portaria MTP nº 671/2021 · CLT Art. 74',
                    "Os horários de Entrada 1 e Saída 1 são iguais ({$e1}), gerando período zerado e inconsistência no registro de ponto.",
                    'Corrija o horário de entrada ou saída do 1º período.'
                );
            }
            if ($e2 !== null && $s2 !== null && $e2 === $s2) {
                $violacoes[] = $this->mk(
                    'CLT-21',
                    'bloqueio',
                    'Entrada 2 e Saída 2 idênticas (' . $e2 . ')',
                    'Portaria MTP nº 671/2021 · CLT Art. 74',
                    "Os horários de Entrada 2 e Saída 2 são iguais ({$e2}), gerando período zerado e inconsistência no registro de ponto.",
                    'Corrija o horário de retorno ou saída do 2º período.'
                );
            }
            if ($e1 !== null && $s1 !== null && $e2 !== null && $s2 !== null) {
                $mE1 = $this->toMin($e1);
                $mS1 = $this->toMin($s1);
                if ($mS1 < $mE1) {
                    $mS1 += 1440;
                }
                $mE2 = $this->toMin($e2);
                if ($mE2 < $mE1) {
                    $mE2 += 1440;
                }
                if ($mE2 < $mS1) {
                    $violacoes[] = $this->mk(
                        'CLT-21',
                        'bloqueio',
                        'Sobreposição de horários (Entrada 2 anterior à Saída 1)',
                        'Portaria MTP nº 671/2021 · CLT Art. 74',
                        "A Entrada 2 ({$e2}) ocorre antes da Saída 1 ({$s1}), indicando erro de digitação ou sobreposição de turnos.",
                        'Ajuste os horários em ordem cronológica crescente.'
                    );
                }
            }
        }

        // ============================================================
        // CLT-19: Batida em dia de Atestado, Férias, Licença, Afastamento ou fora de Admissão/Demissão
        // ============================================================
        if ($temBatida) {
            foreach ($justDia as $j) {
                $tNome = (string) ($j['tipo_nome'] ?? 'Justificativa');
                $tLow = strtolower($tNome);
                if (
                    str_contains($tLow, 'atestado')
                    || str_contains($tLow, 'férias')
                    || str_contains($tLow, 'ferias')
                    || str_contains($tLow, 'licença')
                    || str_contains($tLow, 'licenca')
                    || str_contains($tLow, 'afastamento')
                    || str_contains($tLow, 'inss')
                    || str_contains($tLow, 'maternidade')
                    || str_contains($tLow, 'paternidade')
                    || (int) ($j['abona_falta'] ?? 0) === 1
                ) {
                    $violacoes[] = $this->mk(
                        'CLT-19',
                        'bloqueio',
                        'Trabalho lançado durante ' . $tNome,
                        'CLT Arts. 129, 130 e 476 · Lei 8.213/1991',
                        "Existe registro de '{$tNome}' para o dia {$dataBr}. Lançar batidas trabalhadas durante atestado médico, férias ou afastamento é ilegalidade trabalhista grave (risco de dobra de férias, nulidade do afastamento e dano moral).",
                        'Remova as batidas deste dia ou regularize/exclua o afastamento se o colaborador realmente trabalhou.'
                    );
                    break;
                }
            }

            $adm = (string) ($colab['data_admissao'] ?? '');
            $dem = (string) ($colab['data_demissao'] ?? '');
            if ($adm !== '' && $data < $adm) {
                $violacoes[] = $this->mk(
                    'CLT-19',
                    'bloqueio',
                    'Batida anterior à data de admissão (' . date('d/m/Y', strtotime($adm)) . ')',
                    'CLT Art. 29 e Art. 41',
                    "Não é permitido lançar jornada trabalhada em {$dataBr}, pois o colaborador só foi admitido em " . date('d/m/Y', strtotime($adm)) . '.',
                    'Remova as batidas anteriores à admissão ou corrija a data de admissão no cadastro.'
                );
            }
            if ($dem !== '' && $data > $dem) {
                $violacoes[] = $this->mk(
                    'CLT-19',
                    'bloqueio',
                    'Batida posterior à data de desligamento (' . date('d/m/Y', strtotime($dem)) . ')',
                    'CLT Art. 477',
                    "Não é permitido lançar jornada trabalhada em {$dataBr}, pois o colaborador foi desligado em " . date('d/m/Y', strtotime($dem)) . '.',
                    'Remova as batidas após a data de demissão.'
                );
            }
        }

        // Apuração de minutos do dia
        $ap = $this->apurarDiaSimples($dia);
        $trabMin = $ap['trab'];
        $adnMin = $ap['adn'];
        $intraMin = $ap['intra'];
        $prevMin = (int) ($dia['horas_previstas_min'] ?? 0);
        $heMin = max(0, $trabMin - $prevMin);

        if (!$temBatida) {
            return $violacoes;
        }

        // ============================================================
        // CLT-01 / CLT-02 / CLT-03: Interjornada (11h / 36h na 12x36 / 35h com DSR)
        // ============================================================
        $entAtualTs = $this->timestampPrimeiraEntrada($dia);
        if ($entAtualTs !== null) {
            if ($prev !== null && $this->diaTemTrabalho($prev)) {
                $saiPrevTs = $this->timestampUltimaSaida($prev);
                if ($saiPrevTs !== null) {
                    $descansoMin = (int) round(($entAtualTs - $saiPrevTs) / 60);
                    $minimoExigido = $is12x36 ? 36 * 60 : 11 * 60;
                    if ($descansoMin < $minimoExigido) {
                        $codigo = $is12x36 ? 'CLT-02' : 'CLT-01';
                        $base = $is12x36
                            ? 'CLT Art. 59-A (Escala 12×36 — descanso mínimo de 36h)'
                            : 'CLT Art. 66 · OJ 355 SDI-1/TST · Súmula 110 TST';
                        $descansoFmt = $descansoMin >= 0 ? $this->fmtMin($descansoMin) : '-' . $this->fmtMin(abs($descansoMin));
                        $faltamMin = max(0, $minimoExigido - max(0, $descansoMin));
                        $prevBr = date('d/m', strtotime((string) $prev['data']));
                        $prevSaiHm = date('H:i', $saiPrevTs);
                        $entHm = date('H:i', $entAtualTs);
                        $violacoes[] = $this->mk(
                            $codigo,
                            'bloqueio',
                            'Descanso interjornada insuficiente (' . $descansoFmt . ' de ' . $this->fmtMin($minimoExigido) . ')',
                            $base,
                            "O colaborador encerrou a jornada anterior em {$prevBr} às {$prevSaiHm} e está iniciando em {$dataBr} às {$entHm}, somando apenas {$descansoFmt} de descanso. Para a lotação/escala atual, a lei exige no mínimo " . $this->fmtMin($minimoExigido) . " consecutivas (faltam " . $this->fmtMin($faltamMin) . ").",
                            $is12x36
                                ? 'Na escala 12×36, o descumprimento das 36h de descanso descaracteriza toda a escala. Ajuste o horário de entrada ou a saída anterior.'
                                : 'Pela OJ 355 do TST, as ' . $this->fmtMin($faltamMin) . ' suprimidas devem ser pagas como horas extras (+50% no mínimo), além de risco de autuação do MTE.'
                        );
                    }
                }
            } elseif ($prev !== null && !$this->diaTemTrabalho($prev) && $prev2 !== null && $this->diaTemTrabalho($prev2)) {
                // Dia anterior foi folga (DSR): exige 35h consecutivas (24h DSR + 11h Interjornada)
                $saiPrev2Ts = $this->timestampUltimaSaida($prev2);
                if ($saiPrev2Ts !== null) {
                    $descansoDsrMin = (int) round(($entAtualTs - $saiPrev2Ts) / 60);
                    $minimoDsr = $is12x36 ? 36 * 60 : 35 * 60;
                    if ($descansoDsrMin < $minimoDsr) {
                        $prev2Br = date('d/m', strtotime((string) $prev2['data']));
                        $prev2SaiHm = date('H:i', $saiPrev2Ts);
                        $entHm = date('H:i', $entAtualTs);
                        $violacoes[] = $this->mk(
                            'CLT-03',
                            'bloqueio',
                            'Descanso Interjornada + DSR abaixo de ' . $this->fmtMin($minimoDsr) . ' (' . $this->fmtMin(max(0, $descansoDsrMin)) . ')',
                            'CLT Arts. 66 e 67 · Súmula 110 TST',
                            "Entre a saída antes da folga ({$prev2Br} às {$prev2SaiHm}) e o retorno em {$dataBr} às {$entHm} houve apenas " . $this->fmtMin(max(0, $descansoDsrMin)) . " de descanso. O repouso semanal (24h) soma-se à interjornada (11h), exigindo mínimo de 35h00 consecutivas.",
                            'Ajuste o horário de retorno após a folga para respeitar as 35h consecutivas.'
                        );
                    }
                }
            }
        }

        // ============================================================
        // CLT-04: Proibição de 7 dias consecutivos sem folga (DSR)
        // ============================================================
        if ($diasConsecutivos >= 7) {
            $violacoes[] = $this->mk(
                'CLT-04',
                'bloqueio',
                "{$diasConsecutivos}º dia consecutivo trabalhado sem folga (DSR)",
                'CLT Art. 67 · Lei nº 605/1949 · OJ 410 SDI-1/TST',
                "O colaborador acumula {$diasConsecutivos} dias seguidos de trabalho até {$dataBr} sem nenhum descanso semanal remunerado (DSR). A concessão do repouso após o 6º dia consecutivo viola a OJ 410 do TST.",
                'Conceda folga antes de completar 7 dias seguidos. Caso já tenha ocorrido, o 7º dia é devido em dobro (100%).'
            );
        }

        // ============================================================
        // CLT-05: Trabalho em domingos consecutivos sem revezamento
        // ============================================================
        if ($domingosConsecutivos >= 2 && !$is12x36) {
            if ($isMulher && $domingosConsecutivos >= 2) {
                $violacoes[] = $this->mk(
                    'CLT-05',
                    'alerta',
                    "{$domingosConsecutivos} domingos consecutivos trabalhados (revezamento quinzenal)",
                    'CLT Art. 386 · CF Art. 7º, XV · Jurisprudência TST / Tema 1.046 STF',
                    "A colaboradora possui {$domingosConsecutivos} domingos seguidos com trabalho até {$dataBr}. O Art. 386 da CLT determina escala quinzenal favorecendo folga dominical (salvo regra diversa em ACT/CCT válida).",
                    'Confirme se há folga dominical ou norma coletiva específica autorizando esta escala na lotação.'
                );
            } elseif ($domingosConsecutivos >= 3) {
                $violacoes[] = $this->mk(
                    'CLT-05',
                    'alerta',
                    "{$domingosConsecutivos} domingos consecutivos trabalhados sem folga dominical",
                    'CF Art. 7º, XV · CLT Art. 67 · Lei 10.101/2000',
                    "O colaborador está trabalhando há {$domingosConsecutivos} domingos consecutivos até {$dataBr}. O repouso semanal deve coincidir periodicamente com o domingo conforme a convenção coletiva da categoria.",
                    'Verifique a escala de revezamento de domingos desta lotação.'
                );
            }
        }

        // ============================================================
        // CLT-06: Trabalho em feriado em lotação com trabalha_feriado = 0
        // ============================================================
        if (($dia['tipo_dia'] ?? '') === 'feriado' && !$trabalhaFeriado && !$is12x36) {
            $violacoes[] = $this->mk(
                'CLT-06',
                'alerta',
                'Trabalho lançado em feriado sem escala de plantão configurada',
                'CLT Art. 70 · Lei 605/1949 · Súmula 146 TST',
                "O dia {$dataBr} é feriado e a lotação do colaborador está configurada como 'Não trabalha em feriado'. O labor em feriado exige folga compensatória ou pagamento com adicional de 100%.",
                'Confirme a autorização formal para trabalho em feriado (HE 100% ou compensação).'
            );
        }

        // ============================================================
        // CLT-07 / CLT-08 / CLT-09: Intervalo Intrajornada (Almoço/Refeição)
        // Aplica tolerância legal de 5 minutos (CLT Art. 58, § 1º / jurisprudência TST):
        // 55 a 59 min em intervalo de 1h não configura supressão ilegal.
        // ============================================================
        if ($trabMin > 6 * 60) {
            $pisoComTolerancia = max(0, $minIntraLegal - 5);
            if ($intraMin === null || $intraMin < $pisoComTolerancia) {
                $intraTxt = $intraMin === null ? 'nenhum intervalo registrado' : ('apenas ' . $this->fmtMin($intraMin));
                $violacoes[] = $this->mk(
                    'CLT-07',
                    'bloqueio',
                    'Intervalo intrajornada insuficiente (' . ($intraMin === null ? '00:00' : $this->fmtMin($intraMin)) . ' / mín. ' . $this->fmtMin($minIntraLegal) . ')',
                    'CLT Art. 71, caput e § 4º · Art. 58, § 1º (tolerância 5 min)',
                    "A jornada do dia {$dataBr} soma " . $this->fmtMin($trabMin) . " (> 6h00), porém há {$intraTxt}. Para jornadas acima de 6 horas é obrigatório intervalo mínimo de " . $this->fmtMin($minIntraLegal) . " (tolerância máxima de 5 min).",
                    'Registre a pausa de refeição correta (Saída 1 e Entrada 2). A supressão gera indenização de +50% sobre o tempo não gozado.'
                );
            } elseif ($intraMin > $maxIntraLegal + 5) {
                $violacoes[] = $this->mk(
                    'CLT-08',
                    'alerta',
                    'Intervalo intrajornada acima de ' . $this->fmtMin($maxIntraLegal) . ' (' . $this->fmtMin($intraMin) . ')',
                    'CLT Art. 71, caput',
                    "O intervalo registrado em {$dataBr} é de " . $this->fmtMin($intraMin) . ", excedendo o teto legal de 2h00 (ou o intervalo pactuado na lotação). Sem acordo escrito ou CCT, o excesso pode ser considerado tempo à disposição.",
                    'Verifique se houve erro de digitação entre Saída 1 e Entrada 2 ou se há acordo escrito.'
                );
            }
        } elseif ($trabMin > 4 * 60 && $trabMin <= 6 * 60) {
            if ($intraMin !== null && $intraMin < 15) {
                $violacoes[] = $this->mk(
                    'CLT-09',
                    'alerta',
                    'Intervalo menor que 15 minutos em jornada de 4h a 6h',
                    'CLT Art. 71, § 1º',
                    "A jornada de {$dataBr} soma " . $this->fmtMin($trabMin) . " (entre 4h e 6h), exigindo pausa mínima obrigatória de 15 minutos (registrado: " . $this->fmtMin($intraMin) . ").",
                    'Ajuste a pausa para no mínimo 15 minutos.'
                );
            }
        }

        // ============================================================
        // CLT-10 / CLT-11: Teto Diário de HE (2h / 10h totais) e Escala 12x36
        // ============================================================
        if ($is12x36) {
            if ($trabMin > 12 * 60 + 10) {
                $violacoes[] = $this->mk(
                    'CLT-11',
                    'bloqueio',
                    'Prorrogação acima de 12h na escala 12×36 (' . $this->fmtMin($trabMin) . ')',
                    'CLT Art. 59-A · Jurisprudência TST',
                    "Na escala 12×36, o teto de plantão é de 12h00. O lançamento de " . $this->fmtMin($trabMin) . " em {$dataBr} caracteriza hora extra sobre 12×36, o que pode descaracterizar todo o regime e gerar passivo a partir da 8ª hora.",
                    'Corrija os horários para respeitar o plantão de 12h ou registre justificativa formal de força maior (Art. 61).'
                );
            }
        } else {
            if ($trabMin > 10 * 60) {
                $violacoes[] = $this->mk(
                    'CLT-10',
                    'bloqueio',
                    'Jornada diária excede o limite legal de 10h00 (' . $this->fmtMin($trabMin) . ')',
                    'CLT Art. 58 e Art. 59, caput',
                    "A CLT limita a jornada comum a 8h normais + no máximo 2h extras (teto absoluto de 10h00/dia). O lançamento de {$dataBr} totaliza " . $this->fmtMin($trabMin) . ".",
                    'Reduza a sobrejornada para até 10h00 totais ou documente justificativa de força maior/serviço inadiável (CLT Art. 61).'
                );
            } elseif ($heMin > 2 * 60) {
                $violacoes[] = $this->mk(
                    'CLT-10',
                    'bloqueio',
                    'Horas extras excedem o limite legal de 02:00/dia (' . $this->fmtMin($heMin) . ')',
                    'CLT Art. 59, caput',
                    "O dia {$dataBr} acumula " . $this->fmtMin($heMin) . " de horas extras sobre a jornada prevista (" . $this->fmtMin($prevMin) . "). A CLT permite no máximo 2 horas extras por dia.",
                    'Revise as batidas ou formalize justificativa de necessidade imperiosa (CLT Art. 61).'
                );
            }
        }

        // ============================================================
        // CLT-12: Teto Semanal (44h normais / 56h com HE)
        // ============================================================
        if (!$is12x36 && $semanaTrabMin > 56 * 60) {
            $violacoes[] = $this->mk(
                'CLT-12',
                'alerta',
                'Carga horária semanal excede 56h00 (' . $this->fmtMin($semanaTrabMin) . ' na semana)',
                'CF/88 Art. 7º, XIII · CLT Arts. 58 e 59',
                "Na semana do dia {$dataBr}, o colaborador acumula " . $this->fmtMin($semanaTrabMin) . " trabalhadas (acima de 44h normais + 12h extras semanais).",
                'Acompanhe o saldo de horas extras e evite sobrecarga semanal acima dos limites da CLT.'
            );
        }

        // ============================================================
        // CLT-13: Turno Ininterrupto de Revezamento (6h / 8h)
        // ============================================================
        if ($isRevezamento && $trabMin > 8 * 60) {
            $violacoes[] = $this->mk(
                'CLT-13',
                'bloqueio',
                'Turno de revezamento acima de 8h00 (' . $this->fmtMin($trabMin) . ')',
                'CF/88 Art. 7º, XIV · Súmula 423 TST',
                "Em turno ininterrupto de revezamento, a jornada constitucional é de 6h (podendo chegar a no máximo 8h apenas por ACT/CCT). O dia {$dataBr} soma " . $this->fmtMin($trabMin) . ".",
                'Respeite o teto máximo de 8h diárias para turno de revezamento.'
            );
        }

        // ============================================================
        // CLT-14: Prorrogação em Atividade Insalubre sem Licença MTE
        // ============================================================
        if ($isInsalubre && !$is12x36 && $heMin > 10) {
            $violacoes[] = $this->mk(
                'CLT-14',
                'alerta',
                'Hora extra em atividade insalubre exige licença prévia do MTE',
                'CLT Art. 60 · Súmula 85, VI TST',
                "Foi lançada hora extra (" . $this->fmtMin($heMin) . ") em {$dataBr} para cadastro/lotação com indicação de insalubridade. Pelo Art. 60 da CLT, prorrogações em atividades insalubres exigem licença prévia do MTE ou previsão expressa (exceto 12×36).",
                'Confirme se há licença ministerial / autorização coletiva vigente antes de manter a prorrogação.'
            );
        }

        // ============================================================
        // CLT-15: Trabalho Noturno (22h-05h) fora de enquadramento da lotação
        // Exige anexo PDF de alteração de turno do cliente quando lotação é diurna.
        // ============================================================
        if ($adnMin > 0 && !$permiteAdn) {
            $violacoes[] = $this->mk(
                'CLT-15',
                'bloqueio',
                'Turno noturno (22h–05h) sem autorização de alteração de turno anexada',
                'CLT Art. 73, §§ 1º, 2º e 5º · Súmula 60, II TST',
                "Foram apurados " . $this->fmtMin($adnMin) . " em período noturno em {$dataBr}, porém a lotação do colaborador é diurna e não há anexo PDF de alteração de turno do cliente (Vale) para este período.",
                'Use o botão "Alterar Turno (PDF)" no topo do cartão para anexar a autorização do cliente e liberar o período noturno mantendo a mesma carga horária.'
            );
        }

        // ============================================================
        // CLT-16: Estagiário (Lei 11.788/2008 — máx. 6h/dia e 30h/semana, sem HE)
        // ============================================================
        if ($isEstagiario) {
            if ($trabMin > 6 * 60 || $semanaTrabMin > 30 * 60) {
                $violacoes[] = $this->mk(
                    'CLT-16',
                    'bloqueio',
                    'Estagiário acima do limite legal (6h/dia ou 30h/semana)',
                    'Lei nº 11.788/2008, Art. 10',
                    "O cadastro indica Estagiário, porém o dia {$dataBr} soma " . $this->fmtMin($trabMin) . " (e " . $this->fmtMin($semanaTrabMin) . " na semana). Exceder 6h/dia ou 30h/semana descaracteriza o estágio e gera vínculo empregatício CLT.",
                    'Ajuste os horários para respeitar rigorosamente o limite de 6h/dia e 30h/semana.'
                );
            }
        }

        // ============================================================
        // CLT-17: Menor Aprendiz / Menor de 18 anos (Sem HE, Sem Noturno)
        // ============================================================
        if ($isAprendiz || $isMenor18) {
            if ($adnMin > 0) {
                $violacoes[] = $this->mk(
                    'CLT-17',
                    'bloqueio',
                    'Proibição absoluta de trabalho noturno para menor de 18 anos / aprendiz',
                    'CF/88 Art. 7º, XXXIII · CLT Art. 404',
                    "É expressamente proibido o trabalho entre 22h00 e 05h00 para menores de 18 anos e aprendizes (apontados " . $this->fmtMin($adnMin) . " noturnos em {$dataBr}).",
                    'Remova imediatamente qualquer marcação em horário noturno.'
                );
            }
            if ($heMin > 5 || $trabMin > 8 * 60) {
                $violacoes[] = $this->mk(
                    'CLT-17',
                    'bloqueio',
                    'Proibição de horas extras para aprendiz / menor de 18 anos',
                    'CLT Arts. 413 e 432 · Lei nº 10.097/2000',
                    "É vedada a prorrogação (horas extras) e a compensação de jornada para aprendizes e menores de 18 anos ({$dataBr}: " . $this->fmtMin($trabMin) . " trabalhadas).",
                    'Ajuste a jornada para respeitar o limite legal do contrato de aprendizagem (6h ou 8h sem HE).'
                );
            }
        }

        // ============================================================
        // CLT-18: Regime de Tempo Parcial (CLT Art. 58-A)
        // ============================================================
        if ($isTempoParcial30 && $semanaHeMin > 10) {
            $violacoes[] = $this->mk(
                'CLT-18',
                'bloqueio',
                'Proibição de hora extra em contrato de Tempo Parcial (30h/semana)',
                'CLT Art. 58-A, caput',
                "Contratos em regime de tempo parcial de até 30h semanais não podem realizar horas suplementares (extras).",
                'Remova as horas extras desta semana.'
            );
        } elseif ($isTempoParcial26 && $semanaHeMin > 6 * 60) {
            $violacoes[] = $this->mk(
                'CLT-18',
                'bloqueio',
                'Limite de 6h extras/semana excedido em Tempo Parcial (26h)',
                'CLT Art. 58-A, § 3º',
                "Contratos de tempo parcial de até 26h semanais permitem no máximo 6h extras por semana (acumulado: " . $this->fmtMin($semanaHeMin) . ").",
                'Ajuste para não exceder 6h suplementares na semana.'
            );
        }

        // ============================================================
        // CLT-20: Ponto Britânico (Súmula 338, III TST)
        // ============================================================
        if ($britanicoStreak >= 5) {
            $violacoes[] = $this->mk(
                'CLT-20',
                'alerta',
                "Alerta de Ponto Britânico ({$britanicoStreak} dias seguidos com horários idênticos)",
                'Súmula 338, III TST · Portaria MTP nº 671/2021',
                "Foram detectados {$britanicoStreak} dias consecutivos até {$dataBr} com exatamente os mesmos minutos de entrada e saída. Cartões de ponto invariáveis (britânicos) são invalidados pela Justiça do Trabalho, invertendo o ônus da prova contra a empresa.",
                'Certifique-se de registrar os horários reais praticados pelo colaborador (com as variações naturais de minutos).'
            );
        }

        return $violacoes;
    }

    /**
     * @return array<string,mixed>
     */
    private function mk(
        string $codigo,
        string $severidade,
        string $titulo,
        string $baseLegal,
        string $explicacao,
        string $acaoRecomendada
    ): array {
        return [
            'codigo' => $codigo,
            'severidade' => $severidade, // 'bloqueio' | 'alerta'
            'titulo' => $titulo,
            'base_legal' => $baseLegal,
            'explicacao' => $explicacao,
            'acao_recomendada' => $acaoRecomendada,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function carregarColaborador(int $colaboradorId): array
    {
        if ($colaboradorId <= 0) {
            return [];
        }
        try {
            $st = $this->db->prepare('SELECT * FROM rh_colaboradores WHERE id = ? LIMIT 1');
            $st->execute([$colaboradorId]);
            return $st->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function resolverContextoLotacao(int $colaboradorId, string $data): array
    {
        if ($colaboradorId <= 0) {
            return [];
        }
        try {
            return (new LotacaoResolver($this->db))->resolveParaGeracao($colaboradorId, $data) ?? [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param array<string,mixed> $lotacao
     */
    private function descreverEscala(array $lotacao): string
    {
        $nome = trim((string) ($lotacao['horario_nome'] ?? $lotacao['escala_nome'] ?? $lotacao['nome'] ?? 'Padrão CLT'));
        $is12x36 = str_contains(strtolower($nome), '12x36') || str_contains(strtolower($nome), '12 x 36');
        $inter = $is12x36 ? '36h interjornada (12×36)' : '11h interjornada · máx. 2h HE/dia';
        return $nome . ' (' . $inter . ')';
    }

    /**
     * @param list<array<string,mixed>> $justificativas
     * @return array<string, list<array<string,mixed>>>
     */
    private function indexarJustificativasPorData(array $justificativas): array
    {
        $map = [];
        foreach ($justificativas as $j) {
            $ini = (string) ($j['data_inicio'] ?? '');
            $fim = (string) ($j['data_fim'] ?? $ini);
            if ($ini === '' || $fim === '') {
                continue;
            }
            try {
                $cur = new DateTimeImmutable($ini);
                $end = new DateTimeImmutable($fim);
                $guard = 0;
                while ($cur <= $end && $guard < 62) {
                    $map[$cur->format('Y-m-d')][] = $j;
                    $cur = $cur->modify('+1 day');
                    $guard++;
                }
            } catch (Throwable) {
            }
        }
        return $map;
    }

    /**
     * @param list<array<string,mixed>> $dias
     * @return list<array<string,mixed>>
     */
    private function ordenarDias(array $dias): array
    {
        usort($dias, static fn(array $a, array $b): int => strcmp((string) ($a['data'] ?? ''), (string) ($b['data'] ?? '')));
        return array_values($dias);
    }

    /**
     * @param array<string,mixed> $dia
     */
    private function diaTemTrabalho(array $dia): bool
    {
        $e1 = $this->normHm($dia['ent1'] ?? null);
        $s1 = $this->normHm($dia['sai1'] ?? null);
        $e2 = $this->normHm($dia['ent2'] ?? null);
        $s2 = $this->normHm($dia['sai2'] ?? null);
        return ($e1 !== null && ($s1 !== null || $s2 !== null)) || ($e2 !== null && $s2 !== null);
    }

    /**
     * @param array<string,mixed> $dia
     */
    private function assinaturaHorarios(array $dia): ?string
    {
        $e1 = $this->normHm($dia['ent1'] ?? null);
        $s1 = $this->normHm($dia['sai1'] ?? null);
        $e2 = $this->normHm($dia['ent2'] ?? null);
        $s2 = $this->normHm($dia['sai2'] ?? null);
        if (!$this->diaTemTrabalho($dia)) {
            return null;
        }
        return ($e1 ?? '') . '|' . ($s1 ?? '') . '|' . ($e2 ?? '') . '|' . ($s2 ?? '');
    }

    /**
     * @param array<string,mixed> $dia
     * @return array{trab:int, adn:int, intra:?int}
     */
    private function apurarDiaSimples(array $dia): array
    {
        $e1 = $this->normHm($dia['ent1'] ?? null);
        $s1 = $this->normHm($dia['sai1'] ?? null);
        $e2 = $this->normHm($dia['ent2'] ?? null);
        $s2 = $this->normHm($dia['sai2'] ?? null);

        $pares = ($e1 !== null && $s2 !== null && $s1 === null && $e2 === null)
            ? [[$e1, $s2]]
            : [[$e1, $s1], [$e2, $s2]];

        $segs = [];
        $prevStart = null;
        foreach ($pares as [$ini, $fim]) {
            if ($ini === null || $fim === null) {
                continue;
            }
            $a = $this->toMin($ini);
            $b = $this->toMin($fim);
            if ($b === $a) {
                continue;
            }
            if ($b < $a) {
                $b += 1440;
            }
            if ($prevStart !== null && $a < $prevStart) {
                $a += 1440;
                $b += 1440;
            }
            $prevStart = $a;
            $segs[] = [$a, $b];
        }

        $trab = 0;
        $adn = 0;
        $teveNoturno = false;
        foreach ($segs as [$a, $b]) {
            $trab += max(0, $b - $a);
            $adnSeg = $this->minutosNoturnos($a, $b, $teveNoturno);
            if ($adnSeg > 0) {
                $teveNoturno = true;
            }
            $adn += $adnSeg;
        }

        $intra = null;
        if (count($segs) === 2) {
            $gap = $segs[1][0] - $segs[0][1];
            if ($gap >= 0) {
                $intra = $gap;
            }
        }

        return ['trab' => $trab, 'adn' => $adn, 'intra' => $intra];
    }

    private function minutosNoturnos(int $a, int $b, bool $prorroga): int
    {
        $janelas = [[0, 300], [1320, 1740], [2760, 3180]];
        $total = 0;
        foreach ($janelas as [$x, $y]) {
            $i = max($a, $x);
            $f = min($b, $y);
            if ($f > $i) {
                $total += ($f - $i);
            }
        }
        if ($total > 0 || $prorroga) {
            foreach ([[300, 1320], [1740, 2760]] as [$px, $py]) {
                if ($a <= $px && $b > $px) {
                    $total += max(0, min($b, $py) - $px);
                } elseif ($prorroga && $a > $px && $a < $py) {
                    $total += max(0, min($b, $py) - $a);
                }
            }
        }
        return min($total, max(0, $b - $a));
    }

    /**
     * @param array<string,mixed> $dia
     */
    private function timestampPrimeiraEntrada(array $dia): ?int
    {
        $data = (string) ($dia['data'] ?? '');
        $ent = $this->normHm($dia['ent1'] ?? null) ?? $this->normHm($dia['ent2'] ?? null);
        if ($data === '' || $ent === null) {
            return null;
        }
        $ts = strtotime($data . ' ' . $ent . ':00');
        return $ts === false ? null : $ts;
    }

    /**
     * @param array<string,mixed> $dia
     */
    private function timestampUltimaSaida(array $dia): ?int
    {
        $data = (string) ($dia['data'] ?? '');
        $e1 = $this->normHm($dia['ent1'] ?? null);
        $s1 = $this->normHm($dia['sai1'] ?? null);
        $e2 = $this->normHm($dia['ent2'] ?? null);
        $s2 = $this->normHm($dia['sai2'] ?? null);

        $firstEnt = $e1 ?? $e2;
        $lastSai = $s2 ?? $s1;
        if ($data === '' || $firstEnt === null || $lastSai === null) {
            return null;
        }

        $baseTs = strtotime($data . ' 00:00:00');
        if ($baseTs === false) {
            return null;
        }

        $mEnt = $this->toMin($firstEnt);
        $mSai = $this->toMin($lastSai);
        // Virada de meia-noite (ex.: entrou 22:00 e saiu 06:00)
        if ($mSai < $mEnt) {
            $mSai += 1440;
        } elseif ($s1 !== null && $e2 !== null && $s2 !== null) {
            $mS1 = $this->toMin($s1);
            if ($mS1 < $mEnt) {
                // 1º turno já virou meia-noite -> 2º turno está no dia seguinte
                if ($mSai < 1440) {
                    $mSai += 1440;
                }
            }
        }

        return $baseTs + ($mSai * 60);
    }

    /**
     * @param array<string,mixed> $lotacao
     */
    private function duracaoIntervaloPrevisto(array $lotacao): int
    {
        $ini = $this->normHm($lotacao['intervalo_inicio'] ?? null);
        $fim = $this->normHm($lotacao['intervalo_fim'] ?? null);
        if ($ini === null || $fim === null) {
            return 60;
        }
        $a = $this->toMin($ini);
        $b = $this->toMin($fim);
        if ($b < $a) {
            $b += 1440;
        }
        return max(0, $b - $a);
    }

    private function ehMenorDe18(?string $dataNascimento, string $dataRef): bool
    {
        if (!$dataNascimento || !$dataRef) {
            return false;
        }
        try {
            $nasc = new DateTimeImmutable($dataNascimento);
            $ref = new DateTimeImmutable($dataRef);
            return $nasc->diff($ref)->y < 18;
        } catch (Throwable) {
            return false;
        }
    }

    private function normHm(mixed $v): ?string
    {
        $s = trim((string) ($v ?? ''));
        if ($s === '' || $s === '--:--') {
            return null;
        }
        if (preg_match('/^(\d{2}:\d{2})/', $s, $m)) {
            return $m[1];
        }
        return null;
    }

    private function toMin(string $hm): int
    {
        [$h, $m] = explode(':', $hm);
        return ((int) $h) * 60 + ((int) $m);
    }

    private function fmtMin(int $min): string
    {
        $min = max(0, $min);
        return sprintf('%02d:%02d', intdiv($min, 60), $min % 60);
    }
}
