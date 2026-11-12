<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use PDO;

/**
 * Cálculos da tela de gestão (referência operacional).
 */
final class CalculoPontoService
{
    private const TOLERANCIA_MIN = 5;
    private const NOTURNO_INI = 22 * 60;
    private const NOTURNO_FIM = 5 * 60; // 05:00 do dia seguinte

    private PDO $db;

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
        $feriadoInfo = $this->resolverFeriadoDia($dia);
        if ($feriadoInfo !== null) {
            $tipo = 'feriado';
            if ((string) $dia['tipo_dia'] !== 'feriado') {
                $this->db->prepare('UPDATE ponto_dias SET tipo_dia = \'feriado\' WHERE id = :id')
                    ->execute(['id' => $pontoDiaId]);
            }
        }

        $prevMin = $this->minutosPrevistos($dia);

        if (in_array($tipo, ['folga', 'folga_escala'], true)) {
            $this->persist($pontoDiaId, $this->zerado('folga', 'Folga'));
            return;
        }

        if ($tipo === 'feriado') {
            // Trabalho em feriado (nacional/estadual/municipal) = HE 100% + ADN
            $ent1 = $dia['ent1'] ?? null;
            $sai1 = $dia['sai1'] ?? null;
            $ent2 = $dia['ent2'] ?? null;
            $sai2 = $dia['sai2'] ?? null;
            $trab = $this->minutosTrabalhados($ent1, $sai1, $ent2, $sai2);
            $adn = $this->minutosNoturnos($ent1, $sai1) + $this->minutosNoturnos($ent2, $sai2);
            $nomeFer = $feriadoInfo['nome'] ?? 'Feriado';
            if ($trab <= 0) {
                $this->persist($pontoDiaId, $this->zerado('feriado', (string) $nomeFer));
                return;
            }
            $this->persist($pontoDiaId, [
                'total_trab_min' => $trab,
                'horas_previstas_min' => 0,
                'dia_falta' => 0,
                'horas_falta_min' => 0,
                'atraso_bruto_min' => 0,
                'horas_extras_min' => $trab,
                'he_50_min' => 0,
                'he_100_min' => $trab,
                'adn_min' => $adn,
                'desconto_min' => 0,
                'ok_status' => 'ok',
                'ocorrencia_resumo' => sprintf('%s +%s HE100', $nomeFer, $this->fmt($trab)),
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
            $atraso = max(0, $this->toMin((string) $ent1) - $this->toMin((string) $dia['previsto_entrada']));
        }

        // Variação ≤3 min com jornada fechada: sem atraso/falta artificiais
        if ($trab === $prevMin && $atraso <= 3) {
            $atraso = 0;
            $faltaMin = 0;
            $diaFalta = 0;
        }

        $he = max(0, $trab - $prevMin);
        $dow = (int) date('w', strtotime((string) $dia['data']));
        $he100 = ($dow === 0) ? $he : 0; // domingo = HE 100%
        $he50 = ($dow === 0) ? 0 : $he;
        $adn = $this->minutosNoturnos($ent1, $sai1) + $this->minutosNoturnos($ent2, $sai2);
        $desconto = $faltaMin;

        $atrasoRelevante = $atraso > self::TOLERANCIA_MIN;
        $faltaRelevante = $faltaMin > self::TOLERANCIA_MIN;
        $ok = ($diaFalta === 0 && !$atrasoRelevante && !$faltaRelevante) ? 'ok' : ($diaFalta ? 'falta' : 'pendente');
        $resumo = $diaFalta
            ? 'Falta'
            : ($he > self::TOLERANCIA_MIN
                ? sprintf('+%s HE%s', $this->fmt($he), $he100 > 0 ? '100' : '50')
                : ($atrasoRelevante ? 'Atraso' : ($adn > 0 ? 'ADN' : 'Normal')));

        $this->persist($pontoDiaId, [
            'total_trab_min' => $trab,
            'horas_previstas_min' => $prevMin,
            'dia_falta' => $diaFalta,
            'horas_falta_min' => $faltaMin,
            'atraso_bruto_min' => $atraso,
            'horas_extras_min' => $he,
            'he_50_min' => $he50,
            'he_100_min' => $he100,
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

        $this->atualizarTotaisEspelho($espelhoId);
        $this->db->prepare("UPDATE ponto_espelhos SET status='em_tratamento' WHERE id=:id AND status='em_aberto'")
            ->execute(['id' => $espelhoId]);
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

        $cfg = (new LotacaoResolver($this->db))->resolve($colaboradorId, $data);
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
    private function zerado(string $ok, string $resumo): array
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
            'adn_min' => 0,
            'desconto_min' => 0,
            'ok_status' => $ok,
            'ocorrencia_resumo' => $resumo,
        ];
    }

    /** @param array<string,mixed> $dia */
    private function minutosPrevistos(array $dia): int
    {
        if (empty($dia['previsto_entrada']) || empty($dia['previsto_saida'])) {
            return (int) ($dia['horas_previstas_min'] ?? 0);
        }
        $ini = $this->toMin((string) $dia['previsto_entrada']);
        $fim = $this->toMin((string) $dia['previsto_saida']);
        $bruto = max(0, $fim - $ini);
        if (!empty($dia['previsto_intervalo_inicio']) && !empty($dia['previsto_intervalo_fim'])) {
            $bruto -= max(0, $this->toMin((string) $dia['previsto_intervalo_fim']) - $this->toMin((string) $dia['previsto_intervalo_inicio']));
        }
        return $bruto;
    }

    private function minutosTrabalhados(?string $e1, ?string $s1, ?string $e2, ?string $s2): int
    {
        $t = 0;
        if ($e1 && $s1) {
            $t += max(0, $this->toMin($s1) - $this->toMin($e1));
        }
        if ($e2 && $s2) {
            $t += max(0, $this->toMin($s2) - $this->toMin($e2));
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
