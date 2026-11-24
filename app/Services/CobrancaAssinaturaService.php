<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\DiaUtil;
use App\Helpers\EmailDeliverability;
use DateTimeImmutable;
use PDO;
use Throwable;

/**
 * Régua automática de cobrança de assinatura (folha + RA).
 *
 * Após data_fim do período:
 *  - Dia útil 1 (manhã): cobrança 1
 *  - Dia útil 2 (manhã): cobrança 2
 *  - Dia útil 2 (tarde): cobrança 3 (prazo final)
 * Domingo / sábado / feriado nacional / fora do expediente → não envia.
 */
final class CobrancaAssinaturaService
{
    private const LOOKBACK_DIAS = 45;

    public function __construct(private PDO $db)
    {
        $this->ensureSchema();
    }

    /**
     * @return array{
     *   ok:bool,
     *   skipped:?string,
     *   slot:string,
     *   hoje:string,
     *   enviados:int,
     *   falhas:int,
     *   ignorados:int,
     *   detalhes:list<array<string,mixed>>
     * }
     */
    public function executar(?string $slot = null, ?string $agora = null): array
    {
        $now = $this->parseNow($agora);
        $hoje = $now->format('Y-m-d');
        $slot = $this->resolverSlot($slot, $now);

        $base = [
            'ok' => true,
            'skipped' => null,
            'slot' => $slot,
            'hoje' => $hoje,
            'enviados' => 0,
            'falhas' => 0,
            'ignorados' => 0,
            'detalhes' => [],
        ];

        if ($slot === 'fora') {
            $base['skipped'] = 'Fora do expediente (só manhã ~09h ou tarde ~15h).';
            $base['ok'] = true;

            return $base;
        }

        if (!DiaUtil::isUtil($hoje, $this->db)) {
            $base['skipped'] = 'Hoje não é dia útil (fim de semana ou feriado).';

            return $base;
        }

        $periodos = $this->periodosCandidatos($hoje);
        if ($periodos === []) {
            $base['skipped'] = 'Nenhum período com janela de cobrança ativa.';

            return $base;
        }

        $mail = new MailService();
        $enviados = 0;
        $falhas = 0;
        $ignorados = 0;
        $detalhes = [];

        foreach ($periodos as $periodo) {
            $dataFim = (string) $periodo['data_fim'];
            $dia1 = DiaUtil::nApos($dataFim, 1, $this->db);
            $dia2 = DiaUtil::nApos($dataFim, 2, $this->db);
            $nivel = $this->nivelParaHoje($hoje, $slot, $dia1, $dia2);
            if ($nivel === null) {
                continue;
            }

            $prazoLimite = DiaUtil::formatBr($dia2);
            $periodoLabel = $this->formatPeriodo(
                (string) $periodo['data_inicio'],
                (string) $periodo['data_fim']
            );

            foreach ($this->pendentesFolha((int) $periodo['id']) as $item) {
                $r = $this->enviarUm(
                    $mail,
                    'folha',
                    (int) $item['documento_id'],
                    (int) $periodo['id'],
                    $nivel,
                    $item,
                    $periodoLabel,
                    $prazoLimite
                );
                $detalhes[] = $r;
                if (($r['status'] ?? '') === 'enviado') {
                    $enviados++;
                } elseif (($r['status'] ?? '') === 'falha') {
                    $falhas++;
                } else {
                    $ignorados++;
                }
            }

            foreach ($this->pendentesRaPreenchimento((int) $periodo['id']) as $item) {
                $r = $this->enviarUm(
                    $mail,
                    'ra_preenchimento',
                    (int) $item['documento_id'],
                    (int) $periodo['id'],
                    $nivel,
                    $item,
                    $periodoLabel,
                    $prazoLimite
                );
                $detalhes[] = $r;
                if (($r['status'] ?? '') === 'enviado') {
                    $enviados++;
                } elseif (($r['status'] ?? '') === 'falha') {
                    $falhas++;
                } else {
                    $ignorados++;
                }
            }

            foreach ($this->pendentesRa((int) $periodo['id']) as $item) {
                $r = $this->enviarUm(
                    $mail,
                    'ra',
                    (int) $item['documento_id'],
                    (int) $periodo['id'],
                    $nivel,
                    $item,
                    $periodoLabel,
                    $prazoLimite
                );
                $detalhes[] = $r;
                if (($r['status'] ?? '') === 'enviado') {
                    $enviados++;
                } elseif (($r['status'] ?? '') === 'falha') {
                    $falhas++;
                } else {
                    $ignorados++;
                }
            }
        }

        return [
            'ok' => true,
            'skipped' => null,
            'slot' => $slot,
            'hoje' => $hoje,
            'enviados' => $enviados,
            'falhas' => $falhas,
            'ignorados' => $ignorados,
            'detalhes' => $detalhes,
        ];
    }

