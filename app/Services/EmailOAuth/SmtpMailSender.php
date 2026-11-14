<?php

declare(strict_types=1);

namespace App\Services\EmailOAuth;

use RuntimeException;

final class SmtpMailSender
{
    public function __construct(private EmailAccountService $accounts)
    {
    }

    /**
     * @param array<string,mixed> $conta
     * @return array{ok:bool,erro:?string,provedor:?string,conta_id:?int}
     */
    public function sendWithConta(
        array $conta,
        string $to,
        string $subject,
        string $body,
        ?string $attachmentPath = null,
        ?string $htmlBody = null
    ): array {
        $provedor = (string) ($conta['provedor'] ?? 'smtp');
        $contaId = (int) ($conta['id'] ?? 0);

        try {
            $password = TokenCrypto::decrypt((string) ($conta['password_enc'] ?? ''));
            if ($password === '') {
                throw new RuntimeException('Senha SMTP não encontrada. Reconecte a conta.');
            }

            $host = (string) ($conta['smtp_host'] ?? '');
            $port = (int) ($conta['smtp_port'] ?? 587);
            $enc = (string) ($conta['smtp_encryption'] ?? 'tls');
            $user = (string) (($conta['smtp_user'] ?? '') ?: ($conta['email'] ?? ''));
            $from = (string) ($conta['email'] ?? $user);
            $fromName = (string) (($conta['nome_exibicao'] ?? '') ?: 'Arcadis RH');

            $this->smtpSend($host, $port, $enc, $user, $password, $from, $fromName, $to, $subject, $body, $attachmentPath, $htmlBody);
            $this->accounts->logEnvio($contaId ?: null, $provedor, $to, $subject, true, null);

            return ['ok' => true, 'erro' => null, 'provedor' => $provedor, 'conta_id' => $contaId ?: null];
        } catch (RuntimeException $e) {
            if ($contaId > 0) {
                $this->accounts->marcarErro($contaId, $e->getMessage());
                $this->accounts->logEnvio($contaId, $provedor, $to, $subject, false, $e->getMessage());
            }

            return ['ok' => false, 'erro' => $e->getMessage(), 'provedor' => $provedor, 'conta_id' => $contaId ?: null];
        }
    }

    /**
     * Testa autenticação SMTP sem enviar mensagem real (EHLO + AUTH).
     *
     * @return array{ok:bool,erro:?string}
     */
    public function testAuth(string $host, int $port, string $encryption, string $user, string $password): array
    {
        try {
            $sock = $this->connect($host, $port, $encryption);
            $this->expect($sock, [220]);
            $this->cmd($sock, 'EHLO arcadis.local', [250]);
            if ($encryption === 'tls') {
                $this->cmd($sock, 'STARTTLS', [220]);
                if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('Falha no STARTTLS.');
                }
                $this->cmd($sock, 'EHLO arcadis.local', [250]);
            }
            $this->authenticate($sock, $user, $password);
            $this->cmd($sock, 'QUIT', [221, 250]);
            fclose($sock);

            return ['ok' => true, 'erro' => null];
        } catch (RuntimeException $e) {
            return ['ok' => false, 'erro' => $e->getMessage()];
        }
    }

    private function smtpSend(
        string $host,
        int $port,
        string $encryption,
        string $user,
        string $password,
        string $from,
        string $fromName,
        string $to,
        string $subject,
        string $body,
        ?string $attachmentPath,
        ?string $htmlBody = null
    ): void {
        $from = trim(str_replace(["\r", "\n", "\0"], '', $from));
        $to = trim(str_replace(["\r", "\n", "\0"], '', $to));
        $fromName = trim(str_replace(["\r", "\n", "\0"], '', $fromName));
        if (filter_var($from, FILTER_VALIDATE_EMAIL) === false || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('Endereço de remetente ou destinatário SMTP inválido.');
        }

        $sock = $this->connect($host, $port, $encryption);
        $this->expect($sock, [220]);
        $this->cmd($sock, 'EHLO arcadis.local', [250]);
        if ($encryption === 'tls') {
            $this->cmd($sock, 'STARTTLS', [220]);
            if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('Falha no STARTTLS.');
            }
            $this->cmd($sock, 'EHLO arcadis.local', [250]);
        }
        $this->authenticate($sock, $user, $password);
        $this->cmd($sock, 'MAIL FROM:<' . $from . '>', [250]);
        $this->cmd($sock, 'RCPT TO:<' . $to . '>', [250, 251]);
        $this->cmd($sock, 'DATA', [354]);

        $mime = MimeMessageBuilder::build(
            $from,
            $fromName,
            $to,
            $subject,
            $body,
            $attachmentPath,
            $htmlBody,
            true
        )['raw'];
        $mime = preg_replace('/^\./m', '..', $mime) ?? $mime;
        fwrite($sock, $mime . "\r\n.\r\n");
        $this->expect($sock, [250]);
        $this->cmd($sock, 'QUIT', [221, 250]);
        fclose($sock);
    }

    /** @return resource */
    private function connect(string $host, int $port, string $encryption)
    {
        if ($host === '' || $port <= 0) {
            throw new RuntimeException('Host/porta SMTP inválidos.');
        }
        $remote = ($encryption === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
        $ctx = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
                'peer_name' => $host,
            ],
        ]);
        $errno = 0;
        $errstr = '';
        $sock = @stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $ctx);
        if ($sock === false) {
            throw new RuntimeException("Não conectou ao SMTP {$host}:{$port} — {$errstr}");
        }
        stream_set_timeout($sock, 20);

        return $sock;
    }

    /** @param resource $sock */
    private function authenticate($sock, string $user, string $password): void
    {
        $this->cmd($sock, 'AUTH LOGIN', [334]);
        $this->cmd($sock, base64_encode($user), [334]);
        $this->cmd($sock, base64_encode($password), [235]);
    }

    /**
     * @param resource $sock
     * @param list<int> $ok
     */
    private function cmd($sock, string $line, array $ok): void
    {
        fwrite($sock, $line . "\r\n");
        $this->expect($sock, $ok);
    }

    /**
     * @param resource $sock
     * @param list<int> $ok
     */
    private function expect($sock, array $ok): void
    {
        $response = '';
        while (($line = fgets($sock, 515)) !== false) {
            $response .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $ok, true)) {
            $msg = trim($response) !== '' ? trim($response) : 'sem resposta do servidor';
            throw new RuntimeException('SMTP: ' . $msg);
        }
    }
}
