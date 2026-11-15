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
        if (str_ends_with(strtolower($appUrl), '/public')) {
            $appUrl = rtrim(substr($appUrl, 0, -7), '/');
        }
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
        \App\Services\AssinaturaEletronicaService::ensureDivergenciaSchema($pdo);
        $messages[] = 'Migração: assinatura eletrônica';

        $this->migrateEmail($pdo);
        $messages[] = 'Migração: e-mail';

        $this->migrateUsuariosPerfis($pdo);
        $messages[] = 'Migração: usuários e perfis';

        $this->migratePercentualHe($pdo);
        $messages[] = 'Migração: percentual HE por lotação';

        $this->migrateHeAutorizacao($pdo);
        $messages[] = 'Migração: autorização de hora extra';

        $this->migratePontoPeriodos($pdo);
        $messages[] = 'Migração: ponto por período';

        $this->migrateRelatorioAtividades($pdo);
        $messages[] = 'Migração: relatório de atividades e VR';

        $this->migrateRelatorioAssinatura($pdo);
        $messages[] = 'Migração: assinatura do relatório de atividades';

        $this->migrateOrganizacao($pdo);
        $messages[] = 'Migração: cargos, funções e gestores';

        $this->migrateHeCliente($pdo);
        $messages[] = 'Migração: HE via gestor cliente';

        $this->migrateCobrancaAssinatura($pdo);
        $messages[] = 'Migração: cobrança automática de assinatura';

        $this->migrateContaUsuario($pdo);
        $messages[] = 'Migração: conta de usuário (perfil, avatar, reset)';

        $this->migrateEscopoContratos($pdo);
        $messages[] = 'Migração: escopo de usuário por contrato';

        $this->migrateAlertas($pdo);
        $messages[] = 'Migração: central de alertas';

        $adminPassword = bin2hex(random_bytes(8)) . 'A1';
        $hash = password_hash($adminPassword, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare(
            'UPDATE usuarios SET senha_hash = :h, password_changed_at = NULL WHERE email = :e'
        );
        $stmt->execute(['h' => $hash, 'e' => 'admin@arcadis.local']);
        $messages[] = 'Usuário admin preparado (senha aleatória — anote agora)';

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
            $pdo = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
            ]);
            $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
            return $pdo;
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
        $sql = \App\Helpers\TextEncoding::ensureUtf8($sql);

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
            'percentual_he' => 'SMALLINT UNSIGNED NOT NULL DEFAULT 50',
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
            "INSERT IGNORE INTO ponto_justificativa_tipos (codigo, nome, abona_falta, status) VALUES
             ('atestado', 'Atestado médico', 1, 'ativo'),
             ('ferias', 'Férias', 1, 'ativo'),
             ('folga_compensada', 'Folga compensada', 1, 'ativo'),
             ('outros', 'Outros', 0, 'ativo')"
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
                provedor ENUM('gmail','microsoft','smtp') NOT NULL,
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
                provedor ENUM('gmail','microsoft','local','php_mail','smtp') NULL,
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

        // SMTP corporativo (provedor genérico) — além de gmail/microsoft
        try {
            $pdo->exec("ALTER TABLE email_contas MODIFY COLUMN provedor ENUM('gmail','microsoft','smtp') NOT NULL");
        } catch (\Throwable) {
            // já migrado ou sem permissão
        }
        try {
            $pdo->exec(
                "ALTER TABLE email_envios_log MODIFY COLUMN provedor ENUM('gmail','microsoft','local','php_mail','smtp') NULL"
            );
        } catch (\Throwable) {
            // já migrado
        }

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS email_templates (
                chave VARCHAR(80) NOT NULL,
                assunto VARCHAR(255) NOT NULL,
                titulo VARCHAR(160) NOT NULL,
                texto MEDIUMTEXT NOT NULL,
                corpo_html MEDIUMTEXT NOT NULL,
                rodape TEXT NULL,
                atualizado_por INT UNSIGNED NULL,
                atualizado_em DATETIME NULL,
                PRIMARY KEY (chave)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
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

        (new \App\Services\PerfilService($pdo))->ensureCatalog();

        $map = [
            'admin' => array_column(\App\Services\PerfilService::catalogoDefinicao(), 'codigo'),
            'rh' => [
                'dashboard.ver', 'alertas.ver', 'efetivo.ver', 'efetivo.editar', 'cadastros.ver', 'cadastros.editar',
                'ponto.ver', 'ponto.editar', 'folha.ver', 'folha.enviar',
                'medicao.ver', 'medicao.editar', 'ra.ver', 'ra.editar', 'vr.ver', 'vr.editar',
                'email.gerenciar', 'importacoes.gerenciar',
            ],
            'gestor' => [
                'dashboard.ver', 'alertas.ver', 'efetivo.ver', 'ponto.ver', 'folha.ver', 'medicao.ver', 'ra.ver', 'vr.ver',
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

    private function migratePercentualHe(PDO $pdo): void
    {
        if (!$this->hasColumn($pdo, 'rh_lotacoes', 'percentual_he')) {
            $pdo->exec(
                'ALTER TABLE rh_lotacoes
                 ADD COLUMN percentual_he SMALLINT UNSIGNED NOT NULL DEFAULT 50
                 AFTER calendario_id'
            );
        }
        if (!$this->hasColumn($pdo, 'rh_colaborador_lotacoes', 'percentual_he')) {
            $pdo->exec(
                'ALTER TABLE rh_colaborador_lotacoes
                 ADD COLUMN percentual_he SMALLINT UNSIGNED NULL
                 AFTER escala_ancora_data'
            );
        }
        if (!$this->hasColumn($pdo, 'ponto_dias', 'percentual_he')) {
            $pdo->exec(
                'ALTER TABLE ponto_dias
                 ADD COLUMN percentual_he SMALLINT UNSIGNED NOT NULL DEFAULT 50
                 AFTER he_100_min'
            );
        }
    }

    private function migrateHeAutorizacao(PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS he_solicitacoes (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              colaborador_id BIGINT UNSIGNED NOT NULL,
              gestor_id BIGINT UNSIGNED NOT NULL,
              relatorio_atividade TEXT NOT NULL,
              data DATE NOT NULL,
              hora_inicial TIME NOT NULL,
              hora_final TIME NOT NULL,
              minutos_he INT UNSIGNED NOT NULL DEFAULT 0,
              contrato_id BIGINT UNSIGNED NOT NULL,
              ordem_servico_id BIGINT UNSIGNED NOT NULL,
              modalidade ENUM('viagem','presencial','remoto') NOT NULL,
              status ENUM('pendente','aprovada','recusada','cancelada') NOT NULL DEFAULT 'pendente',
              ponto_dia_id BIGINT UNSIGNED NULL,
              sai2_anterior TIME NULL,
              motivo_recusa VARCHAR(255) NULL,
              aprovado_em DATETIME NULL,
              aprovado_por_colaborador_id BIGINT UNSIGNED NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
              INDEX idx_he_gestor_status (gestor_id, status),
              INDEX idx_he_colab_data (colaborador_id, data),
              INDEX idx_he_status_data (status, data),
              CONSTRAINT fk_he_colab FOREIGN KEY (colaborador_id) REFERENCES rh_colaboradores(id),
              CONSTRAINT fk_he_gestor FOREIGN KEY (gestor_id) REFERENCES rh_colaboradores(id),
              CONSTRAINT fk_he_contrato FOREIGN KEY (contrato_id) REFERENCES rh_contratos(id),
              CONSTRAINT fk_he_os FOREIGN KEY (ordem_servico_id) REFERENCES rh_ordens_servico(id),
              CONSTRAINT fk_he_ponto_dia FOREIGN KEY (ponto_dia_id) REFERENCES ponto_dias(id) ON DELETE SET NULL,
              CONSTRAINT fk_he_aprovador FOREIGN KEY (aprovado_por_colaborador_id) REFERENCES rh_colaboradores(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function migrateRelatorioAtividades(PDO $pdo): void
    {
        $sqlFile = $this->root . '/database/schema_relatorio_atividades.sql';
        if (!is_file($sqlFile)) {
            return;
        }
        $sql = file_get_contents($sqlFile);
        if ($sql === false || trim($sql) === '') {
            return;
        }
        foreach (preg_split('/;\s*\n/', $sql) ?: [] as $part) {
            $stmt = trim(preg_replace('/^--.*$/m', '', $part) ?? '');
            if ($stmt === '') {
                continue;
            }
            $pdo->exec($stmt);
        }
        $this->migrateVrAlocacao($pdo);
    }

    /** Garante coluna todos_contratos e tabela de vínculos (bancos já existentes). */
    public function migrateVrAlocacao(PDO $pdo): void
    {
        if (!$this->tableExists($pdo, 'vr_config')) {
            return;
        }
        if (!$this->hasColumn($pdo, 'vr_config', 'todos_contratos')) {
            $pdo->exec(
                'ALTER TABLE vr_config
                 ADD COLUMN todos_contratos TINYINT(1) NOT NULL DEFAULT 1
                 AFTER vigencia_fim'
            );
        }
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS vr_config_contratos (
              config_id BIGINT UNSIGNED NOT NULL,
              contrato_id BIGINT UNSIGNED NOT NULL,
              PRIMARY KEY (config_id, contrato_id),
              KEY idx_vr_cc_contrato (contrato_id),
              CONSTRAINT fk_vr_cc_config FOREIGN KEY (config_id) REFERENCES vr_config(id) ON DELETE CASCADE,
              CONSTRAINT fk_vr_cc_contrato FOREIGN KEY (contrato_id) REFERENCES rh_contratos(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        if (!$this->hasColumn($pdo, 'vr_config', 'nome')) {
            $pdo->exec(
                "ALTER TABLE vr_config
                 ADD COLUMN nome VARCHAR(120) NOT NULL DEFAULT '' AFTER id"
            );
        }
    }

    public function migrateRelatorioAssinatura(PDO $pdo): void
    {
        $sqlFile = $this->root . '/database/schema_relatorio_assinatura.sql';
        if (!is_file($sqlFile)) {
            throw new RuntimeException('Schema da assinatura do relatório não encontrado.');
        }
        $sql = file_get_contents($sqlFile);
        if ($sql === false || trim($sql) === '') {
            throw new RuntimeException('Schema da assinatura do relatório vazio.');
        }
        $pdo->exec($sql);
    }

    public function migrateOrganizacao(PDO $pdo): void
    {
        $sqlFile = $this->root . '/database/schema_organizacao.sql';
        if (!is_file($sqlFile)) {
            return;
        }
        $sql = file_get_contents($sqlFile);
        if ($sql === false || trim($sql) === '') {
            return;
        }
        foreach (preg_split('/;\s*\n/', $sql) ?: [] as $part) {
            $stmt = trim(preg_replace('/^--.*$/m', '', $part) ?? '');
            if ($stmt === '') {
                continue;
            }
            $pdo->exec($stmt);
        }
    }

    public function migrateHeCliente(PDO $pdo): void
    {
        if (!$this->tableExists($pdo, 'rh_colaboradores')) {
            return;
        }
        if (!$this->hasColumn($pdo, 'rh_colaboradores', 'he_fluxo')) {
            $pdo->exec(
                "ALTER TABLE rh_colaboradores
                 ADD COLUMN he_fluxo ENUM('off','cliente') NOT NULL DEFAULT 'off'
                 AFTER supervisor_id"
            );
        }
        if (!$this->hasColumn($pdo, 'rh_colaboradores', 'gestor_cliente_id')) {
            $pdo->exec(
                'ALTER TABLE rh_colaboradores
                 ADD COLUMN gestor_cliente_id BIGINT UNSIGNED NULL
                 AFTER he_fluxo'
            );
            if ($this->tableExists($pdo, 'rh_gestores_cliente')) {
                try {
                    $pdo->exec(
                        'ALTER TABLE rh_colaboradores
                         ADD CONSTRAINT fk_colab_gestor_cliente
                         FOREIGN KEY (gestor_cliente_id) REFERENCES rh_gestores_cliente(id)
                         ON DELETE SET NULL'
                    );
                } catch (Throwable) {
                    // FK pode já existir ou engine sem suporte em ambiente legado
                }
            }
        }

        if (!$this->tableExists($pdo, 'ra_dias')) {
            return;
        }
        $raCols = [
            'he_hora_inicio' => 'TIME NULL',
            'he_hora_fim' => 'TIME NULL',
            'he_status' => "ENUM('nenhuma','declarada','aplicada','divergente') NOT NULL DEFAULT 'nenhuma'",
            'he_aplicada_em' => 'DATETIME NULL',
            'he_aplicada_por_usuario_id' => 'BIGINT UNSIGNED NULL',
            'he_ponto_dia_id' => 'BIGINT UNSIGNED NULL',
            'he_sai2_anterior' => 'TIME NULL',
        ];
        $after = 'observacoes';
        foreach ($raCols as $col => $def) {
            if ($this->hasColumn($pdo, 'ra_dias', $col)) {
                $after = $col;
                continue;
            }
            $pdo->exec("ALTER TABLE ra_dias ADD COLUMN {$col} {$def} AFTER {$after}");
            $after = $col;
        }
    }

    public function migrateEscopoContratos(PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS usuario_contratos (
                usuario_id BIGINT UNSIGNED NOT NULL,
                contrato_id BIGINT UNSIGNED NOT NULL,
                PRIMARY KEY (usuario_id, contrato_id),
                KEY idx_uc_contrato (contrato_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function migrateAlertas(PDO $pdo): void
    {
        (new \App\Services\AlertasService($pdo));
        (new \App\Services\PerfilService($pdo))->ensureCatalog();

        // Concede alertas.ver a quem já tem dashboard.ver (RH/gestor existentes).
        $permId = (int) $pdo->query(
            "SELECT id FROM permissoes WHERE codigo = 'alertas.ver' LIMIT 1"
        )->fetchColumn();
        $dashId = (int) $pdo->query(
            "SELECT id FROM permissoes WHERE codigo = 'dashboard.ver' LIMIT 1"
        )->fetchColumn();
        if ($permId > 0 && $dashId > 0) {
            $pdo->exec(
                "INSERT IGNORE INTO perfil_permissoes (perfil_id, permissao_id)
                 SELECT DISTINCT pp.perfil_id, {$permId}
                 FROM perfil_permissoes pp
                 WHERE pp.permissao_id = {$dashId}"
            );
        }
    }

    public function migrateContaUsuario(PDO $pdo): void
    {
        if (!$this->hasColumn($pdo, 'usuarios', 'telefone')) {
            $pdo->exec('ALTER TABLE usuarios ADD COLUMN telefone VARCHAR(32) NULL');
        }
        if (!$this->hasColumn($pdo, 'usuarios', 'avatar_path')) {
            $pdo->exec('ALTER TABLE usuarios ADD COLUMN avatar_path VARCHAR(255) NULL');
        }
        if (!$this->hasColumn($pdo, 'usuarios', 'last_login_at')) {
            $pdo->exec('ALTER TABLE usuarios ADD COLUMN last_login_at DATETIME NULL');
        }
        if (!$this->hasColumn($pdo, 'usuarios', 'password_changed_at')) {
            $pdo->exec('ALTER TABLE usuarios ADD COLUMN password_changed_at DATETIME NULL');
        }

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS password_reset_tokens (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                usuario_id BIGINT UNSIGNED NOT NULL,
                token_hash CHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL,
                used_at DATETIME NULL,
                request_ip VARCHAR(45) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uk_prt_token (token_hash),
                KEY idx_prt_usuario (usuario_id),
                KEY idx_prt_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS login_attempts (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                email VARCHAR(190) NOT NULL,
                ip VARCHAR(45) NOT NULL,
                sucesso TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_login_email_created (email, created_at),
                KEY idx_login_ip_created (ip, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $avatars = $this->root . '/storage/uploads/avatars';
        if (!is_dir($avatars)) {
            @mkdir($avatars, 0755, true);
        }
    }

    public function migrateCobrancaAssinatura(PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS cobranca_envios (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                tipo ENUM('folha','ra') NOT NULL,
                documento_id BIGINT UNSIGNED NOT NULL,
                periodo_id BIGINT UNSIGNED NOT NULL,
                nivel TINYINT UNSIGNED NOT NULL,
                destinatario VARCHAR(160) NOT NULL,
                status ENUM('enviado','falha') NOT NULL,
                erro_mensagem VARCHAR(255) NULL,
                enviado_em DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uk_cobranca_doc_nivel (tipo, documento_id, nivel),
                KEY idx_cobranca_periodo (periodo_id),
                KEY idx_cobranca_enviado (enviado_em)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $secretFile = $this->root . '/storage/cron.secret';
        if (!is_file($secretFile)) {
            $dir = dirname($secretFile);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            @file_put_contents($secretFile, bin2hex(random_bytes(24)) . "\n");
            @chmod($secretFile, 0640);
        }
    }

    /**
     * Troca competência mensal por período exato (data_inicio/data_fim).
     * Apaga espelhos/documentos legados baseados em competência.
     */
    public function migratePontoPeriodos(PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS ponto_periodos (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              data_inicio DATE NOT NULL,
              data_fim DATE NOT NULL,
              status ENUM('aberta','em_tratamento','conferida','fechada') NOT NULL DEFAULT 'aberta',
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
              UNIQUE KEY uk_periodo (data_inicio, data_fim)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $temCompetencia = $this->hasColumn($pdo, 'ponto_espelhos', 'competencia_id');
        $temPeriodo = $this->hasColumn($pdo, 'ponto_espelhos', 'periodo_id');

        if ($temCompetencia) {
            // 1A: limpar cartões mensais legados e dependências sem CASCADE.
            $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
            try {
                if ($this->tableExists($pdo, 'documentos_espelhos')) {
                    $pdo->exec('DELETE FROM documentos_espelhos');
                }
                if ($this->tableExists($pdo, 'emails_envios')) {
                    $pdo->exec(
                        'DELETE ee FROM emails_envios ee
                         INNER JOIN documentos d ON d.id = ee.documento_id
                         INNER JOIN ponto_espelhos e ON e.id = d.espelho_id
                         WHERE e.competencia_id IS NOT NULL'
                    );
                }
                if ($this->tableExists($pdo, 'documentos_versoes')) {
                    $pdo->exec(
                        'DELETE dv FROM documentos_versoes dv
                         INNER JOIN documentos d ON d.id = dv.documento_id
                         INNER JOIN ponto_espelhos e ON e.id = d.espelho_id
                         WHERE e.competencia_id IS NOT NULL'
                    );
                }
                if ($this->tableExists($pdo, 'documentos')) {
                    $pdo->exec(
                        'DELETE d FROM documentos d
                         INNER JOIN ponto_espelhos e ON e.id = d.espelho_id
                         WHERE e.competencia_id IS NOT NULL'
                    );
                }
                $pdo->exec('DELETE FROM ponto_espelhos');
                if ($this->tableExists($pdo, 'ponto_competencias')) {
                    $pdo->exec('DELETE FROM ponto_competencias');
                }
            } finally {
                $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
            }

            $this->dropForeignKeysOnColumn($pdo, 'ponto_espelhos', 'competencia_id');
            try {
                $pdo->exec('ALTER TABLE ponto_espelhos DROP INDEX uk_espelho');
            } catch (Throwable) {
                // índice pode já ter outro nome
            }
            $pdo->exec('ALTER TABLE ponto_espelhos DROP COLUMN competencia_id');
            $temCompetencia = false;
            $temPeriodo = $this->hasColumn($pdo, 'ponto_espelhos', 'periodo_id');
        }

        if (!$temPeriodo) {
            $pdo->exec('ALTER TABLE ponto_espelhos ADD COLUMN periodo_id BIGINT UNSIGNED NULL AFTER id');
            // Sem cartões legados: torna obrigatório e amarra a chave.
            $pdo->exec('ALTER TABLE ponto_espelhos MODIFY periodo_id BIGINT UNSIGNED NOT NULL');
            try {
                $pdo->exec('ALTER TABLE ponto_espelhos ADD UNIQUE KEY uk_espelho (periodo_id, colaborador_id)');
            } catch (Throwable) {
                // índice já existente
            }
            try {
                $pdo->exec(
                    'ALTER TABLE ponto_espelhos
                     ADD CONSTRAINT fk_esp_periodo FOREIGN KEY (periodo_id) REFERENCES ponto_periodos(id)'
                );
            } catch (Throwable) {
                // FK já existente
            }
        }

        if ($this->tableExists($pdo, 'ponto_competencias')) {
            $pdo->exec('DROP TABLE ponto_competencias');
        }
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t'
        );
        $stmt->execute(['t' => $table]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function dropForeignKeysOnColumn(PDO $pdo, string $table, string $column): void
    {
        $stmt = $pdo->prepare(
            'SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :t
               AND COLUMN_NAME = :c
               AND REFERENCED_TABLE_NAME IS NOT NULL'
        );
        $stmt->execute(['t' => $table, 'c' => $column]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $fk) {
            $name = str_replace('`', '``', (string) $fk);
            $tbl = str_replace('`', '``', $table);
            $pdo->exec("ALTER TABLE `{$tbl}` DROP FOREIGN KEY `{$name}`");
        }
    }

    private function ensureDirectories(): void
    {
        foreach ([
            'storage/uploads',
            'storage/uploads/avatars',
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
        $cronSecret = '';
        $secretFile = $this->root . '/storage/cron.secret';
        if (is_file($secretFile)) {
            $cronSecret = trim((string) file_get_contents($secretFile));
        }
        if ($cronSecret === '') {
            $cronSecret = bin2hex(random_bytes(24));
        }

        $data = [
            'name' => $appName !== '' ? $appName : 'Corredor Norte RH',
            'tagline' => 'RH Operacional',
            'env' => 'production',
            'url' => $appUrl,
            'timezone' => 'America/Belem',
            'cron_secret' => $cronSecret,
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
