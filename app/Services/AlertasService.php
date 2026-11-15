<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Helpers\Auth;
use App\Helpers\EscopoAcesso;
use App\Services\EmailOAuth\EmailAccountService;
use DateTimeImmutable;
use PDO;
use Throwable;

/**
 * Central de Alertas — eventos persistidos + estado por usuário (lido/dispensado).
 * Sync materializa o estado operacional atual sem perder histórico recente.
 */
final class AlertasService
{
    private static bool $schemaOk = false;

    private PDO $db;

    /** @var array<string,array<string,mixed>>|null */
    private ?array $tiposCache = null;

    /** @var array<string,string>|null */
    private ?array $configCache = null;

    /** @var array<string,list<array{tipo:string,id:int}>>|null */
    private ?array $destinosCache = null;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
        self::ensureSchema($this->db);
    }

    public static function ensureSchema(?PDO $pdo = null): void
    {
        if (self::$schemaOk) {
            return;
        }
        $pdo ??= Database::connection();
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS alerta_eventos (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                chave VARCHAR(190) NOT NULL,
                codigo VARCHAR(80) NOT NULL,
                severidade ENUM('info','media','alta','critica') NOT NULL DEFAULT 'media',
                titulo VARCHAR(200) NOT NULL,
                detalhe VARCHAR(500) NULL,
                href VARCHAR(500) NULL,
                periodo_inicio DATE NULL,
                periodo_fim DATE NULL,
                colaborador_id BIGINT UNSIGNED NULL,
                contrato_id BIGINT UNSIGNED NULL,
                espelho_id BIGINT UNSIGNED NULL,
                documento_id BIGINT UNSIGNED NULL,
                referencia_tipo VARCHAR(40) NULL,
                referencia_id BIGINT UNSIGNED NULL,
                status ENUM('aberto','resolvido') NOT NULL DEFAULT 'aberto',
                payload_json JSON NULL,
                aberto_em DATETIME NOT NULL,
                atualizado_em DATETIME NOT NULL,
                resolvido_em DATETIME NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uk_alerta_chave (chave),
                KEY idx_alerta_status (status, severidade),
                KEY idx_alerta_colab (colaborador_id),
                KEY idx_alerta_codigo (codigo),
                KEY idx_alerta_aberto (status, aberto_em)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS alerta_usuario_estado (
                usuario_id BIGINT UNSIGNED NOT NULL,
                alerta_id BIGINT UNSIGNED NOT NULL,
                lido_em DATETIME NULL,
                dispensado_em DATETIME NULL,
                dispensado_motivo VARCHAR(255) NULL,
                PRIMARY KEY (usuario_id, alerta_id),
                KEY idx_aue_alerta (alerta_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS alerta_tipos (
                codigo VARCHAR(80) NOT NULL,
                nome VARCHAR(120) NOT NULL,
                descricao VARCHAR(255) NULL,
                grupo VARCHAR(80) NOT NULL DEFAULT 'Operação',
                ativo TINYINT(1) NOT NULL DEFAULT 1,
                severidade_padrao ENUM('info','media','alta','critica') NOT NULL DEFAULT 'media',
                sla_horas INT UNSIGNED NULL,
                titulo_template VARCHAR(200) NULL,
                href_padrao VARCHAR(500) NULL,
                ordem INT NOT NULL DEFAULT 100,
                atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (codigo),
                KEY idx_alerta_tipos_ativo (ativo, ordem)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS alerta_destinos (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                codigo VARCHAR(80) NOT NULL,
                destino_tipo ENUM('perfil','usuario') NOT NULL,
                destino_id BIGINT UNSIGNED NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uk_alerta_destino (codigo, destino_tipo, destino_id),
                KEY idx_alerta_destino_codigo (codigo)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS alerta_config (
                chave VARCHAR(80) NOT NULL,
                valor VARCHAR(255) NOT NULL,
                PRIMARY KEY (chave)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        self::ensureColumn($pdo, 'alerta_eventos', 'atribuido_usuario_id', 'BIGINT UNSIGNED NULL');
        self::ensureColumn($pdo, 'alerta_eventos', 'atribuido_em', 'DATETIME NULL');
        self::ensureColumn($pdo, 'alerta_eventos', 'atribuido_por', 'BIGINT UNSIGNED NULL');
        try {
            $ix = $pdo->prepare(
                "SELECT 1 FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alerta_eventos'
                   AND INDEX_NAME = 'idx_alerta_atribuido' LIMIT 1"
            );
            $ix->execute();
            if (!$ix->fetchColumn()) {
                $pdo->exec('CREATE INDEX idx_alerta_atribuido ON alerta_eventos (atribuido_usuario_id, status)');
            }
        } catch (Throwable) {
        }
        self::seedTipos($pdo);
        self::seedConfig($pdo);
        self::$schemaOk = true;
    }

    /** @return list<array{codigo:string,nome:string,descricao:string,grupo:string,severidade_padrao:string,sla_horas:?int,titulo_template:?string,href_padrao:?string,ordem:int}> */
    public static function catalogoTiposPadrao(): array
    {
        return [
            ['codigo' => 'canal_email.critico', 'nome' => 'Canal de e-mail indisponível', 'descricao' => 'Conta de e-mail desconectada ou ausente', 'grupo' => 'Sistema', 'severidade_padrao' => 'critica', 'sla_horas' => null, 'titulo_template' => 'Canal de e-mail indisponível', 'href_padrao' => '/rh/email', 'ordem' => 10],
            ['codigo' => 'divergencia.aberta', 'nome' => 'Divergência aberta', 'descricao' => 'Divergência reportada abaixo do SLA', 'grupo' => 'Ponto', 'severidade_padrao' => 'alta', 'sla_horas' => null, 'titulo_template' => 'Divergência aberta', 'href_padrao' => '/rh/ponto?filtro=divergencia', 'ordem' => 20],
            ['codigo' => 'divergencia.sla_critico', 'nome' => 'Divergência crítica (SLA)', 'descricao' => 'Divergência acima do SLA em horas', 'grupo' => 'Ponto', 'severidade_padrao' => 'critica', 'sla_horas' => 48, 'titulo_template' => 'Divergência crítica', 'href_padrao' => '/rh/ponto?filtro=divergencia', 'ordem' => 21],
            ['codigo' => 'cobertura.sem_espelho', 'nome' => 'Sem espelho', 'descricao' => 'Colaborador ativo sem espelho no período', 'grupo' => 'Ponto', 'severidade_padrao' => 'alta', 'sla_horas' => null, 'titulo_template' => 'Sem espelho', 'href_padrao' => '/rh/ponto', 'ordem' => 30],
            ['codigo' => 'he.bloqueante', 'nome' => 'Horas extras bloqueantes', 'descricao' => 'HE aguardando tratamento', 'grupo' => 'Ponto', 'severidade_padrao' => 'alta', 'sla_horas' => null, 'titulo_template' => 'Horas extras aguardando tratamento', 'href_padrao' => '/rh/ponto', 'ordem' => 40],
            ['codigo' => 'email.falha', 'nome' => 'Falha no envio de e-mail', 'descricao' => 'Envio da folha falhou', 'grupo' => 'E-mail', 'severidade_padrao' => 'alta', 'sla_horas' => null, 'titulo_template' => 'Problema no envio', 'href_padrao' => '/rh/medicao', 'ordem' => 50],
            ['codigo' => 'email.sem_email', 'nome' => 'Colaborador sem e-mail', 'descricao' => 'Cadastro sem e-mail corporativo', 'grupo' => 'E-mail', 'severidade_padrao' => 'alta', 'sla_horas' => null, 'titulo_template' => 'Problema no envio', 'href_padrao' => '/rh/colaboradores', 'ordem' => 51],
            ['codigo' => 'email.invalido', 'nome' => 'E-mail inválido', 'descricao' => 'E-mail corporativo inválido', 'grupo' => 'E-mail', 'severidade_padrao' => 'alta', 'sla_horas' => null, 'titulo_template' => 'Problema no envio', 'href_padrao' => '/rh/colaboradores', 'ordem' => 52],
            ['codigo' => 'assinatura.atraso', 'nome' => 'Assinaturas em atraso', 'descricao' => 'Folhas/RA na janela de cobrança', 'grupo' => 'Assinatura', 'severidade_padrao' => 'alta', 'sla_horas' => null, 'titulo_template' => 'Assinaturas em atraso', 'href_padrao' => '/rh/medicao?filtro=assinaturas', 'ordem' => 60],
            ['codigo' => 'cobranca.falha', 'nome' => 'Falha na cobrança', 'descricao' => 'Régua automática falhou ao enviar', 'grupo' => 'Assinatura', 'severidade_padrao' => 'alta', 'sla_horas' => null, 'titulo_template' => 'Falha na cobrança de assinatura', 'href_padrao' => '/rh/medicao?filtro=assinaturas', 'ordem' => 70],
            ['codigo' => 'gargalo', 'nome' => 'Gargalo do período (família)', 'descricao' => 'Gargalo dominante — cobre códigos gargalo.*', 'grupo' => 'Medição', 'severidade_padrao' => 'alta', 'sla_horas' => null, 'titulo_template' => 'Gargalo', 'href_padrao' => '/rh/medicao', 'ordem' => 80],
        ];
    }

    private static function seedTipos(PDO $pdo): void
    {
        $st = $pdo->prepare(
            'INSERT INTO alerta_tipos
             (codigo, nome, descricao, grupo, ativo, severidade_padrao, sla_horas, titulo_template, href_padrao, ordem)
             VALUES (:c, :n, :d, :g, 1, :sev, :sla, :tit, :href, :ord)
             ON DUPLICATE KEY UPDATE
               nome = IF(nome = VALUES(nome) OR nome = \'\', VALUES(nome), nome),
               descricao = COALESCE(descricao, VALUES(descricao)),
               grupo = VALUES(grupo),
               ordem = VALUES(ordem)'
        );
        foreach (self::catalogoTiposPadrao() as $t) {
            $st->execute([
                'c' => $t['codigo'],
                'n' => $t['nome'],
                'd' => $t['descricao'],
                'g' => $t['grupo'],
                'sev' => $t['severidade_padrao'],
                'sla' => $t['sla_horas'],
                'tit' => $t['titulo_template'],
                'href' => $t['href_padrao'],
                'ord' => $t['ordem'],
            ]);
        }
    }

    private static function seedConfig(PDO $pdo): void
    {
        $defaults = [
            'digest_ativo' => '0',
            'digest_hora' => '08:00',
            'historico_dias' => '30',
            'limite_lista' => '200',
        ];
        $st = $pdo->prepare(
            'INSERT INTO alerta_config (chave, valor) VALUES (:k, :v)
             ON DUPLICATE KEY UPDATE chave = chave'
        );
        foreach ($defaults as $k => $v) {
            $st->execute(['k' => $k, 'v' => $v]);
        }
    }

    private static function ensureColumn(PDO $pdo, string $table, string $column, string $definition): void
    {
        try {
            $st = $pdo->prepare(
                'SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c LIMIT 1'
            );
            $st->execute(['t' => $table, 'c' => $column]);
            if ($st->fetchColumn()) {
                return;
            }
            $pdo->exec('ALTER TABLE `' . str_replace('`', '``', $table) . '` ADD COLUMN `'
                . str_replace('`', '``', $column) . '` ' . $definition);
        } catch (Throwable) {
        }
    }

    /**
     * Emite/atualiza um evento persistido (padrão B).
     *
     * @param array{
     *   chave:string,codigo:string,titulo:string,severidade?:string,detalhe?:?string,href?:?string,
     *   periodo_inicio?:?string,periodo_fim?:?string,colaborador_id?:?int,contrato_id?:?int,
     *   espelho_id?:?int,documento_id?:?int,referencia_tipo?:?string,referencia_id?:?int,
     *   payload?:?array<string,mixed>
     * } $data
     */
    public function emitir(array $data): int
    {
        $chave = trim((string) ($data['chave'] ?? ''));
        if ($chave === '') {
            throw new \InvalidArgumentException('chave obrigatória');
        }
        $codigo = mb_substr((string) ($data['codigo'] ?? ''), 0, 80);
        if ($codigo === '' || !$this->tipoAtivo($codigo)) {
            $this->resolverPorChave($chave);

            return 0;
        }
        $tipo = $this->resolverTipo($codigo);
        if ($tipo !== null) {
            $forcarSev = !empty($data['forcar_severidade']);
            if (!$forcarSev && !empty($tipo['severidade_padrao'])) {
                $data['severidade'] = (string) $tipo['severidade_padrao'];
            } elseif (empty($data['severidade']) && !empty($tipo['severidade_padrao'])) {
                $data['severidade'] = (string) $tipo['severidade_padrao'];
            }

            $hrefCustom = trim((string) ($tipo['href_padrao'] ?? ''));
            $hrefDefault = self::defaultHrefPadrao($codigo);
            if (empty($data['href']) && $hrefCustom !== '') {
                $data['href'] = $hrefCustom;
            } elseif ($hrefCustom !== '' && $hrefDefault !== '' && $hrefCustom !== $hrefDefault) {
                $hrefVal = $hrefCustom;
                $pi = (string) ($data['periodo_inicio'] ?? '');
                $pf = (string) ($data['periodo_fim'] ?? '');
                if ($pi !== '' && $pf !== '' && !str_contains($hrefVal, 'data_inicio=')) {
                    $sep = str_contains($hrefVal, '?') ? '&' : '?';
                    $hrefVal .= $sep . 'data_inicio=' . rawurlencode($pi) . '&data_fim=' . rawurlencode($pf);
                }
                $data['href'] = $hrefVal;
            }

            $tplCustom = trim((string) ($tipo['titulo_template'] ?? ''));
            $tplDefault = self::defaultTituloTemplate($codigo);
            if ($tplCustom !== '' && $tplDefault !== '' && $tplCustom !== $tplDefault) {
                $tituloAtual = trim((string) ($data['titulo'] ?? ''));
                $sufixo = '';
                if (str_contains($tituloAtual, ' — ')) {
                    $parts = explode(' — ', $tituloAtual, 2);
                    $sufixo = trim($parts[1] ?? '');
                }
                if (str_contains($tplCustom, '{colaborador}') || str_contains($tplCustom, '{{colaborador}}')) {
                    $data['titulo'] = str_replace(['{{colaborador}}', '{colaborador}'], $sufixo !== '' ? $sufixo : 'Colaborador', $tplCustom);
                } elseif ($sufixo !== '' && !str_contains($tplCustom, $sufixo)) {
                    $data['titulo'] = $tplCustom . ' — ' . $sufixo;
                } else {
                    $data['titulo'] = $tplCustom;
                }
            }
        }
        $payload = $data['payload'] ?? null;
        $payloadJson = is_array($payload) ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null;

        $st = $this->db->prepare(
            'INSERT INTO alerta_eventos
             (chave, codigo, severidade, titulo, detalhe, href, periodo_inicio, periodo_fim,
              colaborador_id, contrato_id, espelho_id, documento_id, referencia_tipo, referencia_id,
              status, payload_json, aberto_em, atualizado_em)
             VALUES
             (:chave, :codigo, :sev, :titulo, :detalhe, :href, :pi, :pf,
              :colab, :contrato, :espelho, :doc, :ref_tipo, :ref_id,
              \'aberto\', :payload, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
               codigo = VALUES(codigo),
               severidade = VALUES(severidade),
               titulo = VALUES(titulo),
               detalhe = VALUES(detalhe),
               href = VALUES(href),
               periodo_inicio = VALUES(periodo_inicio),
               periodo_fim = VALUES(periodo_fim),
               colaborador_id = VALUES(colaborador_id),
               contrato_id = VALUES(contrato_id),
               espelho_id = VALUES(espelho_id),
               documento_id = VALUES(documento_id),
               referencia_tipo = VALUES(referencia_tipo),
               referencia_id = VALUES(referencia_id),
               payload_json = VALUES(payload_json),
               status = \'aberto\',
               resolvido_em = NULL,
               atualizado_em = NOW()'
        );
        $st->execute([
            'chave' => mb_substr($chave, 0, 190),
            'codigo' => $codigo,
            'sev' => $this->normSeveridade((string) ($data['severidade'] ?? 'media')),
            'titulo' => mb_substr((string) $data['titulo'], 0, 200),
            'detalhe' => isset($data['detalhe']) ? mb_substr((string) $data['detalhe'], 0, 500) : null,
            'href' => isset($data['href']) ? mb_substr((string) $data['href'], 0, 500) : null,
            'pi' => $data['periodo_inicio'] ?? null,
            'pf' => $data['periodo_fim'] ?? null,
            'colab' => isset($data['colaborador_id']) ? (int) $data['colaborador_id'] : null,
            'contrato' => isset($data['contrato_id']) ? (int) $data['contrato_id'] : null,
            'espelho' => isset($data['espelho_id']) ? (int) $data['espelho_id'] : null,
            'doc' => isset($data['documento_id']) ? (int) $data['documento_id'] : null,
            'ref_tipo' => $data['referencia_tipo'] ?? null,
            'ref_id' => isset($data['referencia_id']) ? (int) $data['referencia_id'] : null,
            'payload' => $payloadJson,
        ]);

        $id = (int) $this->db->lastInsertId();
        if ($id > 0) {
            return $id;
        }
        $find = $this->db->prepare('SELECT id FROM alerta_eventos WHERE chave = :c LIMIT 1');
        $find->execute(['c' => mb_substr($chave, 0, 190)]);

        return (int) $find->fetchColumn();
    }

    public function resolverPorChave(string $chave): void
    {
        $this->db->prepare(
            "UPDATE alerta_eventos
             SET status = 'resolvido', resolvido_em = COALESCE(resolvido_em, NOW()), atualizado_em = NOW()
             WHERE chave = :c AND status = 'aberto'"
        )->execute(['c' => mb_substr($chave, 0, 190)]);
    }

    /** @param list<string> $chavesAtivas */
    public function resolverExceto(
        array $chavesAtivas,
        ?array $codigos = null,
        ?string $periodoInicio = null,
        ?string $periodoFim = null
    ): void {
        $sql = "UPDATE alerta_eventos
                SET status = 'resolvido', resolvido_em = COALESCE(resolvido_em, NOW()), atualizado_em = NOW()
                WHERE status = 'aberto'";
        $params = [];
        if ($codigos !== null && $codigos !== []) {
            $ph = [];
            foreach (array_values($codigos) as $i => $c) {
                $k = 'c' . $i;
                $ph[] = ':' . $k;
                $params[$k] = $c;
            }
            $sql .= ' AND codigo IN (' . implode(',', $ph) . ')';
        }
        if ($periodoInicio !== null && $periodoFim !== null && $periodoInicio !== '' && $periodoFim !== '') {
            $sql .= ' AND periodo_inicio = :pi_res AND periodo_fim = :pf_res';
            $params['pi_res'] = $periodoInicio;
            $params['pf_res'] = $periodoFim;
        }
        if ($chavesAtivas !== []) {
            $ph = [];
            foreach (array_values($chavesAtivas) as $i => $chave) {
                $k = 'k' . $i;
                $ph[] = ':' . $k;
                $params[$k] = mb_substr($chave, 0, 190);
            }
            $sql .= ' AND chave NOT IN (' . implode(',', $ph) . ')';
        }
        $st = $this->db->prepare($sql);
        $st->execute($params);
    }

    /**
     * Materializa alertas operacionais atuais a partir do painel + tabelas-fonte.
     *
     * @return array{emitidos:int,resolvidos:int}
     */
    public function sincronizarOperacional(?string $dataInicio = null, ?string $dataFim = null): array
    {
        $emitidos = 0;
        $ativas = [];
        $this->resolverTiposInativos();
        $limite = $this->configInt('limite_lista', 200);

        // Canal de e-mail (alinhado 1:1 com a Visão Geral / PainelExcecoesService)
        try {
            $conta = (new EmailAccountService($this->db))->contaAtiva();
            $estado = (string) ($conta['status'] ?? 'desconectado');
            $ultimoErro = trim((string) ($conta['ultimo_erro'] ?? ''));
            $emailIndisponivel = false;
            $detalheCanal = '';

            if ($conta === null) {
                $emailIndisponivel = true;
                $detalheCanal = 'Nenhuma conta de e-mail ativa configurada. Envios de folhas, RAs e régua de cobrança ficam bloqueados.';
            } elseif (!in_array($estado, ['conectado'], true)) {
                $emailIndisponivel = true;
                $detalheCanal = 'Conta ' . ((string) ($conta['email_remetente'] ?? '')) . ' com status "' . $estado . '"'
                    . ($ultimoErro !== '' ? ' — ' . $ultimoErro : '.');
            } elseif ($ultimoErro !== '') {
                $emailIndisponivel = true;
                $detalheCanal = 'Conta ' . ((string) ($conta['email_remetente'] ?? '')) . ' registrou falha recente: ' . $ultimoErro;
            } elseif (empty($conta['refresh_token_encrypted']) && !empty($conta['token_expires_at'])) {
                $expTs = strtotime((string) $conta['token_expires_at']);
                if ($expTs !== false && $expTs < time()) {
                    $emailIndisponivel = true;
                    $detalheCanal = 'Token OAuth da conta ' . ((string) ($conta['email_remetente'] ?? '')) . ' expirou e exige reconexão.';
                }
            }

            if ($emailIndisponivel) {
                $chave = 'canal_email.critico';
                if ($this->emitir([
                    'chave' => $chave,
                    'codigo' => 'canal_email.critico',
                    'severidade' => 'critica',
                    'titulo' => 'Canal de e-mail indisponível ou com falha',
                    'detalhe' => $detalheCanal,
                    'href' => '/rh/email',
                ]) > 0) {
                    $ativas[] = $chave;
                    $emitidos++;
                }
            }
        } catch (Throwable) {
        }
        $this->resolverExceto($ativas, ['canal_email.critico']);

        // Divergências abertas (com vínculo ao período e espelho do colaborador)
        $divAtivas = [];
        $slaHoras = $this->slaHoras('divergencia.sla_critico', 48);
        try {
            if ($this->tableExists('ponto_divergencias')) {
                $rows = $this->db->query(
                    "SELECT d.id, d.espelho_id, d.documento_id, d.colaborador_id, d.motivo, d.created_at,
                            c.nome AS colaborador_nome,
                            pp.data_inicio AS periodo_inicio, pp.data_fim AS periodo_fim,
                            TIMESTAMPDIFF(HOUR, d.created_at, NOW()) AS idade_h
                     FROM ponto_divergencias d
                     LEFT JOIN rh_colaboradores c ON c.id = d.colaborador_id
                     LEFT JOIN ponto_espelhos e ON e.id = d.espelho_id
                     LEFT JOIN ponto_periodos pp ON pp.id = e.periodo_id
                     WHERE d.status IN ('aberta','em_analise')
                     ORDER BY d.id DESC
                     LIMIT " . max(1, $limite)
                )->fetchAll(PDO::FETCH_ASSOC) ?: [];
                foreach ($rows as $row) {
                    $id = (int) $row['id'];
                    $idade = (int) ($row['idade_h'] ?? 0);
                    $critico = $idade >= $slaHoras;
                    $chave = 'divergencia:' . $id;
                    $nome = trim((string) ($row['colaborador_nome'] ?? 'Colaborador'));
                    $codigo = $critico ? 'divergencia.sla_critico' : 'divergencia.aberta';
                    $espelhoId = (int) ($row['espelho_id'] ?? 0);
                    $piDiv = (string) ($row['periodo_inicio'] ?? '');
                    $pfDiv = (string) ($row['periodo_fim'] ?? '');
                    if ($espelhoId > 0 && $piDiv !== '' && $pfDiv !== '') {
                        $hrefDiv = '/rh/ponto/' . $espelhoId . '?' . http_build_query([
                            'data_inicio' => $piDiv,
                            'data_fim' => $pfDiv,
                        ]);
                    } elseif ($piDiv !== '' && $pfDiv !== '') {
                        $hrefDiv = '/rh/ponto?' . http_build_query([
                            'data_inicio' => $piDiv,
                            'data_fim' => $pfDiv,
                            'filtro' => 'divergencia',
                        ]);
                    } else {
                        $hrefDiv = '/rh/ponto?filtro=divergencia';
                    }
                    if ($this->emitir([
                        'chave' => $chave,
                        'codigo' => $codigo,
                        'severidade' => $critico ? 'critica' : 'alta',
                        'titulo' => $critico
                            ? 'Divergência crítica há ' . $idade . 'h — ' . $nome
                            : 'Divergência aberta — ' . $nome,
                        'detalhe' => mb_substr((string) ($row['motivo'] ?? ''), 0, 500),
                        'href' => $hrefDiv,
                        'periodo_inicio' => $piDiv !== '' ? $piDiv : null,
                        'periodo_fim' => $pfDiv !== '' ? $pfDiv : null,
                        'colaborador_id' => (int) $row['colaborador_id'] ?: null,
                        'espelho_id' => $espelhoId ?: null,
                        'documento_id' => (int) $row['documento_id'] ?: null,
                        'referencia_tipo' => 'ponto_divergencias',
                        'referencia_id' => $id,
                    ]) > 0) {
                        $divAtivas[] = $chave;
                        $ativas[] = $chave;
                        $emitidos++;
                    }
                }
            }
        } catch (Throwable) {
        }
        $this->resolverExceto($divAtivas, ['divergencia.aberta', 'divergencia.sla_critico']);

        // Painel do período (HE, e-mail, cobertura, gargalo)
        try {
            if ($dataInicio === null || $dataFim === null || $dataInicio === '' || $dataFim === '') {
                $periodos = (new MedicaoService($this->db))->listarPeriodosGerados(1);
                if ($periodos !== []) {
                    $dataInicio = (string) $periodos[0]['data_inicio'];
                    $dataFim = (string) $periodos[0]['data_fim'];
                }
            }
            if ($dataInicio && $dataFim) {
                $painel = (new PainelExcecoesService($this->db))->carregar($dataInicio, $dataFim);
                $qs = http_build_query(['data_inicio' => $dataInicio, 'data_fim' => $dataFim]);
                $periodoKeys = [];

                foreach (($painel['cobertura']['lista'] ?? []) as $item) {
                    $cid = (int) ($item['colaborador_id'] ?? $item['id'] ?? 0);
                    if ($cid <= 0) {
                        continue;
                    }
                    $chave = 'cobertura.sem_espelho:' . $dataInicio . ':' . $cid;
                    if ($this->emitir([
                        'chave' => $chave,
                        'codigo' => 'cobertura.sem_espelho',
                        'severidade' => 'alta',
                        'titulo' => 'Sem espelho — ' . (string) ($item['nome'] ?? 'Colaborador'),
                        'detalhe' => 'O colaborador está ativo no período, mas ainda não tem espelho gerado.',
                        'href' => '/rh/ponto?' . $qs . '&filtro=sem_espelho',
                        'periodo_inicio' => $dataInicio,
                        'periodo_fim' => $dataFim,
                        'colaborador_id' => $cid,
                    ]) > 0) {
                        $periodoKeys[] = $chave;
                        $ativas[] = $chave;
                        $emitidos++;
                    }
                }

                foreach (($painel['kpis']['he']['itens'] ?? []) as $item) {
                    $eid = (int) ($item['espelho_id'] ?? 0);
                    $cid = (int) ($item['colaborador_id'] ?? 0);
                    if ($eid <= 0) {
                        continue;
                    }
                    $extra = ((int) ($item['qtd_dias'] ?? 0)) . ' dia(s) com horas extras declaradas';
                    if (!empty($item['horas_fmt'])) {
                        $extra .= ' · ' . $item['horas_fmt'];
                    }
                    $chave = 'he.bloqueante:' . $eid;
                    if ($this->emitir([
                        'chave' => $chave,
                        'codigo' => 'he.bloqueante',
                        'severidade' => 'alta',
                        'titulo' => 'Horas extras aguardando tratamento — ' . (string) ($item['nome'] ?? 'Colaborador'),
                        'detalhe' => $extra,
                        'href' => '/rh/ponto/' . $eid . '?' . $qs,
                        'periodo_inicio' => $dataInicio,
                        'periodo_fim' => $dataFim,
                        'colaborador_id' => $cid ?: null,
                        'espelho_id' => $eid,
                    ]) > 0) {
                        $periodoKeys[] = $chave;
                        $ativas[] = $chave;
                        $emitidos++;
                    }
                }

                foreach (($painel['kpis']['email']['itens'] ?? []) as $item) {
                    $eid = (int) ($item['espelho_id'] ?? 0);
                    $cid = (int) ($item['colaborador_id'] ?? 0);
                    $tipo = (string) ($item['tipo'] ?? 'falha');
                    $codigo = match ($tipo) {
                        'sem_email' => 'email.sem_email',
                        'invalido' => 'email.invalido',
                        default => 'email.falha',
                    };
                    $chave = $codigo . ':esp:' . $eid . ':' . $cid;
                    $href = $tipo === 'falha'
                        ? ('/rh/medicao?' . $qs . '&filtro=email&subfiltro=email_falha')
                        : ('/rh/colaboradores/' . $cid . '/editar');
                    if ($this->emitir([
                        'chave' => $chave,
                        'codigo' => $codigo,
                        'severidade' => 'alta',
                        'titulo' => 'Problema no envio — ' . (string) ($item['nome'] ?? 'Colaborador'),
                        'detalhe' => (string) ($item['motivo'] ?? 'Falha ou ausência de e-mail na folha.'),
                        'href' => $href,
                        'periodo_inicio' => $dataInicio,
                        'periodo_fim' => $dataFim,
                        'colaborador_id' => $cid ?: null,
                        'espelho_id' => $eid ?: null,
                    ]) > 0) {
                        $periodoKeys[] = $chave;
                        $ativas[] = $chave;
                        $emitidos++;
                    }
                }

                $gargalo = $painel['gargalo'] ?? [];
                $gChave = (string) ($gargalo['chave'] ?? '');
                $gQtd = (int) ($gargalo['quantidade'] ?? $gargalo['total'] ?? 0);
                if ($gChave !== '' && !in_array($gChave, ['dossie'], true) && $gQtd > 0) {
                    $chave = 'gargalo:' . $dataInicio . ':' . $gChave;
                    if ($this->emitir([
                        'chave' => $chave,
                        'codigo' => 'gargalo.' . $gChave,
                        'severidade' => in_array($gChave, ['email', 'he', 'sem_espelho'], true) ? 'alta' : 'media',
                        'titulo' => (string) ($gargalo['titulo'] ?? ('Gargalo: ' . $gChave)),
                        'detalhe' => (string) ($gargalo['subtitulo'] ?? $gargalo['cta_label'] ?? ''),
                        'href' => (string) ($gargalo['cta_href'] ?? $gargalo['href'] ?? ('/rh/medicao?' . $qs)),
                        'periodo_inicio' => $dataInicio,
                        'periodo_fim' => $dataFim,
                    ]) > 0) {
                        $periodoKeys[] = $chave;
                        $ativas[] = $chave;
                        $emitidos++;
                    }
                }

                $ass = $painel['kpis']['assinaturas'] ?? [];
                $fase = (string) ($ass['fase'] ?? 'aberto');
                $totalAss = (int) ($ass['total'] ?? 0);
                if ($totalAss > 0 && in_array($fase, ['du1', 'du2', 'vencido'], true)) {
                    $chave = 'assinatura.atraso:' . $dataInicio . ':' . $fase;
                    $critAss = ($fase === 'vencido' || $fase === 'du2');
                    if ($this->emitir([
                        'chave' => $chave,
                        'codigo' => 'assinatura.atraso',
                        'severidade' => $critAss ? 'critica' : 'alta',
                        'forcar_severidade' => $critAss,
                        'titulo' => 'Assinaturas em atraso — ' . $totalAss . ' pendência(s)',
                        'detalhe' => (string) ($ass['cta_label'] ?? 'Há folhas ou relatórios aguardando assinatura.'),
                        'href' => (string) ($ass['href'] ?? ('/rh/medicao?' . $qs . '&filtro=assinaturas')),
                        'periodo_inicio' => $dataInicio,
                        'periodo_fim' => $dataFim,
                    ]) > 0) {
                        $periodoKeys[] = $chave;
                        $ativas[] = $chave;
                        $emitidos++;
                    }
                }

                // resolve operacionais do período que sumiram (restrito ao período sincronizado)
                $codigosPeriodo = [
                    'cobertura.sem_espelho', 'he.bloqueante', 'email.sem_email', 'email.falha', 'email.invalido',
                    'assinatura.atraso',
                ];
                $this->resolverExceto($periodoKeys, $codigosPeriodo, $dataInicio, $dataFim);
                $this->resolverGargalosPeriodo($dataInicio, $periodoKeys);
            }
        } catch (Throwable) {
        }

        $emitidos += $this->sincronizarCobrancaFalhas($ativas);

        return ['emitidos' => $emitidos, 'resolvidos' => 0];
    }

    /**
     * Materializa falhas recentes da régua de cobrança (folha/RA), ignorando documentos já assinados
     * ou que tiveram disparo posterior bem-sucedido.
     *
     * @param list<string> $ativas
     */
    private function sincronizarCobrancaFalhas(array &$ativas): int
    {
        $emitidos = 0;
        $cobAtivas = [];
        try {
            if (!$this->tableExists('cobranca_envios')) {
                return 0;
            }
            $hasRaDocs = $this->tableExists('ra_documentos');
            $raJoin = $hasRaDocs ? 'LEFT JOIN ra_documentos rd ON ce.tipo = \'ra\' AND rd.id = ce.documento_id' : '';
            $raWhere = $hasRaDocs ? "AND (ce.tipo <> 'ra' OR rd.id IS NULL OR rd.status NOT IN ('assinado', 'invalidado'))" : '';

            $rows = $this->db->query(
                "SELECT ce.id, ce.tipo, ce.documento_id, ce.periodo_id, ce.nivel, ce.destinatario,
                        ce.erro_mensagem, ce.created_at, pp.data_inicio, pp.data_fim
                 FROM cobranca_envios ce
                 LEFT JOIN ponto_periodos pp ON pp.id = ce.periodo_id
                 LEFT JOIN documentos d ON ce.tipo = 'folha' AND d.id = ce.documento_id
                 {$raJoin}
                 WHERE ce.status = 'falha'
                   AND ce.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                   AND (ce.tipo <> 'folha' OR d.id IS NULL OR d.status NOT IN ('assinado', 'invalidado'))
                   {$raWhere}
                   AND NOT EXISTS (
                       SELECT 1 FROM cobranca_envios ce2
                       WHERE ce2.tipo = ce.tipo
                         AND ce2.documento_id = ce.documento_id
                         AND ce2.status = 'enviado'
                         AND ce2.id > ce.id
                   )
                 ORDER BY ce.id DESC
                 LIMIT 200"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];

            foreach ($rows as $row) {
                $tipo = (string) ($row['tipo'] ?? 'folha');
                $docId = (int) ($row['documento_id'] ?? 0);
                $nivel = (int) ($row['nivel'] ?? 0);
                if ($docId <= 0 || $nivel <= 0) {
                    continue;
                }
                $meta = $this->metaCobrancaDocumento($tipo, $docId);
                $nome = (string) ($meta['nome'] ?? 'Colaborador');
                $chave = 'cobranca.falha:' . $tipo . ':' . $docId . ':' . $nivel;
                $qs = '';
                if (!empty($row['data_inicio']) && !empty($row['data_fim'])) {
                    $qs = '?' . http_build_query([
                        'data_inicio' => (string) $row['data_inicio'],
                        'data_fim' => (string) $row['data_fim'],
                        'filtro' => 'assinaturas',
                    ]);
                } else {
                    $qs = '?filtro=assinaturas';
                }
                $erro = trim((string) ($row['erro_mensagem'] ?? ''));
                $detalhe = 'Cobrança nível ' . $nivel . ' · ' . strtoupper($tipo);
                if ($erro !== '') {
                    $detalhe .= ' — ' . $erro;
                }
                $dest = trim((string) ($row['destinatario'] ?? ''));
                if ($dest !== '' && $dest !== '-') {
                    $detalhe .= ' · ' . $dest;
                }
                if ($this->emitir([
                    'chave' => $chave,
                    'codigo' => 'cobranca.falha',
                    'severidade' => $nivel >= 3 ? 'critica' : 'alta',
                    'forcar_severidade' => $nivel >= 3,
                    'titulo' => 'Falha na cobrança de assinatura — ' . $nome,
                    'detalhe' => $detalhe,
                    'href' => '/rh/medicao' . $qs,
                    'periodo_inicio' => $row['data_inicio'] ?? null,
                    'periodo_fim' => $row['data_fim'] ?? null,
                    'colaborador_id' => $meta['colaborador_id'] ?? null,
                    'documento_id' => $tipo === 'folha' ? $docId : null,
                    'referencia_tipo' => 'cobranca_envios',
                    'referencia_id' => (int) $row['id'],
                    'payload' => [
                        'tipo' => $tipo,
                        'nivel' => $nivel,
                        'documento_id' => $docId,
                    ],
                ]) > 0) {
                    $cobAtivas[] = $chave;
                    $ativas[] = $chave;
                    $emitidos++;
                }
            }
        } catch (Throwable) {
        }
        $this->resolverExceto($cobAtivas, ['cobranca.falha']);

        return $emitidos;
    }

    /** @return array{nome?:string,colaborador_id?:?int} */
    private function metaCobrancaDocumento(string $tipo, int $documentoId): array
    {
        try {
            if ($tipo === 'ra' && $this->tableExists('ra_documentos')) {
                $st = $this->db->prepare(
                    'SELECT c.id AS colaborador_id, c.nome
                     FROM ra_documentos d
                     INNER JOIN ra_relatorios r ON r.id = d.relatorio_id
                     INNER JOIN rh_colaboradores c ON c.id = r.colaborador_id
                     WHERE d.id = :id LIMIT 1'
                );
                $st->execute(['id' => $documentoId]);
                $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];

                return [
                    'nome' => (string) ($row['nome'] ?? 'Colaborador'),
                    'colaborador_id' => isset($row['colaborador_id']) ? (int) $row['colaborador_id'] : null,
                ];
            }
            $st = $this->db->prepare(
                'SELECT c.id AS colaborador_id, c.nome
                 FROM documentos d
                 INNER JOIN ponto_espelhos e ON e.id = d.espelho_id
                 INNER JOIN rh_colaboradores c ON c.id = e.colaborador_id
                 WHERE d.id = :id LIMIT 1'
            );
            $st->execute(['id' => $documentoId]);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];

            return [
                'nome' => (string) ($row['nome'] ?? 'Colaborador'),
                'colaborador_id' => isset($row['colaborador_id']) ? (int) $row['colaborador_id'] : null,
            ];
        } catch (Throwable) {
            return ['nome' => 'Colaborador', 'colaborador_id' => null];
        }
    }

    /** @param list<string> $chavesAtivas */
    private function resolverGargalosPeriodo(string $dataInicio, array $chavesAtivas): void
    {
        $st = $this->db->prepare(
            "SELECT id, chave FROM alerta_eventos
             WHERE status = 'aberto' AND codigo LIKE 'gargalo.%' AND chave LIKE :pfx"
        );
        $st->execute(['pfx' => 'gargalo:' . $dataInicio . ':%']);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $keep = array_flip($chavesAtivas);
        foreach ($rows as $row) {
            if (!isset($keep[(string) $row['chave']])) {
                $this->resolverPorChave((string) $row['chave']);
            }
        }
    }

    /**
     * @param array{
     *   status?:string,severidade?:string,codigo?:string,q?:string,
     *   periodo_inicio?:string,periodo_fim?:string,somente_nao_lidos?:bool,
     *   sincronizar?:bool
     * } $filtros
     * @return list<array<string,mixed>>
     */
    public function listar(int $usuarioId, array $filtros = []): array
    {
        $vista = (string) ($filtros['status'] ?? 'abertos');
        $isHistorico = in_array($vista, ['resolvidos', 'dispensados'], true);
        $deveSincronizar = ($filtros['sincronizar'] ?? true) !== false;

        if (!$isHistorico && $deveSincronizar) {
            $this->sincronizarOperacional(
                $filtros['periodo_inicio'] ?? null,
                $filtros['periodo_fim'] ?? null
            );
        }

        $escopo = EscopoAcesso::contratoIds($usuarioId);
        $params = ['uid' => $usuarioId];
        $where = [];
        $diasHist = $this->configInt('historico_dias', 30);
        $limite = $this->configInt('limite_lista', 200);

        if ($vista === 'resolvidos') {
            $where[] = "e.status = 'resolvido'";
            $where[] = 'e.resolvido_em >= DATE_SUB(NOW(), INTERVAL ' . $diasHist . ' DAY)';
        } elseif ($vista === 'dispensados') {
            $where[] = 'ue.dispensado_em IS NOT NULL';
            $where[] = 'ue.dispensado_em >= DATE_SUB(NOW(), INTERVAL ' . $diasHist . ' DAY)';
        } else {
            $where[] = "e.status = 'aberto'";
            $where[] = 'ue.dispensado_em IS NULL';
            if (($filtros['somente_nao_lidos'] ?? false) === true || $vista === 'nao_lidos') {
                $where[] = 'ue.lido_em IS NULL';
            }
            if ($vista === 'meus') {
                $where[] = 'e.atribuido_usuario_id = :uid_meus';
                $params['uid_meus'] = $usuarioId;
            }
        }

        $piFiltro = trim((string) ($filtros['periodo_inicio'] ?? ''));
        $pfFiltro = trim((string) ($filtros['periodo_fim'] ?? ''));
        if ($piFiltro !== '' && $pfFiltro !== '' && empty($filtros['ignorar_periodo'])) {
            $where[] = '(e.periodo_inicio IS NULL OR (e.periodo_inicio = :pi_f AND e.periodo_fim = :pf_f))';
            $params['pi_f'] = $piFiltro;
            $params['pf_f'] = $pfFiltro;
        }

        if (!empty($filtros['severidade'])) {
            $where[] = 'e.severidade = :sev';
            $params['sev'] = $this->normSeveridade((string) $filtros['severidade']);
        }
        if (!empty($filtros['codigo'])) {
            $cod = (string) $filtros['codigo'];
            if ($cod === 'gargalo') {
                $where[] = "e.codigo LIKE 'gargalo.%'";
            } elseif ($cod === 'cobranca') {
                $where[] = "e.codigo IN ('cobranca.falha', 'assinatura.atraso')";
            } elseif ($cod === 'divergencia') {
                $where[] = "e.codigo IN ('divergencia.aberta', 'divergencia.sla_critico')";
            } else {
                $where[] = 'e.codigo = :cod';
                $params['cod'] = $cod;
            }
        }
        if (!empty($filtros['q'])) {
            $where[] = '(e.titulo LIKE :q OR e.detalhe LIKE :q OR c.nome LIKE :q)';
            $params['q'] = '%' . trim((string) $filtros['q']) . '%';
        }

        if ($escopo !== null && $escopo !== []) {
            $ph = [];
            foreach (array_values($escopo) as $i => $cid) {
                $k = 'ct' . $i;
                $ph[] = ':' . $k;
                $params[$k] = $cid;
            }
            $in = implode(',', $ph);
            $where[] = "(e.colaborador_id IS NULL OR EXISTS (
                SELECT 1 FROM rh_colaborador_lotacoes cl
                INNER JOIN rh_lotacoes l ON l.id = cl.lotacao_id
                WHERE cl.colaborador_id = e.colaborador_id
                  AND cl.vigencia_fim IS NULL
                  AND l.contrato_id IN ({$in})
            ))";
        }

        if (empty($filtros['ignorar_destinos'])) {
            $where[] = $this->sqlFiltroDestinos();
            $params['uid_dest_user'] = $usuarioId;
            $params['uid_dest_perfil'] = $usuarioId;
        }

        $order = $isHistorico
            ? 'COALESCE(e.resolvido_em, ue.dispensado_em, e.atualizado_em) DESC'
            : "FIELD(e.severidade, 'critica','alta','media','info'), "
                . "FIELD(e.codigo, 'cobranca.falha') DESC, e.aberto_em DESC";

        $sql = 'SELECT e.*,
                       ue.lido_em, ue.dispensado_em, ue.dispensado_motivo,
                       c.nome AS colaborador_nome, c.matricula AS colaborador_matricula,
                       ua.nome AS atribuido_nome,
                       CASE WHEN ue.lido_em IS NULL THEN 0 ELSE 1 END AS lido
                FROM alerta_eventos e
                LEFT JOIN alerta_usuario_estado ue
                  ON ue.alerta_id = e.id AND ue.usuario_id = :uid
                LEFT JOIN rh_colaboradores c ON c.id = e.colaborador_id
                LEFT JOIN usuarios ua ON ua.id = e.atribuido_usuario_id
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY ' . $order . '
                LIMIT ' . max(1, $limite);

        $st = $this->db->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(fn (array $r) => $this->mapRow($r), $rows);
    }

    /**
     * Retorna os contadores consolidados da fila ativa do usuário (independente do filtro de severidade/código/busca ativo)
     * para alimentar os 5 cards de KPI clicáveis no topo da Central de Alertas.
     *
     * @param array{periodo_inicio?:string,periodo_fim?:string} $filtros
     * @return array{abertos:int,nao_lidos:int,criticos:int,altas:int,cobrancas:int,meus:int}
     */
    public function contarResumo(int $usuarioId, array $filtros = []): array
    {
        $rows = $this->listar($usuarioId, [
            'status' => 'abertos',
            'periodo_inicio' => $filtros['periodo_inicio'] ?? '',
            'periodo_fim' => $filtros['periodo_fim'] ?? '',
            'sincronizar' => false,
        ]);

        $resumo = [
            'abertos' => count($rows),
            'nao_lidos' => 0,
            'criticos' => 0,
            'altas' => 0,
            'cobrancas' => 0,
            'meus' => 0,
        ];

        foreach ($rows as $r) {
            if (empty($r['lido'])) {
                $resumo['nao_lidos']++;
            }
            if (($r['severidade'] ?? '') === 'critica') {
                $resumo['criticos']++;
            }
            if (($r['severidade'] ?? '') === 'alta') {
                $resumo['altas']++;
            }
            if (in_array((string) ($r['codigo'] ?? ''), ['cobranca.falha', 'assinatura.atraso'], true)) {
                $resumo['cobrancas']++;
            }
            if ((int) ($r['atribuido_usuario_id'] ?? 0) === $usuarioId) {
                $resumo['meus']++;
            }
        }

        return $resumo;
    }

    /** @return list<array{id:int,nome:string}> */
    public function listarUsuariosAtribuicao(): array
    {
        try {
            $rows = $this->db->query(
                "SELECT DISTINCT u.id, u.nome
                 FROM usuarios u
                 INNER JOIN usuario_perfis up ON up.usuario_id = u.id
                 INNER JOIN perfil_permissoes pp ON pp.perfil_id = up.perfil_id
                 INNER JOIN permissoes p ON p.id = pp.permissao_id
                 WHERE u.status = 'ativo'
                   AND p.codigo IN ('alertas.ver', 'dashboard.ver')
                 ORDER BY u.nome"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];

            return array_map(
                static fn (array $r): array => ['id' => (int) $r['id'], 'nome' => (string) $r['nome']],
                $rows
            );
        } catch (Throwable) {
            return [];
        }
    }

    public function atribuir(int $alertaId, int $atribuidoUsuarioId, int $atorId): void
    {
        if ($alertaId <= 0 || $atribuidoUsuarioId <= 0) {
            return;
        }
        $this->db->prepare(
            "UPDATE alerta_eventos
             SET atribuido_usuario_id = :a, atribuido_em = NOW(), atribuido_por = :p, atualizado_em = NOW()
             WHERE id = :id AND status = 'aberto'"
        )->execute([
            'a' => $atribuidoUsuarioId,
            'p' => $atorId > 0 ? $atorId : null,
            'id' => $alertaId,
        ]);
        $this->auditar($alertaId, 'atribuir', [
            'atribuido_usuario_id' => $atribuidoUsuarioId,
            'ator_id' => $atorId,
        ], $atorId);
    }

    public function desatribuir(int $alertaId, int $atorId): void
    {
        if ($alertaId <= 0) {
            return;
        }
        $this->db->prepare(
            "UPDATE alerta_eventos
             SET atribuido_usuario_id = NULL, atribuido_em = NULL, atribuido_por = NULL, atualizado_em = NOW()
             WHERE id = :id"
        )->execute(['id' => $alertaId]);
        $this->auditar($alertaId, 'desatribuir', ['ator_id' => $atorId], $atorId);
    }

    /** @param array<string,mixed> $dados */
    private function auditar(int $alertaId, string $acao, array $dados, ?int $usuarioId): void
    {
        try {
            if (!$this->tableExists('rh_auditoria')) {
                return;
            }
            $this->db->prepare(
                'INSERT INTO rh_auditoria (entidade, entidade_id, acao, depois_json, usuario_id)
                 VALUES (\'alerta_eventos\', :id, :acao, :depois, :uid)'
            )->execute([
                'id' => $alertaId,
                'acao' => mb_substr($acao, 0, 60),
                'depois' => json_encode($dados, JSON_UNESCAPED_UNICODE),
                'uid' => $usuarioId !== null && $usuarioId > 0 ? $usuarioId : null,
            ]);
        } catch (Throwable) {
        }
    }

    public function contarNaoLidos(?int $usuarioId = null, bool $sincronizar = false): int
    {
        $usuarioId ??= Auth::id();
        if ($usuarioId === null || $usuarioId <= 0) {
            return 0;
        }
        try {
            if ($sincronizar) {
                $this->sincronizarOperacional();
            }
            $escopo = EscopoAcesso::contratoIds($usuarioId);
            $params = ['uid' => $usuarioId];
            $where = "e.status = 'aberto' AND ue.dispensado_em IS NULL AND ue.lido_em IS NULL";
            if ($escopo !== null && $escopo !== []) {
                $ph = [];
                foreach (array_values($escopo) as $i => $cid) {
                    $k = 'ct' . $i;
                    $ph[] = ':' . $k;
                    $params[$k] = $cid;
                }
                $in = implode(',', $ph);
                $where .= " AND (e.colaborador_id IS NULL OR EXISTS (
                    SELECT 1 FROM rh_colaborador_lotacoes cl
                    INNER JOIN rh_lotacoes l ON l.id = cl.lotacao_id
                    WHERE cl.colaborador_id = e.colaborador_id
                      AND cl.vigencia_fim IS NULL
                      AND l.contrato_id IN ({$in})
                ))";
            }
            $where .= ' AND ' . $this->sqlFiltroDestinos();
            $params['uid_dest_user'] = $usuarioId;
            $params['uid_dest_perfil'] = $usuarioId;
            $sql = "SELECT COUNT(*) FROM alerta_eventos e
                    LEFT JOIN alerta_usuario_estado ue
                      ON ue.alerta_id = e.id AND ue.usuario_id = :uid
                    WHERE {$where}";
            $st = $this->db->prepare($sql);
            $st->execute($params);

            return (int) $st->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    }

    public function marcarLido(int $usuarioId, int $alertaId): void
    {
        $this->db->prepare(
            'INSERT INTO alerta_usuario_estado (usuario_id, alerta_id, lido_em)
             VALUES (:u, :a, NOW())
             ON DUPLICATE KEY UPDATE lido_em = COALESCE(lido_em, NOW())'
        )->execute(['u' => $usuarioId, 'a' => $alertaId]);
    }

    public function marcarTodosLidos(int $usuarioId): int
    {
        $itens = $this->listar($usuarioId, ['somente_nao_lidos' => true]);
        foreach ($itens as $item) {
            $this->marcarLido($usuarioId, (int) $item['id']);
        }

        return count($itens);
    }

    public function dispensar(int $usuarioId, int $alertaId, ?string $motivo = null): void
    {
        $this->db->prepare(
            'INSERT INTO alerta_usuario_estado (usuario_id, alerta_id, lido_em, dispensado_em, dispensado_motivo)
             VALUES (:u, :a, NOW(), NOW(), :m)
             ON DUPLICATE KEY UPDATE
               lido_em = COALESCE(lido_em, NOW()),
               dispensado_em = NOW(),
               dispensado_motivo = VALUES(dispensado_motivo)'
        )->execute([
            'u' => $usuarioId,
            'a' => $alertaId,
            'm' => $motivo !== null ? mb_substr(trim($motivo), 0, 255) : null,
        ]);
        $this->auditar($alertaId, 'dispensar', ['motivo' => $motivo], $usuarioId);
    }

    /** Atalho estático para gatilhos em outros serviços. */
    public static function emitirEvento(array $data): void
    {
        try {
            (new self())->emitir($data);
        } catch (Throwable) {
        }
    }

    public static function resolverEvento(string $chave): void
    {
        try {
            (new self())->resolverPorChave($chave);
        } catch (Throwable) {
        }
    }

    public static function tipoEstaAtivo(string $codigo): bool
    {
        try {
            return (new self())->tipoAtivo($codigo);
        } catch (Throwable) {
            return true;
        }
    }

    /** @return list<array<string,mixed>> */
    public function listarTipos(): array
    {
        $rows = $this->db->query(
            'SELECT * FROM alerta_tipos ORDER BY ordem, codigo'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(static function (array $r): array {
            return [
                'codigo' => (string) $r['codigo'],
                'nome' => (string) $r['nome'],
                'descricao' => (string) ($r['descricao'] ?? ''),
                'grupo' => (string) ($r['grupo'] ?? ''),
                'ativo' => (int) ($r['ativo'] ?? 0) === 1,
                'severidade_padrao' => (string) ($r['severidade_padrao'] ?? 'media'),
                'sla_horas' => isset($r['sla_horas']) && $r['sla_horas'] !== null ? (int) $r['sla_horas'] : null,
                'titulo_template' => (string) ($r['titulo_template'] ?? ''),
                'href_padrao' => (string) ($r['href_padrao'] ?? ''),
                'ordem' => (int) ($r['ordem'] ?? 100),
            ];
        }, $rows);
    }

    /** @param list<array<string,mixed>> $tipos */
    public function salvarTipos(array $tipos, ?int $atorId = null): int
    {
        $st = $this->db->prepare(
            'UPDATE alerta_tipos
             SET ativo = :ativo, severidade_padrao = :sev, sla_horas = :sla,
                 nome = :nome, descricao = :desc, titulo_template = :tit, href_padrao = :href
             WHERE codigo = :c'
        );
        $n = 0;
        foreach ($tipos as $t) {
            $codigo = (string) ($t['codigo'] ?? '');
            if ($codigo === '') {
                continue;
            }
            $st->execute([
                'ativo' => !empty($t['ativo']) ? 1 : 0,
                'sev' => $this->normSeveridade((string) ($t['severidade_padrao'] ?? 'media')),
                'sla' => isset($t['sla_horas']) && $t['sla_horas'] !== '' && $t['sla_horas'] !== null
                    ? max(1, (int) $t['sla_horas']) : null,
                'nome' => mb_substr(trim((string) ($t['nome'] ?? $codigo)), 0, 120),
                'desc' => mb_substr(trim((string) ($t['descricao'] ?? '')), 0, 255),
                'tit' => mb_substr(trim((string) ($t['titulo_template'] ?? '')), 0, 200) ?: null,
                'href' => mb_substr(trim((string) ($t['href_padrao'] ?? '')), 0, 500) ?: null,
                'c' => $codigo,
            ]);
            $n++;
        }
        $this->tiposCache = null;
        $this->resolverTiposInativos();
        $this->auditar(0, 'salvar_tipos', ['qtd' => $n], $atorId);

        return $n;
    }

    /** @return array<string,list<array{tipo:string,id:int,label:string}>> */
    public function listarDestinosAgrupados(): array
    {
        $out = [];
        foreach ($this->listarTipos() as $t) {
            $out[(string) $t['codigo']] = [];
        }
        $rows = $this->db->query(
            "SELECT d.codigo, d.destino_tipo, d.destino_id,
                    CASE WHEN d.destino_tipo = 'perfil' THEN p.nome ELSE u.nome END AS label
             FROM alerta_destinos d
             LEFT JOIN perfis p ON d.destino_tipo = 'perfil' AND p.id = d.destino_id
             LEFT JOIN usuarios u ON d.destino_tipo = 'usuario' AND u.id = d.destino_id
             ORDER BY d.codigo, d.destino_tipo, label"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $r) {
            $c = (string) $r['codigo'];
            if (!isset($out[$c])) {
                $out[$c] = [];
            }
            $out[$c][] = [
                'tipo' => (string) $r['destino_tipo'],
                'id' => (int) $r['destino_id'],
                'label' => (string) ($r['label'] ?? ('#' . $r['destino_id'])),
            ];
        }

        return $out;
    }

    /**
     * @param array<string,list<array{tipo:string,id:int}|string>> $porCodigo
     *        valores aceitam "perfil:1" / "usuario:2" ou arrays
     */
    public function salvarDestinos(array $porCodigo, ?int $atorId = null): void
    {
        $del = $this->db->prepare('DELETE FROM alerta_destinos WHERE codigo = :c');
        $ins = $this->db->prepare(
            'INSERT IGNORE INTO alerta_destinos (codigo, destino_tipo, destino_id)
             VALUES (:c, :t, :id)'
        );
        foreach ($porCodigo as $codigo => $itens) {
            $codigo = (string) $codigo;
            if ($codigo === '') {
                continue;
            }
            $del->execute(['c' => $codigo]);
            if (!is_array($itens)) {
                continue;
            }
            foreach ($itens as $item) {
                $tipo = '';
                $id = 0;
                if (is_string($item) && str_contains($item, ':')) {
                    [$tipo, $idRaw] = explode(':', $item, 2);
                    $id = (int) $idRaw;
                } elseif (is_array($item)) {
                    $tipo = (string) ($item['tipo'] ?? '');
                    $id = (int) ($item['id'] ?? 0);
                }
                if (!in_array($tipo, ['perfil', 'usuario'], true) || $id <= 0) {
                    continue;
                }
                $ins->execute(['c' => $codigo, 't' => $tipo, 'id' => $id]);
            }
        }
        $this->destinosCache = null;
        $this->auditar(0, 'salvar_destinos', ['codigos' => array_keys($porCodigo)], $atorId);
    }

    /**
     * @return array{
     *   digest_ativo:bool,
     *   digest_hora:string,
     *   historico_dias:int,
     *   limite_lista:int,
     *   digest_ultimo_disparo:string,
     *   digest_ultima_data:string,
     *   digest_ultimo_resumo:string
     * }
     */
    public function obterConfig(): array
    {
        return [
            'digest_ativo' => $this->configGet('digest_ativo', '0') === '1',
            'digest_hora' => $this->configGet('digest_hora', '08:00'),
            'historico_dias' => $this->configInt('historico_dias', 30),
            'limite_lista' => $this->configInt('limite_lista', 200),
            'digest_ultimo_disparo' => $this->configGet('digest_ultimo_disparo', ''),
            'digest_ultima_data' => $this->configGet('digest_ultima_data', ''),
            'digest_ultimo_resumo' => $this->configGet('digest_ultimo_resumo', ''),
        ];
    }

    /** @param array<string,mixed> $data */
    public function salvarConfig(array $data, ?int $atorId = null): void
    {
        $hora = trim((string) ($data['digest_hora'] ?? '08:00'));
        if (!preg_match('/^\d{2}:\d{2}$/', $hora)) {
            $hora = '08:00';
        }
        $pairs = [
            'digest_ativo' => !empty($data['digest_ativo']) ? '1' : '0',
            'digest_hora' => $hora,
            'historico_dias' => (string) max(1, min(365, (int) ($data['historico_dias'] ?? 30))),
            'limite_lista' => (string) max(20, min(500, (int) ($data['limite_lista'] ?? 200))),
        ];
        $st = $this->db->prepare(
            'INSERT INTO alerta_config (chave, valor) VALUES (:k, :v)
             ON DUPLICATE KEY UPDATE valor = VALUES(valor)'
        );
        foreach ($pairs as $k => $v) {
            $st->execute(['k' => $k, 'v' => $v]);
        }
        $this->configCache = null;
        $this->auditar(0, 'salvar_config', $pairs, $atorId);
    }

    private function salvarChavesConfig(array $pairs): void
    {
        try {
            $st = $this->db->prepare(
                'INSERT INTO alerta_config (chave, valor) VALUES (:k, :v)
                 ON DUPLICATE KEY UPDATE valor = VALUES(valor)'
            );
            foreach ($pairs as $k => $v) {
                $st->execute(['k' => (string) $k, 'v' => mb_substr((string) $v, 0, 255)]);
            }
            $this->configCache = null;
        } catch (Throwable) {
        }
    }

    /** @param list<int> $ids */
    public function forcarResolver(array $ids, ?int $atorId = null): int
    {
        $n = 0;
        $st = $this->db->prepare(
            "UPDATE alerta_eventos
             SET status = 'resolvido', resolvido_em = COALESCE(resolvido_em, NOW()), atualizado_em = NOW()
             WHERE id = :id AND status = 'aberto'"
        );
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id <= 0) {
                continue;
            }
            $st->execute(['id' => $id]);
            if ($st->rowCount() > 0) {
                $this->auditar($id, 'forcar_resolver', [], $atorId);
                $n++;
            }
        }

        return $n;
    }

    /** @param list<int> $ids */
    public function reatribuirEmMassa(array $ids, int $paraUsuarioId, int $atorId): int
    {
        $n = 0;
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id <= 0) {
                continue;
            }
            if ($paraUsuarioId > 0) {
                $this->atribuir($id, $paraUsuarioId, $atorId);
            } else {
                $this->desatribuir($id, $atorId);
            }
            $n++;
        }

        return $n;
    }

    /** @param list<int> $ids */
    public function dispensarGlobal(array $ids, int $atorId, ?string $motivo = null): int
    {
        $usuarios = $this->listarUsuariosAtribuicao();
        $n = 0;
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id <= 0) {
                continue;
            }
            foreach ($usuarios as $u) {
                $this->dispensar((int) $u['id'], $id, $motivo ?? 'Dispensado globalmente pelo admin');
            }
            $this->auditar($id, 'dispensar_global', ['motivo' => $motivo], $atorId);
            $n++;
        }

        return $n;
    }

    /**
     * Dispara o resumo diário por e-mail (via agendador automático ou disparo manual/teste com $force = true).
     *
     * @return array{ok:bool,enviados:int,pulados:int,falhas?:int,erro?:string}
     */
    public function executarDigest(?string $agora = null, bool $force = false, ?int $apenasUsuarioId = null): array
    {
        $cfg = $this->obterConfig();
        $now = $agora !== null ? new DateTimeImmutable($agora) : new DateTimeImmutable('now');
        $hoje = $now->format('Y-m-d');

        if (!$force) {
            if (!$cfg['digest_ativo']) {
                return ['ok' => true, 'enviados' => 0, 'pulados' => 1, 'erro' => 'digest_desligado'];
            }
            $hora = $cfg['digest_hora'];
            if ($now->format('H:i') !== $hora) {
                return ['ok' => true, 'enviados' => 0, 'pulados' => 1, 'erro' => 'fora_da_hora'];
            }
            if (($cfg['digest_ultima_data'] ?? '') === $hoje) {
                return ['ok' => true, 'enviados' => 0, 'pulados' => 1, 'erro' => 'ja_enviado_hoje'];
            }
        }

        // Atualiza a fotografia operacional antes de contar não lidos
        try {
            $this->sincronizarOperacional();
        } catch (Throwable) {
        }

        $sqlUsers = "SELECT DISTINCT u.id, u.nome, u.email
                     FROM usuarios u
                     INNER JOIN usuario_perfis up ON up.usuario_id = u.id
                     INNER JOIN perfil_permissoes pp ON pp.perfil_id = up.perfil_id
                     INNER JOIN permissoes p ON p.id = pp.permissao_id
                     WHERE u.status = 'ativo'
                       AND p.codigo IN ('alertas.ver', 'dashboard.ver', 'alertas.gerenciar')
                       AND u.email IS NOT NULL AND u.email <> ''";
        $paramsUsers = [];
        if ($apenasUsuarioId !== null && $apenasUsuarioId > 0) {
            $sqlUsers .= ' AND u.id = :only_uid';
            $paramsUsers['only_uid'] = $apenasUsuarioId;
        }
        $stU = $this->db->prepare($sqlUsers);
        $stU->execute($paramsUsers);
        $usuarios = $stU->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $enviados = 0;
        $pulados = 0;
        $falhas = 0;
        $ultimoErro = '';
        $mail = new MailService();
        $base = rtrim(\App\Helpers\AppUrl::base(), '/');
        if ($base === '') {
            $app = require dirname(__DIR__, 2) . '/config/app.php';
            $base = rtrim((string) ($app['url'] ?? 'http://127.0.0.1:8090'), '/');
        }
        $portalUrl = $base . '/rh/alertas';

        foreach ($usuarios as $u) {
            $uid = (int) $u['id'];
            $qtd = $this->contarNaoLidos($uid, false);
            if ($qtd <= 0 && !$force) {
                $pulados++;
                continue;
            }
            $qtdExibida = max(0, $qtd);
            try {
                $content = EmailTemplateService::render('sistema.alertas_digest', [
                    'nome' => (string) $u['nome'],
                    'quantidade' => (string) $qtdExibida,
                    'portalUrl' => $portalUrl,
                    'portal_url' => $portalUrl,
                    'cta_label' => 'ABRIR CENTRAL DE ALERTAS',
                ], $this->db);
                $result = $mail->send(
                    (string) $u['email'],
                    $content['subject'],
                    $content['text'],
                    null,
                    $content['html']
                );
                if (!empty($result['ok'])) {
                    $enviados++;
                } else {
                    $falhas++;
                    $pulados++;
                    $ultimoErro = trim((string) ($result['error'] ?? 'Falha no envio SMTP/OAuth'));
                }
            } catch (Throwable $e) {
                $falhas++;
                $pulados++;
                $ultimoErro = $e->getMessage();
            }
        }

        $resumoTexto = sprintf(
            '%d enviado(s), %d sem pendências/pulado(s)%s%s',
            $enviados,
            $pulados,
            $falhas > 0 ? ", {$falhas} falha(s)" : '',
            $force ? ' (disparo manual)' : ' (automático)'
        );
        $pairsUpdate = [
            'digest_ultimo_disparo' => $now->format('Y-m-d H:i:s'),
            'digest_ultimo_resumo' => $resumoTexto,
        ];
        if (!$force && $enviados > 0) {
            $pairsUpdate['digest_ultima_data'] = $hoje;
        }
        $this->salvarChavesConfig($pairsUpdate);

        $ret = ['ok' => $falhas === 0 || $enviados > 0, 'enviados' => $enviados, 'pulados' => $pulados, 'falhas' => $falhas];
        if ($ultimoErro !== '') {
            $ret['erro'] = $ultimoErro;
        }

        return $ret;
    }

    public function tipoAtivo(string $codigo): bool
    {
        $tipo = $this->resolverTipo($codigo);
        if ($tipo === null) {
            return true;
        }

        return !empty($tipo['ativo']);
    }

    /** @return ?array<string,mixed> */
    public function resolverTipo(string $codigo): ?array
    {
        $tipos = $this->mapaTipos();
        if (isset($tipos[$codigo])) {
            return $tipos[$codigo];
        }
        if (str_starts_with($codigo, 'gargalo.') && isset($tipos['gargalo'])) {
            return $tipos['gargalo'];
        }

        return null;
    }

    public function slaHoras(string $codigo, int $default = 48): int
    {
        $tipo = $this->resolverTipo($codigo);
        if ($tipo !== null && isset($tipo['sla_horas']) && $tipo['sla_horas'] !== null) {
            return max(1, (int) $tipo['sla_horas']);
        }

        return $default;
    }

    private function resolverTiposInativos(): void
    {
        foreach ($this->mapaTipos() as $codigo => $tipo) {
            if (!empty($tipo['ativo'])) {
                continue;
            }
            if ($codigo === 'gargalo') {
                $this->db->exec(
                    "UPDATE alerta_eventos
                     SET status = 'resolvido', resolvido_em = COALESCE(resolvido_em, NOW()), atualizado_em = NOW()
                     WHERE status = 'aberto' AND codigo LIKE 'gargalo.%'"
                );
                continue;
            }
            $this->db->prepare(
                "UPDATE alerta_eventos
                 SET status = 'resolvido', resolvido_em = COALESCE(resolvido_em, NOW()), atualizado_em = NOW()
                 WHERE status = 'aberto' AND codigo = :c"
            )->execute(['c' => $codigo]);
        }
    }

    private function sqlFiltroDestinos(): string
    {
        // Placeholders distintos: PDO com EMULATE_PREPARES=false não reutiliza :uid.
        return "(
            NOT EXISTS (
                SELECT 1 FROM alerta_destinos d
                WHERE d.codigo = e.codigo
                   OR (d.codigo = 'gargalo' AND e.codigo LIKE 'gargalo.%')
            )
            OR EXISTS (
                SELECT 1 FROM alerta_destinos d
                WHERE (d.codigo = e.codigo OR (d.codigo = 'gargalo' AND e.codigo LIKE 'gargalo.%'))
                  AND (
                    (d.destino_tipo = 'usuario' AND d.destino_id = :uid_dest_user)
                    OR (d.destino_tipo = 'perfil' AND EXISTS (
                        SELECT 1 FROM usuario_perfis up
                        WHERE up.usuario_id = :uid_dest_perfil AND up.perfil_id = d.destino_id
                    ))
                  )
            )
        )";
    }

    /** @return array<string,array<string,mixed>> */
    private function mapaTipos(): array
    {
        if ($this->tiposCache !== null) {
            return $this->tiposCache;
        }
        $map = [];
        try {
            foreach ($this->listarTipos() as $t) {
                $map[(string) $t['codigo']] = $t;
            }
        } catch (Throwable) {
        }
        $this->tiposCache = $map;

        return $map;
    }

    private function configGet(string $chave, string $default = ''): string
    {
        $all = $this->mapaConfig();

        return $all[$chave] ?? $default;
    }

    private function configInt(string $chave, int $default): int
    {
        $v = $this->configGet($chave, (string) $default);

        return is_numeric($v) ? (int) $v : $default;
    }

    /** @return array<string,string> */
    private function mapaConfig(): array
    {
        if ($this->configCache !== null) {
            return $this->configCache;
        }
        $map = [];
        try {
            $rows = $this->db->query('SELECT chave, valor FROM alerta_config')->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $r) {
                $map[(string) $r['chave']] = (string) $r['valor'];
            }
        } catch (Throwable) {
        }
        $this->configCache = $map;

        return $map;
    }

    /** @param array<string,mixed> $row */
    private function mapRow(array $row): array
    {
        $sev = (string) ($row['severidade'] ?? 'media');
        $codigo = (string) ($row['codigo'] ?? '');
        $tipo = $this->resolverTipo($codigo);
        $tipoNome = (string) ($tipo['nome'] ?? $codigo);
        $grupo = (string) ($tipo['grupo'] ?? 'Operação');
        if (str_starts_with($codigo, 'gargalo.')) {
            $etapaSlug = substr($codigo, strlen('gargalo.'));
            $etapaMap = [
                'email' => 'Envio de E-mail (Etapa 6)',
                'assinaturas' => 'Assinaturas (Etapa 7)',
                'he' => 'Horas Extras (Etapa 2)',
                'ponto' => 'Conferência de Ponto (Etapa 2)',
                'sem_espelho' => 'Cobertura de Espelho (Etapa 1)',
                'folhas' => 'Emissão de Folha (Etapa 4)',
                'ra' => 'Relatório de Atividades (Etapa 3)',
                'portal' => 'Links de Assinatura (Etapa 5)',
            ];
            $tipoNome = 'Gargalo · ' . ($etapaMap[$etapaSlug] ?? ucfirst($etapaSlug));
        }

        $tom = match ($sev) {
            'critica' => 'danger',
            'alta' => 'warning',
            'info' => 'info',
            default => 'neutral',
        };
        $aberto = (string) ($row['aberto_em'] ?? '');
        $idade = '';
        if ($aberto !== '') {
            try {
                $dt = new DateTimeImmutable($aberto);
                $diff = $dt->diff(new DateTimeImmutable('now'));
                if ($diff->days > 0) {
                    $idade = $diff->days . 'd';
                } elseif ($diff->h > 0) {
                    $idade = $diff->h . 'h';
                } else {
                    $idade = max(1, $diff->i) . 'min';
                }
            } catch (Throwable) {
            }
        }

        $pi = (string) ($row['periodo_inicio'] ?? '');
        $pf = (string) ($row['periodo_fim'] ?? '');
        $periodoRotulo = '';
        if ($pi !== '' && $pf !== '') {
            $tsI = strtotime($pi);
            $tsF = strtotime($pf);
            if ($tsI !== false && $tsF !== false) {
                $periodoRotulo = date('d/m', $tsI) . ' a ' . date('d/m/Y', $tsF);
            }
        }

        $href = (string) ($row['href'] ?? '');
        $ctaLabel = 'Verificar pendência';
        if (str_starts_with($href, '/rh/email')) {
            $ctaLabel = 'Configurar e-mail';
        } elseif (str_starts_with($href, '/rh/colaboradores')) {
            $ctaLabel = 'Revisar cadastro';
        } elseif (str_starts_with($href, '/rh/ponto')) {
            $ctaLabel = 'Resolver no Ponto';
        } elseif (str_starts_with($href, '/rh/medicao')) {
            $ctaLabel = 'Abrir na Medição';
        }

        $status = (string) ($row['status'] ?? 'aberto');
        $destaque = $codigo === 'cobranca.falha' || $sev === 'critica';

        return [
            'id' => (int) $row['id'],
            'chave' => (string) $row['chave'],
            'codigo' => $codigo,
            'tipo_nome' => $tipoNome,
            'grupo' => $grupo,
            'severidade' => $sev,
            'tom' => $tom,
            'status' => $status,
            'titulo' => (string) $row['titulo'],
            'detalhe' => (string) ($row['detalhe'] ?? ''),
            'href' => $href,
            'cta_label' => $ctaLabel,
            'colaborador_id' => isset($row['colaborador_id']) ? (int) $row['colaborador_id'] : null,
            'colaborador_nome' => (string) ($row['colaborador_nome'] ?? ''),
            'colaborador_matricula' => (string) ($row['colaborador_matricula'] ?? ''),
            'periodo_inicio' => $row['periodo_inicio'] ?? null,
            'periodo_fim' => $row['periodo_fim'] ?? null,
            'periodo_rotulo' => $periodoRotulo,
            'aberto_em' => $aberto,
            'resolvido_em' => (string) ($row['resolvido_em'] ?? ''),
            'dispensado_em' => (string) ($row['dispensado_em'] ?? ''),
            'idade' => $idade,
            'lido' => (int) ($row['lido'] ?? 0) === 1,
            'destaque' => $destaque,
            'espelho_id' => isset($row['espelho_id']) ? (int) $row['espelho_id'] : null,
            'documento_id' => isset($row['documento_id']) ? (int) $row['documento_id'] : null,
            'atribuido_usuario_id' => isset($row['atribuido_usuario_id']) && $row['atribuido_usuario_id'] !== null
                ? (int) $row['atribuido_usuario_id'] : null,
            'atribuido_nome' => (string) ($row['atribuido_nome'] ?? ''),
        ];
    }

    private static function defaultTituloTemplate(string $codigo): string
    {
        $busca = str_starts_with($codigo, 'gargalo.') ? 'gargalo' : $codigo;
        foreach (self::catalogoTiposPadrao() as $row) {
            if ($row['codigo'] === $busca) {
                return (string) ($row['titulo_template'] ?? '');
            }
        }
        return '';
    }

    private static function defaultHrefPadrao(string $codigo): string
    {
        $busca = str_starts_with($codigo, 'gargalo.') ? 'gargalo' : $codigo;
        foreach (self::catalogoTiposPadrao() as $row) {
            if ($row['codigo'] === $busca) {
                return (string) ($row['href_padrao'] ?? '');
            }
        }
        return '';
    }

    private function normSeveridade(string $s): string
    {
        $s = strtolower(trim($s));

        return in_array($s, ['info', 'media', 'alta', 'critica'], true) ? $s : 'media';
    }

    private function tableExists(string $table): bool
    {
        try {
            $st = $this->db->prepare(
                'SELECT 1 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t LIMIT 1'
            );
            $st->execute(['t' => $table]);

            return (bool) $st->fetchColumn();
        } catch (Throwable) {
            return false;
        }
    }
}
