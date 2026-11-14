<?php

declare(strict_types=1);

namespace App\Services\EmailOAuth;

use RuntimeException;

final class OAuthMailSender
{
    public function __construct(private EmailAccountService $accounts)
    {
    }

    /**
     * @return array{ok:bool,erro:?string,provedor:?string,conta_id:?int}
     */
    public function send(
        string $to,
        string $subject,
        string $body,
        ?string $attachmentPath = null,
        ?string $htmlBody = null
    ): array {
        try {
            $ready = $this->accounts->accessTokenPronto();
        } catch (RuntimeException $e) {
            return ['ok' => false, 'erro' => $e->getMessage(), 'provedor' => null, 'conta_id' => null];
        }

        $conta = $ready['conta'];
        $token = $ready['access_token'];
        $provedor = (string) $conta['provedor'];
        $from = (string) $conta['email'];
        $fromName = (string) ($conta['nome_exibicao'] ?: 'Arcadis RH');

        try {
            if ($provedor === 'gmail') {
                $this->sendGmail($token, $from, $fromName, $to, $subject, $body, $attachmentPath, $htmlBody);
            } else {
                $this->sendMicrosoft($token, $to, $subject, $body, $attachmentPath, $htmlBody);
            }
            $this->accounts->logEnvio((int) $conta['id'], $provedor, $to, $subject, true, null);

            return ['ok' => true, 'erro' => null, 'provedor' => $provedor, 'conta_id' => (int) $conta['id']];
        } catch (RuntimeException $e) {
            $this->accounts->marcarErro((int) $conta['id'], $e->getMessage());
            $this->accounts->logEnvio((int) $conta['id'], $provedor, $to, $subject, false, $e->getMessage());

            return ['ok' => false, 'erro' => $e->getMessage(), 'provedor' => $provedor, 'conta_id' => (int) $conta['id']];
        }
    }

    private function sendGmail(
        string $token,
        string $from,
        string $fromName,
        string $to,
        string $subject,
        string $body,
        ?string $attachmentPath,
        ?string $htmlBody = null
    ): void {
        $raw = MimeMessageBuilder::build(
            $from,
            $fromName,
            $to,
            $subject,
            $body,
            $attachmentPath,
            $htmlBody,
            false
        )['raw'];
        $encoded = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
        $cfg = $this->accounts->providerConfig('gmail');
        $res = OAuthHttp::request('POST', $cfg['send_url'], [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ], ['raw' => $encoded]);

        if ($res['status'] >= 400) {
            $msg = is_array($res['json'])
                ? (string) (($res['json']['error']['message'] ?? null) ?: json_encode($res['json'], JSON_UNESCAPED_UNICODE))
                : $res['body'];
            throw new RuntimeException('Gmail API: ' . $msg);
        }
    }

    private function sendMicrosoft(
        string $token,
        string $to,
        string $subject,
        string $body,
        ?string $attachmentPath,
        ?string $htmlBody = null
    ): void {
        $hasHtml = $htmlBody !== null && trim($htmlBody) !== '';
        $message = [
            'message' => [
                'subject' => $subject,
                'body' => [
                    'contentType' => $hasHtml ? 'HTML' : 'Text',
                    'content' => $hasHtml ? $htmlBody : $body,
                ],
                'toRecipients' => [
                    ['emailAddress' => ['address' => $to]],
                ],
            ],
            'saveToSentItems' => true,
        ];

        if ($attachmentPath && is_file($attachmentPath)) {
            $bytes = file_get_contents($attachmentPath);
            if ($bytes !== false) {
                $message['message']['attachments'] = [[
                    '@odata.type' => '#microsoft.graph.fileAttachment',
                    'name' => basename($attachmentPath),
                    'contentType' => 'application/pdf',
                    'contentBytes' => base64_encode($bytes),
                ]];
            }
        }

        $cfg = $this->accounts->providerConfig('microsoft');
        $res = OAuthHttp::request('POST', $cfg['send_url'], [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ], $message);

        if ($res['status'] >= 400) {
            $msg = is_array($res['json'])
                ? (string) (($res['json']['error']['message'] ?? null) ?: json_encode($res['json'], JSON_UNESCAPED_UNICODE))
                : $res['body'];
            throw new RuntimeException('Microsoft Graph: ' . $msg);
        }
    }
}
