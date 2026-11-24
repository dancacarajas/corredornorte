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
            $apurado = $this->apurarTrabalho(
                $dia['ent1'] ?? null,
                $dia['sai1'] ?? null,
                $dia['ent2'] ?? null,
                $dia['sai2'] ?? null,
                isset($dia['previsto_intervalo_inicio']) ? (string) $dia['previsto_intervalo_inicio'] : null,
                isset($dia['previsto_intervalo_fim']) ? (string) $dia['previsto_intervalo_fim'] : null
            );
            $trab = $apurado['trab'];
            $adn = $apurado['adn'];
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

        // Justificativa que abona falta: dia justificado (não é falta, sem desconto).
        $justAbono = $this->justificativaAbonoDoDia((int) $dia['espelho_id'], (string) $dia['data']);
        if ($justAbono !== null) {
            // Garante UI coerente: sem batidas operacionais em dia abonado.
            if ($dia['ent1'] || $dia['sai1'] || $dia['ent2'] || $dia['sai2']) {
                $this->db->prepare(
                    'UPDATE ponto_dias SET ent1=NULL, sai1=NULL, ent2=NULL, sai2=NULL WHERE id=:id'
                )->execute(['id' => $pontoDiaId]);
                $this->db->prepare(
                    'DELETE FROM ponto_marcacoes WHERE ponto_dia_id=:d AND sequencia BETWEEN 11 AND 14'
                )->execute(['d' => $pontoDiaId]);
            }
            $rotulo = 'Justificado — ' . $justAbono['nome'];
            $payload = $this->zerado('ok', mb_substr($rotulo, 0, 120), $pctHe);
            $payload['horas_previstas_min'] = $prevMin;
            $this->persist($pontoDiaId, $payload);
            return;
        }

        $ent1 = $dia['ent1'] ?? null;
        $sai1 = $dia['sai1'] ?? null;
        $ent2 = $dia['ent2'] ?? null;
        $sai2 = $dia['sai2'] ?? null;

        $apurado = $this->apurarTrabalho(
            $ent1,
            $sai1,
            $ent2,
            $sai2,
            isset($dia['previsto_intervalo_inicio']) ? (string) $dia['previsto_intervalo_inicio'] : null,
            isset($dia['previsto_intervalo_fim']) ? (string) $dia['previsto_intervalo_fim'] : null
        );
        $trab = $apurado['trab'];
        $temBatida = $ent1 || $sai1 || $ent2 || $sai2;
        // Dia útil sem marcação e com jornada prevista = falta.
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

        // Tratativa do RH: se o colaborador cumpriu (ou excedeu) a jornada prevista
        // — inclusive com HE na saída — entrada alguns minutos depois não é atraso.
        // Também descarta jitter do horário britânico (≤N min).
        $variacaoMax = 3;
        try {
            $appCfg = require dirname(__DIR__, 2) . '/config/app.php';
            $variacaoMax = max(1, min(5, (int) ($appCfg['ponto']['variacao_minutos'] ?? 3)));
        } catch (Throwable) {
        }
        if ($atraso > 0 && ($trab >= $prevMin || $atraso <= $variacaoMax)) {
            $atraso = 0;
        }
        if ($temBatida && $faltaMin > 0 && $faltaMin <= $variacaoMax) {
            $faltaMin = 0;
            $diaFalta = 0;
        }

        $he = max(0, $trab - $prevMin);
        // Dia útil: excedente no % da lotação. Domingo → 100%.
        // Plantão em feriado (trabalha_feriado): CLT/planilha → HE 100% de todo o trabalhado.
        $plantaoFeriado = ($feriadoInfo !== null && $trabalhaFeriado && $trab > 0);
        if ($plantaoFeriado) {
            $he = $trab;
            $he100 = $trab;
            $heAdicional = 0;
        } else {
            $he100 = ($dow === 0) ? $he : 0;
            $heAdicional = ($dow === 0) ? 0 : $he;
        }
        $adn = $apurado['adn'];
        $desconto = $faltaMin;

        $atrasoRelevante = $atraso > self::TOLERANCIA_MIN;
        $faltaRelevante = $faltaMin > self::TOLERANCIA_MIN;
        $ok = ($diaFalta === 0 && !$atrasoRelevante && !$faltaRelevante)
            ? 'ok'
            : ($diaFalta ? 'falta' : 'pendente');
        $normalLabel = $plantaoFeriado
            ? mb_substr('Plantão em feriado — ' . (string) ($feriadoInfo['nome'] ?? 'Feriado'), 0, 120)
            : 'Normal';
        $resumo = $diaFalta
            ? 'Falta'
            : ($plantaoFeriado
                ? sprintf('%s +%s HE100', $normalLabel, $this->fmt($he))
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
             WHERE ponto_dia_id IN ({$in})
               AND ((origem = 'previsto' AND sequencia BETWEEN 1 AND 4)
                    OR (origem = 'gerado' AND sequencia BETWEEN 5 AND 8))"
        ) as $row) {
            $marcacoes[(int) $row['ponto_dia_id']][(string) $row['origem']][(int) $row['sequencia']] = substr((string) $row['horario'], 0, 5);
        }

        $espMeta = $this->db->prepare('SELECT id, data FROM ponto_dias WHERE id = :id');
        foreach ($ids as $diaId) {
            $espMeta->execute(['id' => $diaId]);
            $meta = $espMeta->fetch(PDO::FETCH_ASSOC) ?: [];
            // Não gera batida britânica em dia coberto por justificativa que abona falta.
            if ($this->justificativaAbonoDoDia($espelhoId, (string) ($meta['data'] ?? '')) !== null) {
                $this->recalcularDia($diaId);
                continue;
            }
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

    /**
     * Justificativa ativa que abona falta cobrindo a data do espelho.
     *
     * @return ?array{id:int,nome:string}
     */
    public function justificativaAbonoDoDia(int $espelhoId, string $data): ?array
    {
        if ($espelhoId <= 0 || $data === '') {
            return null;
        }
        try {
            $st = $this->db->prepare(
                "SELECT j.id, t.nome
                 FROM ponto_justificativas j
                 INNER JOIN ponto_justificativa_tipos t ON t.id = j.tipo_id
                 WHERE j.espelho_id = :e
                   AND :data BETWEEN j.data_inicio AND j.data_fim
                   AND t.abona_falta = 1
                   AND t.status = 'ativo'
                 ORDER BY j.id DESC
                 LIMIT 1"
            );
            $st->execute(['e' => $espelhoId, 'data' => $data]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                return null;
            }

            return [
                'id' => (int) $row['id'],
                'nome' => (string) ($row['nome'] ?? 'Justificativa'),
            ];
        } catch (\PDOException $e) {
            if ($e->getCode() === '42S02') {
                return null;
            }
            throw $e;
        }
    }

    /**
     * Aplica efeito operacional de justificativa que abona: limpa batidas do intervalo
     * e recalcula o espelho (dias ficam Justificado, sem falta/desconto).
     */
    public function aplicarJustificativaAbono(int $espelhoId, string $inicio, string $fim): void
    {
        if ($espelhoId <= 0 || $inicio === '' || $fim === '' || $inicio > $fim) {
            return;
        }
        $dias = $this->db->prepare(
            "SELECT id FROM ponto_dias
             WHERE espelho_id = :e AND tipo_dia = 'trabalho' AND data BETWEEN :i AND :f"
        );
        $dias->execute(['e' => $espelhoId, 'i' => $inicio, 'f' => $fim]);
        $ids = array_map('intval', $dias->fetchAll(PDO::FETCH_COLUMN) ?: []);
        if ($ids === []) {
            $this->recalcularEspelho($espelhoId);

            return;
        }
        $in = implode(',', $ids);
        $this->db->exec(
            "UPDATE ponto_dias
             SET ent1 = NULL, sai1 = NULL, ent2 = NULL, sai2 = NULL
             WHERE id IN ({$in})"
        );
        $this->db->exec(
            "DELETE FROM ponto_marcacoes
             WHERE ponto_dia_id IN ({$in}) AND sequencia BETWEEN 11 AND 14"
        );
        $this->recalcularEspelho($espelhoId);
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

    /**
     * Horas trabalhadas e ADN a partir das batidas.
     * Se a jornada do dia passa de 6h e o intervalo previsto cai dentro de uma batida
     * (almoço não separado em Sai.1/Ent.2), esse trecho sai da conta.
     * Batidas já separadas no almoço não sofrem segundo desconto.
     *
     * @return array{trab:int,adn:int}
     */
    private function apurarTrabalho(
        ?string $e1,
        ?string $s1,
        ?string $e2,
        ?string $s2,
        ?string $intIni,
        ?string $intFim
    ): array {
        $pares = ($e1 && $s2 && !$s1 && !$e2)
            ? [[$e1, $s2]]
            : [[$e1, $s1], [$e2, $s2]];

        $segs = [];
        $prevStart = null;
        foreach ($pares as [$ini, $fim]) {
            if (!$ini || !$fim) {
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
            $prevStart = $prevStart ?? $a;
            $segs[] = [$a, $b];
        }
        if ($segs === []) {
            return ['trab' => 0, 'adn' => 0];
        }

        usort($segs, static fn (array $x, array $y): int => $x[0] <=> $y[0]);
        $fundidos = [];
        foreach ($segs as [$a, $b]) {
            if ($fundidos === []) {
                $fundidos[] = [$a, $b];
                continue;
            }
            $ultimoIdx = count($fundidos) - 1;
            if ($a <= $fundidos[$ultimoIdx][1]) {
                $fundidos[$ultimoIdx][1] = max($fundidos[$ultimoIdx][1], $b);
                continue;
            }
            $fundidos[] = [$a, $b];
        }
        $segs = $fundidos;

        $primeiro = $segs[0][0];
        $ultimo = $segs[0][1];
        foreach ($segs as [$a, $b]) {
            $primeiro = min($primeiro, $a);
            $ultimo = max($ultimo, $b);
        }
        $intervalo = $this->faixaMinutos($intIni, $intFim);
        if (count($segs) === 1 && $intervalo !== null && ($ultimo - $primeiro) > 6 * 60) {
            $cortados = [];
            foreach ($segs as [$a, $b]) {
                foreach ($this->cortarIntervalo($a, $b, $intervalo[0], $intervalo[1]) as $parte) {
                    $cortados[] = $parte;
                }
            }
            $segs = $cortados;
        }

        $trab = 0;
        $adnRelogio = 0;
        $teveNoturno = false;
        foreach ($segs as [$a, $b]) {
            $trab += max(0, $b - $a);
            $adnSeg = $this->minutosNoturnosFaixa($a, $b, $teveNoturno);
            if ($adnSeg > 0) {
                $teveNoturno = true;
            }
            $adnRelogio += $adnSeg;
        }

        // Hora noturna reduzida CLT (Art. 73, § 1º): 52min30s equivalem a 60min (fator 60 / 52,5 = 8 / 7).
        $adn = $adnRelogio > 0 ? (int) round($adnRelogio * 8 / 7) : 0;

        return ['trab' => $trab, 'adn' => $adn];
    }

    /** @return array{0:int,1:int}|null */
    private function faixaMinutos(?string $ini, ?string $fim): ?array
    {
        $ini = trim((string) $ini);
        $fim = trim((string) $fim);
        if ($ini === '' || $fim === '') {
            return null;
        }
        $a = $this->toMin($ini);
        $b = $this->toMin($fim);
        if ($b === $a) {
            return null;
        }
        if ($b < $a) {
            $b += 1440;
        }

        return [$a, $b];
    }

    /**
     * @return list<array{0:int,1:int}>
     */
    private function cortarIntervalo(int $a, int $b, int $i1, int $i2): array
    {
        $partes = [[$a, $b]];
        $janelas = [[$i1, $i2]];
        if ($i1 < 1440) {
            $janelas[] = [$i1 + 1440, $i2 + 1440];
        }
        foreach ($janelas as [$c, $d]) {
            $novas = [];
            foreach ($partes as [$x, $y]) {
                $ini = max($x, $c);
                $fim = min($y, $d);
                if ($fim <= $ini) {
                    $novas[] = [$x, $y];
                    continue;
                }
                if ($ini > $x) {
                    $novas[] = [$x, $ini];
                }
                if ($fim < $y) {
                    $novas[] = [$fim, $y];
                }
            }
            $partes = $novas;
        }

        return array_values(array_filter(
            $partes,
            static fn (array $p): bool => $p[1] > $p[0]
        ));
    }

    /**
     * Faixa noturna urbana CLT: 22:00 às 05:00, com prorrogação após 05:00 (art. 73 §5º / Súmula 60, II TST).
     * $a/$b já estão na linha do tempo (saída após meia-noite vem com +1440).
     */
    private function minutosNoturnosFaixa(int $a, int $b, bool $teveNoturnoAntes = false): int
    {
        if ($b === $a) {
            return 0;
        }
        if ($b < $a) {
            $b += 1440;
        }
        $noturnoDia0 = $this->overlapMin($a, $b, 0, self::NOTURNO_FIM);
        $noturnoVirada = $this->overlapMin($a, $b, self::NOTURNO_INI, 24 * 60 + self::NOTURNO_FIM);
        $total = $noturnoDia0 + $noturnoVirada;

        $fimNoturnoVirada = 24 * 60 + self::NOTURNO_FIM;
        if (($noturnoVirada > 0 || $teveNoturnoAntes) && $b > $fimNoturnoVirada) {
            $total += $this->overlapMin($a, $b, $fimNoturnoVirada, 24 * 60 + self::NOTURNO_INI);
        } elseif ($noturnoDia0 > 0 && $a <= 2 * 60 && $b > self::NOTURNO_FIM) {
            $total += $this->overlapMin($a, $b, self::NOTURNO_FIM, self::NOTURNO_INI);
        }

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
