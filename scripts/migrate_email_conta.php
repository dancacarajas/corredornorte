<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Helpers\Db;

$pdo = Db::tryConnection();
if (!$pdo instanceof PDO) {
    fwrite(STDERR, "MySQL indisponível.\n");
    exit(1);
}

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS email_oauth_config (
        provedor ENUM('gmail','microsoft') NOT NULL,
        client_id VARCHAR(255) NOT NULL DEFAULT '',
        client_secret_enc TEXT NULL,
        tenant VARCHAR(64) NULL,
        redirect_uri VARCHAR(512) NULL,
        atualizado_em DATETIME NULL,
        PRIMARY KEY (provedor)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);
echo "OK email_oauth_config\n";

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS email_contas (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        provedor ENUM('gmail','microsoft') NOT NULL,
        email VARCHAR(255) NOT NULL,
        nome_exibicao VARCHAR(160) NULL,
        access_token_enc TEXT NOT NULL,
        refresh_token_enc TEXT NULL,
        token_expires_at DATETIME NULL,
        scopes TEXT NULL,
        status ENUM('conectado','expirado','erro','desconectado') NOT NULL DEFAULT 'conectado',
        ativo TINYINT(1) NOT NULL DEFAULT 1,
        ultimo_erro TEXT NULL,
        conectado_por INT UNSIGNED NULL,
        conectado_em DATETIME NOT NULL,
        atualizado_em DATETIME NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uk_email_conta_provedor_email (provedor, email),
        KEY idx_email_contas_ativo (ativo, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);
echo "OK email_contas\n";

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS email_envios_log (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        conta_id BIGINT UNSIGNED NULL,
        provedor ENUM('gmail','microsoft','local','php_mail') NULL,
        destinatario VARCHAR(255) NOT NULL,
        assunto VARCHAR(255) NOT NULL,
        status ENUM('enviado','erro') NOT NULL,
        erro_mensagem TEXT NULL,
        criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_email_envios_log_conta (conta_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);
echo "OK email_envios_log\n";

echo "Migração conta de e-mail OK.\n";
