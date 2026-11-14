<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Helpers\Db;

$pdo = Db::tryConnection();
if (!$pdo instanceof PDO) {
    fwrite(STDERR, "MySQL indisponível.\n");
    exit(1);
}

$sql = file_get_contents(dirname(__DIR__) . '/database/schema_relatorio_assinatura.sql');
if ($sql === false) {
    fwrite(STDERR, "Schema da assinatura não encontrado.\n");
    exit(1);
}
$pdo->exec($sql);
echo "Migração assinatura de relatório OK.\n";
