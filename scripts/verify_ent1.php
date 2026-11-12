<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Config\Database;
use App\Services\CalculoPontoService;
use App\Services\MotorPontoService;

$pdo = Database::connection();
$ano = (int) ($argv[1] ?? 2026);
$mes = (int) ($argv[2] ?? 10);

// Reabre espelhos abertos o bastante para regenerar
$pdo->exec(
    "UPDATE ponto_espelhos e
     INNER JOIN ponto_competencias pc ON pc.id = e.competencia_id
     SET e.status = 'em_tratamento'
     WHERE pc.ano = {$ano} AND pc.mes = {$mes}
       AND e.status IN ('em_aberto','em_tratamento','conferido')"
);

$motor = new MotorPontoService($pdo);
$calc = new CalculoPontoService($pdo);
$motor->gerarCompetenciaParaAtivos($ano, $mes);

$espelhos = $pdo->query(
    "SELECT e.id, c.nome, c.matricula
     FROM ponto_espelhos e
     INNER JOIN rh_colaboradores c ON c.id = e.colaborador_id
     INNER JOIN ponto_competencias pc ON pc.id = e.competencia_id
     WHERE pc.ano={$ano} AND pc.mes={$mes}
     ORDER BY c.nome"
)->fetchAll(PDO::FETCH_ASSOC);

$fail = 0;
foreach ($espelhos as $esp) {
    $id = (int) $esp['id'];
    try {
        $calc->aplicarBritanicoNoEspelho($id);
    } catch (Throwable $e) {
        echo "skip {$esp['matricula']}: {$e->getMessage()}\n";
        continue;
    }

    $dias = $pdo->prepare(
        "SELECT data, ent1, sai1, ent2, sai2, total_trab_min, horas_previstas_min
         FROM ponto_dias WHERE espelho_id=:id AND tipo_dia='trabalho' ORDER BY data"
    );
    $dias->execute(['id' => $id]);
    $rows = $dias->fetchAll(PDO::FETCH_ASSOC);

    echo "=== {$esp['nome']} ({$esp['matricula']}) ===\n";
    $prevEnt1 = null;
    $seqDup = 0;
    foreach ($rows as $d) {
        $ent1 = substr((string) $d['ent1'], 0, 5);
        $sig = implode('|', [
            $ent1,
            substr((string) $d['sai1'], 0, 5),
            substr((string) $d['ent2'], 0, 5),
            substr((string) $d['sai2'], 0, 5),
        ]);
        $same = $prevEnt1 !== null && $ent1 === $prevEnt1;
        if ($same) {
            $seqDup++;
            $fail++;
        }
        $totOk = (int) $d['total_trab_min'] === (int) $d['horas_previstas_min'];
        if (!$totOk) {
            $fail++;
        }
        echo sprintf(
            "%s ENT1=%s %s trab=%02d:%02d%s%s\n",
            $d['data'],
            $ent1,
            $sig,
            intdiv((int) $d['total_trab_min'], 60),
            (int) $d['total_trab_min'] % 60,
            $same ? ' ENT1_DUP' : '',
            $totOk ? '' : ' TOTAL_FAIL'
        );
        $prevEnt1 = $ent1;
    }
    echo "dup_sequenciais={$seqDup}\n\n";
}

echo $fail === 0 ? "VERIFY OK\n" : "VERIFY FAIL count={$fail}\n";
exit($fail === 0 ? 0 : 2);
