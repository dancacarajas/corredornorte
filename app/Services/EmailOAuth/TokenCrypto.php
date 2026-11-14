<?php

declare(strict_types=1);

namespace App\Services\EmailOAuth;

final class TokenCrypto
{
    private const GCM_PREFIX = 'v2:';

    public static function encrypt(string $plain): string
    {
        if ($plain === '') {
            return '';
        }
        $key = self::key();
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($cipher === false || strlen($tag) !== 16) {
            throw new \RuntimeException('Falha ao criptografar token.');
        }

        return self::GCM_PREFIX . base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(string $payload): string
    {
        if ($payload === '') {
            return '';
        }
        if (str_starts_with($payload, self::GCM_PREFIX)) {
            $raw = base64_decode(substr($payload, strlen(self::GCM_PREFIX)), true);
            if ($raw === false || strlen($raw) < 29) {
                return '';
            }
            $iv = substr($raw, 0, 12);
            $tag = substr($raw, 12, 16);
            $cipher = substr($raw, 28);
            $plain = openssl_decrypt($cipher, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);

            return $plain === false ? '' : $plain;
        }

        $raw = base64_decode($payload, true);
        if ($raw === false || strlen($raw) < 17) {
            return '';
        }
        $iv = substr($raw, 0, 16);
        $cipher = substr($raw, 16);
        $plain = openssl_decrypt($cipher, 'AES-256-CBC', self::key(), OPENSSL_RAW_DATA, $iv);
        if ($plain === false) {
            $plain = openssl_decrypt($cipher, 'AES-256-CBC', hash('sha256', 'arcadis-local-dev-key-change-me', true), OPENSSL_RAW_DATA, $iv);
        }

        return $plain === false ? '' : $plain;
    }

    private static function key(): string
    {
        $cfg = require dirname(__DIR__, 3) . '/config/email_oauth.php';
        $src = trim((string) ($cfg['encryption_key'] ?? ''));
        if ($src === '') {
            throw new \RuntimeException('ARCADIS_APP_KEY não configurada para criptografia de credenciais.');
        }

        return hash('sha256', $src, true);
    }
}
