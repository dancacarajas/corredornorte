<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use PDO;
use Throwable;

/**
 * Cálculos da tela de gestão (referência operacional).
 */
final class CalculoPontoService
{
    private const TOLERANCIA_MIN = 5;
    private const NOTURNO_INI = 22 * 60;
    private const NOTURNO_FIM = 5 * 60; // 05:00 do dia seguinte

    private PDO $db;
    /** @var array<string,array<string,mixed>|null> */
    private array $cfgCache = [];

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    public function recalcularDia(int $pontoDiaId): void
    {
        $stmt = $this->db->prepare(
            'SELECT d.*, e.colaborador_id
             FROM ponto_dias d
             INNER JOIN ponto_espelhos e ON e.id = d.espelho_id
             WHERE d.id = :id'
        );
        $stmt->execute(['id' => $pontoDiaId]);
        $dia = $stmt->fetch();
        if ($dia === false) {
            return;
        }

        $tipo = (string) $dia['tipo_dia'];
        $cfgDia = $this->resolverCfgDia($dia);
        $trabalhaFeriado = !empty($cfgDia['trabalha_feriado']);
        $feriadoInfo = $this->resolverFeriadoDia($dia);
        if ($feriadoInfo !== null && !$trabalhaFeriado) {
            $tipo = 'feriado';
            if ((string) $dia['tipo_dia'] !== 'feriado') {
                $this->db->prepare('UPDATE ponto_dias SET tipo_dia = \'feriado\' WHERE id = :id')
                    ->execute(['id' => $pontoDiaId]);
            }
        }

        $prevMin = $this->minutosPrevistos($dia);
        $pctHe = $this->percentualHeDoDia($dia);
        $dow = (int) date('w', strtotime((string) $dia['data']));

        // Folga/DSR ou feriado com batida: todo o tempo vira HE.
        // HE 100% SOMENTE domingo (w=0) ou feriado. Sábado e demais folgas → % da lotação/colaborador.
        if (in_array($tipo, ['folga', 'folga_escala', 'feriado'], true)) {
            $ent1 = $dia['ent1'] ?? null;
            $sai1 = $dia['sai1'] ?? null;
            $ent2 = $dia['ent2'] ?? null;
            $sai2 = $dia['sai2'] ?? null;
            $trab = $this->minutosTrabalhados($ent1, $sai1, $ent2, $sai2);
            $adn = $this->minutosNoturnos($ent1, $sai1) + $this->minutosNoturnos($ent2, $sai2);
            $rotulo = $tipo === 'feriado'
                ? (string) ($feriadoInfo['nome'] ?? 'Feriado')
                : match (true) {
                    $tipo === 'folga_escala' => 'Folga de escala',
                    $dow === 6 => 'Folga (sábado)',
                    $dow === 0 => 'Folga (domingo)',
                    default => 'Folga',
                };

            if ($trab <= 0) {
                $this->persist($pontoDiaId, $this->zerado(
                    $tipo === 'feriado' ? 'feriado' : 'folga',
                    $rotulo,
                    $pctHe
                ));
                return;
            }

            $he100 = ($tipo === 'feriado' || $dow === 0) ? $trab : 0;
            $heAdicional = $he100 > 0 ? 0 : $trab;
            $rotuloHe = $he100 > 0 ? 'HE100' : ('HE' . $pctHe);

            $this->persist($pontoDiaId, [
                'total_trab_min' => $trab,
                'horas_previstas_min' => 0,
                'dia_falta' => 0,
                'horas_falta_min' => 0,
                'atraso_bruto_min' => 0,
                'horas_extras_min' => $trab,
                'he_50_min' => $heAdicional,
                'he_100_min' => $he100,
                'percentual_he' => $pctHe,
                'adn_min' => $adn,
                'desconto_min' => 0,
                'ok_status' => 'ok',
                'ocorrencia_resumo' => sprintf('%s trabalhado +%s %s', $rotulo, $this->fmt($trab), $rotuloHe),
            ]);
            return;
        }

        $ent1 = $dia['ent1'] ?? null;
        $sai1 = $dia['sai1'] ?? null;
        $ent2 = $dia['ent2'] ?? null;
        $sai2 = $dia['sai2'] ?? null;

        $trab = $this->minutosTrabalhados($ent1, $sai1, $ent2, $sai2);
        $temBatida = $ent1 || $sai1 || $ent2 || $sai2;
        $diaFalta = (!$temBatida && $prevMin > 0) ? 1 : 0;
        $faltaMin = $diaFalta ? $prevMin : max(0, $prevMin - $trab);

        $atraso = 0;
        if ($ent1 && !empty($dia['previsto_entrada'])) {
            $diffAtraso = $this->toMin((string) $ent1) - $this->toMin((string) $dia['previsto_entrada']);
            if ($diffAtraso < -720) {
                $diffAtraso += 1440;
            } elseif ($diffAtraso > 720) {
                $diffAtraso -= 1440;
            }
            $atraso = max(0, $diffAtraso);
        }

        // Variação ≤3 min com jornada fechada: sem atraso/falta artificiais
        if ($trab === $prevMin && $atraso <= 3) {
            $atraso = 0;
            $faltaMin = 0;
            $diaFalta = 0;
        }

        $abonado = false;
        if ($diaFalta === 1 || $faltaMin > 0) {
            try {
                $jStmt = $this->db->prepare(
                    'SELECT 1 FROM ponto_justificativas j
                     INNER JOIN ponto_justificativa_tipos t ON t.id = j.tipo_id
                     WHERE j.espelho_id = :e AND :data BETWEEN j.data_inicio AND j.data_fim AND t.abona_falta = 1
                     LIMIT 1'
                );
                $jStmt->execute(['e' => (int) $dia['espelho_id'], 'data' => (string) $dia['data']]);
                if ($jStmt->fetchColumn() !== false) {
                    $diaFalta = 0;
                    $faltaMin = 0;
                    $atraso = 0;
                    $abonado = true;
                }
            } catch (\PDOException $e) {
                if ($e->getCode() !== '42S02') {
                    throw $e;
                }
            }
        }

        $he = max(0, $trab - $prevMin);
        // Dia útil (ex.: 07–17 com saída 19): excedente = HE no % configurado.
        // HE 100% somente domingo; feriado já cai no bloco de descanso acima.
        $he100 = ($dow === 0) ? $he : 0;
        $heAdicional = ($dow === 0) ? 0 : $he;
        $adn = $this->minutosNoturnos($ent1, $sai1) + $this->minutosNoturnos($ent2, $sai2);
        $desconto = $faltaMin;

        $atrasoRelevante = $atraso > self::TOLERANCIA_MIN;
        $faltaRelevante = $faltaMin > self::TOLERANCIA_MIN;
        $ok = ($abonado || ($diaFalta === 0 && !$atrasoRelevante && !$faltaRelevante))
            ? 'ok'
            : ($diaFalta ? 'falta' : 'pendente');
        $normalLabel = ($feriadoInfo !== null && $trabalhaFeriado)
            ? mb_substr('Plantão em feriado — ' . (string) ($feriadoInfo['nome'] ?? 'Feriado'), 0, 120)
            : 'Normal';
        $resumo = $abonado
            ? ($he > self::TOLERANCIA_MIN
                ? sprintf('Justificado +%s HE%s', $this->fmt($he), $he100 > 0 ? '100' : (string) $pctHe)
                : 'Justificado')
            : ($diaFalta
                ? 'Falta'
                : ($he > self::TOLERANCIA_MIN
                    ? sprintf('+%s HE%s', $this->fmt($he), $he100 > 0 ? '100' : (string) $pctHe)
                    : ($atrasoRelevante ? 'Atraso' : ($adn > 0 ? 'ADN' : $normalLabel))));

        $this->persist($pontoDiaId, [
            'total_trab_min' => $trab,
            'horas_previstas_min' => $prevMin,
            'dia_falta' => $diaFalta,
            'horas_falta_min' => $faltaMin,
            'atraso_bruto_min' => $atraso,
            'horas_extras_min' => $he,
            'he_50_min' => $heAdicional,
            'he_100_min' => $he100,
            'percentual_he' => $pctHe,
            'adn_min' => $adn,
            'desconto_min' => $desconto,
            'ok_status' => $ok,
            'ocorrencia_resumo' => $resumo,
        ]);
    }

