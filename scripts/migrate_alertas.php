<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Helpers\Db;
use App\Install\Installer;
use App\Services\AlertasService;
use App\Services\PerfilService;

$pdo = Db::tryConnection();
if (!$pdo instanceof PDO) {
    fwrite(STDERR, "MySQL indisponível.\n");
    exit(1);
}

(new Installer(dirname(__DIR__)))->migrateAlertas($pdo);
(new PerfilService($pdo))->ensureCatalog();
$sync = (new AlertasService($pdo))->sincronizarOperacional();
$tipos = count((new AlertasService($pdo))->listarTipos());
echo 'OK: alerta_eventos + tipos/destinos/config + alertas.gerenciar';
echo ' | tipos=' . $tipos;
echo ' | sync emitidos=' . (int) ($sync['emitidos'] ?? 0) . "\n";
