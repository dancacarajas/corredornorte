<?php

declare(strict_types=1);

namespace App\Controllers\Rh;

use App\Helpers\Auth;
use App\Helpers\Csrf;
use App\Helpers\Db;
use App\Helpers\View;
use App\Services\ColaboradorCadastroLookups;
use App\Services\ColaboradorPurgeService;
use App\Services\LotacaoResolver;
use App\Services\TransferenciaLotacaoService;
use DateTimeImmutable;
use PDO;
use RuntimeException;
use Throwable;

final class ColaboradorController
{
    public function createForm(): void
    {
        Auth::requireAnyPermission('efetivo.criar', 'efetivo.operar');
        $pdo = Db::tryConnection();
        View::render('rh/colaboradores/form', array_merge([
            'pageTitle' => 'Novo colaborador',
            'pageSubtitle' => 'Identificação + lotação inicial (regras herdadas)',
            'activeMenu' => 'efetivo',
            'dbOk' => $pdo instanceof PDO,
            'colaborador' => null,
            'colaboradorId' => 0,
            'isEdit' => false,
            'formAction' => '/rh/colaboradores',
            'errors' => [],
        ], $this->formLookups($pdo)));
    }

    public function store(): void
    {
        Auth::requireAnyPermission('efetivo.criar', 'efetivo.operar');
        $pdo = Db::tryConnection();
        if (!$pdo instanceof PDO) {
            $this->redirectDbMissing('Banco de dados indisponível.');
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
            View::render('rh/colaboradores/form', array_merge([
                'pageTitle' => 'Novo colaborador',
                'pageSubtitle' => 'Corrija os campos destacados',
                'activeMenu' => 'efetivo',
                'dbOk' => true,
                'colaborador' => $data,
                'colaboradorId' => 0,
                'isEdit' => false,
                'formAction' => '/rh/colaboradores',
                'errors' => $errors,
            ], $this->formLookups($pdo)));
            return;
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO rh_colaboradores
                (empresa_id, matricula, nome, cpf, email_corp, email_alt, telefone, data_admissao, cargo, funcao, status, gestor_id, supervisor_id, he_fluxo, gestor_cliente_id)
                VALUES
                (:empresa_id, :matricula, :nome, :cpf, :email_corp, :email_alt, :telefone, :data_admissao, :cargo, :funcao, :status, :gestor_id, :supervisor_id, :he_fluxo, :gestor_cliente_id)'
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
                'he_fluxo' => ($data['he_fluxo'] ?? '') === 'cliente' ? 'cliente' : 'off',
                'gestor_cliente_id' => $data['gestor_cliente_id'] !== '' ? (int) $data['gestor_cliente_id'] : null,
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
            View::render('rh/colaboradores/form', array_merge([
                'pageTitle' => 'Novo colaborador',
                'pageSubtitle' => 'Falha ao salvar',
                'activeMenu' => 'efetivo',
                'dbOk' => true,
                'colaborador' => $data,
                'colaboradorId' => 0,
                'isEdit' => false,
                'formAction' => '/rh/colaboradores',
                'errors' => ['geral' => $e->getMessage()],
            ], $this->formLookups($pdo)));
        }
    }

    public function editForm(string $id = '0'): void
    {
        Auth::requireAnyPermission('efetivo.editar', 'efetivo.operar');
        $id = (int) $id;
        $pdo = Db::tryConnection();
        if (!$pdo instanceof PDO || $id <= 0) {
            $this->redirectDbMissing('Não foi possível abrir a edição.');
            return;
        }

        $colaborador = $this->find($pdo, $id);
        if ($colaborador === null) {
            http_response_code(404);
            View::render('ui/not-found', ['pageTitle' => 'Colaborador não encontrado', 'activeMenu' => 'efetivo']);
            return;
        }

        View::render('rh/colaboradores/form', array_merge([
            'pageTitle' => 'Editar colaborador',
            'pageSubtitle' => 'Mat. ' . $colaborador['matricula'],
            'activeMenu' => 'efetivo',
            'dbOk' => true,
            'colaborador' => $colaborador,
            'colaboradorId' => $id,
            'isEdit' => true,
            'formAction' => '/rh/colaboradores/' . $id,
            'errors' => [],
        ], $this->formLookups($pdo)));
    }

