<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
$config = require $root . '/config/database.php';

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $config['host'], $config['port'], $config['database']),
    $config['username'],
    $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

function hasColumn(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c'
    );
    $stmt->execute(['t' => $table, 'c' => $column]);
    return (int) $stmt->fetchColumn() > 0;
}

$alters = [
    ['rh_lotacoes', 'percentual_he', 'SMALLINT UNSIGNED NOT NULL DEFAULT 50 AFTER calendario_id'],
    ['rh_colaborador_lotacoes', 'percentual_he', 'SMALLINT UNSIGNED NULL AFTER escala_ancora_data'],
    ['ponto_dias', 'percentual_he', 'SMALLINT UNSIGNED NOT NULL DEFAULT 50 AFTER he_100_min'],
];

foreach ($alters as [$table, $column, $def]) {
    if (!hasColumn($pdo, $table, $column)) {
        $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$def}");
        echo "Added {$table}.{$column}\n";
    } else {
        echo "OK {$table}.{$column}\n";
    }
}

echo "Migração percentual_he concluída.\n";
