<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * Normalização e reparo seguro de UTF-8 (evita mojibake duplo e entradas CP1252).
 */
final class TextEncoding
{
    /** @var array<int,int> Mapa inverso de caracteres U+20xx/U+01xx do Windows-1252 (0x80..0x9F) */
    private const CP1252_REVERSE = [
        0x20AC => 0x80, 0x201A => 0x82, 0x0192 => 0x83, 0x201E => 0x84,
        0x2026 => 0x85, 0x2020 => 0x86, 0x2021 => 0x87, 0x02C6 => 0x88,
        0x2030 => 0x89, 0x0160 => 0x8A, 0x2039 => 0x8B, 0x0152 => 0x8C,
        0x017D => 0x8E, 0x2018 => 0x91, 0x2019 => 0x92, 0x201C => 0x93,
        0x201D => 0x94, 0x2022 => 0x95, 0x2013 => 0x96, 0x2014 => 0x97,
        0x02DC => 0x98, 0x2122 => 0x99, 0x0161 => 0x9A, 0x203A => 0x9B,
        0x0153 => 0x9C, 0x017E => 0x9E, 0x0178 => 0x9F,
    ];

    /**
     * Garante UTF-8 válido e repara trechos com dupla codificação (ex.: "OperaÃ§Ã£o" -> "Operação")
     * preservando caracteres UTF-8 já válidos na mesma string (ex.: "—").
     */
    public static function ensureUtf8(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (!mb_check_encoding($value, 'UTF-8')) {
            $converted = @mb_convert_encoding($value, 'UTF-8', 'Windows-1252,ISO-8859-1,UTF-8');
            if (is_string($converted) && $converted !== '' && mb_check_encoding($converted, 'UTF-8')) {
                $value = $converted;
            }
        }

        return self::fixMojibake($value);
    }

    /**
     * Repara sequências duplamente codificadas UTF-8 -> Latin1/CP1252 -> UTF-8 por token/sequência.
     */
    public static function fixMojibake(string $value): string
    {
        if ($value === '' || !mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        // Roda até 2 passadas caso haja tripla codificação, parando quando estabilizar.
        for ($pass = 0; $pass < 2; $pass++) {
            $next = self::decodeDoubleUtf8Pass($value);
            if ($next === $value) {
                break;
            }
            $value = $next;
        }

        return $value;
    }

    private static function decodeDoubleUtf8Pass(string $value): string
    {
        $chars = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($chars) || $chars === []) {
            return $value;
        }

        $out = '';
        $n = count($chars);
        $i = 0;

        while ($i < $n) {
            $b0 = self::toByte($chars[$i]);

            // Sequência 2 bytes UTF-8 duplamente codificada: 0xC2..0xDF seguido de 0x80..0xBF
            if ($b0 !== null && $b0 >= 0xC2 && $b0 <= 0xDF && ($i + 1) < $n) {
                $b1 = self::toByte($chars[$i + 1]);
                if ($b1 !== null && $b1 >= 0x80 && $b1 <= 0xBF) {
                    $cand = chr($b0) . chr($b1);
                    if (mb_check_encoding($cand, 'UTF-8')) {
                        $out .= $cand;
                        $i += 2;
                        continue;
                    }
                }
            }

            // Sequência 3 bytes UTF-8 duplamente codificada: 0xE0..0xEF + 2 bytes 0x80..0xBF
            if ($b0 !== null && $b0 >= 0xE0 && $b0 <= 0xEF && ($i + 2) < $n) {
                $b1 = self::toByte($chars[$i + 1]);
                $b2 = self::toByte($chars[$i + 2]);
                if ($b1 !== null && $b2 !== null && $b1 >= 0x80 && $b1 <= 0xBF && $b2 >= 0x80 && $b2 <= 0xBF) {
                    $cand = chr($b0) . chr($b1) . chr($b2);
                    if (mb_check_encoding($cand, 'UTF-8')) {
                        $out .= $cand;
                        $i += 3;
                        continue;
                    }
                }
            }

            $out .= $chars[$i];
            $i++;
        }

        return $out;
    }

    private static function toByte(string $char): ?int
    {
        $cp = mb_ord($char, 'UTF-8');
        if ($cp === false) {
            return null;
        }
        if ($cp >= 0x80 && $cp <= 0xFF) {
            return $cp;
        }
        return self::CP1252_REVERSE[$cp] ?? null;
    }
}