    public function update(string $id = '0'): void
    {
        Auth::requireAnyPermission('efetivo.editar', 'efetivo.operar');
        $id = (int) $id;
        if ($id <= 0) {
            $id = (int) ($_POST['id'] ?? 0);
        }
        $pdo = Db::tryConnection();
        if (!$pdo instanceof PDO || $id <= 0) {
            $this->redirectDbMissing('Não foi possível salvar o colaborador.');
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
            View::render('rh/colaboradores/form', array_merge([
                'pageTitle' => 'Editar colaborador',
                'pageSubtitle' => 'Corrija os campos destacados',
                'activeMenu' => 'efetivo',
                'dbOk' => true,
                'colaborador' => array_merge($atual, $data, ['id' => $id]),
                'colaboradorId' => $id,
                'isEdit' => true,
                'formAction' => '/rh/colaboradores/' . $id,
                'errors' => $errors,
            ], $this->formLookups($pdo)));
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
                    supervisor_id = :supervisor_id,
                    he_fluxo = :he_fluxo,
                    gestor_cliente_id = :gestor_cliente_id
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
                'he_fluxo' => ($data['he_fluxo'] ?? '') === 'cliente' ? 'cliente' : 'off',
                'gestor_cliente_id' => $data['gestor_cliente_id'] !== '' ? (int) $data['gestor_cliente_id'] : null,
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
            View::render('rh/colaboradores/form', array_merge([
                'pageTitle' => 'Editar colaborador',
                'pageSubtitle' => 'Falha ao salvar',
                'activeMenu' => 'efetivo',
                'dbOk' => true,
                'colaborador' => array_merge($atual, $data, ['id' => $id]),
                'colaboradorId' => $id,
                'isEdit' => true,
                'formAction' => '/rh/colaboradores/' . $id,
                'errors' => ['geral' => $e->getMessage()],
            ], $this->formLookups($pdo)));
        }
    }

