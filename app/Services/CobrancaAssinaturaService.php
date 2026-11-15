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

            return $meta + ['status' => 'falha', 'erro' => $check['erro']];
        }

        $portalUrl = $this->portalUrl($tipo, (string) $item['portal_token']);
        $tplKey = $tipo . '.cobranca_' . $nivel;
        $cta = match ($nivel) {
            1 => 'ASSINAR AGORA',
            2 => 'ASSINAR HOJE',
            default => 'PRAZO FINAL — ASSINAR',
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
                'documento_tipo' => $tipo === 'ra' ? 'Relatório de Atividades' : 'Folha de Ponto',
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

            return $meta + ['status' => $ok ? 'enviado' : 'falha', 'erro' => $erro];
        } catch (Throwable $e) {
            $erro = mb_substr($e->getMessage(), 0, 250);
            $this->registrar($tipo, $documentoId, $periodoId, $nivel, $to, false, $erro);

            return $meta + ['status' => 'falha', 'erro' => $erro];
        }
    }

    private function portalUrl(string $tipo, string $token): string
    {
        $base = $this->baseUrl();

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
