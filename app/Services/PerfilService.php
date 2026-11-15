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

    /**
     * Catálogo canônico — fonte da verdade para a matriz de acesso.
     *
     * @return list<array{codigo:string,nome:string,grupo:string,descricao:string}>
     */
    public static function catalogoDefinicao(): array
    {
        return [
            ['codigo' => 'dashboard.ver', 'nome' => 'Ver visão geral', 'grupo' => 'Principal', 'descricao' => 'Acesso à home / dashboard'],

            ['codigo' => 'efetivo.ver', 'nome' => 'Ver efetivo', 'grupo' => 'Efetivo', 'descricao' => 'Listar colaboradores e estrutura'],
            ['codigo' => 'efetivo.editar', 'nome' => 'Editar efetivo', 'grupo' => 'Efetivo', 'descricao' => 'Criar e editar colaboradores e lotações'],

            ['codigo' => 'cadastros.ver', 'nome' => 'Ver cadastros', 'grupo' => 'Organização / Escalas / Pessoas', 'descricao' => 'Empresas, contratos, jornadas, escalas, cargos, gestores…'],
            ['codigo' => 'cadastros.editar', 'nome' => 'Editar cadastros', 'grupo' => 'Organização / Escalas / Pessoas', 'descricao' => 'Alterar cadastros estruturais'],

            ['codigo' => 'ponto.ver', 'nome' => 'Ver gestão de ponto', 'grupo' => 'Medição e ponto', 'descricao' => 'Consultar espelhos e batidas'],
            ['codigo' => 'ponto.editar', 'nome' => 'Editar gestão de ponto', 'grupo' => 'Medição e ponto', 'descricao' => 'Gerar período, ajustar batidas e fechar'],
            ['codigo' => 'folha.ver', 'nome' => 'Ver folha de ponto', 'grupo' => 'Medição e ponto', 'descricao' => 'Consultar folhas e PDFs'],
            ['codigo' => 'folha.enviar', 'nome' => 'Enviar / assinar folha', 'grupo' => 'Medição e ponto', 'descricao' => 'E-mail, link e lembretes de assinatura'],
            ['codigo' => 'medicao.ver', 'nome' => 'Ver medição', 'grupo' => 'Medição e ponto', 'descricao' => 'Painel da jornada de medição e dossiê'],
            ['codigo' => 'medicao.editar', 'nome' => 'Operar medição', 'grupo' => 'Medição e ponto', 'descricao' => 'Ações em lote da medição (conferir, PDF, e-mail…)'],
            ['codigo' => 'ra.ver', 'nome' => 'Ver relatórios de atividades', 'grupo' => 'Medição e ponto', 'descricao' => 'Consultar RA no RH'],
            ['codigo' => 'ra.editar', 'nome' => 'Aprovar / reabrir RA', 'grupo' => 'Medição e ponto', 'descricao' => 'Aprovar ou reabrir relatório de atividades'],
            ['codigo' => 'vr.ver', 'nome' => 'Ver vale refeição', 'grupo' => 'Vale Refeição', 'descricao' => 'Ver configurações e gerar relatório de VR'],
            ['codigo' => 'vr.editar', 'nome' => 'Editar vale refeição', 'grupo' => 'Vale Refeição', 'descricao' => 'Criar e alterar regras de VR'],

            ['codigo' => 'email.gerenciar', 'nome' => 'Conta de e-mail', 'grupo' => 'Sistema', 'descricao' => 'Conectar SMTP/OAuth e templates'],
            ['codigo' => 'importacoes.gerenciar', 'nome' => 'Importações', 'grupo' => 'Sistema', 'descricao' => 'Importar planilha de efetivo'],
            ['codigo' => 'usuarios.gerenciar', 'nome' => 'Gerenciar usuários', 'grupo' => 'Sistema', 'descricao' => 'Criar e editar usuários'],
            ['codigo' => 'perfis.gerenciar', 'nome' => 'Gerenciar perfis', 'grupo' => 'Sistema', 'descricao' => 'Criar perfis e marcar permissões'],
        ];
    }

    /** Garante permissões no banco e concede novas ao perfil admin (sem reativar removidas). */
    public function ensureCatalog(): void
    {
        $ins = $this->db->prepare(
            'INSERT INTO permissoes (codigo, nome, grupo, descricao)
             VALUES (:c, :n, :g, :d)
             ON DUPLICATE KEY UPDATE nome = VALUES(nome), grupo = VALUES(grupo), descricao = VALUES(descricao)'
        );
        $adminId = (int) $this->db->query("SELECT id FROM perfis WHERE codigo = 'admin' LIMIT 1")->fetchColumn();
        $link = $this->db->prepare(
            'INSERT IGNORE INTO perfil_permissoes (perfil_id, permissao_id) VALUES (:p, :m)'
        );

        foreach (self::catalogoDefinicao() as $row) {
            $exists = $this->db->prepare('SELECT id FROM permissoes WHERE codigo = :c LIMIT 1');
            $exists->execute(['c' => $row['codigo']]);
            $antes = $exists->fetchColumn();

            $ins->execute([
                'c' => $row['codigo'],
                'n' => $row['nome'],
                'g' => $row['grupo'],
                'd' => $row['descricao'],
            ]);

            // Novas permissões entram automaticamente no admin (sem reabrir as que o RH removeu).
            if ($antes === false && $adminId > 0) {
                $permId = (int) $this->db->query(
                    'SELECT id FROM permissoes WHERE codigo = ' . $this->db->quote($row['codigo'])
                )->fetchColumn();
                if ($permId > 0) {
                    $link->execute(['p' => $adminId, 'm' => $permId]);
                }
            }
        }

        // Cura legado: admin sem nenhuma permissão marcada (antes o Auth liberava tudo sozinho).
        if ($adminId > 0) {
            $qtd = (int) $this->db->query(
                'SELECT COUNT(*) FROM perfil_permissoes WHERE perfil_id = ' . $adminId
            )->fetchColumn();
            if ($qtd === 0) {
                foreach (self::catalogoDefinicao() as $row) {
                    $permId = (int) $this->db->query(
                        'SELECT id FROM permissoes WHERE codigo = ' . $this->db->quote($row['codigo'])
                    )->fetchColumn();
                    if ($permId > 0) {
                        $link->execute(['p' => $adminId, 'm' => $permId]);
                    }
                }
            }
        }
    }

    /** @return list<array<string,mixed>> */
    public function listar(bool $somenteAtivos = false): array
    {
        $this->ensureCatalog();
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
        $this->ensureCatalog();
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
        $this->ensureCatalog();

        return $this->db->query(
            'SELECT * FROM permissoes ORDER BY grupo, nome'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @param array{codigo:string,nome:string,descricao?:string,status?:string,permissao_ids?:list<int>} $data
     */
    public function criar(array $data, ?int $actorId = null): int
    {
        $this->ensureCatalog();
        $codigo = strtolower(trim(preg_replace('/[^a-zA-Z0-9_\-]+/', '_', $data['codigo']) ?? ''));
        $nome = trim($data['nome']);
        $descricao = trim((string) ($data['descricao'] ?? ''));
        $status = ($data['status'] ?? 'ativo') === 'inativo' ? 'inativo' : 'ativo';
        $permIds = $this->filtrarPermissoesValidas($data['permissao_ids'] ?? []);

        if ($codigo === '' || strlen($codigo) < 2) {
            throw new RuntimeException('Informe um código válido para o perfil.');
        }
        if ($nome === '') {
            throw new RuntimeException('Informe o nome do perfil.');
        }
        if ($this->codigoExiste($codigo)) {
            throw new RuntimeException('Já existe um perfil com este código.');
        }
        if ($permIds === []) {
            throw new RuntimeException('Marque ao menos uma permissão para o perfil.');
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
        $this->ensureCatalog();
        $atual = $this->buscar($id);
        if ($atual === null) {
            throw new RuntimeException('Perfil não encontrado.');
        }

        $nome = trim($data['nome']);
        $descricao = trim((string) ($data['descricao'] ?? ''));
        $status = ($data['status'] ?? 'ativo') === 'inativo' ? 'inativo' : 'ativo';
        $permIds = $this->filtrarPermissoesValidas($data['permissao_ids'] ?? []);

        if ($nome === '') {
            throw new RuntimeException('Informe o nome do perfil.');
        }
        if (!empty($atual['sistema']) && $status === 'inativo') {
            throw new RuntimeException('O perfil de sistema Administrador não pode ser inativado.');
        }
        if ($permIds === []) {
            throw new RuntimeException('Marque ao menos uma permissão para o perfil.');
        }
        // Evita auto-lockout: quem edita admin precisa manter gerenciar perfis OU o actor não é o único admin.
        if (!empty($atual['sistema']) || (string) ($atual['codigo'] ?? '') === 'admin') {
            $perfisGerenciarId = (int) $this->db->query(
                "SELECT id FROM permissoes WHERE codigo = 'perfis.gerenciar' LIMIT 1"
            )->fetchColumn();
            if ($perfisGerenciarId > 0 && !in_array($perfisGerenciarId, $permIds, true)) {
                throw new RuntimeException('O perfil Administrador precisa manter a permissão “Gerenciar perfis”.');
            }
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
                'nome' => $atual['nome'], 'status' => $atual['status'], 'permissao_ids' => $atual['permissao_ids'] ?? [],
            ], [
                'nome' => $nome, 'status' => $status, 'permissao_ids' => $permIds,
            ], $actorId);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** Duplica perfil com novo código (facilita criar variações). */
    public function duplicar(int $id, string $novoCodigo, string $novoNome, ?int $actorId = null): int
    {
        $origem = $this->buscar($id);
        if ($origem === null) {
            throw new RuntimeException('Perfil de origem não encontrado.');
        }

        return $this->criar([
            'codigo' => $novoCodigo,
            'nome' => $novoNome !== '' ? $novoNome : ((string) $origem['nome'] . ' (cópia)'),
            'descricao' => (string) ($origem['descricao'] ?? ''),
            'status' => 'ativo',
            'permissao_ids' => array_map('intval', $origem['permissao_ids'] ?? []),
        ], $actorId);
    }

    /** @param list<int|string> $permIds @return list<int> */
    private function filtrarPermissoesValidas(array $permIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $permIds), static fn (int $i): bool => $i > 0)));
        if ($ids === []) {
            return [];
        }
        $in = implode(',', $ids);
        $valid = $this->db->query("SELECT id FROM permissoes WHERE id IN ({$in})")
            ->fetchAll(PDO::FETCH_COLUMN) ?: [];

        return array_map('intval', $valid);
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
