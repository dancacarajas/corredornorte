<?php

declare(strict_types=1);

namespace App\Services\EmailOAuth;

final class TokenCrypto
{
    public static function encrypt(string $plain): string
    {
        if ($plain === '') {
            return '';
        }
        $key = self::key();
        $iv = random_bytes(16);
        $cipher = openssl_encrypt($plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if ($cipher === false) {
            throw new \RuntimeException('Falha ao criptografar token.');
        }

        return base64_encode($iv . $cipher);
    }

    public static function decrypt(string $payload): string
    {
        if ($payload === '') {
            return '';
        }
        $raw = base64_decode($payload, true);
        if ($raw === false || strlen($raw) < 17) {
            return '';
        }
        $iv = substr($raw, 0, 16);
        $cipher = substr($raw, 16);
        $plain = openssl_decrypt($cipher, 'AES-256-CBC', self::key(), OPENSSL_RAW_DATA, $iv);

        return $plain === false ? '' : $plain;
    }

    private static function key(): string
    {
        $cfg = require dirname(__DIR__, 3) . '/config/email_oauth.php';
        $src = (string) ($cfg['encryption_key'] ?? 'arcadis');

        return hash('sha256', $src, true);
    }
}
