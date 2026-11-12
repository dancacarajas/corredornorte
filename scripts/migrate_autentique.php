<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Helpers\Db;

$pdo = Db::tryConnection();
if (!$pdo instanceof PDO) {
    fwrite(STDERR, "MySQL indisponível.\n");
    exit(1);
}

$cols = $pdo->query('SHOW COLUMNS FROM documentos')->fetchAll(PDO::FETCH_COLUMN);
$add = [
    'portal_token' => 'VARCHAR(64) NULL',
    'autentique_document_id' => 'VARCHAR(64) NULL',
    'autentique_signature_id' => 'VARCHAR(64) NULL',
    'autentique_link' => 'VARCHAR(500) NULL',
    'autentique_status' => 'VARCHAR(40) NULL',
    'autentique_enviado_em' => 'DATETIME NULL',
    'autentique_assinado_em' => 'DATETIME NULL',
];

foreach ($add as $name => $def) {
    if (!in_array($name, $cols, true)) {
        $pdo->exec("ALTER TABLE documentos ADD COLUMN {$name} {$def}");
        echo "OK coluna {$name}\n";
    } else {
        echo "skip {$name}\n";
    }
}

$idx = $pdo->query("SHOW INDEX FROM documentos WHERE Key_name='uk_documentos_portal_token'")->fetch();
if ($idx === false) {
    $pdo->exec('CREATE UNIQUE INDEX uk_documentos_portal_token ON documentos (portal_token)');
    echo "OK índice portal_token\n";
}

echo "Migração Autentique concluída.\n";
