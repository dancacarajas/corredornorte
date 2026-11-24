<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Helpers\EscopoAcesso;
use DateTimeImmutable;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Relatório de HE = reflexo do ponto tratado (Medição).
 * Buckets 50/60/100 × diurno/noturno derivados na leitura (Opção A).
 */
final class HeRelatorioService
{
    private PDO $db;
    private static bool $schemaOk = false;

    /** @var array<int, array<string,mixed>> */
    private array $horarioCache = [];

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    public function ensureSchema(): void
    {
        if (self::$schemaOk) {
            return;
        }
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS he_saldos_pendentes (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                colaborador_id BIGINT UNSIGNED NOT NULL,
                competencia CHAR(7) NOT NULL COMMENT 'YYYY-MM',
                adn_min INT NOT NULL DEFAULT 0,
                he_diurno_50_min INT NOT NULL DEFAULT 0,
                he_diurno_60_min INT NOT NULL DEFAULT 0,
                he_diurno_100_min INT NOT NULL DEFAULT 0,
                he_noturno_50_min INT NOT NULL DEFAULT 0,
                he_noturno_60_min INT NOT NULL DEFAULT 0,
                he_noturno_100_min INT NOT NULL DEFAULT 0,
                observacao VARCHAR(255) NULL,
                usuario_id BIGINT UNSIGNED NULL,
                criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                atualizado_em DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_he_pend_colab_comp (colaborador_id, competencia),
                INDEX idx_he_pend_comp (competencia),
                CONSTRAINT fk_he_pend_colab FOREIGN KEY (colaborador_id) REFERENCES rh_colaboradores(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        self::$schemaOk = true;
    }

    /**
     * @return array{
     *   data_inicio:string,
     *   data_fim:string,
     *   mes_label:string,
     *   efetivo:list<array<string,mixed>>,
     *   lancamentos:list<array<string,mixed>>,
     *   consolidado:list<array<string,mixed>>,
     *   pendentes:array<string, list<array<string,mixed>>>,
     *   totais:array<string,int>
     * }
     */
    public function gerar(string $dataInicio, string $dataFim): array
    {
        $this->ensureSchema();
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $dataInicio)
            || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $dataFim)
        ) {
            throw new RuntimeException('Informe o período no formato AAAA-MM-DD.');
        }
        if ($dataFim < $dataInicio) {
            throw new RuntimeException('Data fim anterior à data início.');
        }

        $lancamentos = $this->carregarLancamentos($dataInicio, $dataFim);
        $efetivo = $this->montarEfetivo($lancamentos);
        $consolidado = $this->consolidar($lancamentos);
        $pendentes = $this->listarPendentesAgrupados();

        $totais = [
            'colaboradores' => count($consolidado),
            'lancamentos' => count($lancamentos),
            'trab_min' => 0,
            'adn_min' => 0,
            'he_d50' => 0,
            'he_d60' => 0,
            'he_d100' => 0,
            'he_n50' => 0,
            'he_n60' => 0,
            'he_n100' => 0,
        ];
        foreach ($consolidado as $row) {
            $totais['trab_min'] += (int) $row['trab_min'];
            $totais['adn_min'] += (int) $row['adn_min'];
            $totais['he_d50'] += (int) $row['he_d50'];
            $totais['he_d60'] += (int) $row['he_d60'];
            $totais['he_d100'] += (int) $row['he_d100'];
            $totais['he_n50'] += (int) $row['he_n50'];
            $totais['he_n60'] += (int) $row['he_n60'];
            $totais['he_n100'] += (int) $row['he_n100'];
        }

        $mes = (new DateTimeImmutable($dataInicio))->format('m/Y');