    public function show(string $id = '0'): void
    {
        $id = (int) $id;
        $pdo = Db::tryConnection();
        if (!$pdo instanceof PDO || $id <= 0) {
            $this->redirectDbMissing('Colaborador indisponível.');
            return;
        }

        $colaborador = $this->find($pdo, $id);
        if ($colaborador === null) {
            http_response_code(404);
            View::render('ui/not-found', ['pageTitle' => 'Colaborador não encontrado', 'activeMenu' => 'efetivo']);
            return;
        }

        $resolver = new LotacaoResolver($pdo);
        $atual = (string) ($colaborador['status'] ?? '') === 'desligado'
            ? null
            : $resolver->resolve($id, new DateTimeImmutable('today'));
        $historico = $this->historico($pdo, $id);
        $excecoes = $pdo->prepare('SELECT x.*, h.codigo AS horario_codigo
            FROM rh_colaborador_excecoes x
            LEFT JOIN rh_horarios h ON h.id = x.horario_id
            WHERE x.colaborador_id = :id ORDER BY x.vigencia_inicio DESC');
        $excecoes->execute(['id' => $id]);
        $auditoria = $pdo->prepare("SELECT * FROM rh_auditoria WHERE entidade='rh_colaboradores' AND entidade_id=:id ORDER BY id DESC LIMIT 50");
        $auditoria->execute(['id' => $id]);
        $auditoriaRows = \App\Helpers\AuditoriaCopy::formatarLista($auditoria->fetchAll(PDO::FETCH_ASSOC) ?: [], $pdo);

        $podeEditar = Auth::canAny('efetivo.editar', 'efetivo.operar');
        $podeOperar = Auth::canAny('efetivo.operar', 'efetivo.editar');
        $podeExcluir = Auth::can('efetivo.excluir');
        ob_start();
        if ($podeEditar) {
            ?>
        <a class="btn btn-primary btn-sm" href="/rh/colaboradores/<?= $id ?>/editar">Editar dados</a>
            <?php
        }
        if ($podeOperar) {
            ?>
        <button class="btn btn-secondary btn-sm" type="button" data-modal-open="excecaoModal">Exceção individual</button>
        <button class="btn btn-secondary btn-sm" type="button" data-modal-open="transferModal">Transferir lotação</button>
            <?php
        }
        if ($podeExcluir) {
            ?>
        <form method="post" action="/rh/colaboradores/<?= $id ?>/excluir" style="display:inline;" class="js-excluir-colab">
            <?= Csrf::field() ?>
            <button class="btn btn-danger btn-sm" type="submit"
                    data-confirm-title="Apagar permanentemente?"
                    data-confirm="Apaga 100% este colaborador do sistema (cadastro, lotação, ponto, documentos e histórico). Não dá para desfazer."
                    data-confirm-ok="Apagar definitivamente"
                    data-confirm-tone="danger"
                    data-confirm-danger>Excluir</button>
        </form>
            <?php
        }
        ?>
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
            'auditoria' => $auditoriaRows,
            'lotacoes' => $this->formLookups($pdo)['lotacoes'],
            'horarios' => $this->lookupHorariosAtivos($pdo),
            'calendarios' => $pdo->query("SELECT id, codigo, nome FROM rh_calendarios WHERE status='ativo' ORDER BY codigo")->fetchAll(),
            'podeEditar' => $podeEditar,
            'podeOperar' => $podeOperar,
            'podeExcluir' => $podeExcluir,
            'flash' => $_GET['ok'] ?? null,
            'error' => $_GET['erro'] ?? null,
        ]);
    }

    public function excluir(string $id = '0'): void
    {
        Auth::requirePermission('efetivo.excluir');
        Csrf::requireValid();
        $id = (int) $id;
        $pdo = Db::tryConnection();
        if (!$pdo instanceof PDO || $id <= 0) {
            $this->redirectDbMissing('Não foi possível excluir o colaborador.');
            return;
        }

        $log = static function (string $msg): void {
            $dir = dirname(__DIR__, 2) . '/storage/logs';
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            @file_put_contents(
                $dir . '/excluir_colaborador.log',
                date('c') . ' ' . $msg . "\n",
                FILE_APPEND
            );
        };

        try {
            $apagado = (new ColaboradorPurgeService($pdo))->apagar($id);
            $log('PURGE id=' . $id . ' mat=' . $apagado['matricula'] . ' nome=' . $apagado['nome'] . ' user=' . (string) (Auth::id() ?? 0));
            header('Location: /rh/efetivo?ok=excluido');
        } catch (Throwable $e) {
            $log('ERROR id=' . $id . ' ' . $e->getMessage());
            header('Location: /rh/efetivo?erro=' . rawurlencode($e->getMessage()));
        }
        exit;
    }

    public function excluirMassa(): void
    {
        Auth::requirePermission('efetivo.excluir');
        Csrf::requireValid();
        $pdo = Db::tryConnection();
        if (!$pdo instanceof PDO) {
            $this->redirectDbMissing('Não foi possível excluir em massa.');
            return;
        }

        $log = static function (string $msg): void {
            $dir = dirname(__DIR__, 2) . '/storage/logs';
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            @file_put_contents($dir . '/excluir_colaborador.log', date('c') . ' MASS ' . $msg . "\n", FILE_APPEND);
        };

        $ids = array_values(array_unique(array_filter(
            array_map('intval', (array) ($_POST['ids'] ?? [])),
            static fn (int $i): bool => $i > 0
        )));
        $log('ids=' . implode(',', $ids) . ' actor=' . (string) Auth::id());

        if ($ids === []) {
            header('Location: /rh/efetivo?erro=' . rawurlencode('Selecione ao menos um colaborador.'));
            exit;
        }

        try {
            $ok = 0;
            $purge = new ColaboradorPurgeService($pdo);
            $pdo->beginTransaction();
            foreach ($ids as $id) {
                $apagado = $purge->apagar($id);
                $log('PURGE id=' . $id . ' mat=' . $apagado['matricula'] . ' nome=' . $apagado['nome']);
                $ok++;
            }
            $pdo->commit();
            $purge->apagarArquivosPendentes();
            $log('ok=' . $ok);

            if ($ok === 0) {
                header('Location: /rh/efetivo?erro=' . rawurlencode('Nenhum colaborador elegível para exclusão.'));
            } else {
                header('Location: /rh/efetivo?ok=excluido_massa&n=' . $ok);
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $log('ERROR ' . $e->getMessage());
            header('Location: /rh/efetivo?erro=' . rawurlencode($e->getMessage()));
        }
        exit;
    }

    public function storeExcecao(string $id = '0'): void
    {
        Auth::requireAnyPermission('efetivo.operar', 'efetivo.editar');
        Csrf::requireValid();
        $id = (int) $id;
        $pdo = Db::tryConnection();
        if (!$pdo instanceof PDO || $id <= 0) {
            $this->redirectDbMissing('Não foi possível registrar a exceção.');
            return;
        }
        try {
            (new \App\Services\HorarioService($pdo))->ensureSchema();
            $dados = [
                'vigencia_inicio' => (string) ($_POST['vigencia_inicio'] ?? ''),
                'vigencia_fim' => (string) ($_POST['vigencia_fim'] ?? ''),
                'horario_id' => ($_POST['horario_id'] ?? '') !== '' ? (int) $_POST['horario_id'] : null,
                'calendario_id' => ($_POST['calendario_id'] ?? '') !== '' ? (int) $_POST['calendario_id'] : null,
                'observacao' => trim((string) ($_POST['observacao'] ?? '')) ?: null,
            ];
            $pdo->prepare(
                'INSERT INTO rh_colaborador_excecoes
                (colaborador_id, vigencia_inicio, vigencia_fim, horario_id, calendario_id, observacao, usuario_id)
                VALUES (:c,:i,:f,:h,:cal,:o,:u)'
            )->execute([
                'c' => $id,
                'i' => $dados['vigencia_inicio'],
                'f' => $dados['vigencia_fim'],
                'h' => $dados['horario_id'],
                'cal' => $dados['calendario_id'],
                'o' => $dados['observacao'],
                'u' => Auth::id(),
            ]);
            $pdo->prepare(
                'INSERT INTO rh_auditoria (entidade, entidade_id, acao, depois_json, usuario_id)
                 VALUES (\'rh_colaboradores\', :id, \'excecao_individual\', :depois, :u)'
            )->execute([
                'id' => $id,
                'depois' => json_encode([
                    ...$dados,
                    'resumo' => 'Exceção individual de '
                        . $dados['vigencia_inicio'] . ' a ' . $dados['vigencia_fim']
                        . ($dados['observacao'] ? ' · ' . $dados['observacao'] : '') . '.',
                ], JSON_UNESCAPED_UNICODE),
                'u' => Auth::id(),
            ]);
            header('Location: /rh/colaboradores/' . $id . '?ok=excecao');
        } catch (Throwable $e) {
            header('Location: /rh/colaboradores/' . $id . '?erro=' . rawurlencode($e->getMessage()));
        }
        exit;
    }

    public function transfer(string $id = '0'): void
    {
        Auth::requireAnyPermission('efetivo.operar', 'efetivo.editar');
        Csrf::requireValid();
        $id = (int) $id;
        $pdo = Db::tryConnection();
        if (!$pdo instanceof PDO || $id <= 0) {
            $this->redirectDbMissing('Não foi possível transferir a lotação.');
            return;
        }

        $lotacaoId = (int) ($_POST['lotacao_id'] ?? 0);
        $data = (string) ($_POST['data_transferencia'] ?? date('Y-m-d'));
        $observacao = trim((string) ($_POST['observacao'] ?? '')) ?: null;
        $pctRaw = trim((string) ($_POST['percentual_he'] ?? ''));
        $percentualHe = $pctRaw === '' ? null : (int) $pctRaw;

        try {
            $service = new TransferenciaLotacaoService($pdo);
            $service->transferir(
                $id,
                $lotacaoId,
                $data,
                'transferencia',
                $observacao,
                \App\Helpers\Auth::id(),
                null,
                $percentualHe
            );
            header('Location: /rh/colaboradores/' . $id . '?ok=transferencia');
            exit;
        } catch (Throwable $e) {
            $msg = rawurlencode($e->getMessage());
            header('Location: /rh/colaboradores/' . $id . '?erro=' . $msg);
            exit;
        }
    }

    public function atualizarPercentualHe(string $id = '0'): void
    {
        Auth::requireAnyPermission('efetivo.operar', 'efetivo.editar');
        Csrf::requireValid();
        $id = (int) $id;
        $pdo = Db::tryConnection();
        if (!$pdo instanceof PDO || $id <= 0) {
            $this->redirectDbMissing('Não foi possível atualizar o percentual de HE.');
            return;
        }

        $pctRaw = trim((string) ($_POST['percentual_he'] ?? ''));
        $percentualHe = $pctRaw === '' ? null : (int) $pctRaw;

        try {
            $service = new TransferenciaLotacaoService($pdo);
            $service->atualizarPercentualHe($id, $percentualHe, Auth::id());
            header('Location: /rh/colaboradores/' . $id . '?ok=percentual_he');
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
            'he_fluxo' => trim((string) ($_POST['he_fluxo'] ?? 'off')),
            'gestor_cliente_id' => trim((string) ($_POST['gestor_cliente_id'] ?? '')),
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
                   l.percentual_he AS lotacao_percentual_he,
                   COALESCE(cl.percentual_he, l.percentual_he, 50) AS percentual_he_efetivo,
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
    /**
     * @return array<string, list<array<string,mixed>>>
     */
    private function formLookups(?PDO $pdo): array
    {
        $empty = [
            'empresas' => [],
            'cargos' => [],
            'funcoes' => [],
            'gestoresArcadis' => [],
            'supervisores' => [],
            'gestoresCliente' => [],
            'lotacoes' => [],
        ];
        if (!$pdo instanceof PDO) {
            return $empty;
        }
        try {
            return (new ColaboradorCadastroLookups($pdo))->all();
        } catch (Throwable) {
            return $empty;
        }
    }

    /** @return list<array<string,mixed>> */
    private function lookupHorariosAtivos(PDO $pdo): array
    {
        try {
            (new \App\Services\HorarioService($pdo))->ensureSchema();
            return $pdo->query(
                "SELECT id, codigo, nome FROM rh_horarios WHERE status='ativo' ORDER BY codigo"
            )->fetchAll() ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    private function redirectDbMissing(string $mensagem = 'Operação indisponível no momento.'): void
    {
        header('Location: /rh/efetivo?erro=' . rawurlencode($mensagem));
        exit;
    }
}
