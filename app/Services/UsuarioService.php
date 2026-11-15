<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

final class UsuarioService
{
    public function __construct(private PDO $db)
    {
    }

    /** @return list<array<string,mixed>> */
    public function listar(?string $busca = null): array
    {
        $sql = 'SELECT u.id, u.nome, u.email, u.status, u.telefone, u.avatar_path,
                       u.last_login_at, u.password_changed_at, u.created_at, u.updated_at,
                       GROUP_CONCAT(p.nome ORDER BY p.nome SEPARATOR \', \') AS perfis,
                       GROUP_CONCAT(p.id ORDER BY p.nome) AS perfil_ids
                FROM usuarios u
                LEFT JOIN usuario_perfis up ON up.usuario_id = u.id
                LEFT JOIN perfis p ON p.id = up.perfil_id';
        $params = [];
        if ($busca !== null && trim($busca) !== '') {
            $sql .= ' WHERE u.nome LIKE :q1 OR u.email LIKE :q2';
            $like = '%' . trim($busca) . '%';
            $params['q1'] = $like;
            $params['q2'] = $like;
        }
        $sql .= ' GROUP BY u.id ORDER BY u.nome';
        $st = $this->db->prepare($sql);
        $st->execute($params);

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return ?array<string,mixed> */
    public function buscar(int $id): ?array
    {
        $st = $this->db->prepare('SELECT * FROM usuarios WHERE id = :id');
        $st->execute(['id' => $id]);
        $user = $st->fetch(PDO::FETCH_ASSOC);
        if ($user === false) {
            return null;
        }
        $ps = $this->db->prepare(
            'SELECT p.id, p.codigo, p.nome
             FROM usuario_perfis up
             INNER JOIN perfis p ON p.id = up.perfil_id
             WHERE up.usuario_id = :id
             ORDER BY p.nome'
        );
        $ps->execute(['id' => $id]);
        $user['perfis'] = $ps->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $user['contrato_ids'] = \App\Helpers\EscopoAcesso::carregarContratoIds($this->db, $id);

        return $user;
    }

    /**
     * @param array{nome:string,email:string,senha?:string,status:string,perfil_ids:list<int>,contrato_ids?:list<int>} $data
     */
    public function criar(array $data, ?int $actorId = null): int
    {
        $nome = trim($data['nome']);
        $email = strtolower(trim($data['email']));
        $senha = (string) ($data['senha'] ?? '');
        $status = ($data['status'] ?? 'ativo') === 'inativo' ? 'inativo' : 'ativo';
        $perfilIds = array_values(array_unique(array_map('intval', $data['perfil_ids'] ?? [])));
        $contratoIds = array_values(array_unique(array_map('intval', $data['contrato_ids'] ?? [])));

        if ($nome === '' || mb_strlen($nome) < 3) {
            throw new RuntimeException('Informe o nome completo.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('E-mail inválido.');
        }
        (new ContaService($this->db))->assertSenhaForte($senha);
        if ($perfilIds === []) {
            throw new RuntimeException('Selecione ao menos um perfil.');
        }
        if ($this->emailExiste($email)) {
            throw new RuntimeException('Já existe um usuário com este e-mail.');
        }

        $telefone = isset($data['telefone']) ? trim((string) $data['telefone']) : null;
        if ($telefone === '') {
            $telefone = null;
        }

        $this->db->beginTransaction();
        try {
            $this->db->prepare(
                'INSERT INTO usuarios (nome, email, senha_hash, status, telefone, password_changed_at)
                 VALUES (:n, :e, :s, :st, :t, NOW())'
            )->execute([
                'n' => $nome,
                'e' => $email,
                's' => password_hash($senha, PASSWORD_DEFAULT),
                'st' => $status,
                't' => $telefone,
            ]);
            $id = (int) $this->db->lastInsertId();
            $this->syncPerfis($id, $perfilIds);
            \App\Helpers\EscopoAcesso::syncContratos($this->db, $id, $contratoIds);
            $this->audit('usuarios', $id, 'criar', null, [
                'nome' => $nome, 'email' => $email, 'status' => $status,
                'perfil_ids' => $perfilIds, 'contrato_ids' => $contratoIds,
            ], $actorId);
            $this->db->commit();

            return $id;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * @param array{nome:string,email:string,senha?:string,status:string,perfil_ids:list<int>,contrato_ids?:list<int>} $data
     */
    public function atualizar(int $id, array $data, ?int $actorId = null): void
    {
        $atual = $this->buscar($id);
        if ($atual === null) {
            throw new RuntimeException('Usuário não encontrado.');
        }

        $nome = trim($data['nome']);
        $email = strtolower(trim($data['email']));
        $senha = trim((string) ($data['senha'] ?? ''));
        $status = ($data['status'] ?? 'ativo') === 'inativo' ? 'inativo' : 'ativo';
        $perfilIds = array_values(array_unique(array_map('intval', $data['perfil_ids'] ?? [])));
        $contratoIds = array_values(array_unique(array_map('intval', $data['contrato_ids'] ?? [])));

        if ($nome === '' || mb_strlen($nome) < 3) {
            throw new RuntimeException('Informe o nome completo.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('E-mail inválido.');
        }
        if ($perfilIds === []) {
            throw new RuntimeException('Selecione ao menos um perfil.');
        }
        if ($this->emailExiste($email, $id)) {
            throw new RuntimeException('Já existe um usuário com este e-mail.');
        }
        if ($senha !== '') {
            (new ContaService($this->db))->assertSenhaForte($senha);
        }

        // Impede auto-desativação do admin logado sem outro admin — regra simples: não desativar a si mesmo
        if ($actorId === $id && $status === 'inativo') {
            throw new RuntimeException('Você não pode desativar o próprio usuário.');
        }

        $telefone = array_key_exists('telefone', $data)
            ? trim((string) $data['telefone'])
            : (string) ($atual['telefone'] ?? '');
        if ($telefone === '') {
            $telefone = null;
        }

        $this->db->beginTransaction();
        try {
            if ($senha !== '') {
                $this->db->prepare(
                    'UPDATE usuarios SET nome = :n, email = :e, status = :st, telefone = :t,
                        senha_hash = :s, password_changed_at = NOW() WHERE id = :id'
                )->execute([
                    'n' => $nome, 'e' => $email, 'st' => $status, 't' => $telefone,
                    's' => password_hash($senha, PASSWORD_DEFAULT), 'id' => $id,
                ]);
            } else {
                $this->db->prepare(
                    'UPDATE usuarios SET nome = :n, email = :e, status = :st, telefone = :t WHERE id = :id'
                )->execute([
                    'n' => $nome, 'e' => $email, 'st' => $status, 't' => $telefone, 'id' => $id,
                ]);
            }
            $this->syncPerfis($id, $perfilIds);
            \App\Helpers\EscopoAcesso::syncContratos($this->db, $id, $contratoIds);
            $this->audit('usuarios', $id, 'atualizar', [
                'nome' => $atual['nome'], 'email' => $atual['email'], 'status' => $atual['status'],
            ], [
                'nome' => $nome, 'email' => $email, 'status' => $status,
                'perfil_ids' => $perfilIds, 'contrato_ids' => $contratoIds,
            ], $actorId);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** @param list<int> $perfilIds */
    private function syncPerfis(int $usuarioId, array $perfilIds): void
    {
        $this->db->prepare('DELETE FROM usuario_perfis WHERE usuario_id = :id')->execute(['id' => $usuarioId]);
        $ins = $this->db->prepare('INSERT INTO usuario_perfis (usuario_id, perfil_id) VALUES (:u, :p)');
        foreach ($perfilIds as $pid) {
            if ($pid <= 0) {
                continue;
            }
            $exists = $this->db->prepare("SELECT id FROM perfis WHERE id = :id AND status = 'ativo'");
            $exists->execute(['id' => $pid]);
            if (!$exists->fetchColumn()) {
                throw new RuntimeException('Perfil inválido ou inativo: #' . $pid);
            }
            $ins->execute(['u' => $usuarioId, 'p' => $pid]);
        }
    }

    private function emailExiste(string $email, ?int $ignoreId = null): bool
    {
        $sql = 'SELECT id FROM usuarios WHERE email = :e';
        $params = ['e' => $email];
        if ($ignoreId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $ignoreId;
        }
        $st = $this->db->prepare($sql);
        $st->execute($params);

        return (bool) $st->fetchColumn();
    }

    /** @param ?array<string,mixed> $antes @param ?array<string,mixed> $depois */
    private function audit(string $entidade, int $id, string $acao, ?array $antes, ?array $depois, ?int $userId): void
    {
        $this->db->prepare(
            'INSERT INTO rh_auditoria (entidade, entidade_id, acao, antes_json, depois_json, usuario_id)
             VALUES (:e, :id, :a, :antes, :depois, :u)'
        )->execute([
            'e' => $entidade,
            'id' => $id,
            'a' => $acao,
            'antes' => $antes ? json_encode($antes, JSON_UNESCAPED_UNICODE) : null,
            'depois' => $depois ? json_encode($depois, JSON_UNESCAPED_UNICODE) : null,
            'u' => $userId,
        ]);
    }
}
