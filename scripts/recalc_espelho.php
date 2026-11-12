<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Config\Database;
use App\Services\CalculoPontoService;

$id = (int) ($argv[1] ?? 3);
$pdo = Database::connection();
(new CalculoPontoService($pdo))->recalcularEspelho($id);

$rows = $pdo->prepare(
    "SELECT data, ent1, sai2, he_50_min, he_100_min, adn_min, horas_extras_min
     FROM ponto_dias WHERE espelho_id=:id AND tipo_dia='trabalho' ORDER BY data LIMIT 5"
);
$rows->execute(['id' => $id]);
foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo sprintf(
        "%s e1=%s s2=%s he50=%d he100=%d adn=%d he=%d\n",
        $r['data'],
        $r['ent1'] ? substr((string) $r['ent1'], 0, 5) : '--',
        $r['sai2'] ? substr((string) $r['sai2'], 0, 5) : '--',
        (int) $r['he_50_min'],
        (int) $r['he_100_min'],
        (int) $r['adn_min'],
        (int) $r['horas_extras_min']
    );
}
echo "OK #{$id}\n";
