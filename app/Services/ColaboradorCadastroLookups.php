<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use Throwable;

/**
 * Lookups do formulário de colaborador a partir das telas de cadastro.
 */
final class ColaboradorCadastroLookups
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** Garante FKs de gestor/supervisor apontando para os cadastros corretos. */
    public function ensureSchema(): void
    {
        try {
            $this->realinharFk(
                'gestor_id',
                'fk_colab_gestor',
                'rh_gestores_arcadis',
                'fk_colab_gestor_arcadis'
            );
            $this->realinharFk(
                'supervisor_id',
                'fk_colab_supervisor',
                'rh_supervisores',
                'fk_colab_supervisor_cad'
            );
        } catch (Throwable) {
            // Ambiente sem permissão de ALTER: formulário ainda funciona com IDs dos cadastros.
        }
    }

    /**
     * @return array{
     *   empresas:list<array<string,mixed>>,
     *   cargos:list<array<string,mixed>>,
     *   funcoes:list<array<string,mixed>>,
     *   gestoresArcadis:list<array<string,mixed>>,
     *   supervisores:list<array<string,mixed>>,
     *   gestoresCliente:list<array<string,mixed>>,
     *   lotacoes:list<array<string,mixed>>
     * }
     */
    public function all(): array
    {
        $this->ensureSchema();

        return [
            'empresas' => $this->ativos('rh_empresas', 'id, nome', 'nome'),
            'cargos' => $this->ativos('rh_cargos', 'id, codigo, nome', 'nome'),
            'funcoes' => $this->ativos('rh_funcoes', 'id, codigo, nome', 'nome'),
            'gestoresArcadis' => $this->ativos('rh_gestores_arcadis', 'id, nome, matricula, email', 'nome'),
            'supervisores' => $this->ativos('rh_supervisores', 'id, nome, matricula, email', 'nome'),
            'gestoresCliente' => $this->ativos('rh_gestores_cliente', 'id, nome, cliente, email', 'nome'),
            'lotacoes' => $this->ativos('rh_lotacoes', 'id, codigo, nome, percentual_he', 'nome'),
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function ativos(string $table, string $cols, string $orderBy): array
    {
        try {
            return $this->db->query(
                "SELECT {$cols} FROM {$table} WHERE status = 'ativo' ORDER BY {$orderBy}"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    private function realinharFk(
        string $column,
        string $oldConstraint,
        string $refTable,
        string $newConstraint
    ): void {
        if (!$this->tableExists($refTable) || !$this->columnExists('rh_colaboradores', $column)) {
            return;
        }

        // Zera IDs órfãos que não existem no cadastro-alvo (ex.: vinculos antigos a rh_colaboradores).
        $this->db->exec(
            "UPDATE rh_colaboradores c
             LEFT JOIN {$refTable} r ON r.id = c.{$column}
             SET c.{$column} = NULL
             WHERE c.{$column} IS NOT NULL AND r.id IS NULL"
        );

        $current = $this->fkTarget('rh_colaboradores', $column);
        if ($current === $refTable) {
            return;
        }

        if ($this->constraintExists($oldConstraint)) {
            $this->db->exec("ALTER TABLE rh_colaboradores DROP FOREIGN KEY {$oldConstraint}");
        }
        // Também remove constraint com nome novo se já existir quebrada
        if ($this->constraintExists($newConstraint) && $current !== $refTable) {
            $this->db->exec("ALTER TABLE rh_colaboradores DROP FOREIGN KEY {$newConstraint}");
        }

        if (!$this->constraintExists($newConstraint)) {
            $this->db->exec(
                "ALTER TABLE rh_colaboradores
                 ADD CONSTRAINT {$newConstraint}
                 FOREIGN KEY ({$column}) REFERENCES {$refTable}(id)
                 ON DELETE SET NULL"
            );
        }
    }

    private function fkTarget(string $table, string $column): ?string
    {
        $st = $this->db->prepare(
            "SELECT REFERENCED_TABLE_NAME
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :t
               AND COLUMN_NAME = :c
               AND REFERENCED_TABLE_NAME IS NOT NULL
             LIMIT 1"
        );
        $st->execute(['t' => $table, 'c' => $column]);
        $v = $st->fetchColumn();

        return is_string($v) && $v !== '' ? $v : null;
    }

    private function constraintExists(string $name): bool
    {
        $st = $this->db->prepare(
            "SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'rh_colaboradores'
               AND CONSTRAINT_NAME = :n
             LIMIT 1"
        );
        $st->execute(['n' => $name]);

        return (bool) $st->fetchColumn();
    }

    private function tableExists(string $table): bool
    {
        $st = $this->db->prepare(
            'SELECT 1 FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t LIMIT 1'
        );
        $st->execute(['t' => $table]);

        return (bool) $st->fetchColumn();
    }

    private function columnExists(string $table, string $column): bool
    {
        $st = $this->db->prepare(
            'SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c LIMIT 1'
        );
        $st->execute(['t' => $table, 'c' => $column]);

        return (bool) $st->fetchColumn();
    }
}
