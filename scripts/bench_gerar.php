<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Config\Database;
use App\Services\CalculoPontoService;
use App\Services\MotorPontoService;

$pdo = Database::connection();
$motor = new MotorPontoService($pdo);
$calc = new CalculoPontoService($pdo);

$ini = '2026-11-01';
$fim = '2026-11-30';

$t0 = microtime(true);
$motor->gerarPeriodoParaAtivos($ini, $fim);
$ids = $pdo->prepare(
    "SELECT e.id FROM ponto_espelhos e
     INNER JOIN ponto_periodos pp ON pp.id = e.periodo_id
     WHERE pp.data_inicio=? AND pp.data_fim=?"
);
$ids->execute([$ini, $fim]);
$list = $ids->fetchAll(PDO::FETCH_COLUMN);
foreach ($list as $id) {
    $calc->aplicarBritanicoNoEspelho((int) $id);
}
$elapsed = microtime(true) - $t0;
echo sprintf("espelhos=%d tempo=%.2fs\n", count($list), $elapsed);
