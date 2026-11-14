<?php

declare(strict_types=1);

namespace App\Helpers;

final class AppUrl
{
    /**
     * Resolve a URL pública base da aplicação com sanitização do Host header
     * quando config/app.php não define 'url' explicitamente.
     */
    public static function base(?string $rootPath = null): string
    {
        $root = $rootPath ?? dirname(__DIR__, 2);
        $app = require $root . '/config/app.php';
        $url = trim((string) ($app['url'] ?? ''));
        if ($url === '') {
            $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
            $host = self::sanitizeHost((string) ($_SERVER['HTTP_HOST'] ?? '127.0.0.1'));

            return ($https ? 'https://' : 'http://') . $host;
        }

        $url = rtrim($url, '/');
        if (str_ends_with(strtolower($url), '/public')) {
            $url = substr($url, 0, -7);
        }

        return rtrim($url, '/');
    }

    public static function sanitizeHost(string $rawHost): string
    {
        $host = trim($rawHost);
        if (preg_match('/^[a-zA-Z0-9.\-]+(?::\d{1,5})?$/', $host) === 1) {
            return $host;
        }

        return '127.0.0.1';
    }
}
