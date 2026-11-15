<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Helpers\Db;
use App\Install\Installer;

$pdo = Db::tryConnection();
if (!$pdo instanceof PDO) {
    fwrite(STDERR, "MySQL indisponível.\n");
    exit(1);
}

$installer = new Installer(dirname(__DIR__));
$installer->migrateCobrancaAssinatura($pdo);
echo "OK cobranca_envios + cron.secret\n";
