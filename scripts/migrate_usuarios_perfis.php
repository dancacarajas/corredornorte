<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Helpers\Db;

$pdo = Db::tryConnection();
if (!$pdo instanceof PDO) {
    fwrite(STDERR, "MySQL indisponível.\n");
    exit(1);
}

$cols = $pdo->query('SHOW COLUMNS FROM perfis')->fetchAll(PDO::FETCH_COLUMN);
$addPerfil = [
    'descricao' => 'VARCHAR(255) NULL',
    'status' => "ENUM('ativo','inativo') NOT NULL DEFAULT 'ativo'",
    'sistema' => 'TINYINT(1) NOT NULL DEFAULT 0',
    'created_at' => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    'updated_at' => 'DATETIME NULL ON UPDATE CURRENT_TIMESTAMP',
];
foreach ($addPerfil as $name => $def) {
    if (!in_array($name, $cols, true)) {
        $pdo->exec("ALTER TABLE perfis ADD COLUMN {$name} {$def}");
        echo "OK perfis.{$name}\n";
    }
}

$pdo->exec("UPDATE perfis SET sistema = 1 WHERE codigo = 'admin'");
$pdo->exec("UPDATE perfis SET descricao = COALESCE(descricao, 'Acesso total ao sistema') WHERE codigo = 'admin'");
$pdo->exec("UPDATE perfis SET descricao = COALESCE(descricao, 'Gestão de RH, ponto e folha') WHERE codigo = 'rh'");
$pdo->exec("UPDATE perfis SET descricao = COALESCE(descricao, 'Acompanhamento de equipe e ponto') WHERE codigo = 'gestor'");

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS permissoes (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        codigo VARCHAR(80) NOT NULL,
        nome VARCHAR(120) NOT NULL,
        grupo VARCHAR(60) NOT NULL,
        descricao VARCHAR(255) NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uk_permissoes_codigo (codigo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);
echo "OK permissoes\n";

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS perfil_permissoes (
        perfil_id BIGINT UNSIGNED NOT NULL,
        permissao_id BIGINT UNSIGNED NOT NULL,
        PRIMARY KEY (perfil_id, permissao_id),
        CONSTRAINT fk_pp_perfil FOREIGN KEY (perfil_id) REFERENCES perfis(id) ON DELETE CASCADE,
        CONSTRAINT fk_pp_permissao FOREIGN KEY (permissao_id) REFERENCES permissoes(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);
echo "OK perfil_permissoes\n";

$catalog = [
    ['dashboard.ver', 'Ver dashboard', 'Principal', 'Acesso à visão geral'],
    ['efetivo.ver', 'Ver efetivo', 'Efetivo', 'Listar colaboradores e estrutura'],
    ['efetivo.editar', 'Editar efetivo', 'Efetivo', 'Criar/editar colaboradores e lotações'],
    ['cadastros.ver', 'Ver cadastros', 'Cadastros', 'Empresas, jornadas, escalas, etc.'],
    ['cadastros.editar', 'Editar cadastros', 'Cadastros', 'Alterar cadastros base'],
    ['ponto.ver', 'Ver ponto', 'Ponto', 'Consultar espelhos de ponto'],
    ['ponto.editar', 'Editar ponto', 'Ponto', 'Gerar e ajustar batidas'],
    ['folha.ver', 'Ver folha', 'Folha', 'Consultar folhas e PDFs'],
    ['folha.enviar', 'Enviar/assinar folha', 'Folha', 'Enviar e-mail e gerar link de assinatura'],
    ['email.gerenciar', 'Conta de e-mail', 'Sistema', 'Conectar Gmail/Microsoft'],
    ['importacoes.gerenciar', 'Importações', 'Sistema', 'Importar planilha de efetivo'],
    ['usuarios.gerenciar', 'Gerenciar usuários', 'Sistema', 'Criar e editar usuários'],
    ['perfis.gerenciar', 'Gerenciar perfis', 'Sistema', 'Criar perfis e permissões'],
];

$ins = $pdo->prepare(
    'INSERT INTO permissoes (codigo, nome, grupo, descricao)
     VALUES (:c, :n, :g, :d)
     ON DUPLICATE KEY UPDATE nome = VALUES(nome), grupo = VALUES(grupo), descricao = VALUES(descricao)'
);
foreach ($catalog as [$c, $n, $g, $d]) {
    $ins->execute(['c' => $c, 'n' => $n, 'g' => $g, 'd' => $d]);
}
echo 'OK seed permissoes (' . count($catalog) . ")\n";

$map = [
    'admin' => array_column($catalog, 0),
    'rh' => [
        'dashboard.ver', 'efetivo.ver', 'efetivo.editar', 'cadastros.ver', 'cadastros.editar',
        'ponto.ver', 'ponto.editar', 'folha.ver', 'folha.enviar', 'email.gerenciar', 'importacoes.gerenciar',
    ],
    'gestor' => [
        'dashboard.ver', 'efetivo.ver', 'ponto.ver', 'folha.ver',
    ],
];

foreach ($map as $perfilCodigo => $perms) {
    $perfilId = (int) $pdo->query("SELECT id FROM perfis WHERE codigo=" . $pdo->quote($perfilCodigo))->fetchColumn();
    if ($perfilId <= 0) {
        continue;
    }
    foreach ($perms as $codigo) {
        $permId = (int) $pdo->query('SELECT id FROM permissoes WHERE codigo=' . $pdo->quote($codigo))->fetchColumn();
        if ($permId <= 0) {
            continue;
        }
        $pdo->prepare(
            'INSERT IGNORE INTO perfil_permissoes (perfil_id, permissao_id) VALUES (?, ?)'
        )->execute([$perfilId, $permId]);
    }
    echo "OK permissões perfil {$perfilCodigo}\n";
}

// Garante vínculo admin
$pdo->exec(
    "INSERT IGNORE INTO usuario_perfis (usuario_id, perfil_id)
     SELECT u.id, p.id FROM usuarios u
     CROSS JOIN perfis p
     WHERE u.email = 'admin@arcadis.local' AND p.codigo = 'admin'"
);

echo "Migração usuários/perfis OK.\n";
