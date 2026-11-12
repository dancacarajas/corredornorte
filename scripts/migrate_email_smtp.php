<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Helpers\Db;

$pdo = Db::tryConnection();
if (!$pdo instanceof PDO) {
    fwrite(STDERR, "MySQL indisponível.\n");
    exit(1);
}

$cols = $pdo->query('SHOW COLUMNS FROM email_contas')->fetchAll(PDO::FETCH_COLUMN);
$add = [
    'auth_mode' => "ENUM('oauth','smtp') NOT NULL DEFAULT 'oauth'",
    'smtp_host' => 'VARCHAR(190) NULL',
    'smtp_port' => 'SMALLINT UNSIGNED NULL',
    'smtp_encryption' => "ENUM('tls','ssl','none') NULL",
    'smtp_user' => 'VARCHAR(255) NULL',
    'password_enc' => 'TEXT NULL',
];

foreach ($add as $name => $def) {
    if (!in_array($name, $cols, true)) {
        $pdo->exec("ALTER TABLE email_contas ADD COLUMN {$name} {$def}");
        echo "OK {$name}\n";
    } else {
        echo "skip {$name}\n";
    }
}

// OAuth tokens podem ficar vazios em conta SMTP
try {
    $pdo->exec('ALTER TABLE email_contas MODIFY access_token_enc TEXT NULL');
    echo "OK access_token_enc nullable\n";
} catch (Throwable $e) {
    echo 'skip access_token_enc: ' . $e->getMessage() . "\n";
}

echo "Migração SMTP e-mail OK.\n";
