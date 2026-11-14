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
            header('Location: /he/login');
            exit;
        }
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
        unset($_SESSION['he_portal']);
    }
}
