<?php

declare(strict_types=1);

namespace App\Controllers\Rh;

use App\Helpers\Db;
use App\Helpers\View;
use App\Services\LotacaoResolver;
use App\Services\TransferenciaLotacaoService;
use DateTimeImmutable;
use PDO;
use Throwable;

final class ColaboradorController
{
    public function createForm(): void
    {
        $pdo = Db::tryConnection();
        View::render('rh/colaboradores/form', [
            'pageTitle' => 'Novo colaborador',
            'pageSubtitle' => 'Identificação + lotação inicial (regras herdadas)',
            'activeMenu' => 'efetivo',
            'dbOk' => $pdo instanceof PDO,
            'colaborador' => null,
            'colaboradorId' => 0,
            'isEdit' => false,
            'formAction' => '/rh/colaboradores',
            'lotacoes' => $this->lotacoesAtivas($pdo),
            'empresas' => $this->empresas($pdo),
            'colaboradores' => $this->colaboradoresAtivos($pdo),
            'errors' => [],
        ]);
    }

    public function store(): void
    {
        $pdo = Db::tryConnection();
        if (!$pdo instanceof PDO) {
            $this->redirectDbMissing();
            return;
        }

        // Se veio da tela de edição, atualiza em vez de criar
        $postedId = (int) ($_POST['id'] ?? 0);
        if ($postedId > 0) {
            $this->update((string) $postedId);
            return;
        }

        $data = $this->input();
        $errors = $this->validate($data, true);
        if ($errors !== []) {
            View::render('rh/colaboradores/form', [
                'pageTitle' => 'Novo colaborador',
                'pageSubtitle' => 'Corrija os campos destacados',
                'activeMenu' => 'efetivo',
                'dbOk' => true,
                'colaborador' => $data,
                'colaboradorId' => 0,
                'isEdit' => false,
                'formAction' => '/rh/colaboradores',
                'lotacoes' => $this->lotacoesAtivas($pdo),
                'empresas' => $this->empresas($pdo),
                'colaboradores' => $this->colaboradoresAtivos($pdo),
                'errors' => $errors,
            ]);
            return;
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO rh_colaboradores
                (empresa_id, matricula, nome, cpf, email_corp, email_alt, telefone, data_admissao, cargo, funcao, status, gestor_id, supervisor_id)
                VALUES
                (:empresa_id, :matricula, :nome, :cpf, :email_corp, :email_alt, :telefone, :data_admissao, :cargo, :funcao, :status, :gestor_id, :supervisor_id)'
            );
            $stmt->execute([
                'empresa_id' => (int) $data['empresa_id'],
                'matricula' => $data['matricula'],
                'nome' => $data['nome'],
                'cpf' => preg_replace('/\D+/', '', $data['cpf']) ?: '',
                'email_corp' => $data['email_corp'] ?: null,
                'email_alt' => $data['email_alt'] ?: null,
                'telefone' => $data['telefone'] ?: null,
                'data_admissao' => $data['data_admissao'] ?: null,
                'cargo' => $data['cargo'] ?: null,
                'funcao' => $data['funcao'] ?: null,
                'status' => $data['status'] ?: 'ativo',
                'gestor_id' => $data['gestor_id'] !== '' ? (int) $data['gestor_id'] : null,
                'supervisor_id' => $data['supervisor_id'] !== '' ? (int) $data['supervisor_id'] : null,
            ]);
            $id = (int) $pdo->lastInsertId();

            $service = new TransferenciaLotacaoService($pdo);
            $service->transferir(
                $id,
                (int) $data['lotacao_id'],
                $data['data_admissao'] ?: date('Y-m-d'),
                'admissao',
                'Admissão inicial',
                null,
                $data['data_admissao'] ? new DateTimeImmutable($data['data_admissao']) : null
            );

            $pdo->commit();
            header('Location: /rh/colaboradores/' . $id);
            exit;
        } catch (Throwable $e) {
            $pdo->rollBack();
            View::render('rh/colaboradores/form', [
                'pageTitle' => 'Novo colaborador',
                'pageSubtitle' => 'Falha ao salvar',
                'activeMenu' => 'efetivo',
                'dbOk' => true,
                'colaborador' => $data,
                'colaboradorId' => 0,
                'isEdit' => false,
                'formAction' => '/rh/colaboradores',
                'lotacoes' => $this->lotacoesAtivas($pdo),
                'empresas' => $this->empresas($pdo),
                'colaboradores' => $this->colaboradoresAtivos($pdo),
                'errors' => ['geral' => $e->getMessage()],
            ]);
        }
    }

    public function editForm(string $id = '0'): void
    {
        $id = (int) $id;
        $pdo = Db::tryConnection();
        if (!$pdo instanceof PDO || $id <= 0) {
            $this->redirectDbMissing();
            return;
        }

        $colaborador = $this->find($pdo, $id);
        if ($colaborador === null) {
            http_response_code(404);
            View::render('ui/not-found', ['pageTitle' => 'Colaborador não encontrado', 'activeMenu' => 'efetivo']);
            return;
        }

        View::render('rh/colaboradores/form', [
            'pageTitle' => 'Editar colaborador',
            'pageSubtitle' => 'Mat. ' . $colaborador['matricula'],
            'activeMenu' => 'efetivo',
            'dbOk' => true,
            'colaborador' => $colaborador,
            'colaboradorId' => $id,
            'isEdit' => true,
            'formAction' => '/rh/colaboradores/' . $id,
            'lotacoes' => $this->lotacoesAtivas($pdo),
            'empresas' => $this->empresas($pdo),
            'colaboradores' => $this->colaboradoresAtivos($pdo, $id),
            'errors' => [],
        ]);
    }

    public function update(string $id = '0'): void
    {
        $id = (int) $id;
        if ($id <= 0) {
            $id = (int) ($_POST['id'] ?? 0);
        }
        $pdo = Db::tryConnection();
        if (!$pdo instanceof PDO || $id <= 0) {
            $this->redirectDbMissing();
            return;
        }

        $atual = $this->find($pdo, $id);
        if ($atual === null) {
            http_response_code(404);
            View::render('ui/not-found', ['pageTitle' => 'Colaborador não encontrado', 'activeMenu' => 'efetivo']);
            return;
        }

        $data = $this->input();
        $data['id'] = (string) $id;
        $errors = $this->validate($data, false, $id);
        if ($errors !== []) {
            View::render('rh/colaboradores/form', [
                'pageTitle' => 'Editar colaborador',
                'pageSubtitle' => 'Corrija os campos destacados',
                'activeMenu' => 'efetivo',
                'dbOk' => true,
                'colaborador' => array_merge($atual, $data, ['id' => $id]),
                'colaboradorId' => $id,
                'isEdit' => true,
                'formAction' => '/rh/colaboradores/' . $id,
                'lotacoes' => $this->lotacoesAtivas($pdo),
                'empresas' => $this->empresas($pdo),
                'colaboradores' => $this->colaboradoresAtivos($pdo, $id),
                'errors' => $errors,
            ]);
            return;
        }

        try {
            $stmt = $pdo->prepare(
                'UPDATE rh_colaboradores SET
                    empresa_id = :empresa_id,
                    matricula = :matricula,
                    nome = :nome,
                    cpf = :cpf,
                    email_corp = :email_corp,
                    email_alt = :email_alt,
                    telefone = :telefone,
                    data_admissao = :data_admissao,
                    cargo = :cargo,
                    funcao = :funcao,
                    status = :status,
                    gestor_id = :gestor_id,
                    supervisor_id = :supervisor_id
                 WHERE id = :id'
            );
            $stmt->execute([
                'id' => $id,
                'empresa_id' => (int) $data['empresa_id'],
                'matricula' => $data['matricula'],
                'nome' => $data['nome'],
                'cpf' => preg_replace('/\D+/', '', $data['cpf']) ?: '',
                'email_corp' => $data['email_corp'] ?: null,
                'email_alt' => $data['email_alt'] ?: null,
                'telefone' => $data['telefone'] ?: null,
                'data_admissao' => $data['data_admissao'] ?: null,
                'cargo' => $data['cargo'] ?: null,
                'funcao' => $data['funcao'] ?: null,
                'status' => $data['status'] ?: 'ativo',
                'gestor_id' => $data['gestor_id'] !== '' ? (int) $data['gestor_id'] : null,
                'supervisor_id' => $data['supervisor_id'] !== '' ? (int) $data['supervisor_id'] : null,
            ]);

            $pdo->prepare(
                'INSERT INTO rh_auditoria (entidade, entidade_id, acao, antes_json, depois_json, usuario_id)
                 VALUES (\'rh_colaboradores\', :id, \'atualizar\', :antes, :depois, :u)'
            )->execute([
                'id' => $id,
                'antes' => json_encode($atual, JSON_UNESCAPED_UNICODE),
                'depois' => json_encode($data, JSON_UNESCAPED_UNICODE),
                'u' => \App\Helpers\Auth::id(),
            ]);

            header('Location: /rh/colaboradores/' . $id . '?ok=atualizado');
            exit;
        } catch (Throwable $e) {
            View::render('rh/colaboradores/form', [
                'pageTitle' => 'Editar colaborador',
                'pageSubtitle' => 'Falha ao salvar',
                'activeMenu' => 'efetivo',
                'dbOk' => true,
                'colaborador' => array_merge($atual, $data, ['id' => $id]),
                'colaboradorId' => $id,
                'isEdit' => true,
                'formAction' => '/rh/colaboradores/' . $id,
                'lotacoes' => $this->lotacoesAtivas($pdo),
                'empresas' => $this->empresas($pdo),
                'colaboradores' => $this->colaboradoresAtivos($pdo, $id),
                'errors' => ['geral' => $e->getMessage()],
            ]);
        }
    }

    public function show(string $id = '0'): void
    {
        $id = (int) $id;
        $pdo = Db::tryConnection();
        if (!$pdo instanceof PDO || $id <= 0) {
            $this->redirectDbMissing();
            return;
        }

        $colaborador = $this->find($pdo, $id);
        if ($colaborador === null) {
            http_response_code(404);
            View::render('ui/not-found', ['pageTitle' => 'Colaborador não encontrado', 'activeMenu' => 'efetivo']);
            return;
        }

        $resolver = new LotacaoResolver($pdo);
        $atual = $resolver->resolve($id, new DateTimeImmutable('today'));
        $historico = $this->historico($pdo, $id);
        $excecoes = $pdo->prepare('SELECT x.*, j.codigo AS jornada_codigo, e.codigo AS escala_codigo
            FROM rh_colaborador_excecoes x
            LEFT JOIN rh_jornadas j ON j.id = x.jornada_id
            LEFT JOIN rh_escalas e ON e.id = x.escala_id
            WHERE x.colaborador_id = :id ORDER BY x.vigencia_inicio DESC');
        $excecoes->execute(['id' => $id]);
        $auditoria = $pdo->prepare("SELECT * FROM rh_auditoria WHERE entidade='rh_colaboradores' AND entidade_id=:id ORDER BY id DESC LIMIT 50");
        $auditoria->execute(['id' => $id]);

        ob_start();
        ?>
        <a class="btn btn-primary btn-sm" href="/rh/colaboradores/<?= $id ?>/editar">Editar dados</a>
        <button class="btn btn-secondary btn-sm" type="button" data-modal-open="excecaoModal">Exceção individual</button>
        <button class="btn btn-secondary btn-sm" type="button" data-modal-open="transferModal">Transferir lotação</button>
        <a class="btn btn-secondary btn-sm" href="/rh/efetivo">Voltar ao efetivo</a>
        <?php
        $topbarActions = ob_get_clean() ?: '';

        View::render('rh/colaboradores/show', [
            'pageTitle' => (string) $colaborador['nome'],
            'pageSubtitle' => 'Mat. ' . $colaborador['matricula'],
            'activeMenu' => 'efetivo',
            'topbarActions' => $topbarActions,
            'colaborador' => $colaborador,
            'lotacaoAtual' => $atual,
            'historico' => $historico,
            'excecoes' => $excecoes->fetchAll(),
            'auditoria' => $auditoria->fetchAll(),
            'lotacoes' => $this->lotacoesAtivas($pdo),
            'jornadas' => $pdo->query("SELECT id, codigo, nome FROM rh_jornadas WHERE status='ativo' ORDER BY codigo")->fetchAll(),
            'escalas' => $pdo->query("SELECT id, codigo, nome FROM rh_escalas WHERE status='ativo' ORDER BY codigo")->fetchAll(),
            'calendarios' => $pdo->query("SELECT id, codigo, nome FROM rh_calendarios WHERE status='ativo' ORDER BY codigo")->fetchAll(),
            'flash' => $_GET['ok'] ?? null,
            'error' => $_GET['erro'] ?? null,
        ]);
    }

    public function storeExcecao(string $id = '0'): void
    {
        $id = (int) $id;
        $pdo = Db::tryConnection();
        if (!$pdo instanceof PDO || $id <= 0) {
            $this->redirectDbMissing();
            return;
        }
        try {
            $pdo->prepare(
                'INSERT INTO rh_colaborador_excecoes
                (colaborador_id, vigencia_inicio, vigencia_fim, jornada_id, escala_id, calendario_id, observacao, usuario_id)
                VALUES (:c,:i,:f,:j,:e,:cal,:o,:u)'
            )->execute([
                'c' => $id,
                'i' => (string) ($_POST['vigencia_inicio'] ?? ''),
                'f' => (string) ($_POST['vigencia_fim'] ?? ''),
                'j' => ($_POST['jornada_id'] ?? '') !== '' ? (int) $_POST['jornada_id'] : null,
                'e' => ($_POST['escala_id'] ?? '') !== '' ? (int) $_POST['escala_id'] : null,
                'cal' => ($_POST['calendario_id'] ?? '') !== '' ? (int) $_POST['calendario_id'] : null,
                'o' => trim((string) ($_POST['observacao'] ?? '')) ?: null,
                'u' => \App\Helpers\Auth::id(),
            ]);
            header('Location: /rh/colaboradores/' . $id . '?ok=excecao');
        } catch (Throwable $e) {
            header('Location: /rh/colaboradores/' . $id . '?erro=' . rawurlencode($e->getMessage()));
        }
        exit;
    }

    public function transfer(string $id = '0'): void
    {
        $id = (int) $id;
        $pdo = Db::tryConnection();
        if (!$pdo instanceof PDO || $id <= 0) {
            $this->redirectDbMissing();
            return;
        }

        $lotacaoId = (int) ($_POST['lotacao_id'] ?? 0);
        $data = (string) ($_POST['data_transferencia'] ?? date('Y-m-d'));
        $observacao = trim((string) ($_POST['observacao'] ?? '')) ?: null;

        try {
            $service = new TransferenciaLotacaoService($pdo);
            $service->transferir($id, $lotacaoId, $data, 'transferencia', $observacao);
            header('Location: /rh/colaboradores/' . $id . '?ok=transferencia');
            exit;
        } catch (Throwable $e) {
            $msg = rawurlencode($e->getMessage());
            header('Location: /rh/colaboradores/' . $id . '?erro=' . $msg);
            exit;
        }
    }

    /**
     * @return array<string,string>
     */
    private function input(): array
    {
        return [
            'empresa_id' => (string) ($_POST['empresa_id'] ?? ''),
            'matricula' => trim((string) ($_POST['matricula'] ?? '')),
            'nome' => trim((string) ($_POST['nome'] ?? '')),
            'cpf' => trim((string) ($_POST['cpf'] ?? '')),
            'email_corp' => trim((string) ($_POST['email_corp'] ?? '')),
            'email_alt' => trim((string) ($_POST['email_alt'] ?? '')),
            'telefone' => trim((string) ($_POST['telefone'] ?? '')),
            'data_admissao' => trim((string) ($_POST['data_admissao'] ?? '')),
            'cargo' => trim((string) ($_POST['cargo'] ?? '')),
            'funcao' => trim((string) ($_POST['funcao'] ?? '')),
            'status' => trim((string) ($_POST['status'] ?? 'ativo')),
            'gestor_id' => trim((string) ($_POST['gestor_id'] ?? '')),
            'supervisor_id' => trim((string) ($_POST['supervisor_id'] ?? '')),
            'lotacao_id' => (string) ($_POST['lotacao_id'] ?? ''),
        ];
    }

    /**
     * @param array<string,string> $data
     * @return array<string,string>
     */
    private function validate(array $data, bool $requireLotacao, ?int $ignoreId = null): array
    {
        $errors = [];
        if ($data['empresa_id'] === '') {
            $errors['empresa_id'] = 'Empresa obrigatória.';
        }
        if ($data['matricula'] === '') {
            $errors['matricula'] = 'Matrícula obrigatória.';
        }
        if ($data['nome'] === '') {
            $errors['nome'] = 'Nome obrigatório.';
        }
        $cpf = preg_replace('/\D+/', '', $data['cpf']) ?: '';
        if (strlen($cpf) !== 11) {
            $errors['cpf'] = 'CPF inválido.';
        }
        if ($requireLotacao && $data['lotacao_id'] === '') {
            $errors['lotacao_id'] = 'Lotação inicial obrigatória.';
        }

        $pdo = Db::tryConnection();
        if ($pdo instanceof PDO && $cpf !== '' && strlen($cpf) === 11) {
            $sql = 'SELECT id FROM rh_colaboradores WHERE cpf = :cpf';
            $params = ['cpf' => $cpf];
            if ($ignoreId !== null) {
                $sql .= ' AND id <> :id';
                $params['id'] = $ignoreId;
            }
            $stmt = $pdo->prepare($sql . ' LIMIT 1');
            $stmt->execute($params);
            if ($stmt->fetch() !== false) {
                $errors['cpf'] = 'CPF já cadastrado.';
            }
        }

        if ($pdo instanceof PDO && $data['matricula'] !== '' && $data['empresa_id'] !== '') {
            $sql = 'SELECT id FROM rh_colaboradores WHERE empresa_id = :e AND matricula = :m';
            $params = ['e' => (int) $data['empresa_id'], 'm' => $data['matricula']];
            if ($ignoreId !== null) {
                $sql .= ' AND id <> :id';
                $params['id'] = $ignoreId;
            }
            $stmt = $pdo->prepare($sql . ' LIMIT 1');
            $stmt->execute($params);
            if ($stmt->fetch() !== false) {
                $errors['matricula'] = 'Matrícula já existe nesta empresa.';
            }
        }

        return $errors;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function find(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM rh_colaboradores WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function historico(PDO $pdo, int $colaboradorId): array
    {
        $sql = <<<SQL
            SELECT cl.*, l.codigo AS lotacao_codigo, l.nome AS lotacao_nome,
                   ct.codigo AS contrato_codigo, cc.codigo AS cc_codigo, os.codigo AS os_codigo,
                   loc.municipio, loc.uf
            FROM rh_colaborador_lotacoes cl
            INNER JOIN rh_lotacoes l ON l.id = cl.lotacao_id
            INNER JOIN rh_contratos ct ON ct.id = l.contrato_id
            INNER JOIN rh_centros_custo cc ON cc.id = l.centro_custo_id
            INNER JOIN rh_ordens_servico os ON os.id = l.ordem_servico_id
            INNER JOIN rh_localidades loc ON loc.id = l.localidade_id
            WHERE cl.colaborador_id = :id
            ORDER BY cl.vigencia_inicio DESC, cl.id DESC
        SQL;
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['id' => $colaboradorId]);
        return $stmt->fetchAll();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function lotacoesAtivas(?PDO $pdo): array
    {
        if (!$pdo instanceof PDO) {
            return [];
        }
        return $pdo->query("SELECT id, codigo, nome FROM rh_lotacoes WHERE status = 'ativo' ORDER BY nome")->fetchAll();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function empresas(?PDO $pdo): array
    {
        if (!$pdo instanceof PDO) {
            return [];
        }
        return $pdo->query("SELECT id, nome FROM rh_empresas WHERE status = 'ativo' ORDER BY nome")->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    private function colaboradoresAtivos(?PDO $pdo, ?int $excludeId = null): array
    {
        if (!$pdo instanceof PDO) {
            return [];
        }
        if ($excludeId !== null) {
            $stmt = $pdo->prepare("SELECT id, matricula, nome FROM rh_colaboradores WHERE status='ativo' AND id <> :id ORDER BY nome");
            $stmt->execute(['id' => $excludeId]);
            return $stmt->fetchAll();
        }
        return $pdo->query("SELECT id, matricula, nome FROM rh_colaboradores WHERE status='ativo' ORDER BY nome")->fetchAll();
    }

    private function redirectDbMissing(): void
    {
        header('Location: /rh/efetivo');
        exit;
    }
}
