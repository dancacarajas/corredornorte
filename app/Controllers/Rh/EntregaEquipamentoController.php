<?php

declare(strict_types=1);

namespace App\Controllers\Rh;

use App\Helpers\Auth;
use App\Helpers\Db;
use App\Helpers\View;
use App\Services\EntregaEquipamentoService;
use PDO;
use RuntimeException;
use Throwable;

final class EntregaEquipamentoController
{
    public function index(): void
    {
        Auth::requirePermission('entregas.ver');
        $pdo = Db::tryConnection();
        $q = trim((string) ($_GET['q'] ?? ''));
        $status = trim((string) ($_GET['status'] ?? ''));
        $lista = [];
        $dbOk = $pdo instanceof PDO;
        if ($dbOk) {
            try {
                $lista = (new EntregaEquipamentoService($pdo))->listar($q, $status);
            } catch (Throwable $e) {
                $_SESSION['entrega_error'] = $e->getMessage();
            }
        }

        View::render('rh/entregas_equipamentos/index', [
            'pageTitle' => 'Entrega de Equipamentos',
            'pageSubtitle' => 'Termo COR-JUR-FM-005 · comodato',
            'activeMenu' => 'entregas',
            'dbOk' => $dbOk,
            'entregas' => $lista,
            'q' => $q,
            'status' => $status,
            'podeGerir' => Auth::canAny('entregas.gerir', 'entregas.operar'),
            'flash' => $_SESSION['entrega_flash'] ?? null,
            'error' => $_SESSION['entrega_error'] ?? null,
        ]);
        unset($_SESSION['entrega_flash'], $_SESSION['entrega_error']);
    }

    public function equipamentos(): void
    {
        $this->catalogoIndex('equipamento');
    }

    public function perifericos(): void
    {
        $this->catalogoIndex('periferico');
    }

    public function salvarEquipamento(): void
    {
        $this->catalogoSalvar('equipamento');
    }

    public function salvarPeriferico(): void
    {
        $this->catalogoSalvar('periferico');
    }

    public function excluirEquipamento(string $id = '0'): void
    {
        $this->catalogoExcluir('equipamento', (int) $id);
    }

    public function excluirPeriferico(string $id = '0'): void
    {
        $this->catalogoExcluir('periferico', (int) $id);
    }

    public function createForm(): void
    {
        Auth::requirePermission('entregas.gerir');
        $this->renderForm(null);
    }

    public function editForm(string $id = '0'): void
    {
        Auth::requirePermission('entregas.ver');
        $this->renderForm((int) $id);
    }

    public function store(): void
    {
        Auth::requirePermission('entregas.gerir');
        $this->persist(null);
    }

    public function update(string $id = '0'): void
    {
        Auth::requirePermission('entregas.gerir');
        $this->persist((int) $id);
    }

    public function emitir(string $id = '0'): void
    {
        Auth::requirePermission('entregas.gerir');
        $entregaId = (int) $id;
        $pdo = Db::tryConnection();
        $isGet = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'GET';
        try {
            if (!$pdo instanceof PDO || $entregaId <= 0) {
                throw new RuntimeException('Entrega inválida.');
            }
            $result = (new EntregaEquipamentoService($pdo))->emitirPdf($entregaId, Auth::id());
            if ($isGet || !empty($_POST['download']) || !empty($_GET['download'])) {
                $this->streamFile((string) $result['abs'], 'termo_entrega_' . $entregaId . '.pdf', 'application/pdf', true);
            }
            $_SESSION['entrega_flash'] = 'Termo PDF emitido.';
        } catch (Throwable $e) {
            $_SESSION['entrega_error'] = $e->getMessage();
        }
        header('Location: /rh/entregas-equipamentos/' . $entregaId);
        exit;
    }

    public function anexarAssinado(string $id = '0'): void
    {
        Auth::requirePermission('entregas.gerir');
        $entregaId = (int) $id;
        $pdo = Db::tryConnection();
        try {
            if (!$pdo instanceof PDO || $entregaId <= 0) {
                throw new RuntimeException('Entrega inválida.');
            }
            $file = $_FILES['arquivo'] ?? null;
            if (!is_array($file)) {
                throw new RuntimeException('Selecione o termo assinado.');
            }
            (new EntregaEquipamentoService($pdo))->anexarAssinado($entregaId, $file, Auth::id());
            $_SESSION['entrega_flash'] = 'Termo assinado anexado.';
        } catch (Throwable $e) {
            $_SESSION['entrega_error'] = $e->getMessage();
        }
        header('Location: /rh/entregas-equipamentos/' . $entregaId);
        exit;
    }

