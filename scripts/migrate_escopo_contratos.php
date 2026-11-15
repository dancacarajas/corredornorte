<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Helpers\Db;
use App\Helpers\EscopoAcesso;
use App\Install\Installer;

$pdo = Db::tryConnection();
if (!$pdo instanceof PDO) {
    fwrite(STDERR, "MySQL indisponível.\n");
    exit(1);
}

(new Installer(dirname(__DIR__)))->migrateEscopoContratos($pdo);
EscopoAcesso::ensureSchema($pdo);
echo "OK: usuario_contratos\n";
