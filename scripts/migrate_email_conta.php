<?php

declare(strict_types=1);

/**
 * Cria/atualiza tabelas de conta de e-mail (OAuth + SMTP).
 * Uso: php scripts/migrate_email_conta.php
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use App\Helpers\Db;
use App\Install\Installer;

$pdo = Db::tryConnection();
if (!$pdo instanceof \PDO) {
    fwrite(STDERR, "MySQL indisponível.\n");
    exit(1);
}

$inst = new Installer($root);
$ref = new ReflectionClass($inst);
$m = $ref->getMethod('migrateEmail');
$m->invoke($inst, $pdo);

echo "OK migrateEmail\n";
foreach ($pdo->query("SHOW TABLES LIKE 'email_%'")->fetchAll(\PDO::FETCH_COLUMN) as $t) {
    echo " - {$t}\n";
}
echo "Migração conta de e-mail OK.\n";
