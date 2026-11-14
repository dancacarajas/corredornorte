<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Config\Database;
use App\Helpers\TextEncoding;

$pdo = Database::connection();
$tables = $pdo->query(
    "SELECT c.TABLE_NAME, c.COLUMN_NAME
     FROM information_schema.COLUMNS c
     INNER JOIN information_schema.TABLES t
       ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME
     WHERE c.TABLE_SCHEMA = DATABASE()
       AND t.TABLE_TYPE = 'BASE TABLE'
       AND c.DATA_TYPE IN ('varchar','text','mediumtext','longtext','char','tinytext')
     ORDER BY c.TABLE_NAME, c.ORDINAL_POSITION"
)->fetchAll();

$byTable = [];
foreach ($tables as $col) {
    $byTable[$col['TABLE_NAME']][] = $col['COLUMN_NAME'];
}

$fixed = 0;
foreach ($byTable as $table => $cols) {
    $pkRows = $pdo->query("SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'")->fetchAll();
    $pks = array_map(static fn(array $k): string => (string) $k['Column_name'], $pkRows);
    if ($pks === []) {
        continue;
    }

    $selectCols = array_unique(array_merge($pks, $cols));
    $sqlCols = implode(', ', array_map(static fn(string $c): string => "`{$c}`", $selectCols));
    $rows = $pdo->query("SELECT {$sqlCols} FROM `{$table}`")->fetchAll();

    foreach ($rows as $row) {
        $updates = [];
        $params = [];
        foreach ($cols as $col) {
            $val = $row[$col] ?? null;
            if (!is_string($val) || $val === '') {
                continue;
            }
            $clean = TextEncoding::ensureUtf8($val);
            if ($clean !== $val) {
                $updates[] = "`{$col}` = :u_{$col}";
                $params["u_{$col}"] = $clean;
            }
        }
        if ($updates === []) {
            continue;
        }
        $where = [];
        foreach ($pks as $pk) {
            $where[] = "`{$pk}` = :k_{$pk}";
            $params["k_{$pk}"] = $row[$pk];
        }
        $updSql = "UPDATE `{$table}` SET " . implode(', ', $updates) . ' WHERE ' . implode(' AND ', $where);
        $pdo->prepare($updSql)->execute($params);
        $fixed++;
        echo "Fixed {$table} (" . implode(',', array_map(static fn($p) => $p . '=' . $row[$p], $pks)) . ")\n";
    }
}

echo "Total rows repaired: {$fixed}\n";
