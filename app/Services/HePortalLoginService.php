<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\EmailDeliverability;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Login do portal HE com CPF + OTP por e-mail e rate limit persistente.
 */
final class HePortalLoginService
{
    public const MAX_FAILS = 8;
    public const WINDOW_SECONDS = 900;
    public const OTP_TTL_SECONDS = 600;
    public const OTP_MAX_TRIES = 5;

    public function __construct(private PDO $db)
    {
        (new ContaService($this->db))->ensureSchema();
    }

    /**
     * @return array{id:int,nome:string,cpf:string,matricula:string,gestor_id:?int,email:string,status:string}|null
     */
    public function buscarColaboradorAtivoPorCpf(string $cpf): ?array
    {
        $cpf = preg_replace('/\D+/', '', $cpf) ?: '';
        if (strlen($cpf) !== 11) {
            return null;
        }
        $stmt = $this->db->prepare(
            "SELECT id, nome, cpf, matricula, gestor_id, status, email_corp, email_alt
             FROM rh_colaboradores
             WHERE cpf = :cpf
             LIMIT 1"
        );
        $stmt->execute(['cpf' => $cpf]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false || (string) $row['status'] !== 'ativo') {
            return null;
        }

        $email = trim((string) ($row['email_corp'] ?? ''));
        if ($email === '' || !EmailDeliverability::isDeliverable($email)) {
            $alt = trim((string) ($row['email_alt'] ?? ''));
            if ($alt !== '' && EmailDeliverability::isDeliverable($alt)) {
                $email = $alt;
            } else {
                $email = '';
            }
        }

        return [
            'id' => (int) $row['id'],
            'nome' => (string) $row['nome'],
            'cpf' => (string) $row['cpf'],
            'matricula' => (string) ($row['matricula'] ?? ''),
            'gestor_id' => isset($row['gestor_id']) && $row['gestor_id'] !== null
                ? (int) $row['gestor_id']
                : null,
            'email' => $email,
            'status' => (string) $row['status'],
        ];
    }

    public function bloqueado(string $cpf, string $ip): bool
    {
        $chave = $this->chave($cpf);
        try {
            $secs = self::WINDOW_SECONDS;
            $st = $this->db->prepare(
                "SELECT COUNT(*) FROM login_attempts
                 WHERE sucesso = 0
                   AND created_at >= (NOW() - INTERVAL {$secs} SECOND)
                   AND (email = :chave OR ip = :ip)"
            );
            $st->execute(['chave' => $chave, 'ip' => $ip]);

            return (int) $st->fetchColumn() >= self::MAX_FAILS;
        } catch (Throwable) {
            return false;
        }
    }

    public function registrarFalha(string $cpf, string $ip): void
    {
        $this->registrar($cpf, $ip, false);
    }

    public function registrarSucesso(string $cpf, string $ip): void
    {
        $this->registrar($cpf, $ip, true);
        try {
            $this->db->prepare(
                'DELETE FROM login_attempts WHERE email = :chave AND sucesso = 0'
            )->execute(['chave' => $this->chave($cpf)]);
        } catch (Throwable) {
        }
    }

    private function registrar(string $cpf, string $ip, bool $ok): void
    {
        try {
            $this->db->prepare(
                'INSERT INTO login_attempts (email, ip, sucesso) VALUES (:e, :ip, :s)'
            )->execute([
                'e' => $this->chave($cpf),
                'ip' => $ip,
                's' => $ok ? 1 : 0,
            ]);
        } catch (Throwable) {
        }
    }

    private function chave(string $cpf): string
    {
        $cpf = preg_replace('/\D+/', '', $cpf) ?: '';

        return 'he:' . $cpf;
    }

    /**
     * @param array{id:int,nome:string,cpf:string,email:string} $colab
     * @return array{challenge:string,mascara:string}
     */
    public function iniciarOtp(array $colab): array
    {
        if (($colab['email'] ?? '') === '') {
            throw new RuntimeException(
                'Seu cadastro não tem e-mail corporativo válido. Contate o RH para liberar o acesso.'
            );
        }

        $otp = (string) random_int(100000, 999999);
        $challenge = bin2hex(random_bytes(16));
        $_SESSION['he_otp'] = [
            'challenge' => $challenge,
            'colaborador_id' => (int) $colab['id'],
            'cpf' => (string) $colab['cpf'],
            'nome' => (string) $colab['nome'],
            'matricula' => (string) ($colab['matricula'] ?? ''),
            'gestor_id' => $colab['gestor_id'] ?? null,
            'email' => (string) $colab['email'],
            'hash' => password_hash($otp, PASSWORD_DEFAULT),
            'expires' => time() + self::OTP_TTL_SECONDS,
            'tries' => 0,
        ];

        $mascara = $this->mascararEmail((string) $colab['email']);
        try {
            $tpl = EmailTemplateService::render('auth.portal_otp', [
                'nome' => (string) $colab['nome'],
                'otp' => $otp,
                'validade' => '10 minutos',
            ], $this->db);
            $send = (new MailService())->send(
                (string) $colab['email'],
                $tpl['subject'],
                $tpl['text'],
                null,
                $tpl['html']
            );
            if (empty($send['ok'])) {
                unset($_SESSION['he_otp']);
                throw new RuntimeException(
                    'Não foi possível enviar o código de acesso. Tente novamente em alguns minutos.'
                );
            }
        } catch (RuntimeException $e) {
            throw $e;
        } catch (Throwable $e) {
            unset($_SESSION['he_otp']);
            error_log('HE OTP mail: ' . $e->getMessage());
            throw new RuntimeException(
                'Não foi possível enviar o código de acesso. Tente novamente em alguns minutos.'
            );
        }

        return ['challenge' => $challenge, 'mascara' => $mascara];
    }

    /**
     * @return array{id:int,nome:string,cpf:string,matricula:string,gestor_id:?int}|null
     */
    public function validarOtp(string $challenge, string $otp): ?array
    {
        $pending = $_SESSION['he_otp'] ?? null;
        if (!is_array($pending)) {
            return null;
        }
        if (!hash_equals((string) ($pending['challenge'] ?? ''), $challenge)) {
            return null;
        }
        if (time() > (int) ($pending['expires'] ?? 0)) {
            unset($_SESSION['he_otp']);
            return null;
        }
        $tries = (int) ($pending['tries'] ?? 0);
        if ($tries >= self::OTP_MAX_TRIES) {
            unset($_SESSION['he_otp']);
            return null;
        }

        $otp = preg_replace('/\D+/', '', $otp) ?: '';
        if (strlen($otp) !== 6 || !password_verify($otp, (string) ($pending['hash'] ?? ''))) {
            $_SESSION['he_otp']['tries'] = $tries + 1;
            return null;
        }

        unset($_SESSION['he_otp']);

        return [
            'id' => (int) $pending['colaborador_id'],
            'nome' => (string) $pending['nome'],
            'cpf' => (string) $pending['cpf'],
            'matricula' => (string) ($pending['matricula'] ?? ''),
            'gestor_id' => isset($pending['gestor_id']) ? (int) $pending['gestor_id'] : null,
        ];
    }

    public function mascararEmail(string $email): string
    {
        $parts = explode('@', $email, 2);
        if (count($parts) !== 2) {
            return '***';
        }
        $user = $parts[0];
        $dom = $parts[1];
        $keep = max(1, min(2, (int) floor(strlen($user) / 3)));
        $maskedUser = substr($user, 0, $keep) . str_repeat('*', max(3, strlen($user) - $keep));

        return $maskedUser . '@' . $dom;
    }
}
