<?php

declare(strict_types=1);

/**
 * Aplica migração de conta/perfil/avatar/reset em ambiente já instalado.
 * Uso: php scripts/migrate_conta_usuario.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Helpers\Db;
use App\Install\Installer;
use App\Services\ContaService;

$pdo = Db::tryConnection();
if (!$pdo instanceof PDO) {
    fwrite(STDERR, "MySQL indisponível.\n");
    exit(1);
}

$installer = new Installer(dirname(__DIR__));
$installer->migrateContaUsuario($pdo);
(new ContaService($pdo))->ensureSchema();

echo "OK: migração conta/usuário aplicada.\n";
