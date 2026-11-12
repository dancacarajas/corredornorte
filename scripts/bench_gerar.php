<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Config\Database;
use App\Services\CalculoPontoService;
use App\Services\MotorPontoService;

$pdo = Database::connection();
$motor = new MotorPontoService($pdo);
$calc = new CalculoPontoService($pdo);

$t0 = microtime(true);
$motor->gerarCompetenciaParaAtivos(2026, 11);
$ids = $pdo->query(
    "SELECT e.id FROM ponto_espelhos e
     INNER JOIN ponto_competencias pc ON pc.id = e.competencia_id
     WHERE pc.ano=2026 AND pc.mes=11"
)->fetchAll(PDO::FETCH_COLUMN);
foreach ($ids as $id) {
    $calc->aplicarBritanicoNoEspelho((int) $id);
}
$elapsed = microtime(true) - $t0;
echo sprintf("espelhos=%d tempo=%.2fs\n", count($ids), $elapsed);