    public function devolucao(string $id = '0'): void
    {
        Auth::requirePermission('entregas.gerir');
        $entregaId = (int) $id;
        $pdo = Db::tryConnection();
        try {
            if (!$pdo instanceof PDO || $entregaId <= 0) {
                throw new RuntimeException('Entrega inválida.');
            }
            $file = $_FILES['arquivo'] ?? null;
            $fileOk = is_array($file) && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE
                ? $file
                : null;
            (new EntregaEquipamentoService($pdo))->registrarDevolucao(
                $entregaId,
                $_POST,
                $fileOk,
                Auth::id()
            );
            $_SESSION['entrega_flash'] = 'Devolução registrada.';
        } catch (Throwable $e) {
            $_SESSION['entrega_error'] = $e->getMessage();
        }
        header('Location: /rh/entregas-equipamentos/' . $entregaId);
        exit;
    }

    public function cancelar(string $id = '0'): void
    {
        Auth::requirePermission('entregas.gerir');
        $entregaId = (int) $id;
        $pdo = Db::tryConnection();
        try {
            if (!$pdo instanceof PDO || $entregaId <= 0) {
                throw new RuntimeException('Entrega inválida.');
            }
            (new EntregaEquipamentoService($pdo))->cancelar($entregaId);
            $_SESSION['entrega_flash'] = 'Entrega cancelada.';
        } catch (Throwable $e) {
            $_SESSION['entrega_error'] = $e->getMessage();
        }
        header('Location: /rh/entregas-equipamentos/' . $entregaId);
        exit;
    }

    public function pdf(string $id = '0'): void
    {
        Auth::requirePermission('entregas.ver');
        $entregaId = (int) $id;
        $pdo = Db::tryConnection();
        if (!$pdo instanceof PDO || $entregaId <= 0) {
            http_response_code(404);
            exit;
        }
        $svc = new EntregaEquipamentoService($pdo);
        $entrega = $svc->obter($entregaId);
        if ($entrega === null || empty($entrega['arquivo_path'])) {
            $_SESSION['entrega_error'] = 'PDF ainda não emitido.';
            header('Location: /rh/entregas-equipamentos/' . $entregaId);
            exit;
        }
        $abs = dirname(__DIR__, 3) . '/' . ltrim((string) $entrega['arquivo_path'], '/');
        if (!is_file($abs)) {
            $_SESSION['entrega_error'] = 'Arquivo PDF não encontrado no servidor.';
            header('Location: /rh/entregas-equipamentos/' . $entregaId);
            exit;
        }
        $this->streamFile($abs, 'termo_' . ($entrega['codigo'] ?? $entregaId) . '.pdf', 'application/pdf', true);
    }

    public function anexo(string $id = '0', string $anexoId = '0'): void
    {
        Auth::requirePermission('entregas.ver');
        $entregaId = (int) $id;
        $aid = (int) $anexoId;
        $pdo = Db::tryConnection();
        if (!$pdo instanceof PDO || $entregaId <= 0 || $aid <= 0) {
            http_response_code(404);
            exit;
        }
        $svc = new EntregaEquipamentoService($pdo);
        $anexos = $svc->anexos($entregaId);
        $row = null;
        foreach ($anexos as $a) {
            if ((int) ($a['id'] ?? 0) === $aid) {
                $row = $a;
                break;
            }
        }
        if ($row === null) {
            http_response_code(404);
            exit;
        }
        $abs = dirname(__DIR__, 3) . '/' . ltrim((string) $row['arquivo_path'], '/');
        if (!is_file($abs)) {
            http_response_code(404);
            exit;
        }
        $mime = (string) ($row['mime'] ?? 'application/octet-stream');
        $nome = (string) ($row['arquivo_nome'] ?? 'anexo.bin');
        $this->streamFile($abs, $nome, $mime, false);
    }

