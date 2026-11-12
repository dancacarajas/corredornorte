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

$ano = 2026;
$mes = 11;

echo "== Gerar competência {$mes}/{$ano}\n";
$motor = new MotorPontoService($pdo);
$calc = new CalculoPontoService($pdo);
$motor->gerarCompetenciaParaAtivos($ano, $mes);

$ids = $pdo->prepare(
    'SELECT e.id, c.nome, c.matricula, e.status
     FROM ponto_espelhos e
     INNER JOIN rh_colaboradores c ON c.id = e.colaborador_id
     INNER JOIN ponto_competencias pc ON pc.id = e.competencia_id
     WHERE pc.ano=:a AND pc.mes=:m
     ORDER BY e.id LIMIT 5'
);
$ids->execute(['a' => $ano, 'm' => $mes]);
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
         FROM ponto_dias WHERE espelho_id=:id ORDER BY data LIMIT 8"
    );
    $sample->execute(['id' => $id]);
    foreach ($sample->fetchAll() as $d) {
        echo sprintf(
            "  %s %-12s e1=%s s1=%s e2=%s s2=%s prev=%d falta=%d ok=%s\n",
            $d['data'],
            $d['tipo_dia'],
            $d['ent1'] ? substr((string) $d['ent1'], 0, 5) : '--:--',
            $d['sai1'] ? substr((string) $d['sai1'], 0, 5) : '--:--',
            $d['ent2'] ? substr((string) $d['ent2'], 0, 5) : '--:--',
            $d['sai2'] ? substr((string) $d['sai2'], 0, 5) : '--:--',
            (int) $d['horas_previstas_min'],
            (int) $d['dia_falta'],
            $d['ok_status']
        );
    }
}

$espelhoId = (int) $rows[0]['id'];
echo "== Fechar espelho #{$espelhoId}\n";
$motor->alterarStatus($espelhoId, 'conferido', 'verify', 1);
$motor->alterarStatus($espelhoId, 'fechado', 'verify', 1);

echo "== Gerar PDF\n";
$folha = new FolhaPontoService($pdo);
$doc = $folha->gerarPdf($espelhoId, 1);
echo "PDF: {$doc['path']} codigo={$doc['codigo']}\n";
$abs = $root . '/' . $doc['path'];
echo 'Size: ' . (is_file($abs) ? filesize($abs) : 0) . " bytes\n";

echo "== Enviar e-mail (pode falhar se mail desabilitado)\n";
try {
    $result = $folha->enviarDocumento($doc['documento_id']);
    echo 'Email ok=' . ($result['ok'] ? '1' : '0') . ' erro=' . ($result['erro'] ?? '') . "\n";
} catch (Throwable $e) {
    echo 'Email exception: ' . $e->getMessage() . "\n";
}

echo "VERIFY OK\n";