    public function recalcularEspelho(int $espelhoId): void
    {
        $dias = $this->db->prepare('SELECT id FROM ponto_dias WHERE espelho_id = :id');
        $dias->execute(['id' => $espelhoId]);
        foreach ($dias->fetchAll() as $d) {
            $this->recalcularDia((int) $d['id']);
        }
        $this->atualizarTotaisEspelho($espelhoId);
    }

    /**
     * @param array{ent1?:?string,sai1?:?string,ent2?:?string,sai2?:?string} $batidas
     */
    public function salvarBatidas(int $pontoDiaId, array $batidas, string $origem = 'manual', bool $recalcularEspelho = true): void
    {
        $norm = static function (?string $v): ?string {
            $v = trim((string) $v);
            if ($v === '' || $v === '--:--') {
                return null;
            }
            if (preg_match('/^\d{2}:\d{2}$/', $v)) {
                return $v . ':00';
            }
            return $v;
        };

        $ent1 = $norm($batidas['ent1'] ?? null);
        $sai1 = $norm($batidas['sai1'] ?? null);
        $ent2 = $norm($batidas['ent2'] ?? null);
        $sai2 = $norm($batidas['sai2'] ?? null);

        $this->db->prepare(
            'UPDATE ponto_dias SET ent1=:e1, sai1=:s1, ent2=:e2, sai2=:s2 WHERE id=:id'
        )->execute(['e1' => $ent1, 's1' => $sai1, 'e2' => $ent2, 's2' => $sai2, 'id' => $pontoDiaId]);

        $this->db->prepare('DELETE FROM ponto_marcacoes WHERE ponto_dia_id=:d AND sequencia BETWEEN 11 AND 14')
            ->execute(['d' => $pontoDiaId]);
        $ins = $this->db->prepare(
            'INSERT INTO ponto_marcacoes (ponto_dia_id, sequencia, horario, origem) VALUES (:d,:s,:h,:o)'
        );
        foreach ([11 => $ent1, 12 => $sai1, 13 => $ent2, 14 => $sai2] as $seq => $hora) {
            if ($hora) {
                $ins->execute(['d' => $pontoDiaId, 's' => $seq, 'h' => $hora, 'o' => $origem]);
            }
        }

        $this->recalcularDia($pontoDiaId);
        if (!$recalcularEspelho) {
            return;
        }

        $esp = $this->db->prepare('SELECT espelho_id FROM ponto_dias WHERE id=:id');
        $esp->execute(['id' => $pontoDiaId]);
        $espelhoId = (int) $esp->fetchColumn();
        if ($espelhoId > 0) {
            $this->atualizarTotaisEspelho($espelhoId);
            $this->db->prepare("UPDATE ponto_espelhos SET status='em_tratamento' WHERE id=:id AND status='em_aberto'")
                ->execute(['id' => $espelhoId]);
        }
    }

