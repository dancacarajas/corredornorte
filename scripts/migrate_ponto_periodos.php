<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use App\Install\Installer;

$config = require $root . '/config/database.php';

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $config['host'], $config['port'], $config['database']),
    $config['username'],
    $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$installer = new Installer($root);
$installer->migratePontoPeriodos($pdo);

$periodos = (int) $pdo->query('SELECT COUNT(*) FROM ponto_periodos')->fetchColumn();
$espelhos = (int) $pdo->query('SELECT COUNT(*) FROM ponto_espelhos')->fetchColumn();
$temComp = (int) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ponto_competencias'"
)->fetchColumn();

echo "Migração ponto por período OK.\n";
echo "ponto_periodos={$periodos} ponto_espelhos={$espelhos} ponto_competencias_existe=" . ($temComp > 0 ? 'sim' : 'nao') . "\n";
