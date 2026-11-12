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

$cols = [
    'ent1' => 'TIME NULL',
    'sai1' => 'TIME NULL',
    'ent2' => 'TIME NULL',
    'sai2' => 'TIME NULL',
    'total_trab_min' => 'INT NOT NULL DEFAULT 0',
    'horas_previstas_min' => 'INT NOT NULL DEFAULT 0',
    'dia_falta' => 'TINYINT NOT NULL DEFAULT 0',
    'horas_falta_min' => 'INT NOT NULL DEFAULT 0',
    'atraso_bruto_min' => 'INT NOT NULL DEFAULT 0',
    'horas_extras_min' => 'INT NOT NULL DEFAULT 0',
    'he_50_min' => 'INT NOT NULL DEFAULT 0',
    'he_100_min' => 'INT NOT NULL DEFAULT 0',
    'adn_min' => 'INT NOT NULL DEFAULT 0',
    'desconto_min' => 'INT NOT NULL DEFAULT 0',
    'ok_status' => "ENUM('ok','pendente','folga','feriado','falta') NOT NULL DEFAULT 'pendente'",
];

foreach ($cols as $name => $def) {
    if (!hasColumn($pdo, 'ponto_dias', $name)) {
        $pdo->exec("ALTER TABLE ponto_dias ADD COLUMN {$name} {$def}");
        echo "Added ponto_dias.{$name}\n";
    }
}

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS ponto_justificativa_tipos (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      codigo VARCHAR(40) NOT NULL UNIQUE,
      nome VARCHAR(120) NOT NULL,
      abona_falta TINYINT(1) NOT NULL DEFAULT 0,
      status ENUM('ativo','inativo') NOT NULL DEFAULT 'ativo'
    ) ENGINE=InnoDB"
);

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS ponto_justificativas (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      espelho_id BIGINT UNSIGNED NOT NULL,
      ponto_dia_id BIGINT UNSIGNED NULL,
      tipo_id BIGINT UNSIGNED NOT NULL,
      data_inicio DATE NOT NULL,
      data_fim DATE NOT NULL,
      observacao TEXT NULL,
      anexo_path VARCHAR(255) NULL,
      usuario_id BIGINT UNSIGNED NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      CONSTRAINT fk_just_esp FOREIGN KEY (espelho_id) REFERENCES ponto_espelhos(id) ON DELETE CASCADE,
      CONSTRAINT fk_just_dia FOREIGN KEY (ponto_dia_id) REFERENCES ponto_dias(id) ON DELETE SET NULL,
      CONSTRAINT fk_just_tipo FOREIGN KEY (tipo_id) REFERENCES ponto_justificativa_tipos(id),
      CONSTRAINT fk_just_user FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
    ) ENGINE=InnoDB"
);

$pdo->exec(
    "INSERT INTO ponto_justificativa_tipos (codigo, nome, abona_falta, status) VALUES
    ('ATST', 'Atestado médico', 1, 'ativo'),
    ('DECL', 'Declaração', 1, 'ativo'),
    ('FOLG', 'Folga compensatória', 1, 'ativo'),
    ('FALTA', 'Falta injustificada', 0, 'ativo'),
    ('OUTROS', 'Outros', 0, 'ativo')
    ON DUPLICATE KEY UPDATE nome = VALUES(nome)"
);

echo "Migration OK\n";
