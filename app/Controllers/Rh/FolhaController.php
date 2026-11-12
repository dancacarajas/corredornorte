<?php

declare(strict_types=1);

namespace App\Controllers\Rh;

use App\Helpers\Auth;
use App\Helpers\Db;
use App\Helpers\View;
use App\Services\AssinaturaEletronicaService;
use App\Services\FolhaPontoService;
use App\Services\MotorPontoService;
use App\Services\PeriodoPonto;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class FolhaController
{
    public function index(): void
    {
        $pdo = Db::tryConnection();
        $docs = [];
        if ($pdo instanceof PDO) {
            $docs = $pdo->query(
                'SELECT d.*, c.nome, c.matricula, e.status AS espelho_status, pc.ano, pc.mes
                 FROM documentos d
                 INNER JOIN ponto_espelhos e ON e.id = d.espelho_id
                 INNER JOIN rh_colaboradores c ON c.id = e.colaborador_id
                 INNER JOIN ponto_competencias pc ON pc.id = e.competencia_id
                 ORDER BY d.id DESC LIMIT 100'
            )->fetchAll();
        }

        View::render('rh/folha/index', [
            'pageTitle' => 'Folha de Ponto',
            'pageSubtitle' => 'PDF, envio e controle de assinatura',
            'activeMenu' => 'folha',
            'dbOk' => $pdo instanceof PDO,
            'docs' => $docs,
        ]);
    }

    public function show(string $id = '0'): void
    {
        $pdo = Db::tryConnection();
        $espelhoId = (int) $id;
        if (!$pdo instanceof PDO || $espelhoId <= 0) {
            header('Location: /rh/folha');
            exit;
        }

        $service = new FolhaPontoService($pdo);
        try {
            $dados = $service->carregar($espelhoId, $_GET['data_inicio'] ?? null, $_GET['data_fim'] ?? null);
        } catch (RuntimeException $erro) {
            header('Location: /rh/folha/' . $espelhoId . '?erro=' . rawurlencode($erro->getMessage()));
            exit;
        }
        if ($dados === null) {
            http_response_code(404);
            View::render('ui/not-found', ['pageTitle' => 'Folha não encontrada', 'activeMenu' => 'folha']);
            return;
        }

        $periodo = $dados['periodo'];
        $periodoQuery = PeriodoPonto::consulta($periodo);
        if ($dados['recorte']) {
            $docs = $pdo->prepare('SELECT d.* FROM documentos d JOIN ponto_espelhos e ON e.id = d.espelho_id
                WHERE e.colaborador_id = ? AND d.periodo_inicio = ? AND d.periodo_fim = ? ORDER BY d.id DESC');
            $docs->execute([(int) $dados['espelho']['colaborador_id'], $periodo['data_inicio'], $periodo['data_fim']]);
        } else {
            $docs = $pdo->prepare('SELECT * FROM documentos WHERE espelho_id = ? AND periodo_inicio IS NULL ORDER BY id DESC');
            $docs->execute([$espelhoId]);
        }
        $documentos = $docs->fetchAll();
        $portalUrl = $_SESSION['folha_link_rh'][$espelhoId] ?? null;
        unset($_SESSION['folha_link_rh'][$espelhoId]);

        $envios = [];
        if ($documentos !== []) {
            $ids = implode(',', array_map(static fn($d) => (int) $d['id'], $documentos));
            $envios = $pdo->query("SELECT * FROM emails_envios WHERE documento_id IN ({$ids}) ORDER BY id DESC")->fetchAll();
        }

        ob_start();
        ?>
        <form method="post" action="/rh/folha/<?= $espelhoId ?>/gerar?<?= View::e($periodoQuery) ?>" style="display:inline;">
            <button class="btn btn-primary btn-sm" type="submit">Gerar PDF</button>
        </form>
        <?php
        $topbarActions = ob_get_clean() ?: '';

        View::render('rh/folha/show', [
            'pageTitle' => 'Folha · ' . $dados['espelho']['nome'],
            'pageSubtitle' => PeriodoPonto::rotulo($periodo),
            'periodoQuery' => $periodoQuery,
            'activeMenu' => 'folha',
            'topbarActions' => $topbarActions,
            'dados' => $dados,
            'documentos' => $documentos,
            'portalUrl' => $portalUrl,
            'envios' => $envios,
            'flash' => $_GET['ok'] ?? null,
            'error' => $_GET['erro'] ?? null,
        ]);
    }

    public function gerar(string $id = '0'): void
    {
        $pdo = Db::tryConnection();
        $espelhoId = (int) $id;
        if (!$pdo instanceof PDO || $espelhoId <= 0) {
            header('Location: /rh/folha');
            exit;
        }

        try {
            $service = new FolhaPontoService($pdo);
            $inicio = $_GET['data_inicio'] ?? null;
            $fim = $_GET['data_fim'] ?? null;
            $dados = $service->carregar($espelhoId, $inicio, $fim);
            if ($dados === null) {
                throw new RuntimeException('Espelho não encontrado.');
            }
            if (!$dados['recorte'] && $dados['espelho']['status'] === 'conferido') {
                (new MotorPontoService($pdo))->alterarStatus($espelhoId, 'fechado', 'Fechamento automático para PDF', Auth::id());
            }
            $service->gerarPdf($espelhoId, Auth::id(), $inicio, $fim);
            $this->redirecionarFolha($espelhoId, ['ok' => 'pdf']);
        } catch (Throwable $e) {
            $this->redirecionarFolha($espelhoId, ['erro' => $e->getMessage()]);
        }
        exit;
    }

    public function enviar(string $id = '0'): void
    {
        $pdo = Db::tryConnection();
        $espelhoId = (int) $id;
        $docId = (int) ($_POST['documento_id'] ?? 0);
        if (!$pdo instanceof PDO || $espelhoId <= 0 || $docId <= 0) {
            header('Location: /rh/folha');
            exit;
        }

        try {
            $service = new FolhaPontoService($pdo);
            $result = $service->enviarDocumento($docId, null, $espelhoId);
            if (!$result['ok']) {
                throw new \RuntimeException($result['erro'] ?: 'Falha no envio');
            }
            $this->redirecionarFolha($espelhoId, ['ok' => 'email']);
        } catch (Throwable $e) {
            $this->redirecionarFolha($espelhoId, ['erro' => $e->getMessage()]);
        }
        exit;
    }

    /** Gera link do portal (só leitura) para assinatura eletrônica Arcadis. */
    public function gerarLinkAssinatura(string $id = '0'): void
    {
        $pdo = Db::tryConnection();
        $espelhoId = (int) $id;
        $docId = (int) ($_POST['documento_id'] ?? 0);
        if (!$pdo instanceof PDO || $espelhoId <= 0 || $docId <= 0) {
            header('Location: /rh/folha');
            exit;
        }
        try {
            $result = (new AssinaturaEletronicaService($pdo))->gerarLinkPortal($docId, Auth::id(), $espelhoId);
            $_SESSION['folha_link_rh'] = [$espelhoId => $result['portal_url']];
            $this->redirecionarFolha($espelhoId, ['ok' => 'link_assinatura']);
        } catch (Throwable $e) {
            $this->redirecionarFolha($espelhoId, ['erro' => $e->getMessage()]);
        }
        exit;
    }

    private function redirecionarFolha(int $espelhoId, array $parametros): void
    {
        if (is_string($_GET['data_inicio'] ?? null) && is_string($_GET['data_fim'] ?? null)) {
            $parametros += ['data_inicio' => $_GET['data_inicio'], 'data_fim' => $_GET['data_fim']];
        }
        header('Location: /rh/folha/' . $espelhoId . '?' . http_build_query($parametros), true, 303);
    }

    public function portalAssinatura(string $token = ''): void
    {
        $this->headersPortal();
        $pdo = Db::tryConnection();
        $doc = $pdo instanceof PDO ? (new AssinaturaEletronicaService($pdo))->buscarPorPortalToken($token) : null;
        $mensagem = $_SESSION['folha_mensagem'][$token] ?? [];
        unset($_SESSION['folha_mensagem'][$token]);
        $_SESSION['folha_csrf'] ??= bin2hex(random_bytes(32));

        View::render('rh/folha/portal_assinatura', [
            'documento' => $doc,
            'modeloAnterior' => $doc !== null && $doc['status'] !== 'assinado'
                && (int) $doc['layout_pdf'] !== FolhaPontoService::LAYOUT_PDF_ATUAL,
            'assinaturaConfigurada' => AssinaturaEletronicaService::seloConfigurado(),
            'csrf' => $_SESSION['folha_csrf'],
            'textoAceite' => AssinaturaEletronicaService::TEXTO_ACEITE,
            'token' => $token,
            'flash' => $mensagem['ok'] ?? null,
            'error' => $mensagem['erro'] ?? null,
        ], null);
    }

    public function portalAssinar(string $token = ''): void
    {
        $this->headersPortal();
        $csrf = $_POST['csrf'] ?? '';
        if (!is_string($csrf) || empty($_SESSION['folha_csrf']) || !hash_equals($_SESSION['folha_csrf'], $csrf)) {
            http_response_code(403);
            echo 'Sessão inválida. Volte ao portal e tente novamente.';
            return;
        }
        $mensagem = [];
        try {
            $pdo = Db::tryConnection();
            if (!$pdo instanceof PDO) {
                throw new RuntimeException('Sistema indisponível. Tente novamente em instantes.');
            }
            $nome = is_string($_POST['nome'] ?? null) ? $_POST['nome'] : '';
            $hashVisualizado = is_string($_POST['hash_sha256'] ?? null) ? $_POST['hash_sha256'] : '';
            (new AssinaturaEletronicaService($pdo))->assinarNoPortal($token, $nome, ($_POST['aceite'] ?? '') === '1', $hashVisualizado);
            $mensagem['ok'] = 'assinado';
        } catch (Throwable $e) {
            $mensagem['erro'] = $e instanceof RuntimeException && !$e instanceof PDOException
                ? $e->getMessage() : 'Não foi possível concluir a operação. Tente novamente ou fale com o RH.';
        }
        $_SESSION['folha_mensagem'] = [$token => $mensagem];
        header('Location: /assinar/' . rawurlencode($token), true, 303);
        exit;
    }

    public function portalPdf(string $token = ''): void
    {
        $this->headersPortal();
        $pdo = Db::tryConnection();
        if (!$pdo instanceof PDO) {
            http_response_code(404);
            echo 'Documento não encontrado';
            return;
        }
        $doc = (new AssinaturaEletronicaService($pdo))->buscarPorPortalToken($token);
        if ($doc === null) {
            http_response_code(404);
            echo 'Documento não encontrado';
            return;
        }
        if ($doc['status'] !== 'assinado' && (int) $doc['layout_pdf'] !== FolhaPontoService::LAYOUT_PDF_ATUAL) {
            http_response_code(409);
            echo 'Peça ao RH para atualizar esta folha antes de assinar.';
            return;
        }
        if (($doc['status'] ?? '') === 'assinado' && empty($doc['arquivo_assinado_path'])) {
            http_response_code(409);
            echo 'PDF assinado indisponível';
            return;
        }
        $rel = (($doc['status'] ?? '') === 'assinado')
            ? (string) $doc['arquivo_assinado_path']
            : (string) $doc['arquivo_path'];
        $path = dirname(__DIR__, 3) . '/' . ltrim($rel, '/');
        if (!is_file($path)) {
            http_response_code(404);
            echo 'Arquivo ausente';
            return;
        }
        $expectedHash = (($doc['status'] ?? '') === 'assinado')
            ? (string) ($doc['arquivo_assinado_sha256'] ?? '')
            : (string) ($doc['hash_sha256'] ?? '');
        if (array_key_exists('versao', $_GET) && (!is_string($_GET['versao']) || !hash_equals($expectedHash, $_GET['versao']))) {
            http_response_code(409);
            echo 'A via da folha mudou. Atualize a página para conferir o PDF atual.';
            return;
        }
        if ($expectedHash === '' || !hash_equals($expectedHash, (string) hash_file('sha256', $path))) {
            http_response_code(409);
            echo 'Integridade do PDF comprometida';
            return;
        }
        $name = (($doc['status'] ?? '') === 'assinado')
            ? 'cartao_ponto_assinado_' . (int) $doc['id'] . '.pdf'
            : 'folha_' . (int) $doc['id'] . '.pdf';
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $name . '"');
        readfile($path);
        exit;
    }

    private function headersPortal(): void
    {
        header('Cache-Control: no-store');
        header('Referrer-Policy: no-referrer');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
    }

    public function verificar(string $codigo = ''): void
    {
        $this->headersPortal();
        $pdo = Db::tryConnection();
        $doc = null;
        if ($pdo instanceof PDO && $codigo !== '') {
            $stmt = $pdo->prepare(
                'SELECT d.*, c.nome, c.matricula, pc.ano, pc.mes
                 FROM documentos d
                 INNER JOIN ponto_espelhos e ON e.id = d.espelho_id
                 INNER JOIN rh_colaboradores c ON c.id = e.colaborador_id
                 INNER JOIN ponto_competencias pc ON pc.id = e.competencia_id
                 WHERE d.codigo_verificacao = :c LIMIT 1'
            );
            $stmt->execute(['c' => $codigo]);
            $doc = $stmt->fetch() ?: null;
        }
        $integridade = null;
        if ($doc !== null && ($doc['status'] ?? '') === 'assinado') {
            $original = dirname(__DIR__, 3) . '/' . ltrim((string) $doc['arquivo_path'], '/');
            $signed = dirname(__DIR__, 3) . '/' . ltrim((string) ($doc['arquivo_assinado_path'] ?? ''), '/');
            $integridade = is_file($original) && is_file($signed)
                && !empty($doc['hash_sha256']) && !empty($doc['arquivo_assinado_sha256'])
                && hash_equals((string) $doc['hash_sha256'], (string) hash_file('sha256', $original))
                && hash_equals((string) $doc['arquivo_assinado_sha256'], (string) hash_file('sha256', $signed));
        }
        View::render('rh/folha/verificar', [
            'pageTitle' => 'Verificação de documento',
            'pageSubtitle' => $codigo,
            'activeMenu' => 'folha',
            'documento' => $doc,
            'codigo' => $codigo,
            'integridade' => $integridade,
        ], null);
    }

    public function download(string $id = '0'): void
    {
        $pdo = Db::tryConnection();
        $docId = (int) $id;
        if (!$pdo instanceof PDO || $docId <= 0) {
            http_response_code(404);
            echo 'Documento não encontrado';
            return;
        }
        $stmt = $pdo->prepare('SELECT * FROM documentos WHERE id = :id');
        $stmt->execute(['id' => $docId]);
        $doc = $stmt->fetch();
        if ($doc === false) {
            http_response_code(404);
            echo 'Documento não encontrado';
            return;
        }
        $versaoAssinada = ($_GET['versao'] ?? '') === 'assinada';
        $rel = $versaoAssinada ? (string) ($doc['arquivo_assinado_path'] ?? '') : (string) $doc['arquivo_path'];
        $path = dirname(__DIR__, 3) . '/' . ltrim($rel, '/');
        if (!is_file($path)) {
            http_response_code(404);
            echo 'Arquivo ausente';
            return;
        }
        $hash = (string) ($doc['arquivo_assinado_sha256'] ?? '');
        if ($versaoAssinada && $hash !== '' && !hash_equals($hash, (string) hash_file('sha256', $path))) {
            http_response_code(409);
            echo 'Integridade do PDF comprometida';
            return;
        }
        $this->headersPortal();
        $nome = $versaoAssinada ? 'folha_assinada_' . $docId : 'folha_' . $docId;
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $nome . '.pdf"');
        readfile($path);
        exit;
    }
}
