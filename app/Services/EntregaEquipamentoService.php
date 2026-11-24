<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;
use Throwable;

final class EntregaEquipamentoService
{
    private PDO $db;
    private bool $schemaOk = false;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function ensureSchema(): void
    {
        if ($this->schemaOk) {
            return;
        }
        $sqlFile = dirname(__DIR__, 2) . '/database/schema_entrega_equipamentos.sql';
        if (!is_file($sqlFile)) {
            throw new RuntimeException('Schema de entrega de equipamentos não encontrado.');
        }
        $sql = file_get_contents($sqlFile);
        if ($sql === false || trim($sql) === '') {
            throw new RuntimeException('Schema de entrega de equipamentos vazio.');
        }
        foreach (preg_split('/;\s*\n/', $sql) ?: [] as $part) {
            $stmt = trim(preg_replace('/^--.*$/m', '', $part) ?? '');
            if ($stmt === '') {
                continue;
            }
            try {
                $this->db->exec($stmt);
            } catch (Throwable) {
                // tabela já existe / seed duplicado
            }
        }
        $this->ensureColunasExtras();
        $this->schemaOk = true;
    }

    private function ensureColunasExtras(): void
    {
        $cols = [
            'gestor_arcadis_id' => "BIGINT UNSIGNED NULL AFTER data_termo",
            'supervisor_id' => "BIGINT UNSIGNED NULL AFTER gestor_arcadis_id",
            'supervisor_nome' => "VARCHAR(160) NULL AFTER gestor_cpf",
        ];
        foreach ($cols as $col => $ddl) {
            try {
                $st = $this->db->query("SHOW COLUMNS FROM ti_entregas LIKE " . $this->db->quote($col));
                if ($st && !$st->fetch()) {
                    $this->db->exec("ALTER TABLE ti_entregas ADD COLUMN {$col} {$ddl}");
                }
            } catch (Throwable) {
                // sem ALTER
            }
        }
    }

