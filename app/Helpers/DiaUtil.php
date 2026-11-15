<?php

declare(strict_types=1);

namespace App\Helpers;

use DateInterval;
use DateTimeImmutable;
use PDO;

/**
 * Dias úteis para régua de cobrança (pula sábado, domingo e feriados cadastrados).
 */
final class DiaUtil
{
    /** @var array<string,true>|null */
    private static ?array $feriadosCache = null;

    public static function resetCache(): void
    {
        self::$feriadosCache = null;
    }

    public static function isUtil(string $ymd, ?PDO $pdo = null): bool
    {
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd);
        if ($dt === false) {
            return false;
        }
        $dow = (int) $dt->format('w'); // 0=dom … 6=sáb
        if ($dow === 0 || $dow === 6) {
            return false;
        }

        return !isset(self::mapaFeriados($pdo)[$ymd]);
    }

    /**
     * Primeiro dia útil estritamente após $ymd.
     */
    public static function proximoApos(string $ymd, ?PDO $pdo = null): string
    {
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd);
        if ($dt === false) {
            throw new \InvalidArgumentException('Data inválida: ' . $ymd);
        }
        $dt = $dt->add(new DateInterval('P1D'));
        while (!self::isUtil($dt->format('Y-m-d'), $pdo)) {
            $dt = $dt->add(new DateInterval('P1D'));
        }

        return $dt->format('Y-m-d');
    }

    /**
     * N-ésimo dia útil após a data (n=1 → primeiro útil após).
     */
    public static function nApos(string $ymd, int $n, ?PDO $pdo = null): string
    {
        if ($n < 1) {
            throw new \InvalidArgumentException('n deve ser >= 1');
        }
        $atual = $ymd;
        for ($i = 0; $i < $n; $i++) {
            $atual = self::proximoApos($atual, $pdo);
        }

        return $atual;
    }

    public static function formatBr(string $ymd): string
    {
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd);

        return $dt === false ? $ymd : $dt->format('d/m/Y');
    }

    /**
     * @return array<string,true>
     */
    private static function mapaFeriados(?PDO $pdo): array
    {
        if (self::$feriadosCache !== null) {
            return self::$feriadosCache;
        }

        $mapa = [];
        if ($pdo === null) {
            $pdo = Db::tryConnection();
        }
        if ($pdo === null) {
            self::$feriadosCache = $mapa;

            return $mapa;
        }

        try {
            $anoAtual = (int) date('Y');
            $anos = [$anoAtual - 1, $anoAtual, $anoAtual + 1];
            $st = $pdo->query(
                "SELECT f.data, f.recorrente_ano
                 FROM rh_feriados f
                 INNER JOIN rh_calendarios c ON c.id = f.calendario_id
                 WHERE c.status = 'ativo' AND c.escopo = 'nacional'"
            );
            if ($st !== false) {
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $data = (string) ($row['data'] ?? '');
                    if ($data === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
                        continue;
                    }
                    $mapa[$data] = true;
                    if (!empty($row['recorrente_ano'])) {
                        $md = substr($data, 5);
                        foreach ($anos as $ano) {
                            $mapa[sprintf('%04d-%s', $ano, $md)] = true;
                        }
                    }
                }
            }
        } catch (\Throwable) {
            // Sem calendário: só sábado/domingo bloqueiam.
        }

        self::$feriadosCache = $mapa;

        return $mapa;
    }
}
