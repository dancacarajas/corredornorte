<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use App\Config\Database;
use App\Services\CalculoPontoService;
use App\Services\MotorPontoService;

$espelhoId = (int) ($argv[1] ?? 3);
$pdo = Database::connection();

$row = $pdo->prepare(
    'SELECT e.id, e.colaborador_id, e.status, pc.ano, pc.mes
     FROM ponto_espelhos e
     INNER JOIN ponto_competencias pc ON pc.id = e.competencia_id
     WHERE e.id = :id'
);
$row->execute(['id' => $espelhoId]);
$esp = $row->fetch(PDO::FETCH_ASSOC);
if ($esp === false) {
    fwrite(STDERR, "Espelho não encontrado\n");
    exit(1);
}

// Reabre se necessário para regenerar
if (in_array($esp['status'], ['fechado', 'enviado', 'assinado', 'conferido'], true)) {
    $pdo->prepare("UPDATE ponto_espelhos SET status='em_tratamento' WHERE id=:id")->execute(['id' => $espelhoId]);
    echo "Status -> em_tratamento\n";
}

$motor = new MotorPontoService($pdo);
$calc = new CalculoPontoService($pdo);
$motor->gerarEspelho((int) $esp['colaborador_id'], (int) $esp['ano'], (int) $esp['mes'], 1);
$calc->aplicarBritanicoNoEspelho($espelhoId);
$calc->recalcularEspelho($espelhoId);

$dias = $pdo->prepare(
    "SELECT data, ent1, sai1, ent2, sai2, total_trab_min, horas_previstas_min, horas_falta_min, atraso_bruto_min, desconto_min, ok_status
     FROM ponto_dias WHERE espelho_id=:id AND tipo_dia='trabalho' ORDER BY data"
);
$dias->execute(['id' => $espelhoId]);
$rows = $dias->fetchAll(PDO::FETCH_ASSOC);

$signatures = [];
$dup = 0;
$badTotal = 0;
foreach ($rows as $d) {
    $sig = implode('|', [
        substr((string) $d['ent1'], 0, 5),
        substr((string) $d['sai1'], 0, 5),
        substr((string) $d['ent2'], 0, 5),
        substr((string) $d['sai2'], 0, 5),
    ]);
    if (isset($signatures[$sig])) {
        $dup++;
        echo "DUP {$d['data']} == {$signatures[$sig]} => {$sig}\n";
    }
    $signatures[$sig] = $d['data'];

    $prev = (int) $d['horas_previstas_min'];
    $trab = (int) $d['total_trab_min'];
    $okTotal = $prev === $trab;
    if (!$okTotal) {
        $badTotal++;
    }
    echo sprintf(
        "%s %s trab=%s prev=%s falta=%s atraso=%s ok=%s %s\n",
        $d['data'],
        $sig,
        $calc->fmt($trab),
        $calc->fmt($prev),
        $calc->fmt((int) $d['horas_falta_min']),
        $calc->fmt((int) $d['atraso_bruto_min']),
        $d['ok_status'],
        $okTotal ? 'TOTAL_OK' : 'TOTAL_FAIL'
    );
}

echo "dias=" . count($rows) . " duplicatas={$dup} totais_errados={$badTotal}\n";
exit(($dup === 0 && $badTotal === 0) ? 0 : 2);
