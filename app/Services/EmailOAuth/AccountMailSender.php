<?php

declare(strict_types=1);

namespace App\Services\EmailOAuth;

use RuntimeException;

/** Envia pela conta ativa (OAuth ou SMTP usuário/senha). */
final class AccountMailSender
{
    public function __construct(private EmailAccountService $accounts)
    {
    }

    /**
     * @return array{ok:bool,erro:?string,provedor:?string,conta_id:?int}
     */
    public function send(string $to, string $subject, string $body, ?string $attachmentPath = null): array
    {
        try {
            $conta = $this->accounts->contaAtiva();
            if ($conta === null) {
                return ['ok' => false, 'erro' => 'Nenhuma conta de e-mail conectada.', 'provedor' => null, 'conta_id' => null];
            }
            if (($conta['auth_mode'] ?? 'oauth') === 'smtp') {
                return (new SmtpMailSender($this->accounts))->sendWithConta($conta, $to, $subject, $body, $attachmentPath);
            }

            return (new OAuthMailSender($this->accounts))->send($to, $subject, $body, $attachmentPath);
        } catch (RuntimeException $e) {
            return ['ok' => false, 'erro' => $e->getMessage(), 'provedor' => null, 'conta_id' => null];
        }
    }
}