        return [
            'data_inicio' => $dataInicio,
            'data_fim' => $dataFim,
            'mes_label' => $mes,
            'efetivo' => $efetivo,
            'lancamentos' => $lancamentos,
            'consolidado' => $consolidado,
            'pendentes' => $pendentes,
            'totais' => $totais,
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function carregarLancamentos(string $dataInicio, string $dataFim): array
    {
        $escopo = EscopoAcesso::sqlFiltroColaborador('c');
        $tolHe = PontoHeAutorizacaoService::TOLERANCIA_HE_MIN;
        $sql = <<<SQL
            SELECT
              d.id AS ponto_dia_id,
              d.data,
              d.tipo_dia,
              d.ent1, d.sai1, d.ent2, d.sai2,
              d.total_trab_min,
              d.horas_previstas_min,
              d.he_50_min,
              d.he_100_min,
              d.adn_min,
              d.percentual_he,
              d.horas_extras_min,
              e.id AS espelho_id,
              e.status AS espelho_status,
              c.id AS colaborador_id,
              c.matricula,
              c.nome,
              c.status AS colaborador_status,
              c.funcao,
              c.cargo,
              c.data_admissao,
              loc.municipio,
              loc.uf,
              os.nome AS secao,
              os.codigo AS os_codigo,
              l.percentual_he AS lotacao_percentual_he,
              h.id AS horario_id,
              h.codigo AS horario_codigo,
              h.nome AS horario_nome,
              h.tipo AS horario_tipo,
              h.adicional_noturno AS horario_noturno
            FROM ponto_dias d
            INNER JOIN ponto_espelhos e ON e.id = d.espelho_id
            INNER JOIN ponto_periodos pp ON pp.id = e.periodo_id
            INNER JOIN rh_colaboradores c ON c.id = e.colaborador_id
            LEFT JOIN rh_lotacoes l ON l.id = e.lotacao_id
            LEFT JOIN rh_ordens_servico os ON os.id = l.ordem_servico_id
            LEFT JOIN rh_localidades loc ON loc.id = l.localidade_id
            LEFT JOIN rh_horarios h ON h.id = l.horario_id
            WHERE d.data BETWEEN :ini AND :fim
              AND e.status IN ('em_aberto','em_tratamento','em_divergencia','conferido','fechado','enviado','assinado')
              -- Planilha Lançamentos: dias com HE acima da tolerância de autorização OU com ADN
              AND (
                    COALESCE(d.he_50_min, 0) > {$tolHe}
                 OR COALESCE(d.he_100_min, 0) > {$tolHe}
                 OR COALESCE(d.horas_extras_min, 0) > {$tolHe}
                 OR COALESCE(d.adn_min, 0) > 0
              )
              {$escopo['sql']}
            ORDER BY c.nome, d.data
        SQL;

        $stmt = $this->db->prepare($sql);
        $stmt->execute(array_merge([
            'ini' => $dataInicio,
            'fim' => $dataFim,
        ], $escopo['params']));

        $out = [];
        $ordemPorColab = [];
        $ordemSeq = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $enriched = $this->enrichLancamento($row);
            $cid = (int) $enriched['colaborador_id'];
            if (!isset($ordemPorColab[$cid])) {
                $ordemPorColab[$cid] = ++$ordemSeq;
                $enriched['ordem_unica'] = $ordemPorColab[$cid];
            } else {
                $enriched['ordem_unica'] = '';
            }
            $out[] = $enriched;
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function enrichLancamento(array $row): array
    {
        $data = (string) $row['data'];
        $dia = new DateTimeImmutable($data);
        $buckets = self::bucketsDoDia($row);
        $horarioMeta = $this->metaHorario((int) ($row['horario_id'] ?? 0));

        $entrada = $this->fmtHora($row['ent1'] ?? null);
        $saida = $this->fmtHora($row['sai2'] ?? null) ?: $this->fmtHora($row['sai1'] ?? null);
        $sai1Fmt = $this->fmtHora($row['sai1'] ?? null);
        $ent2Fmt = $this->fmtHora($row['ent2'] ?? null);

        $feriado = ((string) ($row['tipo_dia'] ?? '')) === 'feriado' ? 'SIM' : 'NÃO';
        $dowNomes = [1 => 'Segunda', 2 => 'Terça', 3 => 'Quarta', 4 => 'Quinta', 5 => 'Sexta', 6 => 'Sábado', 7 => 'Domingo'];
        $dow = (int) $dia->format('N');

        // Usa diretamente a jornada prevista apurada no dia (respeita escalas 6x1, 12x36, feriados e folgas).
        $jornadaPrevMin = max(0, (int) ($row['horas_previstas_min'] ?? 0));
        $jornadaPrevH = $this->minToHoras($jornadaPrevMin);

        $pctDia = (int) ($row['percentual_he'] ?? $row['lotacao_percentual_he'] ?? 50);
        if ($feriado === 'SIM' || $dow === 7) {
            $taxaHe = 100;
        } else {
            $taxaHe = $pctDia > 0 ? $pctDia : 50;
        }

        return array_merge($row, [
            'dia_semana' => $dowNomes[$dow] ?? '',
            'funcao_label' => (string) ($row['funcao'] ?: ($row['cargo'] ?? '')),
            'horario_codigo' => (string) ($row['horario_codigo'] ?? ''),
            'horario_texto' => $horarioMeta['texto'] ?? (string) ($row['horario_nome'] ?? ''),
            'horas_seg_qui' => (float) ($horarioMeta['horas_seg_qui'] ?? 0),
            'horas_sexta' => (float) ($horarioMeta['horas_sexta'] ?? 0),
            'intervalo_previsto_h' => (float) ($horarioMeta['intervalo_h'] ?? 0),
            'jornada_prevista_h' => $jornadaPrevH,
            'jornada_prevista_min' => $jornadaPrevMin,
            'entrada' => $entrada,
            'saida' => $saida,
            'sai1_fmt' => $sai1Fmt,
            'ent2_fmt' => $ent2Fmt,
            'trab_min' => (int) ($row['total_trab_min'] ?? 0),
            'trab_h' => $this->minToHoras((int) ($row['total_trab_min'] ?? 0)),
            'adn_min' => (int) ($row['adn_min'] ?? 0),
            'adn_h' => $this->minToHoras((int) ($row['adn_min'] ?? 0)),
            'feriado_sn' => $feriado,
            'taxa_he' => $taxaHe,
            'he_d50' => $buckets['he_d50'],
            'he_d60' => $buckets['he_d60'],
            'he_d100' => $buckets['he_d100'],
            'he_n50' => $buckets['he_n50'],
            'he_n60' => $buckets['he_n60'],
            'he_n100' => $buckets['he_n100'],
            'he_d50_h' => $this->minToHoras($buckets['he_d50']),
            'he_d60_h' => $this->minToHoras($buckets['he_d60']),
            'he_d100_h' => $this->minToHoras($buckets['he_d100']),
            'he_n50_h' => $this->minToHoras($buckets['he_n50']),
            'he_n60_h' => $this->minToHoras($buckets['he_n60']),
            'he_n100_h' => $this->minToHoras($buckets['he_n100']),
        ]);
    }

    /**
     * Classifica HE do ponto em buckets diurnos (05:00-22:00) e noturnos (22:00-05:00),
     * apurando separadamente a parcela noturna a partir das batidas do dia (em vez de usar
     * adn_min > 0 como chave binária) e respeitando o percentual real do dia/lotação.
     *
     * @param array<string,mixed> $dia
     * @return array<string,int>
     */
    public static function bucketsDoDia(array $dia): array
    {
        $out = [
            'he_d50' => 0, 'he_d60' => 0, 'he_d100' => 0,
            'he_n50' => 0, 'he_n60' => 0, 'he_n100' => 0,
        ];
        $hePct = max(0, (int) ($dia['he_50_min'] ?? 0));
        $he100 = max(0, (int) ($dia['he_100_min'] ?? 0));
        $heTotal = $hePct + $he100;
        if ($heTotal <= PontoHeAutorizacaoService::TOLERANCIA_HE_MIN) {
            return $out;
        }

        $pctRaw = $dia['percentual_he'] ?? $dia['lotacao_percentual_he'] ?? 50;
        $pct = (int) $pctRaw;
        if ($pct <= 0) {
            $pct = 50;
        }

        $heNoturno = self::apurarHeNoturnaMin($dia, $heTotal);
        $heDiurno = max(0, $heTotal - $heNoturno);

        if ($he100 > 0) {
            $n100 = $hePct === 0
                ? min($he100, $heNoturno)
                : min($he100, $heNoturno, (int) round($heNoturno * ($he100 / $heTotal)));
            $d100 = $he100 - $n100;
            $out['he_n100'] += $n100;
            $out['he_d100'] += $d100;
            $heNoturno = max(0, $heNoturno - $n100);
            $heDiurno = max(0, $heDiurno - $d100);
        }

        if ($hePct > 0) {
            $nPct = min($hePct, $heNoturno);
            $dPct = max(0, $hePct - $nPct);

            if ($pct >= 100) {
                $out['he_n100'] += $nPct;
                $out['he_d100'] += $dPct;
            } elseif ($pct === 60) {
                $out['he_n60'] += $nPct;
                $out['he_d60'] += $dPct;
            } elseif ($pct === 50) {
                $out['he_n50'] += $nPct;
                $out['he_d50'] += $dPct;
            } else {
                // Suporta percentuais customizados praticados (ex.: 70%, 75%, 80%) sem rebaixar para 50%.
                $kN = 'he_n' . $pct;
                $kD = 'he_d' . $pct;
                $out[$kN] = ($out[$kN] ?? 0) + $nPct;
                $out[$kD] = ($out[$kD] ?? 0) + $dPct;
            }
        }

        return $out;
    }

    /**
     * Calcula quantos minutos da HE total ($heTotal) ocorreram dentro da faixa noturna CLT (22:00-05:00).
     *
     * @param array<string,mixed> $dia
     */
    private static function apurarHeNoturnaMin(array $dia, int $heTotal): int
    {
        $adn = max(0, (int) ($dia['adn_min'] ?? 0));
        if ($adn === 0 || $heTotal <= 0) {
            return 0;
        }

        $e1 = self::horaToMinStatic($dia['ent1'] ?? null);
        $s1 = self::horaToMinStatic($dia['sai1'] ?? null);
        $e2 = self::horaToMinStatic($dia['ent2'] ?? null);
        $s2 = self::horaToMinStatic($dia['sai2'] ?? null);

        $pares = ($e1 !== null && $s2 !== null && $s1 === null && $e2 === null)
            ? [[$e1, $s2]]
            : [[$e1, $s1], [$e2, $s2]];

        // Mantém linha do tempo contínua após a meia-noite (sem inverter 2º turno com usort).
        $fundidos = [];
        $prevStart = null;
        foreach ($pares as [$a, $b]) {
            if ($a === null || $b === null || $b === $a) {
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

            if ($fundidos === []) {
                $fundidos[] = [$a, $b];
                continue;
            }
            $last = count($fundidos) - 1;
            if ($a <= $fundidos[$last][1]) {
                $fundidos[$last][1] = max($fundidos[$last][1], $b);
            } else {
                $fundidos[] = [$a, $b];
            }
        }

        if ($fundidos === []) {
            // Fallback sem batidas: limita ao menor entre HE total e ADN do dia.
            return min($heTotal, $adn);
        }

        $trabSegs = 0;
        foreach ($fundidos as [$a, $b]) {
            $trabSegs += max(0, $b - $a);
        }

        $tipoDia = (string) ($dia['tipo_dia'] ?? '');
        $prevMin = max(0, (int) ($dia['horas_previstas_min'] ?? 0));
        $ehFolgaOuFeriado = in_array($tipoDia, ['folga', 'folga_escala', 'feriado'], true);
        $normalConsumir = ($ehFolgaOuFeriado || $prevMin <= 0 || $heTotal >= $trabSegs)
            ? 0
            : max(0, $trabSegs - $heTotal);

        $noturnoHe = 0;
        $teveNoturnoAntes = false;
        foreach ($fundidos as [$a, $b]) {
            $dur = max(0, $b - $a);
            $temNotSeg = self::minutosNoturnosFaixaStatic($a, $b, $teveNoturnoAntes) > 0;
            if ($normalConsumir >= $dur) {
                $normalConsumir -= $dur;
                if ($temNotSeg) {
                    $teveNoturnoAntes = true;
                }
                continue;
            }
            $heIni = $a + $normalConsumir;
            if ($normalConsumir > 0 && self::minutosNoturnosFaixaStatic($a, $heIni, $teveNoturnoAntes) > 0) {
                $teveNoturnoAntes = true;
            }
            $normalConsumir = 0;
            if ($b > $heIni) {
                $notHeSeg = self::minutosNoturnosFaixaStatic($heIni, $b, $teveNoturnoAntes);
                $noturnoHe += $notHeSeg;
                if ($notHeSeg > 0 || $temNotSeg) {
                    $teveNoturnoAntes = true;
                }
            }
        }

        return min($heTotal, max(0, $noturnoHe));
    }

    private static function minutosNoturnosFaixaStatic(int $a, int $b, bool $teveNoturnoAntes = false): int
    {
        if ($b <= $a) {
            return 0;
        }
        $overlap = static fn (int $a1, int $a2, int $b1, int $b2): int => max(0, min($a2, $b2) - max($a1, $b1));
        $noturnoDia0 = $overlap($a, $b, 0, 5 * 60);
        $noturnoVirada = $overlap($a, $b, 22 * 60, 29 * 60);
        $total = $noturnoDia0 + $noturnoVirada;

        // Prorrogação noturna após 05:00 (CLT Art. 73 § 5º / Súmula 60, II TST)
        if (($noturnoVirada > 0 || $teveNoturnoAntes) && $b > 29 * 60) {
            $total += $overlap($a, $b, 29 * 60, 46 * 60);
        } elseif ($noturnoDia0 > 0 && $a <= 2 * 60 && $b > 5 * 60) {
            $total += $overlap($a, $b, 5 * 60, 22 * 60);
        }

        return min($total, max(0, $b - $a));
    }

    private static function horaToMinStatic(mixed $v): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (preg_match('/^(\d{1,2}):(\d{2})/', (string) $v, $m)) {
            return ((int) $m[1]) * 60 + (int) $m[2];
        }
        return null;
    }

    /**
     * @param list<array<string,mixed>> $lancamentos
     * @return list<array<string,mixed>>
     */
    private function montarEfetivo(array $lancamentos): array
    {
        $byColab = [];
        foreach ($lancamentos as $row) {
            $id = (int) $row['colaborador_id'];
            if (isset($byColab[$id])) {
                continue;
            }
            $horarioMeta = $this->metaHorario((int) ($row['horario_id'] ?? 0));
            $status = (string) ($row['colaborador_status'] ?? '');
            $situacao = match ($status) {
                'ativo' => 'A',
                'desligado' => 'D',
                default => 'E',
            };
            $segQui = (float) ($horarioMeta['horas_seg_qui'] ?? 0);
            $sexta = (float) ($horarioMeta['horas_sexta'] ?? 0);
            $jornadaMensal = (int) ($horarioMeta['jornada_mensal'] ?? 0);
            if ($jornadaMensal <= 0) {
                $jornadaMensal = (int) round(($segQui * 4 + $sexta) * 4.345);
            }
            if ($jornadaMensal <= 0) {
                $jornadaMensal = 220;
            }
            $turno = !empty($row['horario_noturno']) || (($horarioMeta['turno'] ?? '') === 'Noturno')
                ? 'Noturno'
                : 'Diurno';

            $byColab[$id] = [
                'colaborador_id' => $id,
                'matricula' => (string) $row['matricula'],
                'nome' => (string) $row['nome'],
                'situacao' => $situacao,
                'funcao' => (string) ($row['funcao_label'] ?? ''),
                'cidade' => (string) ($row['municipio'] ?? ''),
                'uf' => (string) ($row['uf'] ?? ''),
                'horario_codigo' => (string) ($row['horario_codigo'] ?? ''),
                'horario_texto' => (string) ($row['horario_texto'] ?? ''),
                'jornada_mensal' => $jornadaMensal,
                'horas_seg_qui' => $segQui,
                'horas_sexta' => $sexta,
                'intervalo_h' => (float) ($horarioMeta['intervalo_h'] ?? 0),
                'secao' => (string) ($row['secao'] ?? ''),
                'percentual_he' => (int) ($row['lotacao_percentual_he'] ?? $row['percentual_he'] ?? 50),
                'admissao' => (string) ($row['data_admissao'] ?? ''),
                'demissao' => '',
                'turno' => $turno,
            ];
        }
        $list = array_values($byColab);
        usort($list, static fn (array $a, array $b): int => strcmp($a['nome'], $b['nome']));

        return $list;
    }

    /**
     * @param list<array<string,mixed>> $lancamentos
     * @return list<array<string,mixed>>
     */
    private function consolidar(array $lancamentos): array
    {
        $by = [];
        foreach ($lancamentos as $row) {
            $id = (int) $row['colaborador_id'];
            if (!isset($by[$id])) {
                $by[$id] = [
                    'colaborador_id' => $id,
                    'nome' => (string) $row['nome'],
                    'matricula' => (string) $row['matricula'],
                    'dias' => 0,
                    'trab_min' => 0,
                    'adn_min' => 0,
                    'he_d50' => 0,
                    'he_d60' => 0,
                    'he_d100' => 0,
                    'he_n50' => 0,
                    'he_n60' => 0,
                    'he_n100' => 0,
                ];
            }
            $by[$id]['dias']++;
            $by[$id]['trab_min'] += (int) ($row['total_trab_min'] ?? 0);
            $by[$id]['adn_min'] += (int) ($row['adn_min'] ?? 0);
            $by[$id]['he_d50'] += (int) $row['he_d50'];
            $by[$id]['he_d60'] += (int) $row['he_d60'];
            $by[$id]['he_d100'] += (int) $row['he_d100'];
            $by[$id]['he_n50'] += (int) $row['he_n50'];
            $by[$id]['he_n60'] += (int) $row['he_n60'];
            $by[$id]['he_n100'] += (int) $row['he_n100'];
        }

        $list = [];
        foreach ($by as $row) {
            $temHe = $row['he_d50'] + $row['he_d60'] + $row['he_d100']
                + $row['he_n50'] + $row['he_n60'] + $row['he_n100'] + $row['adn_min'] > 0;
            if (!$temHe) {
                continue;
            }
            $row['trab_h'] = $this->minToHoras($row['trab_min']);
            $row['adn_h'] = $this->minToHoras($row['adn_min']);
            $row['he_d50_h'] = $this->minToHoras($row['he_d50']);
            $row['he_d60_h'] = $this->minToHoras($row['he_d60']);
            $row['he_d100_h'] = $this->minToHoras($row['he_d100']);
            $row['he_n50_h'] = $this->minToHoras($row['he_n50']);
            $row['he_n60_h'] = $this->minToHoras($row['he_n60']);
            $row['he_n100_h'] = $this->minToHoras($row['he_n100']);
            $list[] = $row;
        }
        usort($list, static fn (array $a, array $b): int => strcmp($a['nome'], $b['nome']));

        return $list;
    }

    /** @return array<string, list<array<string,mixed>>> */
    public function listarPendentesAgrupados(): array
    {
        $this->ensureSchema();
        $escopo = EscopoAcesso::sqlFiltroColaborador('c');
        $sql = "SELECT p.*, c.matricula, c.nome
                FROM he_saldos_pendentes p
                INNER JOIN rh_colaboradores c ON c.id = p.colaborador_id
                WHERE 1=1 {$escopo['sql']}
                ORDER BY p.competencia DESC, c.nome";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($escopo['params']);
        $grupos = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $comp = (string) $row['competencia'];
            $row['adn_min'] = (int) ($row['adn_min'] ?? 0);
            $row['he_diurno_50_min'] = (int) ($row['he_diurno_50_min'] ?? 0);
            $row['he_diurno_60_min'] = (int) ($row['he_diurno_60_min'] ?? 0);
            $row['he_diurno_100_min'] = (int) ($row['he_diurno_100_min'] ?? 0);
            $row['he_noturno_50_min'] = (int) ($row['he_noturno_50_min'] ?? 0);
            $row['he_noturno_60_min'] = (int) ($row['he_noturno_60_min'] ?? 0);
            $row['he_noturno_100_min'] = (int) ($row['he_noturno_100_min'] ?? 0);
            $row['adn_h'] = $this->minToHoras($row['adn_min']);
            $row['he_d50_h'] = $this->minToHoras($row['he_diurno_50_min']);
            $row['he_d60_h'] = $this->minToHoras($row['he_diurno_60_min']);
            $row['he_d100_h'] = $this->minToHoras($row['he_diurno_100_min']);
            $row['he_n50_h'] = $this->minToHoras($row['he_noturno_50_min']);
            $row['he_n60_h'] = $this->minToHoras($row['he_noturno_60_min']);
            $row['he_n100_h'] = $this->minToHoras($row['he_noturno_100_min']);
            $grupos[$comp][] = $row;
        }

        return $grupos;
    }

    /**
     * @param array<string,mixed> $dados
     */
    public function salvarPendente(array $dados, ?int $usuarioId = null): int
    {
        $this->ensureSchema();
        $colabId = (int) ($dados['colaborador_id'] ?? 0);
        $comp = (string) ($dados['competencia'] ?? '');
        if ($colabId <= 0) {
            throw new RuntimeException('Selecione o colaborador.');
        }
        if (!preg_match('/^\d{4}-\d{2}$/D', $comp)) {
            throw new RuntimeException('Competência inválida (use AAAA-MM).');
        }

        // Unidade padrão de entrada do formulário: horas decimais (ex.: "2", "2.5", "2,5") ou "HH:MM".
        // Campos explícitos *_min são aceitos diretamente em minutos inteiros.
        $toMin = static function (mixed $v): int {
            if ($v === null || $v === '') {
                return 0;
            }
            $s = trim(str_replace(',', '.', (string) $v));
            if ($s === '') {
                return 0;
            }
            if (preg_match('/^(\d{1,3}):([0-5]\d)$/', $s, $m)) {
                return ((int) $m[1]) * 60 + (int) $m[2];
            }
            if (is_numeric($s)) {
                return max(0, (int) round(((float) $s) * 60));
            }

            return 0;
        };
        $resolveMin = static function (array $src, string $campoHoras, string $campoMin) use ($toMin): int {
            if (array_key_exists($campoMin, $src) && $src[$campoMin] !== null && $src[$campoMin] !== '') {
                return max(0, (int) $src[$campoMin]);
            }
            return $toMin($src[$campoHoras] ?? 0);
        };

        $params = [
            'c' => $colabId,
            'comp' => $comp,
            'adn' => $resolveMin($dados, 'adn', 'adn_min'),
            'd50' => $resolveMin($dados, 'he_diurno_50', 'he_diurno_50_min'),
            'd60' => $resolveMin($dados, 'he_diurno_60', 'he_diurno_60_min'),
            'd100' => $resolveMin($dados, 'he_diurno_100', 'he_diurno_100_min'),
            'n50' => $resolveMin($dados, 'he_noturno_50', 'he_noturno_50_min'),
            'n60' => $resolveMin($dados, 'he_noturno_60', 'he_noturno_60_min'),
            'n100' => $resolveMin($dados, 'he_noturno_100', 'he_noturno_100_min'),
            'o' => trim((string) ($dados['observacao'] ?? '')) ?: null,
            'u' => $usuarioId,
        ];

        $this->db->prepare(
            'INSERT INTO he_saldos_pendentes
             (colaborador_id, competencia, adn_min, he_diurno_50_min, he_diurno_60_min, he_diurno_100_min,
              he_noturno_50_min, he_noturno_60_min, he_noturno_100_min, observacao, usuario_id)
             VALUES (:c,:comp,:adn,:d50,:d60,:d100,:n50,:n60,:n100,:o,:u)
             ON DUPLICATE KEY UPDATE
               adn_min = VALUES(adn_min),
               he_diurno_50_min = VALUES(he_diurno_50_min),
               he_diurno_60_min = VALUES(he_diurno_60_min),
               he_diurno_100_min = VALUES(he_diurno_100_min),
               he_noturno_50_min = VALUES(he_noturno_50_min),
               he_noturno_60_min = VALUES(he_noturno_60_min),
               he_noturno_100_min = VALUES(he_noturno_100_min),
               observacao = VALUES(observacao),
               usuario_id = VALUES(usuario_id)'
        )->execute($params);

        $id = (int) $this->db->lastInsertId();
        if ($id > 0) {
            return $id;
        }
        $st = $this->db->prepare(
            'SELECT id FROM he_saldos_pendentes WHERE colaborador_id = :c AND competencia = :comp'
        );
        $st->execute(['c' => $colabId, 'comp' => $comp]);

        return (int) $st->fetchColumn();
    }

    public function excluirPendente(int $id): void
    {
        $this->ensureSchema();
        $this->db->prepare('DELETE FROM he_saldos_pendentes WHERE id = :id')->execute(['id' => $id]);
    }

    /** @return list<array{id:int,matricula:string,nome:string}> */
    public function lookupColaboradoresAtivos(): array
    {
        $escopo = EscopoAcesso::sqlFiltroColaborador('c');
        $sql = "SELECT c.id, c.matricula, c.nome
                FROM rh_colaboradores c
                WHERE c.status = 'ativo' {$escopo['sql']}
                ORDER BY c.nome
                LIMIT 2000";
        $st = $this->db->prepare($sql);
        $st->execute($escopo['params']);

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array{texto:string,horas_seg_qui:float,horas_sexta:float,intervalo_h:float,turno:string,jornada_mensal:int}|null */
    private function metaHorario(int $horarioId): ?array
    {
        if ($horarioId <= 0) {
            return null;
        }
        if (isset($this->horarioCache[$horarioId])) {
            return $this->horarioCache[$horarioId];
        }
        try {
            $svc = new HorarioService($this->db);
            $h = $svc->buscar($horarioId);
            if ($h === null) {
                return $this->horarioCache[$horarioId] = null;
            }
            $dias = $svc->listarDias($horarioId);
            $tipo = (string) ($h['tipo'] ?? 'semanal');
            $cargas = [];
            $faixas = [];
            $intervalo = 0.0;
            $noturno = !empty($h['adicional_noturno']);
            foreach ($dias as $d) {
                if (($d['tipo_dia'] ?? '') !== 'trabalho') {
                    continue;
                }
                $idx = (int) $d['indice'];
                $cargas[$idx] = $this->cargaDiaMin($d);
                $faixas[$idx] = $this->faixaDiaPlanilha($d);
                $ii = $this->horaToMin($d['sai1'] ?? null);
                $iff = $this->horaToMin($d['ent2'] ?? null);
                if ($ii !== null && $iff !== null) {
                    if ($iff < $ii) {
                        $iff += 1440;
                    }
                    if ($iff > $ii) {
                        $intervalo = max($intervalo, ($iff - $ii) / 60);
                    }
                }
            }

            if ($tipo === 'ciclico') {
                $mediaDiaMin = $cargas !== [] ? (array_sum($cargas) / count($cargas)) : 0.0;
                $horasSegQui = round($mediaDiaMin / 60, 2);
                $horasSexta = $horasSegQui;
                $cicloLen = max(1, count($dias));
                $jornadaMensal = (int) round((array_sum($cargas) / $cicloLen / 60) * 30);
            } else {
                $segQui = [];
                foreach ([1, 2, 3, 4] as $i) {
                    if (isset($cargas[$i])) {
                        $segQui[] = $cargas[$i];
                    }
                }
                $horasSegQui = $segQui !== []
                    ? round(array_sum($segQui) / count($segQui) / 60, 2)
                    : 0.0;
                $horasSexta = isset($cargas[5]) ? round($cargas[5] / 60, 2) : 0.0;
                // Soma todos os dias de trabalho da semana (inclui sábado/domingo em escalas 6x1).
                $jornadaMensal = (int) round((array_sum($cargas) / 60) * 4.345);
            }

            $meta = [
                'texto' => $this->textoHorarioPlanilha($faixas, $tipo)
                    ?: (string) ($h['nome'] ?? ''),
                'horas_seg_qui' => $horasSegQui,
                'horas_sexta' => $horasSexta,
                'intervalo_h' => round($intervalo, 2),
                'turno' => $noturno ? 'Noturno' : 'Diurno',
                'jornada_mensal' => $jornadaMensal > 0 ? $jornadaMensal : 220,
            ];

            return $this->horarioCache[$horarioId] = $meta;
        } catch (Throwable) {
            return $this->horarioCache[$horarioId] = null;
        }
    }

    /** @param array<string,mixed> $d */
    private function cargaDiaMin(array $d): int
    {
        $e1 = $this->horaToMin($d['ent1'] ?? null);
        $s1 = $this->horaToMin($d['sai1'] ?? null);
        $e2 = $this->horaToMin($d['ent2'] ?? null);
        $s2 = $this->horaToMin($d['sai2'] ?? null);
        $total = 0;
        if ($e1 !== null && $s1 !== null) {
            $total += $s1 > $e1 ? $s1 - $e1 : ($s1 + 1440 - $e1);
        }
        if ($e2 !== null && $s2 !== null) {
            $total += $s2 > $e2 ? $s2 - $e2 : ($s2 + 1440 - $e2);
        }

        return max(0, $total);
    }

    /** @param array<string,mixed> $d */
    private function faixaDiaPlanilha(array $d): string
    {
        $ent1 = $this->fmtHora($d['ent1'] ?? null);
        $sai1 = $this->fmtHora($d['sai1'] ?? null);
        $ent2 = $this->fmtHora($d['ent2'] ?? null);
        $sai2 = $this->fmtHora($d['sai2'] ?? null);
        if ($ent1 === '' || $sai1 === '') {
            return '';
        }
        $txt = $ent1 . ' AS ' . $sai1;
        if ($ent2 !== '' && $sai2 !== '') {
            $txt .= ' E DAS ' . $ent2 . ' AS ' . $sai2;
        }

        return $txt;
    }

    /**
     * Texto estilo planilha: "SEGUNDA A QUINTA 07:00 AS 12:00 E DAS 13:00 AS 17:00 / SEXTA …"
     *
     * @param array<int,string> $faixas indice => faixa
     */
    private function textoHorarioPlanilha(array $faixas, string $tipo): string
    {
        if ($faixas === []) {
            return '';
        }
        if ($tipo === 'ciclico') {
            $partes = [];
            ksort($faixas);
            foreach ($faixas as $idx => $faixa) {
                if ($faixa !== '') {
                    $partes[] = 'DIA ' . $idx . ' ' . $faixa;
                }
            }

            return implode(' / ', $partes);
        }

        $nomes = [
            1 => 'SEGUNDA', 2 => 'TERÇA', 3 => 'QUARTA', 4 => 'QUINTA',
            5 => 'SEXTA', 6 => 'SÁBADO', 7 => 'DOMINGO',
        ];
        $grupos = [];
        $atualFaixa = null;
        $inicio = null;
        $fim = null;
        foreach ([1, 2, 3, 4, 5, 6, 7] as $idx) {
            $faixa = $faixas[$idx] ?? '';
            if ($faixa === '') {
                if ($atualFaixa !== null && $inicio !== null) {
                    $grupos[] = [$inicio, $fim, $atualFaixa];
                    $atualFaixa = null;
                    $inicio = null;
                    $fim = null;
                }
                continue;
            }
            if ($atualFaixa === $faixa) {
                $fim = $idx;
                continue;
            }
            if ($atualFaixa !== null && $inicio !== null) {
                $grupos[] = [$inicio, $fim, $atualFaixa];
            }
            $atualFaixa = $faixa;
            $inicio = $idx;
            $fim = $idx;
        }
        if ($atualFaixa !== null && $inicio !== null) {
            $grupos[] = [$inicio, $fim, $atualFaixa];
        }

        $partes = [];
        foreach ($grupos as [$i, $f, $faixa]) {
            $rotulo = $i === $f
                ? $nomes[$i]
                : ($nomes[$i] . ' A ' . $nomes[$f]);
            $partes[] = $rotulo . ' ' . $faixa;
        }

        return implode(' / ', $partes);
    }

    private function horaToMin(mixed $v): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (preg_match('/^(\d{1,2}):(\d{2})/', (string) $v, $m)) {
            return ((int) $m[1]) * 60 + (int) $m[2];
        }

        return null;
    }

    private function fmtHora(mixed $v): string
    {
        if ($v === null || $v === '') {
            return '';
        }
        $s = (string) $v;
        if (preg_match('/^(\d{1,2}):(\d{2})/', $s, $m)) {
            return sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
        }

        return '';
    }

    public function minToHoras(int $min): float
    {
        return round($min / 60, 2);
    }

    public static function fmtMin(int $min): string
    {
        $s = $min < 0 ? '-' : '';
        $min = abs($min);
        return $s . sprintf('%02d:%02d', intdiv($min, 60), $min % 60);
    }
}
