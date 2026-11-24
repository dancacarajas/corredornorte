<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\DiaUtil;
use App\Helpers\EmailDeliverability;
use PDO;
use Throwable;

/**
 * E-mails do Relatório de Atividades: disponível para preenchimento.
 * Cobranças de preenchimento/assinatura ficam em CobrancaAssinaturaService.
 */
final class RelatorioAtividadesNotificacaoService
{
    private static bool $schemaOk = false;

    public function __construct(private PDO $db)
    {
    }

    public function ensureSchema(): void
    {
        if (self::$schemaOk) {
            return;
        }
        try {
            $has = $this->db->query(
                "SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'ra_relatorios'
                   AND COLUMN_NAME = 'disponivel_notificado_em'
                 LIMIT 1"
            )->fetchColumn();
            if (!$has) {
                $this->db->exec(
                    'ALTER TABLE ra_relatorios
                     ADD COLUMN disponivel_notificado_em DATETIME NULL AFTER status'
                );
            }
            self::$schemaOk = true;
        } catch (Throwable) {
            // tabela ainda não migrada
        }
    }

    /**
     * Avisa o colaborador que o RA do período está disponível para preenchimento.
     * Idempotente: só envia 1x (grava disponivel_notificado_em).
     *
     * @return array{ok:bool,skipped:?string,erro:?string}
     */
    public function notificarDisponivel(int $relatorioId): array
    {
        $this->ensureSchema();
        $st = $this->db->prepare(
            "SELECT r.id, r.status, r.disponivel_notificado_em, r.colaborador_id,
                    c.nome, c.matricula, c.email_corp,
                    pp.data_inicio, pp.data_fim,
                    DATE_FORMAT(pp.data_inicio, '%Y-%m') AS competencia
             FROM ra_relatorios r
             INNER JOIN rh_colaboradores c ON c.id = r.colaborador_id
             INNER JOIN ponto_periodos pp ON pp.id = r.periodo_id
             WHERE r.id = ?
             LIMIT 1"
        );
        $st->execute([$relatorioId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return ['ok' => false, 'skipped' => 'RA não encontrado.', 'erro' => null];
        }
        if ((string) ($row['status'] ?? '') !== 'rascunho') {
            return ['ok' => true, 'skipped' => 'RA não está em rascunho.', 'erro' => null];
        }
        if (!empty($row['disponivel_notificado_em'])) {
            return ['ok' => true, 'skipped' => 'Já notificado.', 'erro' => null];
        }

        $to = trim((string) ($row['email_corp'] ?? ''));
        $check = EmailDeliverability::validate($to);
        if (!$check['ok']) {
            return ['ok' => false, 'skipped' => null, 'erro' => (string) $check['erro']];
        }

        $periodoLabel = DiaUtil::formatBr((string) $row['data_inicio'])
            . ' a ' . DiaUtil::formatBr((string) $row['data_fim']);
        $portalUrl = $this->baseUrl() . '/he/relatorios/' . $relatorioId;

        try {
            $content = EmailTemplateService::render('ra.disponivel', [
                'nome' => (string) ($row['nome'] ?? 'Colaborador'),
                'matricula' => (string) ($row['matricula'] ?? ''),
                'periodo' => $periodoLabel,
                'competencia' => (string) ($row['competencia'] ?? ''),
                'portalUrl' => $portalUrl,
                'cta_label' => 'PREENCHER RELATÓRIO',
            ], $this->db);

            $mail = new MailService();
            $result = $mail->send(
                $to,
                $content['subject'],
                $content['text'],
                null,
                $content['html']
            );
            if (empty($result['ok'])) {
                return ['ok' => false, 'skipped' => null, 'erro' => (string) ($result['erro'] ?? 'Falha no envio')];
            }

            $this->db->prepare(
                'UPDATE ra_relatorios SET disponivel_notificado_em = NOW() WHERE id = ? AND disponivel_notificado_em IS NULL'
            )->execute([$relatorioId]);

            return ['ok' => true, 'skipped' => null, 'erro' => null];
        } catch (Throwable $e) {
            return ['ok' => false, 'skipped' => null, 'erro' => mb_substr($e->getMessage(), 0, 250)];
        }
    }

    private function baseUrl(): string
    {
        return \App\Helpers\AppUrl::base(dirname(__DIR__, 2));
    }
}
