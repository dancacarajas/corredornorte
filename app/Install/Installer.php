<?php

declare(strict_types=1);

namespace App\Install;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class Installer
{
    public const LOCK_FILE = 'storage/install.lock';
    public const DB_LOCAL = 'config/database.local.php';
    public const APP_LOCAL = 'config/app.local.php';

    public function __construct(private readonly string $root)
    {
    }

    public function isInstalled(): bool
    {
        return is_file($this->root . '/' . self::LOCK_FILE)
            && is_file($this->root . '/' . self::DB_LOCAL);
    }

    /**
     * @param array{
     *   host:string,port:string,database:string,username:string,password:string,
     *   app_url?:string,app_name?:string
     * } $input
     * @return array{admin_email:string,admin_password:string,messages:list<string>}
     */
    public function install(array $input): array
    {
        if ($this->isInstalled()) {
            throw new RuntimeException('O sistema já está instalado.');
        }

        $host = trim($input['host'] ?? '');
        $port = trim($input['port'] ?? '3306');
        $database = trim($input['database'] ?? '');
        $username = trim($input['username'] ?? '');
        $password = (string) ($input['password'] ?? '');
        $appUrl = rtrim(trim($input['app_url'] ?? ''), '/');
        $appName = trim($input['app_name'] ?? 'Corredor Norte RH');

        if ($host === '' || $database === '' || $username === '') {
            throw new RuntimeException('Host, banco e usuário são obrigatórios.');
        }
        if ($password === '') {
            throw new RuntimeException('Informe a senha do banco de dados.');
        }

        $messages = [];
        try {
            $pdo = $this->connect($host, $port, $username, $password, $database);
        } catch (RuntimeException $e) {
            throw new RuntimeException(
                'Não foi possível conectar ao banco "' . $database . '". Confirme senha e se o banco já existe no painel Hostinger. '
                . $e->getMessage(),
                previous: $e
            );
        }
        $messages[] = 'Conexão MySQL OK (' . $database . ')';

        foreach ([
            'database/schema_efetivo.sql',
            'database/schema_ponto_folha.sql',
        ] as $rel) {
            $this->execSqlFile($pdo, $this->root . '/' . $rel, $database);
            $messages[] = 'Schema: ' . basename($rel);
        }

        $this->applyPontoGestao($pdo);
        $messages[] = 'Schema: gestão de ponto';

        $this->execSqlFile($pdo, $this->root . '/database/seeds.sql', $database);
        $messages[] = 'Seeds iniciais';

        $this->migrateAssinatura($pdo);
        $messages[] = 'Migração: assinatura eletrônica';

        $this->migrateEmail($pdo);
        $messages[] = 'Migração: e-mail';

        $this->migrateUsuariosPerfis($pdo);
        $messages[] = 'Migração: usuários e perfis';

        $adminPassword = 'admin123';
        $hash = password_hash($adminPassword, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare('UPDATE usuarios SET senha_hash = :h WHERE email = :e');
        $stmt->execute(['h' => $hash, 'e' => 'admin@arcadis.local']);
        $messages[] = 'Usuário admin preparado';

        $this->ensureDirectories();
        $this->writeDatabaseLocal($host, $port, $database, $username, $password);
        $this->writeAppLocal($appUrl, $appName);
        $this->writeLock($database, $username);

        $messages[] = 'Configuração gravada e instalador bloqueado';

        return [
            'admin_email' => 'admin@arcadis.local',
            'admin_password' => $adminPassword,
            'messages' => $messages,
        ];
    }

    private function connect(
        string $host,
        string $port,
        string $username,
        string $password,
        ?string $database
    ): PDO {
        $dsn = $database === null
            ? sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $host, $port)
            : sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $database);

