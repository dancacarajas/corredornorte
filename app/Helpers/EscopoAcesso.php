<?php

declare(strict_types=1);

namespace App\Helpers;

use PDO;
use Throwable;

/**
 * Isolamento por contrato: se o usuário tiver vínculos em usuario_contratos,
 * só enxerga colaboradores da lotação vigente nesses contratos.
 * Sem vínculos = acesso irrestrito (compatível com o RBAC atual).
 * Perfil admin sempre irrestrito.
 */
final class EscopoAcesso
{
    private static bool $schemaOk = false;

    public static function ensureSchema(?PDO $pdo = null): void
    {
        if (self::$schemaOk) {
            return;
        }
        $pdo ??= Db::tryConnection();
        if (!$pdo instanceof PDO) {
            return;
        }
        try {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS usuario_contratos (
                    usuario_id BIGINT UNSIGNED NOT NULL,
                    contrato_id BIGINT UNSIGNED NOT NULL,
                    PRIMARY KEY (usuario_id, contrato_id),
                    KEY idx_uc_contrato (contrato_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
            self::$schemaOk = true;
        } catch (Throwable) {
        }
    }

    /**
     * @return list<int>|null null = sem restrição
     */
    public static function contratoIds(?int $usuarioId = null): ?array
    {
        $usuarioId ??= Auth::id();
        if ($usuarioId === null || $usuarioId <= 0) {
            return null;
        }
        $user = Auth::user();
        if ($user !== null && (int) ($user['id'] ?? 0) === $usuarioId) {
            if (in_array('admin', $user['perfis'] ?? [], true)) {
                return null;
            }
            if (array_key_exists('contrato_ids', $user)) {
                $ids = $user['contrato_ids'];
                // null na sessão = irrestrito; [] também tratado como irrestrito
                if ($ids === null) {
                    return null;
                }
                if (!is_array($ids) || $ids === []) {
                    return null;
                }

                return array_values(array_map('intval', $ids));
            }
        }

        $pdo = Db::tryConnection();
        if (!$pdo instanceof PDO) {
            return null;
        }
        self::ensureSchema($pdo);
        try {
            // admin por perfil no banco
            $st = $pdo->prepare(
                "SELECT 1 FROM usuario_perfis up
                 INNER JOIN perfis p ON p.id = up.perfil_id
                 WHERE up.usuario_id = :u AND p.codigo = 'admin' AND p.status = 'ativo' LIMIT 1"
            );
            $st->execute(['u' => $usuarioId]);
            if ($st->fetchColumn()) {
                return null;
            }

            $st = $pdo->prepare(
                'SELECT contrato_id FROM usuario_contratos WHERE usuario_id = :u'
            );
            $st->execute(['u' => $usuarioId]);
            $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
            return $ids === [] ? null : $ids;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return list<int> */
    public static function carregarContratoIds(PDO $pdo, int $usuarioId): array
    {
        self::ensureSchema($pdo);
        try {
            $st = $pdo->prepare(
                'SELECT contrato_id FROM usuario_contratos WHERE usuario_id = :u ORDER BY contrato_id'
            );
            $st->execute(['u' => $usuarioId]);
            return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param list<int> $contratoIds
     */
    public static function syncContratos(PDO $pdo, int $usuarioId, array $contratoIds): void
    {
        self::ensureSchema($pdo);
        $pdo->prepare('DELETE FROM usuario_contratos WHERE usuario_id = :u')->execute(['u' => $usuarioId]);
        $ins = $pdo->prepare(
            'INSERT INTO usuario_contratos (usuario_id, contrato_id) VALUES (:u, :c)'
        );
        foreach (array_unique(array_map('intval', $contratoIds)) as $cid) {
            if ($cid <= 0) {
                continue;
            }
            $ins->execute(['u' => $usuarioId, 'c' => $cid]);
        }
    }

    /**
     * Fragmento SQL + params para filtrar colaborador por escopo.
     * $colabAlias = alias da tabela rh_colaboradores (ex.: c).
     *
     * @return array{sql:string,params:array<string,int>}
     */
    public static function sqlFiltroColaborador(string $colabAlias = 'c', ?array $contratoIds = null): array
    {
        $contratoIds ??= self::contratoIds();
        if ($contratoIds === null || $contratoIds === []) {
            return ['sql' => '', 'params' => []];
        }

        $params = [];
        $ph = [];
        foreach (array_values($contratoIds) as $i => $id) {
            $key = 'escopo_ct_' . $i;
            $ph[] = ':' . $key;
            $params[$key] = (int) $id;
        }
        $in = implode(',', $ph);
        $a = preg_replace('/[^a-zA-Z0-9_]/', '', $colabAlias) ?: 'c';

        $sql = " AND EXISTS (
            SELECT 1 FROM rh_colaborador_lotacoes cl_esc
            INNER JOIN rh_lotacoes l_esc ON l_esc.id = cl_esc.lotacao_id
            WHERE cl_esc.colaborador_id = {$a}.id
              AND cl_esc.vigencia_fim IS NULL
              AND l_esc.contrato_id IN ({$in})
        )";

        return ['sql' => $sql, 'params' => $params];
    }
}
