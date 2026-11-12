<?php

declare(strict_types=1);

/**
 * Aplica schemas + seeds e gera hash real do admin (admin123).
 * Uso: php scripts/install_db.php
 */

$root = dirname(__DIR__);
$config = require $root . '/config/database.php';

$host = $config['host'];
$port = $config['port'];
$user = $config['username'];
$pass = $config['password'];
$dbName = $config['database'];

function execSqlFile(PDO $pdo, string $file): void
{
    $sql = file_get_contents($file);
    if ($sql === false) {
        throw new RuntimeException("Não leu {$file}");
    }

    // PDO MySQL nem sempre aceita multi-query; dividir por ';'
    $parts = preg_split('/;\s*\n/', $sql) ?: [];
    foreach ($parts as $part) {
        $stmt = trim($part);
        if ($stmt === '' || str_starts_with($stmt, '--')) {
            // pode ter comentários iniciais; ainda assim tenta se houver SQL
            $clean = trim(preg_replace('/^--.*$/m', '', $stmt) ?? '');
            if ($clean === '') {
                continue;
            }
            $stmt = $clean;
        }
        if ($stmt === '') {
            continue;
        }
        $pdo->exec($stmt);
    }
    echo 'OK: ' . basename($file) . PHP_EOL;
}

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $host, $port),
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
        ]
    );

    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `{$dbName}`");

    // Drop e recria para instalação limpa local
    $pdo->exec("DROP DATABASE IF EXISTS `{$dbName}`");
    $pdo->exec("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `{$dbName}`");

    foreach ([
        $root . '/database/schema_efetivo.sql',
        $root . '/database/schema_ponto_folha.sql',
        $root . '/database/seeds.sql',
    ] as $file) {
        $sql = file_get_contents($file);
        if ($sql === false) {
            throw new RuntimeException("Não leu {$file}");
        }
        $pdo->exec($sql);
        echo 'OK: ' . basename($file) . PHP_EOL;
    }

    $hash = password_hash('admin123', PASSWORD_BCRYPT);
    $stmt = $pdo->prepare('UPDATE usuarios SET senha_hash = :h WHERE email = :e');
    $stmt->execute(['h' => $hash, 'e' => 'admin@arcadis.local']);
    echo "OK: admin@arcadis.local / admin123" . PHP_EOL;
    echo "Database ready." . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'ERRO: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