    public function aplicarBritanicoNoEspelho(int $espelhoId): void
    {
        $dias = $this->db->prepare("SELECT id FROM ponto_dias WHERE espelho_id=:id AND tipo_dia='trabalho'");
        $dias->execute(['id' => $espelhoId]);
        $diaRows = $dias->fetchAll();
        if ($diaRows === []) {
            return;
        }

        $ids = array_map(static fn(array $d): int => (int) $d['id'], $diaRows);
        $in = implode(',', $ids);
        $marcacoes = [];
        foreach ($this->db->query(
            "SELECT ponto_dia_id, sequencia, horario, origem
             FROM ponto_marcacoes
             WHERE ponto_dia_id IN ({$in}) AND origem IN ('gerado','previsto')"
        ) as $row) {
            $marcacoes[(int) $row['ponto_dia_id']][(string) $row['origem']][(int) $row['sequencia']] = substr((string) $row['horario'], 0, 5);
        }

        foreach ($ids as $diaId) {
            $map = [];
            foreach ($marcacoes[$diaId]['gerado'] ?? [] as $seq => $hora) {
                $slot = $seq >= 5 ? $seq - 4 : $seq;
                $map[$slot] = $hora;
            }
            if ($map === []) {
                foreach ($marcacoes[$diaId]['previsto'] ?? [] as $seq => $hora) {
                    $map[$seq] = $hora;
                }
            }
            $this->salvarBatidas($diaId, [
                'ent1' => $map[1] ?? null,
                'sai1' => $map[2] ?? null,
                'ent2' => $map[3] ?? null,
                'sai2' => $map[4] ?? null,
            ], 'gerado', false);
        }

        // Folga/feriado não passam pelo britânico; garante ok_status correto.
        $outros = $this->db->prepare(
            "SELECT id FROM ponto_dias
             WHERE espelho_id = :id AND tipo_dia IN ('folga','folga_escala','feriado')"
        );
        $outros->execute(['id' => $espelhoId]);
        foreach ($outros->fetchAll(PDO::FETCH_COLUMN) ?: [] as $outroId) {
            $this->recalcularDia((int) $outroId);
        }

        $this->atualizarTotaisEspelho($espelhoId);
        $this->db->prepare("UPDATE ponto_espelhos SET status='em_tratamento' WHERE id=:id AND status='em_aberto'")
            ->execute(['id' => $espelhoId]);

        // HE autorizadas pelo gestor devem sobreviver à regeneração do espelho.
        try {
            (new HeAutorizacaoService($this->db))->reaplicarAprovadasNoEspelho($espelhoId);
        } catch (Throwable) {
            // Tabela ainda não migrada ou falha pontual não bloqueia o britânico.
        }
    }

