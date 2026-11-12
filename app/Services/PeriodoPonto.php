<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use RuntimeException;

final class PeriodoPonto
{
    public const MAX_DIAS_PDF = 40;

    /** @return array{data_inicio:string,data_fim:string} */
    public static function selecionar(mixed $inicio, mixed $fim, int $ano, int $mes): array
    {
        if (($inicio === null || $inicio === '') && ($fim === null || $fim === '')) {
            $primeiro = new DateTimeImmutable(sprintf('%04d-%02d-01', $ano, $mes));
            return ['data_inicio' => $primeiro->format('Y-m-d'), 'data_fim' => $primeiro->format('Y-m-t')];
        }
        if (!self::dataValida($inicio) || !self::dataValida($fim) || $inicio > $fim) {
            throw new RuntimeException('Informe datas inicial e final válidas, em ordem.');
        }
        return ['data_inicio' => $inicio, 'data_fim' => $fim];
    }

    public static function consulta(array $periodo): string
    {
        return http_build_query(['data_inicio' => $periodo['data_inicio'], 'data_fim' => $periodo['data_fim']]);
    }

    public static function dias(array $periodo): int
    {
        return (int) (new DateTimeImmutable($periodo['data_inicio']))->diff(new DateTimeImmutable($periodo['data_fim']))->days + 1;
    }

    public static function rotulo(array $periodo): string
    {
        return (new DateTimeImmutable($periodo['data_inicio']))->format('d/m/Y') . ' a '
            . (new DateTimeImmutable($periodo['data_fim']))->format('d/m/Y');
    }

    private static function dataValida(mixed $data): bool
    {
        if (!is_string($data) || !preg_match('/^([1-9][0-9]{3})-([0-9]{2})-([0-9]{2})$/D', $data, $partes)) {
            return false;
        }
        return checkdate((int) $partes[2], (int) $partes[3], (int) $partes[1]);
    }
}
