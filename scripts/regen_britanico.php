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
    'SELECT e.id, e.colaborador_id, e.status, pp.data_inicio, pp.data_fim
     FROM ponto_espelhos e
     INNER JOIN ponto_periodos pp ON pp.id = e.periodo_id
     WHERE e.id = :id'
);
$row->execute(['id' => $espelhoId]);
$esp = $row->fetch(PDO::FETCH_ASSOC);
if ($esp === false) {
    fwrite(STDERR, "Espelho não encontrado\n");
    exit(1);
}

if (in_array($esp['status'], ['fechado', 'enviado', 'assinado', 'conferido'], true)) {
    $pdo->prepare("UPDATE ponto_espelhos SET status='em_tratamento' WHERE id=:id")->execute(['id' => $espelhoId]);
    echo "Status -> em_tratamento\n";
}

$motor = new MotorPontoService($pdo);
$calc = new CalculoPontoService($pdo);
$motor->gerarEspelho((int) $esp['colaborador_id'], (string) $esp['data_inicio'], (string) $esp['data_fim'], 1);
$calc->aplicarBritanicoNoEspelho($espelhoId);
$calc->recalcularEspelho($espelhoId);

echo "Regen OK espelho={$espelhoId} periodo={$esp['data_inicio']}..{$esp['data_fim']}\n";