    public function atualizarTotaisEspelho(int $espelhoId): void
    {
        $tot = $this->db->prepare(
            'SELECT
                COALESCE(SUM(horas_extras_min),0) he,
                COALESCE(SUM(horas_falta_min),0) falta,
                COALESCE(SUM(atraso_bruto_min),0) atraso,
                COALESCE(SUM(adn_min),0) adn
             FROM ponto_dias WHERE espelho_id = :id'
        );
        $tot->execute(['id' => $espelhoId]);
        $t = $tot->fetch() ?: [];
        $this->db->prepare(
            'UPDATE ponto_espelhos
             SET total_he_min = :he, total_falta_min = :falta, total_atraso_min = :atraso, total_adn_min = :adn
             WHERE id = :id'
        )->execute([
            'he' => (int) ($t['he'] ?? 0),
            'falta' => (int) ($t['falta'] ?? 0),
            'atraso' => (int) ($t['atraso'] ?? 0),
            'adn' => (int) ($t['adn'] ?? 0),
            'id' => $espelhoId,
        ]);
    }

    /**
     * @param array<string,mixed> $dia
     * @return array<string,mixed>|null
     */
    private function resolverCfgDia(array $dia): ?array
    {
        $colaboradorId = (int) ($dia['colaborador_id'] ?? 0);
        $data = (string) ($dia['data'] ?? '');
        if ($colaboradorId <= 0 || $data === '') {
            return null;
        }
        $key = $colaboradorId . '|' . $data;
        if (!array_key_exists($key, $this->cfgCache)) {
            $this->cfgCache[$key] = (new LotacaoResolver($this->db))->resolveParaGeracao($colaboradorId, $data);
        }
        return $this->cfgCache[$key];
    }

    /**
     * Confere calendário composto (nacional + estadual + municipal) do colaborador na data.
     *
     * @param array<string,mixed> $dia
     * @return array<string,mixed>|null
     */
    private function resolverFeriadoDia(array $dia): ?array
    {
        $colaboradorId = (int) ($dia['colaborador_id'] ?? 0);
        $data = (string) ($dia['data'] ?? '');
        if ($colaboradorId <= 0 || $data === '') {
            return (string) ($dia['tipo_dia'] ?? '') === 'feriado' ? ['nome' => 'Feriado'] : null;
        }

        $cfg = $this->resolverCfgDia($dia);
        $feriados = $cfg['feriados'] ?? [];
        if ($feriados === []) {
            return null;
        }

        $primeiro = $feriados[0];
        $escopo = (string) ($primeiro['escopo'] ?? '');
        $nome = (string) ($primeiro['nome'] ?? 'Feriado');
        $label = match ($escopo) {
            'nacional' => 'Feriado nacional',
            'estadual' => 'Feriado estadual',
            'municipal' => 'Feriado municipal',
            default => 'Feriado',
        };

        return [
            'nome' => $label . ' — ' . $nome,
            'escopo' => $escopo,
            'feriados' => $feriados,
        ];
    }

