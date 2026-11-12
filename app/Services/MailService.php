<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\Db;
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
    public function send(string $to, string $subject, string $body, ?string $attachmentPath = null): array
    {
        $pdo = Db::tryConnection();
        if ($pdo instanceof PDO) {
            try {
                $accounts = new EmailAccountService($pdo);
                $ativa = $accounts->contaAtiva();
                if ($ativa !== null && ($ativa['status'] ?? '') !== 'desconectado') {
                    $result = (new AccountMailSender($accounts))->send($to, $subject, $body, $attachmentPath);
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
            if (isset($oauthErro) && is_string($oauthErro) && $oauthErro !== '') {
                return ['ok' => false, 'erro' => $oauthErro];
            }

            return ['ok' => true, 'erro' => null];
        }

        $from = (string) ($mail['from'] ?? 'noreply@localhost');
        $fromName = (string) ($mail['from_name'] ?? 'Arcadis RH');
        $boundary = 'b_' . bin2hex(random_bytes(8));
        $headers = [];
        $headers[] = 'From: ' . sprintf('"%s" <%s>', addslashes($fromName), $from);
        $headers[] = 'MIME-Version: 1.0';

        if ($attachmentPath && is_file($attachmentPath)) {
            $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';
            $filename = basename($attachmentPath);
            $fileData = chunk_split(base64_encode((string) file_get_contents($attachmentPath)));
            $message = "--{$boundary}\r\n";
            $message .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n{$body}\r\n";
            $message .= "--{$boundary}\r\n";
            $message .= "Content-Type: application/pdf; name=\"{$filename}\"\r\n";
            $message .= "Content-Transfer-Encoding: base64\r\n";
            $message .= "Content-Disposition: attachment; filename=\"{$filename}\"\r\n\r\n";
            $message .= $fileData . "\r\n--{$boundary}--";
        } else {
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
            $message = $body;
        }

        $ok = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $message, implode("\r\n", $headers));
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
