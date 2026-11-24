<?php

declare(strict_types=1);

namespace App\Helpers;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Fonte única do horário de negócio: América/São_Paulo (Brasil).
 */
final class AppClock
{
    public const TIMEZONE = 'America/Sao_Paulo';
    public const MYSQL_OFFSET = '-03:00';

    public static function zone(): DateTimeZone
    {
        return new DateTimeZone(self::TIMEZONE);
    }

    public static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', self::zone());
    }

    public static function format(?string $datetime, string $format = 'd/m/Y H:i'): string
    {
        if ($datetime === null || trim($datetime) === '') {
            return '—';
        }
        try {
            // Timestamps do MySQL (sem offset) são wall-clock de São Paulo.
            $dt = new DateTimeImmutable($datetime, self::zone());
            return $dt->format($format);
        } catch (\Throwable) {
            $ts = strtotime($datetime);
            return $ts ? date($format, $ts) : '—';
        }
    }

    public static function applyPhpDefault(): void
    {
        date_default_timezone_set(self::TIMEZONE);
    }
}