    private function parseNow(?string $agora): DateTimeImmutable
    {
        if ($agora !== null && $agora !== '') {
            $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $agora)
                ?: DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', $agora);
            if ($dt instanceof DateTimeImmutable) {
                return $dt;
            }
        }

        return new DateTimeImmutable('now');
    }

    /**
     * @return 'manha'|'tarde'|'fora'
     */
    private function resolverSlot(?string $slot, DateTimeImmutable $now): string
    {
        $slot = strtolower(trim((string) $slot));
        if (in_array($slot, ['manha', 'manhã', 'morning'], true)) {
            return 'manha';
        }
        if (in_array($slot, ['tarde', 'afternoon'], true)) {
            return 'tarde';
        }
        if ($slot === 'auto' || $slot === '') {
            $h = (int) $now->format('G');
            if ($h >= 8 && $h < 12) {
                return 'manha';
            }
            if ($h >= 14 && $h < 18) {
                return 'tarde';
            }

            return 'fora';
        }

        return 'fora';
    }

    private function nivelParaHoje(string $hoje, string $slot, string $dia1, string $dia2): ?int
    {
        if ($hoje === $dia1 && $slot === 'manha') {
            return 1;
        }
        if ($hoje === $dia2 && $slot === 'manha') {
            return 2;
        }
        if ($hoje === $dia2 && $slot === 'tarde') {
            return 3;
        }

        return null;
    }

    /**
     * @return list<array{id:int,data_inicio:string,data_fim:string}>
     */
    private function periodosCandidatos(string $hoje): array
    {
        $desde = (new DateTimeImmutable($hoje))
            ->sub(new \DateInterval('P' . self::LOOKBACK_DIAS . 'D'))
            ->format('Y-m-d');

        $st = $this->db->prepare(
            'SELECT id, data_inicio, data_fim
             FROM ponto_periodos
             WHERE data_fim >= :desde AND data_fim < :hoje
             ORDER BY data_fim DESC, id DESC'
        );
        $st->execute(['desde' => $desde, 'hoje' => $hoje]);

        return array_map(
            static fn (array $r): array => [
                'id' => (int) $r['id'],
                'data_inicio' => (string) $r['data_inicio'],
                'data_fim' => (string) $r['data_fim'],
            ],
            $st->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    /**
     * Folha enviada, ainda não assinada, com e-mail ok e link.
     *
     * @return list<array<string,mixed>>
     */
    private function pendentesFolha(int $periodoId): array
    {
        AssinaturaEletronicaService::ensureDivergenciaSchema($this->db);

        $st = $this->db->prepare(
            "SELECT d.id AS documento_id, d.portal_token, d.codigo_verificacao,
                    c.nome, c.matricula, c.email_corp,
                    DATE_FORMAT(pp.data_inicio, '%Y-%m') AS competencia,
                    pp.data_inicio, pp.data_fim
             FROM documentos d
             INNER JOIN ponto_espelhos e ON e.id = d.espelho_id
             INNER JOIN ponto_periodos pp ON pp.id = e.periodo_id
             INNER JOIN rh_colaboradores c ON c.id = e.colaborador_id
             WHERE pp.id = :pid
               AND e.status IN ('fechado', 'enviado')
               AND NOT EXISTS (
                    SELECT 1 FROM ponto_divergencias pd
                    WHERE pd.espelho_id = e.id AND pd.status IN ('aberta', 'em_analise')
               )
               AND d.tipo = 'folha_ponto'
               AND d.status = 'enviado'
               AND d.portal_token IS NOT NULL
               AND d.portal_token <> ''
               AND EXISTS (
                    SELECT 1 FROM emails_envios ee
                    WHERE ee.documento_id = d.id AND ee.status = 'enviado'
               )
             ORDER BY c.nome, d.id"
        );
        $st->execute(['pid' => $periodoId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($rows === []) {
            return [];
        }

        // Alinha ao critério da Etapa 5 (MedicaoService::mapEmailsOkPorDocumento):
        // só cobra documentos cujo envio anterior foi para destinatário entregável.
        $docIds = array_values(array_unique(array_filter(array_map(
            static fn (array $r): int => (int) ($r['documento_id'] ?? 0),
            $rows
        ))));
        if ($docIds === []) {
            return [];
        }

        $in = implode(',', $docIds);
        $envSt = $this->db->query(
            "SELECT documento_id, destinatario
             FROM emails_envios
             WHERE documento_id IN ({$in}) AND status = 'enviado'
             ORDER BY id DESC"
        );
        $entregaveis = [];
        foreach ($envSt ? ($envSt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [] as $env) {
            $docId = (int) $env['documento_id'];
            if (isset($entregaveis[$docId])) {
                continue;
            }
            $dest = (string) ($env['destinatario'] ?? '');
            if (EmailDeliverability::isDeliverable($dest)) {
                $entregaveis[$docId] = true;
            }
        }

        return array_values(array_filter(
            $rows,
            static fn (array $r): bool => isset($entregaveis[(int) ($r['documento_id'] ?? 0)])
        ));
    }

    /**
     * RA em rascunho ou ainda não iniciado — cobrança de preenchimento/envio.
     * Usa r.id como documento_id quando existe rascunho, ou e.id quando o RA ainda não foi criado.
     *
     * @return list<array<string,mixed>>
     */
    private function pendentesRaPreenchimento(int $periodoId): array
    {
        if (!$this->tableExists('ra_relatorios')) {
            return [];
        }

        $st = $this->db->prepare(
            "SELECT COALESCE(r.id, e.id) AS documento_id,
                    COALESCE(r.id, 0) AS relatorio_id,
                    e.id AS espelho_id,
                    '' AS portal_token,
                    '' AS codigo_verificacao,
                    c.nome, c.matricula, c.email_corp,
                    SUBSTR(pp.data_inicio, 1, 7) AS competencia,
                    pp.data_inicio, pp.data_fim
             FROM ponto_espelhos e
             INNER JOIN ponto_periodos pp ON pp.id = e.periodo_id
             INNER JOIN rh_colaboradores c ON c.id = e.colaborador_id
             LEFT JOIN ra_relatorios r
                ON r.colaborador_id = e.colaborador_id AND r.periodo_id = e.periodo_id
             WHERE e.periodo_id = :pid
               AND (r.id IS NULL OR r.status = 'rascunho')
             ORDER BY c.nome, e.id"
        );
        $st->execute(['pid' => $periodoId]);

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * RA com link, ainda não assinado.
     *
     * @return list<array<string,mixed>>
     */
    private function pendentesRa(int $periodoId): array
    {
        if (!$this->tableExists('ra_documentos')) {
            return [];
        }

        $st = $this->db->prepare(
            "SELECT d.id AS documento_id, d.portal_token, d.codigo_verificacao,
                    c.nome, c.matricula, c.email_corp,
                    DATE_FORMAT(pp.data_inicio, '%Y-%m') AS competencia,
                    pp.data_inicio, pp.data_fim
             FROM ra_documentos d
             INNER JOIN ra_relatorios r ON r.id = d.relatorio_id
             INNER JOIN ponto_periodos pp ON pp.id = r.periodo_id
             INNER JOIN rh_colaboradores c ON c.id = r.colaborador_id
             WHERE pp.id = :pid
               AND d.status = 'enviado'
               AND d.portal_token IS NOT NULL
               AND d.portal_token <> ''
               AND r.status IN ('enviado','aprovado')
             ORDER BY c.nome, d.id"
        );
        $st->execute(['pid' => $periodoId]);

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @param array<string,mixed> $item
     * @return array<string,mixed>
     */
    private function enviarUm(
        MailService $mail,
        string $tipo,
        int $documentoId,
        int $periodoId,
        int $nivel,
        array $item,
        string $periodoLabel,
        string $prazoLimite
    ): array {
        $meta = [
            'tipo' => $tipo,
            'documento_id' => $documentoId,
            'nivel' => $nivel,
            'nome' => (string) ($item['nome'] ?? ''),
            'email' => (string) ($item['email_corp'] ?? ''),
        ];

        if ($this->jaEnviado($tipo, $documentoId, $nivel)) {
            return $meta + ['status' => 'ja_enviado', 'erro' => null];
        }

        $to = trim((string) ($item['email_corp'] ?? ''));
        $check = EmailDeliverability::validate($to);
        if (!$check['ok']) {
            $this->registrar($tipo, $documentoId, $periodoId, $nivel, $to, false, $check['erro']);
            $this->emitirAlertaFalha($tipo, $documentoId, $periodoId, $nivel, $item, (string) $check['erro']);

            return $meta + ['status' => 'falha', 'erro' => $check['erro']];
        }

        $portalUrl = $this->portalUrl($tipo, (string) ($item['portal_token'] ?? ''), (int) ($item['relatorio_id'] ?? $documentoId));
        $tplKey = match ($tipo) {
            'ra_preenchimento' => 'ra.preenchimento_cobranca_' . $nivel,
            'ra' => 'ra.cobranca_' . $nivel,
            default => 'folha.cobranca_' . $nivel,
        };
        $cta = match (true) {
            $tipo === 'ra_preenchimento' && $nivel === 1 => 'PREENCHER AGORA',
            $tipo === 'ra_preenchimento' && $nivel === 2 => 'PREENCHER HOJE',
            $tipo === 'ra_preenchimento' => 'PRAZO FINAL — PREENCHER',
            $nivel === 1 => 'ASSINAR AGORA',
            $nivel === 2 => 'ASSINAR HOJE',
            default => 'PRAZO FINAL — ASSINAR',
        };
        $docTipo = match ($tipo) {
            'ra_preenchimento' => 'Relatório de Atividades (preenchimento)',
            'ra' => 'Relatório de Atividades',
            default => 'Folha de Ponto',
        };

        try {
            $content = EmailTemplateService::render($tplKey, [
                'nome' => (string) ($item['nome'] ?? 'Colaborador'),
                'matricula' => (string) ($item['matricula'] ?? ''),
                'periodo' => $periodoLabel,
                'competencia' => (string) ($item['competencia'] ?? ''),
                'codigo' => (string) ($item['codigo_verificacao'] ?? ''),
                'portalUrl' => $portalUrl,
                'cta_label' => $cta,
                'prazo_limite' => $prazoLimite,
                'nivel' => (string) $nivel,
                'documento_tipo' => $docTipo,
            ], $this->db);

            $result = $mail->send(
                $to,
                $content['subject'],
                $content['text'],
                null,
                $content['html']
            );
            $ok = !empty($result['ok']);
            $erro = $ok ? null : (string) ($result['erro'] ?? 'Falha no envio');
            $this->registrar($tipo, $documentoId, $periodoId, $nivel, $to, $ok, $erro);

            // Também registra no histórico da folha (emails_envios) para o painel.
            if ($tipo === 'folha' && $ok) {
                try {
                    (new FolhaPontoService($this->db))->registrarEnvio($documentoId, $to, true, null, $nivel + 10);
                } catch (Throwable) {
                    // não bloqueia a cobrança
                }
            }

            if ($ok) {
                AlertasService::resolverEvento('cobranca.falha:' . $tipo . ':' . $documentoId . ':' . $nivel);
            } else {
                $this->emitirAlertaFalha($tipo, $documentoId, $periodoId, $nivel, $item, (string) $erro);
            }

            return $meta + ['status' => $ok ? 'enviado' : 'falha', 'erro' => $erro];
        } catch (Throwable $e) {
            $erro = mb_substr($e->getMessage(), 0, 250);
            $this->registrar($tipo, $documentoId, $periodoId, $nivel, $to, false, $erro);
            $this->emitirAlertaFalha($tipo, $documentoId, $periodoId, $nivel, $item, $erro);

            return $meta + ['status' => 'falha', 'erro' => $erro];
        }
    }

    /** @param array<string,mixed> $item */
    private function emitirAlertaFalha(
        string $tipo,
        int $documentoId,
        int $periodoId,
        int $nivel,
        array $item,
        string $erro
    ): void {
        $nome = trim((string) ($item['nome'] ?? 'Colaborador'));
        $pi = (string) ($item['data_inicio'] ?? '');
        $pf = (string) ($item['data_fim'] ?? '');
        $qs = $pi !== '' && $pf !== ''
            ? ('?' . http_build_query(['data_inicio' => $pi, 'data_fim' => $pf, 'filtro' => 'assinaturas']))
            : '?filtro=assinaturas';
        $detalhe = 'Cobrança nível ' . $nivel . ' · ' . strtoupper($tipo);
        if ($erro !== '') {
            $detalhe .= ' — ' . $erro;
        }
        AlertasService::emitirEvento([
            'chave' => 'cobranca.falha:' . $tipo . ':' . $documentoId . ':' . $nivel,
            'codigo' => 'cobranca.falha',
            'severidade' => $nivel >= 3 ? 'critica' : 'alta',
            'titulo' => 'Falha na cobrança de assinatura — ' . ($nome !== '' ? $nome : 'Colaborador'),
            'detalhe' => $detalhe,
            'href' => '/rh/medicao' . $qs,
            'periodo_inicio' => $pi !== '' ? $pi : null,
            'periodo_fim' => $pf !== '' ? $pf : null,
            'documento_id' => $tipo === 'folha' ? $documentoId : null,
            'referencia_tipo' => 'cobranca_envios',
            'referencia_id' => $periodoId,
            'payload' => [
                'tipo' => $tipo,
                'nivel' => $nivel,
                'documento_id' => $documentoId,
                'periodo_id' => $periodoId,
            ],
        ]);
    }

    private function portalUrl(string $tipo, string $token, int $relatorioId = 0): string
    {
        $base = $this->baseUrl();
        if ($tipo === 'ra_preenchimento') {
            return $relatorioId > 0
                ? ($base . '/he/relatorios/' . $relatorioId)
                : ($base . '/he/relatorios');
        }

        return $tipo === 'ra'
            ? $base . '/assinar-relatorio/' . $token
            : $base . '/assinar/' . $token;
    }

    private function baseUrl(): string
    {
        $app = require dirname(__DIR__, 2) . '/config/app.php';
        $base = rtrim(trim((string) ($app['url'] ?? '')), '/');
        if ($base === '') {
            $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
            $base = ($https ? 'https' : 'http') . '://'
                . \App\Helpers\AppUrl::sanitizeHost((string) ($_SERVER['HTTP_HOST'] ?? '127.0.0.1'));
        }
        if (str_ends_with(strtolower($base), '/public')) {
            $base = rtrim(substr($base, 0, -7), '/');
        }

        return $base;
    }

    private function formatPeriodo(string $ini, string $fim): string
    {
        $a = DiaUtil::formatBr($ini);
        $b = DiaUtil::formatBr($fim);

        return "{$a} a {$b}";
    }

    private function jaEnviado(string $tipo, int $documentoId, int $nivel): bool
    {
        $st = $this->db->prepare(
            "SELECT 1 FROM cobranca_envios
             WHERE tipo = :t AND documento_id = :d AND nivel = :n AND status = 'enviado'
             LIMIT 1"
        );
        $st->execute(['t' => $tipo, 'd' => $documentoId, 'n' => $nivel]);

        return (bool) $st->fetchColumn();
    }

    private function registrar(
        string $tipo,
        int $documentoId,
        int $periodoId,
        int $nivel,
        string $destinatario,
        bool $ok,
        ?string $erro
    ): void {
        $status = $ok ? 'enviado' : 'falha';
        $st = $this->db->prepare(
            'INSERT INTO cobranca_envios
                (tipo, documento_id, periodo_id, nivel, destinatario, status, erro_mensagem, enviado_em)
             VALUES (:t, :d, :p, :n, :dest, :st, :erro, CASE WHEN :st2 = \'enviado\' THEN NOW() ELSE NULL END)
             ON DUPLICATE KEY UPDATE
                destinatario = VALUES(destinatario),
                status = VALUES(status),
                erro_mensagem = VALUES(erro_mensagem),
                enviado_em = VALUES(enviado_em)'
        );
        $st->execute([
            't' => $tipo,
            'd' => $documentoId,
            'p' => $periodoId,
            'n' => $nivel,
            'dest' => mb_substr($destinatario !== '' ? $destinatario : '-', 0, 160),
            'st' => $status,
            'st2' => $status,
            'erro' => $erro !== null ? mb_substr($erro, 0, 255) : null,
        ]);
    }

    private function ensureSchema(): void
    {
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS cobranca_envios (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                tipo ENUM('folha','ra','ra_preenchimento') NOT NULL,
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

        // Migra ENUM antigo (folha|ra) para incluir ra_preenchimento.
        try {
            $col = $this->db->query(
                "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'cobranca_envios'
                   AND COLUMN_NAME = 'tipo'
                 LIMIT 1"
            )->fetchColumn();
            if (is_string($col) && !str_contains($col, 'ra_preenchimento')) {
                $this->db->exec(
                    "ALTER TABLE cobranca_envios
                     MODIFY COLUMN tipo ENUM('folha','ra','ra_preenchimento') NOT NULL"
                );
            }
        } catch (Throwable) {
        }
    }

    private function tableExists(string $table): bool
    {
        $st = $this->db->prepare(
            'SELECT 1 FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t LIMIT 1'
        );
        $st->execute(['t' => $table]);

        return (bool) $st->fetchColumn();
    }
}
