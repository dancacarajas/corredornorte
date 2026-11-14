<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use App\Services\CalculoPontoService;
use App\Services\FolhaPontoService;
use App\Services\MotorPontoService;

$config = require $root . '/config/database.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $config['host'], $config['port'], $config['database']),
    $config['username'],
    $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$ini = '2026-11-01';
$fim = '2026-11-30';

echo "== Gerar período {$ini} a {$fim}\n";
$motor = new MotorPontoService($pdo);
$calc = new CalculoPontoService($pdo);
$motor->gerarPeriodoParaAtivos($ini, $fim);

$ids = $pdo->prepare(
    'SELECT e.id, c.nome, c.matricula, e.status
     FROM ponto_espelhos e
     INNER JOIN rh_colaboradores c ON c.id = e.colaborador_id
     INNER JOIN ponto_periodos pp ON pp.id = e.periodo_id
     WHERE pp.data_inicio=:i AND pp.data_fim=:f
     ORDER BY e.id LIMIT 5'
);
$ids->execute(['i' => $ini, 'f' => $fim]);
$rows = $ids->fetchAll();
if ($rows === []) {
    fwrite(STDERR, "Nenhum espelho gerado\n");
    exit(1);
}

foreach ($rows as $row) {
    $id = (int) $row['id'];
    echo "Espelho #{$id} {$row['matricula']} {$row['nome']} status={$row['status']}\n";
    $calc->aplicarBritanicoNoEspelho($id);
    $calc->recalcularEspelho($id);

    $sample = $pdo->prepare(
        "SELECT data, tipo_dia, ent1, sai1, ent2, sai2, horas_previstas_min, dia_falta, horas_falta_min, ok_status
         FROM ponto_dias WHERE espelho_id=:id ORDER BY data LIMIT 3"
    );
    $sample->execute(['id' => $id]);
    foreach ($sample->fetchAll() as $d) {
        echo "  {$d['data']} {$d['tipo_dia']} ent1={$d['ent1']} ok={$d['ok_status']}\n";
    }
}

$folha = new FolhaPontoService($pdo);
$pdf = $folha->gerarPdf((int) $rows[0]['id'], 1, $ini, $fim);
echo "PDF gerado: " . ($pdf['arquivo_path'] ?? '?') . "\n";
echo "OK\n";
