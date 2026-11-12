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
    'periodo_inicio' => 'DATE NULL',
    'periodo_fim' => 'DATE NULL',
    'layout_pdf' => 'SMALLINT UNSIGNED NOT NULL DEFAULT 0',
    'substituido_por_id' => 'BIGINT UNSIGNED NULL',
    'portal_token' => 'VARCHAR(64) NULL',
    'hash_md5' => 'CHAR(32) NULL',
    'hash_sha1' => 'CHAR(40) NULL',
    'hash_sha256' => 'CHAR(64) NULL',
    'arquivo_assinado_path' => 'VARCHAR(255) NULL',
    'arquivo_assinado_sha256' => 'CHAR(64) NULL',
    'assinatura_nome' => 'VARCHAR(160) NULL',
    'assinatura_cpf' => 'CHAR(11) NULL',
    'assinatura_nascimento' => 'DATE NULL',
    'assinatura_email' => 'VARCHAR(160) NULL',
    'assinatura_ip' => 'VARCHAR(64) NULL',
    'assinatura_user_agent' => 'VARCHAR(255) NULL',
    'assinatura_geo_json' => 'TEXT NULL',
    'assinatura_em' => 'DATETIME NULL',
    'assinatura_aceite' => 'TINYINT(1) NULL',
    'assinatura_texto_aceite' => 'TEXT NULL',
];

foreach ($add as $name => $def) {
    if (!in_array($name, $cols, true)) {
        $pdo->exec("ALTER TABLE documentos ADD COLUMN {$name} {$def}");
        echo "OK {$name}\n";
    } else {
        echo "skip {$name}\n";
    }
}

$idx = $pdo->query("SHOW INDEX FROM documentos WHERE Key_name='uk_documentos_portal_token'")->fetch();
if ($idx === false) {
    $pdo->exec('CREATE UNIQUE INDEX uk_documentos_portal_token ON documentos (portal_token)');
    echo "OK índice portal_token\n";
}

$pdo->exec('CREATE TABLE IF NOT EXISTS documentos_espelhos (
    documento_id BIGINT UNSIGNED NOT NULL,
    espelho_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (documento_id, espelho_id),
    KEY idx_de_espelho (espelho_id),
    CONSTRAINT fk_de_documento FOREIGN KEY (documento_id) REFERENCES documentos(id) ON DELETE CASCADE,
    CONSTRAINT fk_de_espelho FOREIGN KEY (espelho_id) REFERENCES ponto_espelhos(id)
) ENGINE=InnoDB');

echo "Migração assinatura eletrônica OK.\n";
