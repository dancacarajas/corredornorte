<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Config\Database;
use App\Services\CalculoPontoService;
use App\Services\MotorPontoService;

$pdo = Database::connection();
$ini = (string) ($argv[1] ?? '2026-10-01');
$fim = (string) ($argv[2] ?? '2026-10-31');

$pdo->prepare(
    "UPDATE ponto_espelhos e
     INNER JOIN ponto_periodos pp ON pp.id = e.periodo_id
     SET e.status = 'em_tratamento'
     WHERE pp.data_inicio = ? AND pp.data_fim = ?
       AND e.status IN ('em_aberto','em_tratamento','conferido')"
)->execute([$ini, $fim]);

$motor = new MotorPontoService($pdo);
$calc = new CalculoPontoService($pdo);
$motor->gerarPeriodoParaAtivos($ini, $fim);

$espelhos = $pdo->prepare(
    "SELECT e.id, c.nome, c.matricula
     FROM ponto_espelhos e
     INNER JOIN rh_colaboradores c ON c.id = e.colaborador_id
     INNER JOIN ponto_periodos pp ON pp.id = e.periodo_id
     WHERE pp.data_inicio=? AND pp.data_fim=?
     ORDER BY c.nome"
);
$espelhos->execute([$ini, $fim]);
$rows = $espelhos->fetchAll(PDO::FETCH_ASSOC);

$fail = 0;
foreach ($rows as $esp) {
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
    $prevEnt1 = null;
    foreach ($dias->fetchAll(PDO::FETCH_ASSOC) as $dia) {
        $ent1 = $dia['ent1'] ?? null;
        if ($ent1 === null || $ent1 === '') {
            continue;
        }
        if ($prevEnt1 !== null && $ent1 === $prevEnt1) {
            echo "FAIL {$esp['matricula']} {$dia['data']}: ENT1 repetido {$ent1}\n";
            $fail++;
        }
        $prevEnt1 = $ent1;
    }
}

echo $fail === 0 ? "OK ENT1 sem repetição consecutiva ({$ini}..{$fim})\n" : "FAIL={$fail}\n";
exit($fail === 0 ? 0 : 1);
