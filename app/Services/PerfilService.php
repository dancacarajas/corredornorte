<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

final class PerfilService
{
    public function __construct(private PDO $db)
    {
    }

    /** @return list<array<string,mixed>> */
    public function listar(bool $somenteAtivos = false): array
    {
        $sql = 'SELECT p.*,
                       (SELECT COUNT(*) FROM usuario_perfis up WHERE up.perfil_id = p.id) AS total_usuarios,
                       (SELECT COUNT(*) FROM perfil_permissoes pp WHERE pp.perfil_id = p.id) AS total_permissoes
                FROM perfis p';
        if ($somenteAtivos) {
            $sql .= " WHERE p.status = 'ativo'";
        }
        $sql .= ' ORDER BY p.sistema DESC, p.nome';

        return $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return ?array<string,mixed> */
    public function buscar(int $id): ?array
    {
        $st = $this->db->prepare('SELECT * FROM perfis WHERE id = :id');
        $st->execute(['id' => $id]);
        $perfil = $st->fetch(PDO::FETCH_ASSOC);
        if ($perfil === false) {
            return null;
        }
        $ps = $this->db->prepare(
            'SELECT permissao_id FROM perfil_permissoes WHERE perfil_id = :id'
        );
        $ps->execute(['id' => $id]);
        $perfil['permissao_ids'] = array_map('intval', $ps->fetchAll(PDO::FETCH_COLUMN) ?: []);

        return $perfil;
    }

    /** @return list<array<string,mixed>> */
    public function catalogoPermissoes(): array
    {
        return $this->db->query(
            'SELECT * FROM permissoes ORDER BY grupo, nome'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @param array{codigo:string,nome:string,descricao?:string,status?:string,permissao_ids?:list<int>} $data
     */
    public function criar(array $data, ?int $actorId = null): int
    {
        $codigo = strtolower(trim(preg_replace('/[^a-zA-Z0-9_\-]+/', '_', $data['codigo']) ?? ''));
        $nome = trim($data['nome']);
        $descricao = trim((string) ($data['descricao'] ?? ''));
        $status = ($data['status'] ?? 'ativo') === 'inativo' ? 'inativo' : 'ativo';
        $permIds = array_values(array_unique(array_map('intval', $data['permissao_ids'] ?? [])));

        if ($codigo === '' || strlen($codigo) < 2) {
            throw new RuntimeException('Informe um código válido para o perfil.');
        }
        if ($nome === '') {
            throw new RuntimeException('Informe o nome do perfil.');
        }
        if ($this->codigoExiste($codigo)) {
            throw new RuntimeException('Já existe um perfil com este código.');
        }

        $this->db->beginTransaction();
        try {
            $this->db->prepare(
                'INSERT INTO perfis (codigo, nome, descricao, status, sistema) VALUES (:c, :n, :d, :s, 0)'
            )->execute(['c' => $codigo, 'n' => $nome, 'd' => $descricao !== '' ? $descricao : null, 's' => $status]);
            $id = (int) $this->db->lastInsertId();
            $this->syncPermissoes($id, $permIds);
            $this->audit($id, 'criar', null, [
                'codigo' => $codigo, 'nome' => $nome, 'permissao_ids' => $permIds,
            ], $actorId);
            $this->db->commit();

            return $id;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * @param array{nome:string,descricao?:string,status?:string,permissao_ids?:list<int>} $data
     */
    public function atualizar(int $id, array $data, ?int $actorId = null): void
    {
        $atual = $this->buscar($id);
        if ($atual === null) {
            throw new RuntimeException('Perfil não encontrado.');
        }

        $nome = trim($data['nome']);
        $descricao = trim((string) ($data['descricao'] ?? ''));
        $status = ($data['status'] ?? 'ativo') === 'inativo' ? 'inativo' : 'ativo';
        $permIds = array_values(array_unique(array_map('intval', $data['permissao_ids'] ?? [])));

        if ($nome === '') {
            throw new RuntimeException('Informe o nome do perfil.');
        }
        if (!empty($atual['sistema']) && $status === 'inativo') {
            throw new RuntimeException('O perfil de sistema Administrador não pode ser inativado.');
        }

        $this->db->beginTransaction();
        try {
            $this->db->prepare(
                'UPDATE perfis SET nome = :n, descricao = :d, status = :s WHERE id = :id'
            )->execute([
                'n' => $nome,
                'd' => $descricao !== '' ? $descricao : null,
                's' => $status,
                'id' => $id,
            ]);
            $this->syncPermissoes($id, $permIds);
            $this->audit($id, 'atualizar', [
                'nome' => $atual['nome'], 'status' => $atual['status'],
            ], [
                'nome' => $nome, 'status' => $status, 'permissao_ids' => $permIds,
            ], $actorId);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** @param list<int> $permIds */
    private function syncPermissoes(int $perfilId, array $permIds): void
    {
        $this->db->prepare('DELETE FROM perfil_permissoes WHERE perfil_id = :id')->execute(['id' => $perfilId]);
        $ins = $this->db->prepare(
            'INSERT INTO perfil_permissoes (perfil_id, permissao_id) VALUES (:p, :m)'
        );
        foreach ($permIds as $mid) {
            if ($mid <= 0) {
                continue;
            }
            $ins->execute(['p' => $perfilId, 'm' => $mid]);
        }
    }

    private function codigoExiste(string $codigo, ?int $ignoreId = null): bool
    {
        $sql = 'SELECT id FROM perfis WHERE codigo = :c';
        $params = ['c' => $codigo];
        if ($ignoreId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $ignoreId;
        }
        $st = $this->db->prepare($sql);
        $st->execute($params);

        return (bool) $st->fetchColumn();
    }

    /** @param ?array<string,mixed> $antes @param ?array<string,mixed> $depois */
    private function audit(int $id, string $acao, ?array $antes, ?array $depois, ?int $userId): void
    {
        $this->db->prepare(
            'INSERT INTO rh_auditoria (entidade, entidade_id, acao, antes_json, depois_json, usuario_id)
             VALUES (\'perfis\', :id, :a, :antes, :depois, :u)'
        )->execute([
            'id' => $id,
            'a' => $acao,
            'antes' => $antes ? json_encode($antes, JSON_UNESCAPED_UNICODE) : null,
            'depois' => $depois ? json_encode($depois, JSON_UNESCAPED_UNICODE) : null,
            'u' => $userId,
        ]);
    }
}
