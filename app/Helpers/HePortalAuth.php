<?php

declare(strict_types=1);

namespace App\Helpers;

final class HePortalAuth
{
    public static function start(): void
    {
        Auth::start();
    }

    public static function attempt(array $colaborador): void
    {
        self::start();
        session_regenerate_id(true);
        $_SESSION['he_portal'] = [
            'id' => (int) $colaborador['id'],
            'nome' => (string) $colaborador['nome'],
            'cpf' => (string) $colaborador['cpf'],
            'matricula' => (string) ($colaborador['matricula'] ?? ''),
            'gestor_id' => isset($colaborador['gestor_id']) && $colaborador['gestor_id'] !== null
                ? (int) $colaborador['gestor_id']
                : null,
        ];
    }

    public static function check(): bool
    {
        self::start();
        return isset($_SESSION['he_portal']['id']);
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            $path = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/he', PHP_URL_PATH) ?: '/he');
            $qs = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY) ?: '');
            $next = $path . ($qs !== '' ? ('?' . $qs) : '');
            if (!str_starts_with($next, '/he')) {
                $next = '/he';
            }
            header('Location: /he/login?next=' . rawurlencode($next));
            exit;
        }
    }

    /** Destino seguro pós-login no portal HE (apenas paths /he...). */
    public static function safeNext(?string $raw): string
    {
        $next = trim((string) $raw);
        if ($next === '') {
            return '/he';
        }
        if (!str_starts_with($next, '/he')) {
            return '/he';
        }
        if (str_contains($next, '://') || str_starts_with($next, '//')) {
            return '/he';
        }
        return $next;
    }

    public static function id(): ?int
    {
        self::start();
        return isset($_SESSION['he_portal']['id']) ? (int) $_SESSION['he_portal']['id'] : null;
    }

    /** @return array{id:int,nome:string,cpf:string,matricula:string,gestor_id:?int}|null */
    public static function user(): ?array
    {
        self::start();
        if (!isset($_SESSION['he_portal']['id'])) {
            return null;
        }
        return [
            'id' => (int) $_SESSION['he_portal']['id'],
            'nome' => (string) ($_SESSION['he_portal']['nome'] ?? ''),
            'cpf' => (string) ($_SESSION['he_portal']['cpf'] ?? ''),
            'matricula' => (string) ($_SESSION['he_portal']['matricula'] ?? ''),
            'gestor_id' => isset($_SESSION['he_portal']['gestor_id'])
                ? (int) $_SESSION['he_portal']['gestor_id']
                : null,
        ];
    }

    public static function logout(): void
    {
        self::start();
        unset($_SESSION['he_portal'], $_SESSION['he_otp']);
        // Não destrói a sessão inteira (pode coexistir com admin no mesmo browser),
        // mas regenera o ID para invalidar fixação.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }
}
