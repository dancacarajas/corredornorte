<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * Segredo do endpoint HTTP de cron (Hostinger wget/curl).
 * CLI não exige; HTTP exige key=… igual a este valor.
 */
final class CronSecret
{
    private const FILE = 'storage/cron.secret';

    public static function resolve(): string
    {
        $env = trim((string) (getenv('ARCADIS_CRON_SECRET') ?: ''));
        if ($env !== '') {
            return $env;
        }

        $config = require dirname(__DIR__, 2) . '/config/app.php';
        $fromCfg = trim((string) ($config['cron_secret'] ?? ''));
        if ($fromCfg !== '') {
            return $fromCfg;
        }

        $path = dirname(__DIR__, 2) . '/' . self::FILE;
        if (is_file($path)) {
            $stored = trim((string) file_get_contents($path));
            if ($stored !== '') {
                return $stored;
            }
        }

        $generated = bin2hex(random_bytes(24));
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        file_put_contents($path, $generated . "\n");
        @chmod($path, 0640);

        return $generated;
    }

    public static function matches(string $provided): bool
    {
        $expected = self::resolve();
        if ($expected === '' || $provided === '') {
            return false;
        }

        return hash_equals($expected, $provided);
    }
}