        try {
            return new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException('Falha na conexão MySQL: ' . $e->getMessage(), previous: $e);
        }
    }

    private function execSqlFile(PDO $pdo, string $file, string $database): void
    {
        $sql = file_get_contents($file);
        if ($sql === false) {
            throw new RuntimeException('Não foi possível ler ' . $file);
        }

        // Remove CREATE DATABASE (pode ser multilinha) e qualquer USE — no shared hosting
        // o banco já existe e o usuário só tem acesso ao DB informado.
        $sql = preg_replace('/\bCREATE\s+DATABASE\b.*?;/is', '', $sql) ?? $sql;
        $sql = preg_replace('/\bUSE\s+[`\'"]?[a-zA-Z0-9_]+[`\'"]?\s*;/i', '', $sql) ?? $sql;
        $sql = str_ireplace('arcadis_rh', $database, $sql);

        $dbSafe = str_replace('`', '``', $database);
        $pdo->exec('USE `' . $dbSafe . '`');

        $parts = preg_split('/;\s*\n/', $sql) ?: [];
        foreach ($parts as $part) {
            $stmt = trim(preg_replace('/^--.*$/m', '', $part) ?? '');
            if ($stmt === '') {
                continue;
            }
            if (preg_match('/^(CREATE\s+DATABASE|USE)\b/i', $stmt)) {
                continue;
            }
            $pdo->exec($stmt);
        }
    }

    private function hasColumn(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c'
        );
        $stmt->execute(['t' => $table, 'c' => $column]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function applyPontoGestao(PDO $pdo): void
    {
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
            if (!$this->hasColumn($pdo, 'ponto_dias', $name)) {
                $pdo->exec("ALTER TABLE ponto_dias ADD COLUMN {$name} {$def}");
            }
        }

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS ponto_justificativa_tipos (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              codigo VARCHAR(40) NOT NULL UNIQUE,
              nome VARCHAR(120) NOT NULL,
              abona_falta TINYINT(1) NOT NULL DEFAULT 0,
              status ENUM('ativo','inativo') NOT NULL DEFAULT 'ativo'
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $pdo->exec(
            "INSERT IGNORE INTO ponto_justificativa_tipos (codigo, nome, abona_falta) VALUES
             ('atestado', 'Atestado médico', 1),
             ('ferias', 'Férias', 1),
             ('folga_compensada', 'Folga compensada', 1),
             ('outros', 'Outros', 0)"
        );
    }

    private function migrateAssinatura(PDO $pdo): void
    {
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
            'autentique_document_id' => 'VARCHAR(64) NULL',
            'autentique_signature_id' => 'VARCHAR(64) NULL',
            'autentique_link' => 'VARCHAR(500) NULL',
            'autentique_status' => 'VARCHAR(40) NULL',
            'autentique_enviado_em' => 'DATETIME NULL',
            'autentique_assinado_em' => 'DATETIME NULL',
        ];

        foreach ($add as $name => $def) {
            if (!$this->hasColumn($pdo, 'documentos', $name)) {
                $pdo->exec("ALTER TABLE documentos ADD COLUMN {$name} {$def}");
            }
        }

        $idx = $pdo->query("SHOW INDEX FROM documentos WHERE Key_name='uk_documentos_portal_token'")->fetch();
        if ($idx === false) {
            $pdo->exec('CREATE UNIQUE INDEX uk_documentos_portal_token ON documentos (portal_token)');
        }

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS documentos_espelhos (
                documento_id BIGINT UNSIGNED NOT NULL,
                espelho_id BIGINT UNSIGNED NOT NULL,
                PRIMARY KEY (documento_id, espelho_id),
                KEY idx_de_espelho (espelho_id),
                CONSTRAINT fk_de_documento FOREIGN KEY (documento_id) REFERENCES documentos(id) ON DELETE CASCADE,
                CONSTRAINT fk_de_espelho FOREIGN KEY (espelho_id) REFERENCES ponto_espelhos(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    private function migrateEmail(PDO $pdo): void
    {
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

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS email_contas (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                provedor ENUM('gmail','microsoft') NOT NULL,
                email VARCHAR(255) NOT NULL,
                nome_exibicao VARCHAR(160) NULL,
                access_token_enc TEXT NULL,
                refresh_token_enc TEXT NULL,
                token_expires_at DATETIME NULL,
                scopes TEXT NULL,
                status ENUM('conectado','expirado','erro','desconectado') NOT NULL DEFAULT 'conectado',
                ativo TINYINT(1) NOT NULL DEFAULT 1,
                ultimo_erro TEXT NULL,
                conectado_por INT UNSIGNED NULL,
                conectado_em DATETIME NOT NULL,
                atualizado_em DATETIME NULL,
                auth_mode ENUM('oauth','smtp') NOT NULL DEFAULT 'oauth',
                smtp_host VARCHAR(190) NULL,
                smtp_port SMALLINT UNSIGNED NULL,
                smtp_encryption ENUM('tls','ssl','none') NULL,
                smtp_user VARCHAR(255) NULL,
                password_enc TEXT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uk_email_conta_provedor_email (provedor, email),
                KEY idx_email_contas_ativo (ativo, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

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

        $smtpCols = [
            'auth_mode' => "ENUM('oauth','smtp') NOT NULL DEFAULT 'oauth'",
            'smtp_host' => 'VARCHAR(190) NULL',
            'smtp_port' => 'SMALLINT UNSIGNED NULL',
            'smtp_encryption' => "ENUM('tls','ssl','none') NULL",
            'smtp_user' => 'VARCHAR(255) NULL',
            'password_enc' => 'TEXT NULL',
        ];
        foreach ($smtpCols as $name => $def) {
            if (!$this->hasColumn($pdo, 'email_contas', $name)) {
                $pdo->exec("ALTER TABLE email_contas ADD COLUMN {$name} {$def}");
            }
        }
    }

    private function migrateUsuariosPerfis(PDO $pdo): void
    {
        $addPerfil = [
            'descricao' => 'VARCHAR(255) NULL',
            'status' => "ENUM('ativo','inativo') NOT NULL DEFAULT 'ativo'",
            'sistema' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'created_at' => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'DATETIME NULL ON UPDATE CURRENT_TIMESTAMP',
        ];
        foreach ($addPerfil as $name => $def) {
            if (!$this->hasColumn($pdo, 'perfis', $name)) {
                $pdo->exec("ALTER TABLE perfis ADD COLUMN {$name} {$def}");
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

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS perfil_permissoes (
                perfil_id BIGINT UNSIGNED NOT NULL,
                permissao_id BIGINT UNSIGNED NOT NULL,
                PRIMARY KEY (perfil_id, permissao_id),
                CONSTRAINT fk_pp_perfil FOREIGN KEY (perfil_id) REFERENCES perfis(id) ON DELETE CASCADE,
                CONSTRAINT fk_pp_permissao FOREIGN KEY (permissao_id) REFERENCES permissoes(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

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
            $perfilId = (int) $pdo->query('SELECT id FROM perfis WHERE codigo=' . $pdo->quote($perfilCodigo))->fetchColumn();
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
        }

        $pdo->exec(
            "INSERT IGNORE INTO usuario_perfis (usuario_id, perfil_id)
             SELECT u.id, p.id FROM usuarios u
             CROSS JOIN perfis p
             WHERE u.email = 'admin@arcadis.local' AND p.codigo = 'admin'"
        );
    }

    private function ensureDirectories(): void
    {
        foreach ([
            'storage/uploads',
            'storage/logs',
            'storage/assinaturas',
            'storage/tmp',
            'output',
        ] as $dir) {
            $path = $this->root . '/' . $dir;
            if (!is_dir($path) && !mkdir($path, 0755, true) && !is_dir($path)) {
                throw new RuntimeException('Não foi possível criar ' . $dir);
            }
        }
    }

    private function writeDatabaseLocal(
        string $host,
        string $port,
        string $database,
        string $username,
        string $password
    ): void {
        $export = var_export([
            'host' => $host,
            'port' => $port,
            'database' => $database,
            'username' => $username,
            'password' => $password,
            'charset' => 'utf8mb4',
        ], true);

        $content = "<?php\n\ndeclare(strict_types=1);\n\nreturn {$export};\n";
        $path = $this->root . '/' . self::DB_LOCAL;
        if (file_put_contents($path, $content) === false) {
            throw new RuntimeException('Não foi possível gravar ' . self::DB_LOCAL);
        }
        @chmod($path, 0640);
    }

    private function writeAppLocal(string $appUrl, string $appName): void
    {
        $data = [
            'name' => $appName !== '' ? $appName : 'Corredor Norte RH',
            'tagline' => 'RH Operacional',
            'env' => 'production',
            'url' => $appUrl,
            'timezone' => 'America/Belem',
        ];
        $export = var_export($data, true);
        $content = "<?php\n\ndeclare(strict_types=1);\n\nreturn {$export};\n";
        $path = $this->root . '/' . self::APP_LOCAL;
        if (file_put_contents($path, $content) === false) {
            throw new RuntimeException('Não foi possível gravar ' . self::APP_LOCAL);
        }
    }

    private function writeLock(string $database, string $username): void
    {
        $payload = [
            'installed_at' => date('c'),
            'database' => $database,
            'username' => $username,
            'version' => '1.0.0',
        ];
        $path = $this->root . '/' . self::LOCK_FILE;
        if (file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
            throw new RuntimeException('Não foi possível gravar ' . self::LOCK_FILE);
        }
    }
}
