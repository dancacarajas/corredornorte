<?php

declare(strict_types=1);

namespace App\Helpers;

final class Csrf
{
    private const SESSION_KEY = '_csrf_token';

    public static function token(): string
    {
        Auth::start();
        $token = $_SESSION[self::SESSION_KEY] ?? '';
        if (!is_string($token) || strlen($token) < 32) {
            $token = bin2hex(random_bytes(32));
            $_SESSION[self::SESSION_KEY] = $token;
        }

        return $token;
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="'
            . htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8')
            . '">';
    }

    public static function verify(?string $provided = null): bool
    {
        Auth::start();
        $expected = $_SESSION[self::SESSION_KEY] ?? '';
        if (!is_string($expected) || $expected === '') {
            return false;
        }

        $candidate = $provided
            ?? ($_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
        if (!is_string($candidate) || $candidate === '') {
            return false;
        }

        return hash_equals($expected, $candidate);
    }

    public static function requireValid(): void
    {
        if (self::verify()) {
            return;
        }

        http_response_code(403);
        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        $xhr = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        if (str_contains($accept, 'application/json') || $xhr === 'xmlhttprequest') {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['ok' => false, 'error' => 'Sessão expirada ou token CSRF inválido. Recarregue a página.']);
            exit;
        }

        View::render('ui/forbidden', [
            'pageTitle' => 'Sessão inválida',
            'pageSubtitle' => 'Atualize a página e tente novamente',
            'activeMenu' => '',
            'permissao' => 'Token de segurança (CSRF) inválido ou expirado.',
        ], null);
        exit;
    }
}
