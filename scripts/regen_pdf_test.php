<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use App\Config\Database;
use App\Services\CalculoPontoService;
use App\Services\FolhaPontoService;

$pdo = Database::connection();
$calc = new CalculoPontoService($pdo);
$calc->aplicarBritanicoNoEspelho(2);
$calc->recalcularEspelho(2);
$pdo->prepare("UPDATE ponto_espelhos SET status='fechado' WHERE id=2")->execute();

$doc = (new FolhaPontoService($pdo))->gerarPdf(2, 1);
$abs = $root . '/' . $doc['path'];
echo json_encode($doc, JSON_PRETTY_PRINT) . PHP_EOL;
echo 'exists=' . (is_file($abs) ? '1' : '0') . ' size=' . (is_file($abs) ? filesize($abs) : 0) . PHP_EOL;
echo 'header=' . (is_file($abs) ? substr((string) file_get_contents($abs, false, null, 0, 8), 0, 8) : '') . PHP_EOL;

// sample day status
$rows = $pdo->query(
    "SELECT data, ok_status, atraso_bruto_min, ent1, sai2 FROM ponto_dias WHERE espelho_id=2 AND tipo_dia='trabalho' LIMIT 3"
)->fetchAll(PDO::FETCH_ASSOC);
print_r($rows);