    /** @return list<array<string,mixed>> */
    public function listar(string $q = '', string $status = '', int $limit = 100): array
    {
        $this->ensureSchema();
        $where = ['1=1'];
        $params = [];
        if ($q !== '') {
            $where[] = '(e.codigo LIKE :q OR e.colab_nome LIKE :q OR e.colab_cpf LIKE :q OR c.matricula LIKE :q)';
            $params['q'] = '%' . $q . '%';
        }
        if ($status !== '' && in_array($status, ['rascunho', 'emitido', 'assinado_anexado', 'devolvido', 'cancelado'], true)) {
            $where[] = 'e.status = :st';
            $params['st'] = $status;
        }
        $sql = 'SELECT e.*, c.matricula
                FROM ti_entregas e
                LEFT JOIN rh_colaboradores c ON c.id = e.colaborador_id
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY e.id DESC
                LIMIT ' . max(1, min(500, $limit));
        $st = $this->db->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<string,mixed>|null */
    public function obter(int $id): ?array
    {
        $this->ensureSchema();
        $st = $this->db->prepare(
            'SELECT e.*, c.matricula
             FROM ti_entregas e
             LEFT JOIN rh_colaboradores c ON c.id = e.colaborador_id
             WHERE e.id = ? LIMIT 1'
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @return list<array<string,mixed>> */
    public function itens(int $entregaId): array
    {
        $this->ensureSchema();
        $st = $this->db->prepare(
            'SELECT * FROM ti_entrega_itens WHERE entrega_id = ? ORDER BY tipo, ordem, id'
        );
        $st->execute([$entregaId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<array<string,mixed>> */
    public function anexos(int $entregaId): array
    {
        $this->ensureSchema();
        $st = $this->db->prepare(
            'SELECT * FROM ti_entrega_anexos WHERE entrega_id = ? ORDER BY id DESC'
        );
        $st->execute([$entregaId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<array<string,mixed>> */
    public function tiposItens(?string $categoria = null, bool $somenteAtivos = true): array
    {
        $this->ensureSchema();
        $where = ['1=1'];
        $params = [];
        if ($categoria === 'equipamento' || $categoria === 'periferico') {
            $where[] = 'categoria = :cat';
            $params['cat'] = $categoria;
        }
        if ($somenteAtivos) {
            $where[] = "status = 'ativo'";
        }
        $sql = 'SELECT * FROM ti_item_tipos WHERE ' . implode(' AND ', $where) . ' ORDER BY nome';
        $st = $this->db->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<string,mixed>|null */
    public function obterTipo(int $id): ?array
    {
        $this->ensureSchema();
        $st = $this->db->prepare('SELECT * FROM ti_item_tipos WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function salvarTipo(?int $id, string $categoria, string $nome, string $status = 'ativo'): int
    {
        $this->ensureSchema();
        if (!in_array($categoria, ['equipamento', 'periferico'], true)) {
            throw new RuntimeException('Categoria inválida.');
        }
        $nome = trim($nome);
        if ($nome === '') {
            throw new RuntimeException('Informe o nome.');
        }
        $status = $status === 'inativo' ? 'inativo' : 'ativo';
        if ($id !== null && $id > 0) {
            $atual = $this->obterTipo($id);
            if ($atual === null || (string) $atual['categoria'] !== $categoria) {
                throw new RuntimeException('Item não encontrado.');
            }
            $this->db->prepare(
                'UPDATE ti_item_tipos SET nome = ?, status = ? WHERE id = ?'
            )->execute([mb_substr($nome, 0, 120), $status, $id]);
            return $id;
        }
        $this->db->prepare(
            'INSERT INTO ti_item_tipos (nome, categoria, status) VALUES (?, ?, ?)'
        )->execute([mb_substr($nome, 0, 120), $categoria, $status]);
        return (int) $this->db->lastInsertId();
    }

    public function inativarTipo(int $id, string $categoria): void
    {
        $this->ensureSchema();
        $atual = $this->obterTipo($id);
        if ($atual === null || (string) $atual['categoria'] !== $categoria) {
            throw new RuntimeException('Item não encontrado.');
        }
        $this->db->prepare("UPDATE ti_item_tipos SET status='inativo' WHERE id=?")->execute([$id]);
    }

    /** @return list<array<string,mixed>> */
    public function gestoresArcadis(): array
    {
        try {
            return $this->db->query(
                "SELECT id, nome, matricula, email, telefone
                 FROM rh_gestores_arcadis WHERE status='ativo' ORDER BY nome"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    /** @return list<array<string,mixed>> */
    public function supervisores(): array
    {
        try {
            return $this->db->query(
                "SELECT id, nome, matricula, email, telefone
                 FROM rh_supervisores WHERE status='ativo' ORDER BY nome"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    /** @return list<array<string,mixed>> */
    public function colaboradoresAtivos(): array
    {
        return $this->db->query(
            "SELECT id, nome, matricula, cpf, email_corp, telefone
             FROM rh_colaboradores WHERE status='ativo' ORDER BY nome"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<string,mixed>|null */
    public function colaborador(int $id): ?array
    {
        $st = $this->db->prepare('SELECT * FROM rh_colaboradores WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * @param array<string,mixed> $dados
     * @param list<array{tipo:string,quantidade:int,descricao:string,tombamento:?string}> $itens
     */
    public function salvar(?int $id, array $dados, array $itens, ?int $usuarioId): int
    {
        $this->ensureSchema();
        $colabId = (int) ($dados['colaborador_id'] ?? 0);
        $nome = trim((string) ($dados['colab_nome'] ?? ''));
        if ($nome === '') {
            throw new RuntimeException('Informe o nome do colaborador.');
        }
        $equip = array_values(array_filter(
            $itens,
            static fn(array $i): bool => ($i['tipo'] ?? '') === 'equipamento' && trim((string) ($i['descricao'] ?? '')) !== ''
        ));
        if ($equip === []) {
            throw new RuntimeException('Inclua ao menos um equipamento.');
        }

        $gestorArcadisId = (int) ($dados['gestor_arcadis_id'] ?? 0);
        $supervisorId = (int) ($dados['supervisor_id'] ?? 0);
        $gestorSnap = $this->resolverGestorArcadis($gestorArcadisId);
        $supervisorSnap = $this->resolverSupervisor($supervisorId);
        $gestorNome = $gestorSnap['nome'] ?? $this->nullIfEmpty($dados['gestor_nome'] ?? null);
        $supervisorNome = $supervisorSnap['nome'] ?? $this->nullIfEmpty($dados['supervisor_nome'] ?? null);

        $payload = [
            'colaborador_id' => $colabId > 0 ? $colabId : null,
            'colab_nome' => $nome,
            'colab_cpf' => trim((string) ($dados['colab_cpf'] ?? '')),
            'colab_rg' => $this->nullIfEmpty($dados['colab_rg'] ?? null),
            'colab_razao_social' => $this->nullIfEmpty($dados['colab_razao_social'] ?? null),
            'colab_cnpj' => $this->nullIfEmpty($dados['colab_cnpj'] ?? null),
            'colab_endereco' => $this->nullIfEmpty($dados['colab_endereco'] ?? null),
            'colab_bairro' => $this->nullIfEmpty($dados['colab_bairro'] ?? null),
            'colab_municipio' => $this->nullIfEmpty($dados['colab_municipio'] ?? null),
            'colab_telefone' => $this->nullIfEmpty($dados['colab_telefone'] ?? null),
            'colab_email' => $this->nullIfEmpty($dados['colab_email'] ?? null),
            'local_termo' => $this->nullIfEmpty($dados['local_termo'] ?? null),
            'data_termo' => $this->dataOrNull($dados['data_termo'] ?? null),
            'gestor_arcadis_id' => $gestorArcadisId > 0 ? $gestorArcadisId : null,
            'supervisor_id' => $supervisorId > 0 ? $supervisorId : null,
            'gestor_nome' => $gestorNome,
            'gestor_cpf' => $this->nullIfEmpty($dados['gestor_cpf'] ?? null),
            'supervisor_nome' => $supervisorNome,
            'projeto_nome' => $this->nullIfEmpty($dados['projeto_nome'] ?? null),
            'centro_custo' => $this->nullIfEmpty($dados['centro_custo'] ?? null),
            'observacao' => $this->nullIfEmpty($dados['observacao'] ?? null),
        ];

        $this->db->beginTransaction();
        try {
            if ($id !== null && $id > 0) {
                $atual = $this->obter($id);
                if ($atual === null) {
                    throw new RuntimeException('Entrega não encontrada.');
                }
                if (in_array((string) $atual['status'], ['devolvido', 'cancelado'], true)) {
                    throw new RuntimeException('Entrega encerrada — não pode ser editada.');
                }
                $sql = 'UPDATE ti_entregas SET
                    colaborador_id=:colaborador_id, colab_nome=:colab_nome, colab_cpf=:colab_cpf, colab_rg=:colab_rg,
                    colab_razao_social=:colab_razao_social, colab_cnpj=:colab_cnpj, colab_endereco=:colab_endereco,
                    colab_bairro=:colab_bairro, colab_municipio=:colab_municipio, colab_telefone=:colab_telefone,
                    colab_email=:colab_email, local_termo=:local_termo, data_termo=:data_termo,
                    gestor_arcadis_id=:gestor_arcadis_id, supervisor_id=:supervisor_id,
                    gestor_nome=:gestor_nome, gestor_cpf=:gestor_cpf, supervisor_nome=:supervisor_nome,
                    projeto_nome=:projeto_nome, centro_custo=:centro_custo, observacao=:observacao
                    WHERE id=:id';
                $payload['id'] = $id;
                $this->db->prepare($sql)->execute($payload);
                $this->db->prepare('DELETE FROM ti_entrega_itens WHERE entrega_id = ?')->execute([$id]);
                $entregaId = $id;
            } else {
                $codigo = $this->proximoCodigo();
                $sql = 'INSERT INTO ti_entregas
                    (codigo, colaborador_id, status, colab_nome, colab_cpf, colab_rg, colab_razao_social, colab_cnpj,
                     colab_endereco, colab_bairro, colab_municipio, colab_telefone, colab_email,
                     local_termo, data_termo, gestor_arcadis_id, supervisor_id,
                     gestor_nome, gestor_cpf, supervisor_nome, projeto_nome, centro_custo, observacao, created_by)
                    VALUES
                    (:codigo, :colaborador_id, \'rascunho\', :colab_nome, :colab_cpf, :colab_rg, :colab_razao_social, :colab_cnpj,
                     :colab_endereco, :colab_bairro, :colab_municipio, :colab_telefone, :colab_email,
                     :local_termo, :data_termo, :gestor_arcadis_id, :supervisor_id,
                     :gestor_nome, :gestor_cpf, :supervisor_nome, :projeto_nome, :centro_custo, :observacao, :created_by)';
                $payload['codigo'] = $codigo;
                $payload['created_by'] = $usuarioId;
                $this->db->prepare($sql)->execute($payload);
                $entregaId = (int) $this->db->lastInsertId();
            }

            $ins = $this->db->prepare(
                'INSERT INTO ti_entrega_itens (entrega_id, tipo, quantidade, descricao, tombamento, ordem)
                 VALUES (:e, :t, :q, :d, :tomb, :o)'
            );
            $ordem = 0;
            foreach ($itens as $item) {
                $desc = trim((string) ($item['descricao'] ?? ''));
                if ($desc === '') {
                    continue;
                }
                $tipo = ($item['tipo'] ?? '') === 'periferico' ? 'periferico' : 'equipamento';
                $ins->execute([
                    'e' => $entregaId,
                    't' => $tipo,
                    'q' => max(1, (int) ($item['quantidade'] ?? 1)),
                    'd' => mb_substr($desc, 0, 255),
                    'tomb' => $this->nullIfEmpty($item['tombamento'] ?? null),
                    'o' => $ordem++,
                ]);
            }
            $this->db->commit();
            return $entregaId;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function emitirPdf(int $id, ?int $usuarioId): array
    {
        $this->ensureSchema();
        $entrega = $this->obter($id);
        if ($entrega === null) {
            throw new RuntimeException('Entrega não encontrada.');
        }
        if ((string) $entrega['status'] === 'cancelado') {
            throw new RuntimeException('Entrega cancelada.');
        }
        $itens = $this->itens($id);
        $dir = dirname(__DIR__, 2) . '/storage/uploads/entregas';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Não foi possível criar a pasta de uploads.');
        }
        $rel = 'storage/uploads/entregas/entrega_' . $id . '_' . date('YmdHis') . '.pdf';
        $abs = dirname(__DIR__, 2) . '/' . $rel;
        (new EntregaEquipamentoPdfRenderer())->render($entrega, $itens, $abs);
        $hash = hash_file('sha256', $abs);
        if ($hash === false) {
            throw new RuntimeException('Falha ao gerar hash do PDF.');
        }
        $status = (string) $entrega['status'];
        if (in_array($status, ['rascunho', 'emitido'], true)) {
            $status = 'emitido';
        }
        $this->db->prepare(
            'UPDATE ti_entregas
             SET arquivo_path = ?, arquivo_sha256 = ?, emitido_em = NOW(), emitido_por = ?, status = ?
             WHERE id = ?'
        )->execute([$rel, $hash, $usuarioId, $status, $id]);

        return ['path' => $rel, 'sha256' => $hash, 'abs' => $abs];
    }

    /**
     * @param array{tmp_name:string,name:string,type?:string,size?:int,error?:int} $file
     */
    public function anexarAssinado(int $id, array $file, ?int $usuarioId): int
    {
        $this->ensureSchema();
        $entrega = $this->obter($id);
        if ($entrega === null) {
            throw new RuntimeException('Entrega não encontrada.');
        }
        if (!in_array((string) $entrega['status'], ['emitido', 'assinado_anexado', 'devolvido'], true)
            && empty($entrega['arquivo_path'])
        ) {
            throw new RuntimeException('Emita o PDF do termo antes de anexar o assinado.');
        }
        $saved = $this->salvarUpload($id, $file, 'assinado');
        $this->db->prepare(
            'INSERT INTO ti_entrega_anexos (entrega_id, tipo, arquivo_path, arquivo_nome, mime, tamanho, sha256, uploaded_by)
             VALUES (?, \'termo_assinado\', ?, ?, ?, ?, ?, ?)'
        )->execute([
            $id,
            $saved['rel'],
            $saved['nome'],
            $saved['mime'],
            $saved['tamanho'],
            $saved['sha256'],
            $usuarioId,
        ]);
        $anexoId = (int) $this->db->lastInsertId();
        $novoStatus = (string) $entrega['status'] === 'devolvido' ? 'devolvido' : 'assinado_anexado';
        $this->db->prepare(
            'UPDATE ti_entregas
             SET arquivo_assinado_path = ?, assinado_anexado_em = NOW(), assinado_anexado_por = ?, status = ?
             WHERE id = ?'
        )->execute([$saved['rel'], $usuarioId, $novoStatus, $id]);
        return $anexoId;
    }

    /**
     * @param array<string,mixed> $dados
     * @param array{tmp_name:string,name:string,type?:string,size?:int,error?:int}|null $file
     */
    public function registrarDevolucao(int $id, array $dados, ?array $file, ?int $usuarioId): void
    {
        $this->ensureSchema();
        $entrega = $this->obter($id);
        if ($entrega === null) {
            throw new RuntimeException('Entrega não encontrada.');
        }
        if (!in_array((string) $entrega['status'], ['emitido', 'assinado_anexado', 'devolvido'], true)) {
            throw new RuntimeException('Só é possível devolver após emitir o termo.');
        }
        $data = $this->dataOrNull($dados['devolucao_data'] ?? null);
        if ($data === null) {
            throw new RuntimeException('Informe a data da devolução.');
        }
        $cond = (string) ($dados['devolucao_condicao'] ?? '');
        if (!in_array($cond, ['bom_estado', 'com_defeito'], true)) {
            throw new RuntimeException('Informe a condição da devolução.');
        }
        $desc = $this->nullIfEmpty($dados['devolucao_descricao'] ?? null);
        if ($cond === 'com_defeito' && ($desc === null || $desc === '')) {
            throw new RuntimeException('Descreva o defeito.');
        }
        $nome = trim((string) ($dados['devolucao_nome'] ?? ''));
        if ($nome === '') {
            $nome = (string) ($entrega['colab_nome'] ?? '');
        }
        if ($file !== null && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $saved = $this->salvarUpload($id, $file, 'devolucao');
            $this->db->prepare(
                'INSERT INTO ti_entrega_anexos (entrega_id, tipo, arquivo_path, arquivo_nome, mime, tamanho, sha256, uploaded_by)
                 VALUES (?, \'devolucao\', ?, ?, ?, ?, ?, ?)'
            )->execute([
                $id,
                $saved['rel'],
                $saved['nome'],
                $saved['mime'],
                $saved['tamanho'],
                $saved['sha256'],
                $usuarioId,
            ]);
        }
        $this->db->prepare(
            'UPDATE ti_entregas SET
                devolucao_data = ?, devolucao_condicao = ?, devolucao_descricao = ?,
                devolucao_nome = ?, devolucao_em = NOW(), devolucao_por = ?, status = \'devolvido\'
             WHERE id = ?'
        )->execute([$data, $cond, $desc, $nome, $usuarioId, $id]);
    }

    public function cancelar(int $id): void
    {
        $this->ensureSchema();
        $entrega = $this->obter($id);
        if ($entrega === null) {
            throw new RuntimeException('Entrega não encontrada.');
        }
        if ((string) $entrega['status'] === 'devolvido') {
            throw new RuntimeException('Entrega já devolvida.');
        }
        $this->db->prepare("UPDATE ti_entregas SET status='cancelado' WHERE id=?")->execute([$id]);
    }

    /** @return array{rel:string,nome:string,mime:?string,tamanho:int,sha256:string} */
    private function salvarUpload(int $entregaId, array $file, string $prefix): array
    {
        $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Falha no upload do arquivo.');
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        $nome = basename((string) ($file['name'] ?? 'anexo.bin'));
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new RuntimeException('Arquivo de upload inválido.');
        }
        $size = (int) ($file['size'] ?? filesize($tmp) ?: 0);
        if ($size <= 0 || $size > 15 * 1024 * 1024) {
            throw new RuntimeException('Arquivo deve ter até 15 MB.');
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmp) ?: null;
        $okMime = [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'image/webp',
        ];
        if ($mime !== null && !in_array($mime, $okMime, true)) {
            throw new RuntimeException('Anexe PDF ou imagem (JPG/PNG/WEBP).');
        }
        $ext = match ($mime) {
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => pathinfo($nome, PATHINFO_EXTENSION) ?: 'bin',
        };
        $dir = dirname(__DIR__, 2) . '/storage/uploads/entregas';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Não foi possível criar a pasta de uploads.');
        }
        $rel = sprintf('storage/uploads/entregas/entrega_%d_%s_%s.%s', $entregaId, $prefix, bin2hex(random_bytes(4)), $ext);
        $abs = dirname(__DIR__, 2) . '/' . $rel;
        if (!move_uploaded_file($tmp, $abs)) {
            throw new RuntimeException('Não foi possível salvar o anexo.');
        }
        @chmod($abs, 0664);
        $hash = hash_file('sha256', $abs);
        if ($hash === false) {
            throw new RuntimeException('Falha ao calcular hash do anexo.');
        }
        return [
            'rel' => $rel,
            'nome' => mb_substr($nome, 0, 255),
            'mime' => $mime,
            'tamanho' => $size,
            'sha256' => $hash,
        ];
    }

    private function proximoCodigo(): string
    {
        $ano = date('Y');
        $st = $this->db->prepare(
            "SELECT codigo FROM ti_entregas WHERE codigo LIKE :p ORDER BY id DESC LIMIT 1"
        );
        $st->execute(['p' => 'ENT-' . $ano . '-%']);
        $ultimo = (string) ($st->fetchColumn() ?: '');
        $seq = 1;
        if (preg_match('/ENT-\d{4}-(\d+)/', $ultimo, $m)) {
            $seq = (int) $m[1] + 1;
        }
        return sprintf('ENT-%s-%05d', $ano, $seq);
    }

    /** @return array{nome:?string}|null */
    private function resolverGestorArcadis(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        try {
            $st = $this->db->prepare(
                "SELECT nome FROM rh_gestores_arcadis WHERE id = ? AND status='ativo' LIMIT 1"
            );
            $st->execute([$id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return $row ? ['nome' => (string) ($row['nome'] ?? '')] : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array{nome:?string}|null */
    private function resolverSupervisor(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        try {
            $st = $this->db->prepare(
                "SELECT nome FROM rh_supervisores WHERE id = ? AND status='ativo' LIMIT 1"
            );
            $st->execute([$id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return $row ? ['nome' => (string) ($row['nome'] ?? '')] : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function nullIfEmpty(mixed $v): ?string
    {
        $v = trim((string) ($v ?? ''));
        return $v === '' ? null : mb_substr($v, 0, 500);
    }

    private function dataOrNull(mixed $v): ?string
    {
        $v = trim((string) ($v ?? ''));
        if ($v === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $v)) {
            return null;
        }
        return $v;
    }
}