    /** @return array<string,int|string> */
    private function zerado(string $ok, string $resumo, int $percentualHe = 50): array
    {
        return [
            'total_trab_min' => 0,
            'horas_previstas_min' => 0,
            'dia_falta' => 0,
            'horas_falta_min' => 0,
            'atraso_bruto_min' => 0,
            'horas_extras_min' => 0,
            'he_50_min' => 0,
            'he_100_min' => 0,
            'percentual_he' => $percentualHe,
            'adn_min' => 0,
            'desconto_min' => 0,
            'ok_status' => $ok,
            'ocorrencia_resumo' => $resumo,
        ];
    }

    /** @param array<string,mixed> $dia */
    private function percentualHeDoDia(array $dia): int
    {
        $cfg = $this->resolverCfgDia($dia);
        if (is_array($cfg)) {
            $pct = (int) ($cfg['percentual_he'] ?? 50);
            if ($pct >= 1 && $pct <= 200) {
                return $pct;
            }
        }
        $stored = (int) ($dia['percentual_he'] ?? 50);
        return $stored >= 1 && $stored <= 200 ? $stored : 50;
    }

    /** @param array<string,mixed> $dia */
    private function minutosPrevistos(array $dia): int
    {
        if (empty($dia['previsto_entrada']) || empty($dia['previsto_saida'])) {
            return (int) ($dia['horas_previstas_min'] ?? 0);
        }
        $ini = $this->toMin((string) $dia['previsto_entrada']);
        $fim = $this->toMin((string) $dia['previsto_saida']);
        if ($fim < $ini) {
            $fim += 1440;
        }
        $bruto = max(0, $fim - $ini);
        if (!empty($dia['previsto_intervalo_inicio']) && !empty($dia['previsto_intervalo_fim'])) {
            $intIni = $this->toMin((string) $dia['previsto_intervalo_inicio']);
            $intFim = $this->toMin((string) $dia['previsto_intervalo_fim']);
            if ($intFim < $intIni) {
                $intFim += 1440;
            }
            $bruto = max(0, $bruto - max(0, $intFim - $intIni));
        }
        return $bruto;
    }

    private function minutosTrabalhados(?string $e1, ?string $s1, ?string $e2, ?string $s2): int
    {
        $t = 0;
        if ($e1 && $s1) {
            $a = $this->toMin($e1);
            $b = $this->toMin($s1);
            if ($b < $a) {
                $b += 1440;
            }
            $t += max(0, $b - $a);
        }
        if ($e2 && $s2) {
            $a = $this->toMin($e2);
            $b = $this->toMin($s2);
            if ($b < $a) {
                $b += 1440;
            }
            $t += max(0, $b - $a);
        }
        return $t;
    }

    /**
     * Faixa noturna urbana CLT: 22:00 às 05:00.
     */
    private function minutosNoturnos(?string $ini, ?string $fim): int
    {
        if (!$ini || !$fim) {
            return 0;
        }
        $a = $this->toMin($ini);
        $b = $this->toMin($fim);
        if ($b <= $a) {
            $b += 24 * 60;
        }

        $total = 0;
        // janela do mesmo dia 22:00-24:00
        $total += $this->overlapMin($a, $b, self::NOTURNO_INI, 24 * 60);
        // janela madrugada 00:00-05:00
        $total += $this->overlapMin($a, $b, 0, self::NOTURNO_FIM);
        // se cruza meia-noite: 24:00-29:00 (=05:00 D+1)
        $total += $this->overlapMin($a, $b, 24 * 60, 24 * 60 + self::NOTURNO_FIM);
        return $total;
    }

    private function overlapMin(int $a1, int $a2, int $b1, int $b2): int
    {
        $ini = max($a1, $b1);
        $fim = min($a2, $b2);
        return max(0, $fim - $ini);
    }

    private function toMin(string $hora): int
    {
        $p = explode(':', $hora);
        return ((int) ($p[0] ?? 0)) * 60 + ((int) ($p[1] ?? 0));
    }

    public function fmt(int $min): string
    {
        $sign = $min < 0 ? '-' : '';
        $min = abs($min);
        return sprintf('%s%02d:%02d', $sign, intdiv($min, 60), $min % 60);
    }

    /** @param array<string,int|string> $data */
    private function persist(int $id, array $data): void
    {
        $sets = [];
        foreach (array_keys($data) as $k) {
            $sets[] = "{$k} = :{$k}";
        }
        $data['id'] = $id;
        $this->db->prepare('UPDATE ponto_dias SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($data);
    }
}
