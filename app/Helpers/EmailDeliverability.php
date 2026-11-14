<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * Validação de entregabilidade básica antes do SMTP.
 * Evita marcar como "enviado" endereços que o provedor aceita e depois devolve (bounce).
 */
final class EmailDeliverability
{
    /** @var list<string> */
    private const BLOQUEADOS = [
        'local',
        'localhost',
        'test',
        'invalid',
        'example',
        'example.com',
        'example.org',
        'example.net',
        'email.teste',
        'mailinator.com',
    ];

    /**
     * @return array{ok:bool,erro:?string}
     */
    public static function validate(string $email): array
    {
        $email = trim($email);
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return ['ok' => false, 'erro' => 'Endereço de e-mail inválido.'];
        }

        $at = strrpos($email, '@');
        if ($at === false) {
            return ['ok' => false, 'erro' => 'Endereço de e-mail inválido.'];
        }
        $domain = strtolower(substr($email, $at + 1));
        if ($domain === '' || !str_contains($domain, '.')) {
            return ['ok' => false, 'erro' => 'Domínio de e-mail inválido.'];
        }

        foreach (self::BLOQUEADOS as $bad) {
            if ($domain === $bad || str_ends_with($domain, '.' . $bad)) {
                return [
                    'ok' => false,
                    'erro' => 'Domínio não entregável (' . $domain . '). Atualize o e-mail corporativo do colaborador.',
                ];
            }
        }

        // Sem MX/A o provedor costuma aceitar e devolver bounce depois.
        if (!self::domainHasMailExchange($domain)) {
            return [
                'ok' => false,
                'erro' => 'Domínio sem registro MX/A (' . $domain . '). E-mail inexistente ou inválido.',
            ];
        }

        return ['ok' => true, 'erro' => null];
    }

    public static function isDeliverable(string $email): bool
    {
        return self::validate($email)['ok'] === true;
    }

    private static function domainHasMailExchange(string $domain): bool
    {
        $mx = [];
        if (@getmxrr($domain, $mx) && $mx !== []) {
            return true;
        }
        // Fallback: alguns domínios pequenos só têm A/AAAA.
        $a = @dns_get_record($domain, DNS_A);
        if (is_array($a) && $a !== []) {
            return true;
        }
        $aaaa = @dns_get_record($domain, DNS_AAAA);

        return is_array($aaaa) && $aaaa !== [];
    }
}