    public function colaboradorJson(string $id = '0'): void
    {
        Auth::requirePermission('entregas.ver');
        header('Content-Type: application/json; charset=UTF-8');
        $pdo = Db::tryConnection();
        $colabId = (int) $id;
        if (!$pdo instanceof PDO || $colabId <= 0) {
            echo json_encode(['ok' => false], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $c = (new EntregaEquipamentoService($pdo))->colaborador($colabId);
        if ($c === null) {
            echo json_encode(['ok' => false], JSON_UNESCAPED_UNICODE);
            exit;
        }
        echo json_encode([
            'ok' => true,
            'id' => (int) $c['id'],
            'nome' => (string) ($c['nome'] ?? ''),
            'cpf' => $this->formatCpf((string) ($c['cpf'] ?? '')),
            'telefone' => (string) ($c['telefone'] ?? ''),
            'email' => (string) ($c['email_corp'] ?? $c['email_alt'] ?? ''),
            'rg' => (string) ($c['rg'] ?? ''),
            'endereco' => (string) ($c['endereco'] ?? ''),
            'bairro' => (string) ($c['bairro'] ?? ''),
            'municipio' => (string) ($c['municipio'] ?? ''),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    private function renderForm(?int $id): void
    {
        $pdo = Db::tryConnection();
        if (!$pdo instanceof PDO) {
            View::render('rh/entregas_equipamentos/form', [
                'pageTitle' => 'Entrega de Equipamentos',
                'activeMenu' => 'entregas',
                'dbOk' => false,
                'entrega' => null,
                'itens' => [],
                'anexos' => [],
                'colaboradores' => [],
                'tipos' => [],
                'gestoresArcadis' => [],
                'supervisores' => [],
                'podeGerir' => false,
                'flash' => null,
                'error' => 'Banco indisponível.',
            ]);
            return;
        }

        $svc = new EntregaEquipamentoService($pdo);
        $entrega = null;
        $itens = [];
        $anexos = [];
        if ($id !== null && $id > 0) {
            $entrega = $svc->obter($id);
            if ($entrega === null) {
                http_response_code(404);
                View::render('ui/not-found', [
                    'pageTitle' => 'Entrega não encontrada',
                    'activeMenu' => 'entregas',
                ]);
                return;
            }
            $itens = $svc->itens($id);
            $anexos = $svc->anexos($id);
        }

        $codigo = (string) ($entrega['codigo'] ?? 'Nova');
        View::render('rh/entregas_equipamentos/form', [
            'pageTitle' => $entrega ? ('Entrega ' . $codigo) : 'Nova entrega',
            'pageSubtitle' => 'Termo COR-JUR-FM-005',
            'activeMenu' => 'entregas',
            'dbOk' => true,
            'entrega' => $entrega,
            'itens' => $itens,
            'anexos' => $anexos,
            'colaboradores' => $svc->colaboradoresAtivos(),
            'tipos' => $svc->tiposItens(),
            'gestoresArcadis' => $svc->gestoresArcadis(),
            'supervisores' => $svc->supervisores(),
            'podeGerir' => Auth::canAny('entregas.gerir', 'entregas.operar'),
            'flash' => $_SESSION['entrega_flash'] ?? null,
            'error' => $_SESSION['entrega_error'] ?? null,
        ]);
        unset($_SESSION['entrega_flash'], $_SESSION['entrega_error']);
    }

    private function catalogoIndex(string $categoria): void
    {
        Auth::requirePermission('entregas.ver');
        $pdo = Db::tryConnection();
        $itens = [];
        $dbOk = $pdo instanceof PDO;
        $editando = null;
        $editarId = (int) ($_GET['editar'] ?? 0);
        if ($dbOk) {
            try {
                $svc = new EntregaEquipamentoService($pdo);
                $itens = $svc->tiposItens($categoria, false);
                if ($editarId > 0) {
                    $editando = $svc->obterTipo($editarId);
                    if ($editando !== null && (string) ($editando['categoria'] ?? '') !== $categoria) {
                        $editando = null;
                    }
                }
            } catch (Throwable $e) {
                $_SESSION['entrega_error'] = $e->getMessage();
            }
        }
        $isEquip = $categoria === 'equipamento';
        View::render('rh/entregas_equipamentos/catalogo', [
            'pageTitle' => $isEquip ? 'Equipamentos' : 'Periféricos',
            'pageSubtitle' => $isEquip
                ? 'Catálogo de equipamentos para as entregas'
                : 'Catálogo de periféricos para as entregas',
            'activeMenu' => $isEquip ? 'entregas-equipamentos-cat' : 'entregas-perifericos',
            'categoria' => $categoria,
            'basePath' => $isEquip
                ? '/rh/entregas-equipamentos/equipamentos'
                : '/rh/entregas-equipamentos/perifericos',
            'dbOk' => $dbOk,
            'itens' => $itens,
            'editando' => $editando,
            'podeGerir' => Auth::canAny('entregas.gerir', 'entregas.operar'),
            'flash' => $_SESSION['entrega_flash'] ?? null,
            'error' => $_SESSION['entrega_error'] ?? null,
        ]);
        unset($_SESSION['entrega_flash'], $_SESSION['entrega_error']);
    }

    private function catalogoSalvar(string $categoria): void
    {
        Auth::requirePermission('entregas.gerir');
        $pdo = Db::tryConnection();
        $base = $categoria === 'equipamento'
            ? '/rh/entregas-equipamentos/equipamentos'
            : '/rh/entregas-equipamentos/perifericos';
        try {
            if (!$pdo instanceof PDO) {
                throw new RuntimeException('Banco indisponível.');
            }
            $id = (int) ($_POST['id'] ?? 0);
            (new EntregaEquipamentoService($pdo))->salvarTipo(
                $id > 0 ? $id : null,
                $categoria,
                (string) ($_POST['nome'] ?? ''),
                (string) ($_POST['status'] ?? 'ativo')
            );
            $_SESSION['entrega_flash'] = $id > 0 ? 'Item atualizado.' : 'Item cadastrado.';
        } catch (Throwable $e) {
            $_SESSION['entrega_error'] = $e->getMessage();
        }
        header('Location: ' . $base);
        exit;
    }

    private function catalogoExcluir(string $categoria, int $id): void
    {
        Auth::requirePermission('entregas.gerir');
        $pdo = Db::tryConnection();
        $base = $categoria === 'equipamento'
            ? '/rh/entregas-equipamentos/equipamentos'
            : '/rh/entregas-equipamentos/perifericos';
        try {
            if (!$pdo instanceof PDO || $id <= 0) {
                throw new RuntimeException('Item inválido.');
            }
            (new EntregaEquipamentoService($pdo))->inativarTipo($id, $categoria);
            $_SESSION['entrega_flash'] = 'Item inativado.';
        } catch (Throwable $e) {
            $_SESSION['entrega_error'] = $e->getMessage();
        }
        header('Location: ' . $base);
        exit;
    }

    private function persist(?int $id): void
    {
        $pdo = Db::tryConnection();
        try {
            if (!$pdo instanceof PDO) {
                throw new RuntimeException('Banco indisponível.');
            }
            $itens = $this->parseItens($_POST);
            $entregaId = (new EntregaEquipamentoService($pdo))->salvar(
                $id,
                $_POST,
                $itens,
                Auth::id()
            );
            $_SESSION['entrega_flash'] = $id ? 'Entrega atualizada.' : 'Entrega criada.';
            $passo = (int) ($_POST['voltar_passo'] ?? 0);
            if ($passo < 1 || $passo > 4) {
                $passo = 4;
            }
            header('Location: /rh/entregas-equipamentos/' . $entregaId . '#passo=' . $passo);
            exit;
        } catch (Throwable $e) {
            $_SESSION['entrega_error'] = $e->getMessage();
            $passo = (int) ($_POST['voltar_passo'] ?? 1);
            if ($passo < 1 || $passo > 4) {
                $passo = 1;
            }
            if ($id !== null && $id > 0) {
                header('Location: /rh/entregas-equipamentos/' . $id . '#passo=' . $passo);
            } else {
                header('Location: /rh/entregas-equipamentos/nova#passo=' . $passo);
            }
            exit;
        }
    }

    /** @return list<array{tipo:string,quantidade:int,descricao:string,tombamento:?string}> */
    private function parseItens(array $post): array
    {
        $tipos = $post['item_tipo'] ?? [];
        $qtds = $post['item_qtd'] ?? [];
        $descs = $post['item_desc'] ?? [];
        $tombs = $post['item_tomb'] ?? [];
        if (!is_array($tipos) || !is_array($descs)) {
            return [];
        }
        $out = [];
        foreach ($descs as $i => $desc) {
            $out[] = [
                'tipo' => (string) ($tipos[$i] ?? 'equipamento'),
                'quantidade' => (int) ($qtds[$i] ?? 1),
                'descricao' => (string) $desc,
                'tombamento' => isset($tombs[$i]) ? (string) $tombs[$i] : null,
            ];
        }
        return $out;
    }

    private function formatCpf(string $digits): string
    {
        $d = preg_replace('/\D+/', '', $digits) ?? '';
        if (strlen($d) !== 11) {
            return $digits;
        }
        return substr($d, 0, 3) . '.' . substr($d, 3, 3) . '.' . substr($d, 6, 3) . '-' . substr($d, 9, 2);
    }

    private function streamFile(string $abs, string $nome, string $mime, bool $inline): void
    {
        header('Content-Type: ' . $mime);
        $disp = $inline ? 'inline' : 'attachment';
        header('Content-Disposition: ' . $disp . '; filename="' . str_replace('"', '', $nome) . '"');
        header('Content-Length: ' . (string) filesize($abs));
        readfile($abs);
        exit;
    }
}
