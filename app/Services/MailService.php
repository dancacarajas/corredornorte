<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\Db;
use App\Helpers\EmailDeliverability;
use App\Services\EmailOAuth\AccountMailSender;
use App\Services\EmailOAuth\EmailAccountService;
use PDO;

final class MailService
{
    /**
     * Prioridade:
     * 1) Conta Gmail/Microsoft conectada (OAuth ou SMTP usuário/senha)
     * 2) mail() do PHP se ARCADIS_MAIL_ENABLED=1
     * 3) Modo local (sucesso lógico)
     *
     * @return array{ok:bool,erro:?string}
     */
    public function send(
        string $to,
        string $subject,
        string $body,
        ?string $attachmentPath = null,
        ?string $htmlBody = null
    ): array {
        $to = trim($to);
        if (preg_match('/[\r\n\0]/', $to . $subject) === 1 || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            return ['ok' => false, 'erro' => 'Destinatário ou assunto de e-mail inválido.'];
        }
        $deliver = EmailDeliverability::validate($to);
        if (!$deliver['ok']) {
            return ['ok' => false, 'erro' => $deliver['erro']];
        }

        $pdo = Db::tryConnection();
        if ($pdo instanceof PDO) {
            try {
                $accounts = new EmailAccountService($pdo);
                $ativa = $accounts->contaAtiva();
                if ($ativa !== null && ($ativa['status'] ?? '') !== 'desconectado') {
                    $result = (new AccountMailSender($accounts))->send($to, $subject, $body, $attachmentPath, $htmlBody);
                    if ($result['ok']) {
                        return ['ok' => true, 'erro' => null];
                    }
                    // Se conta conectada falhar, tenta fallback abaixo
                    $oauthErro = $result['erro'];
                }
            } catch (\Throwable) {
                // segue para fallback
            }
        }

        $cfg = require dirname(__DIR__, 2) . '/config/app.php';
        $mail = $cfg['mail'] ?? ['enabled' => false];

        if (empty($mail['enabled'])) {
            $erro = (isset($oauthErro) && is_string($oauthErro) && $oauthErro !== '')
                ? $oauthErro
                : 'Nenhum canal de e-mail ativo configurado.';

            return ['ok' => false, 'erro' => $erro];
        }

        $from = (string) ($mail['from'] ?? 'noreply@localhost');
        $fromName = (string) ($mail['from_name'] ?? 'Arcadis RH');
        $mime = \App\Services\EmailOAuth\MimeMessageBuilder::build(
            $from,
            $fromName,
            $to,
            $subject,
            $body,
            $attachmentPath,
            $htmlBody,
            false
        );

        $ok = @mail($to, $mime['subject_encoded'], $mime['body_mail'], $mime['headers_mail']);
        if ($pdo instanceof PDO) {
            try {
                (new EmailAccountService($pdo))->logEnvio(
                    null,
                    'php_mail',
                    $to,
                    $subject,
                    $ok,
                    $ok ? null : 'Falha no mail() do servidor'
                );
            } catch (\Throwable) {
            }
        }

        return $ok ? ['ok' => true, 'erro' => null] : ['ok' => false, 'erro' => 'Falha no mail() do servidor'];
    }
}
