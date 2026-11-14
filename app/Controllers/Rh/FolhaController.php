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
        $q = trim((string) ($_GET['q'] ?? ''));
        $status = trim((string) ($_GET['status'] ?? ''));
        $dataInicio = trim((string) ($_GET['data_inicio'] ?? ''));
        $dataFim = trim((string) ($_GET['data_fim'] ?? ''));
        $docs = [];
        if ($pdo instanceof PDO) {
            $where = ['1=1'];
            $params = [];
            if ($q !== '') {
                $where[] = '(c.nome LIKE :q_nome OR c.matricula LIKE :q_mat)';
                $like = '%' . $q . '%';
                $params['q_nome'] = $like;
                $params['q_mat'] = $like;
            }
            if ($status !== '' && in_array($status, ['gerado', 'enviado', 'assinado', 'invalidado'], true)) {
                $where[] = 'd.status = :st';
                $params['st'] = $status;
            }
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $dataInicio) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $dataFim)) {
                $where[] = 'pp.data_inicio >= :ini AND pp.data_fim <= :fim';
                $params['ini'] = $dataInicio;
                $params['fim'] = $dataFim;
            }
            $st = $pdo->prepare(
                'SELECT d.*, c.nome, c.matricula, e.status AS espelho_status,
                        pp.data_inicio AS periodo_cartao_inicio, pp.data_fim AS periodo_cartao_fim,
                        YEAR(pp.data_inicio) AS ano, MONTH(pp.data_inicio) AS mes
                 FROM documentos d
                 INNER JOIN ponto_espelhos e ON e.id = d.espelho_id
                 INNER JOIN rh_colaboradores c ON c.id = e.colaborador_id
                 INNER JOIN ponto_periodos pp ON pp.id = e.periodo_id
                 WHERE ' . implode(' AND ', $where) . '
                 ORDER BY d.id DESC LIMIT 200'
            );
            $st->execute($params);
            $docs = $st->fetchAll();
        }

        View::render('rh/folha/index', [
            'pageTitle' => 'Folha de Ponto',
            'pageSubtitle' => 'PDF, envio e controle de assinatura',
            'activeMenu' => 'folha',
            'dbOk' => $pdo instanceof PDO,
            'docs' => $docs,
            'q' => $q,
            'status' => $status,
            'dataInicio' => $dataInicio,
            'dataFim' => $dataFim,
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
        $idsEspelhos = array_map(static fn(array $e): int => (int) $e['id'], $dados['espelhos_periodo']);
        if ($idsEspelhos === []) {
            $idsEspelhos = [$espelhoId];
        }
        $inEspelhos = implode(',', $idsEspelhos);

        if ($dados['recorte']) {
            $docs = $pdo->prepare(
                "SELECT DISTINCT d.*
                 FROM documentos d
                 LEFT JOIN documentos_espelhos de ON de.documento_id = d.id
                 WHERE d.periodo_inicio = ? AND d.periodo_fim = ?
                   AND (d.espelho_id IN ({$inEspelhos}) OR de.espelho_id IN ({$inEspelhos}))
                 ORDER BY d.id DESC"
            );
            $docs->execute([$periodo['data_inicio'], $periodo['data_fim']]);
        } else {
            $docs = $pdo->prepare(
                'SELECT * FROM documentos
                 WHERE espelho_id = ? AND periodo_inicio IS NULL
                 ORDER BY id DESC'
            );
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

        // Detalhe do(s) cartão(ões) do período para a UI.
        $espelhosUi = $pdo->query(
            "SELECT e.id, e.status, pp.data_inicio, pp.data_fim,
                    YEAR(pp.data_inicio) AS ano, MONTH(pp.data_inicio) AS mes
             FROM ponto_espelhos e
             INNER JOIN ponto_periodos pp ON pp.id = e.periodo_id
             WHERE e.id IN ({$inEspelhos})
             ORDER BY pp.data_inicio, pp.data_fim"
        )->fetchAll();

        $docAtivo = null;
        foreach ($documentos as $doc) {
            if (in_array((string) $doc['status'], ['gerado', 'enviado'], true)) {
                $docAtivo = $doc;
                break;
            }
        }

        ob_start();
        ?>
        <a class="btn btn-secondary btn-sm" href="/rh/ponto/<?= $espelhoId ?>?<?= View::e($periodoQuery) ?>">Voltar ao ponto</a>
        <form method="post" action="/rh/folha/<?= $espelhoId ?>/gerar?<?= View::e($periodoQuery) ?>" style="display:inline;">
            <button class="btn btn-primary btn-sm" type="submit">Gerar PDF</button>
        </form>
        <?php if ($docAtivo !== null): ?>
            <form method="post" action="/rh/folha/<?= $espelhoId ?>/enviar?<?= View::e($periodoQuery) ?>" style="display:inline;">
                <input type="hidden" name="documento_id" value="<?= (int) $docAtivo['id'] ?>">
                <button class="btn btn-secondary btn-sm" type="submit">Enviar PDF</button>
            </form>
            <form method="post" action="/rh/folha/<?= $espelhoId ?>/link-assinatura?<?= View::e($periodoQuery) ?>" style="display:inline;">
                <input type="hidden" name="documento_id" value="<?= (int) $docAtivo['id'] ?>">
                <button class="btn btn-secondary btn-sm" type="submit">Gerar link assinatura</button>
            </form>
        <?php endif; ?>
        <?php
        $topbarActions = ob_get_clean() ?: '';

        View::render('rh/folha/show', [
            'pageTitle' => 'Folha · ' . $dados['espelho']['nome'],
            'pageSubtitle' => PeriodoPonto::rotulo($periodo),
            'periodoQuery' => $periodoQuery,
            'activeMenu' => 'folha',
            'topbarActions' => $topbarActions,
            'dados' => $dados,
            'espelhosUi' => $espelhosUi,
            'documentos' => $documentos,
            'docAtivo' => $docAtivo,
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

            // Período 16–15 pode cruzar 2 competências: fecha todas antes de emitir.
            $motor = new MotorPontoService($pdo);
            foreach ($dados['espelhos_periodo'] as $espelhoPeriodo) {
                $statusEsp = (string) ($espelhoPeriodo['status'] ?? '');
                if (!in_array($statusEsp, ['fechado', 'enviado', 'assinado'], true)) {
                    $motor->alterarStatus(
                        (int) $espelhoPeriodo['id'],
                        'fechado',
                        'Fechamento automático para emissão da folha',
                        Auth::id()
                    );
                }
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
        $assinatura = $pdo instanceof PDO ? new AssinaturaEletronicaService($pdo) : null;
        $doc = $assinatura?->buscarPorPortalToken($token);
        if ($doc !== null && $doc['status'] === 'enviado' && (int) $doc['layout_pdf'] === 3) {
            try {
                // A via nova reserva o espaço do selo e preserva o link da via pendente.
                (new FolhaPontoService($pdo))->atualizarModeloPendente((int) $doc['id']);
                $doc = $assinatura->buscarPorPortalToken($token);
            } catch (Throwable $erro) {
                error_log('Falha ao atualizar o modelo da folha ' . (int) $doc['id'] . ': ' . $erro->getMessage());
            }
        }
        $resumoEspelho = ($doc !== null && $assinatura !== null)
            ? $assinatura->carregarResumoEspelho($doc)
            : [
                'espelho_status' => 'enviado',
                'totais' => ['trab_min' => 0, 'he50_min' => 0, 'he100_min' => 0, 'falta_min' => 0, 'adn_min' => 0, 'dias' => 0],
                'dias' => [],
                'divergencias' => [],
            ];
        $mensagem = $_SESSION['folha_mensagem'][$token] ?? [];
        unset($_SESSION['folha_mensagem'][$token]);
        $_SESSION['folha_csrf'] ??= bin2hex(random_bytes(32));

        View::render('rh/folha/portal_assinatura', [
            'documento' => $doc,
            'resumoEspelho' => $resumoEspelho,
            'modeloAnterior' => $doc !== null && $doc['status'] !== 'assinado'
                && (int) $doc['layout_pdf'] !== FolhaPontoService::LAYOUT_PDF_ATUAL,
            'assinaturaConfigurada' => AssinaturaEletronicaService::seloConfigurado(),
            'csrf' => $_SESSION['folha_csrf'],
            'textoAceite' => AssinaturaEletronicaService::TEXTO_ACEITE,
            'token' => $token,
            'flash' => $mensagem['ok'] ?? null,
            'error' => $mensagem['erro'] ?? null,
            'nomeInformado' => (string) ($mensagem['nome'] ?? ''),
        ], null);
    }

    public function portalAssinar(string $token = ''): void
    {
        $this->headersPortal();
        $csrf = $_POST['csrf'] ?? '';
        if (!is_string($csrf) || empty($_SESSION['folha_csrf']) || !hash_equals($_SESSION['folha_csrf'], $csrf)) {
            $this->renderPortalErro(
                403,
                'Sessão expirada',
                'Por segurança, o formulário desta folha expirou. Recarregue a página do portal e tente novamente.',
                '/assinar/' . rawurlencode($token)
            );
            return;
        }
        $mensagem = [];
        $nome = is_string($_POST['nome'] ?? null) ? $_POST['nome'] : '';
        try {
            $pdo = Db::tryConnection();
            if (!$pdo instanceof PDO) {
                throw new RuntimeException('Sistema indisponível. Tente novamente em instantes.');
            }
            $hashVisualizado = is_string($_POST['hash_sha256'] ?? null) ? $_POST['hash_sha256'] : '';
            (new AssinaturaEletronicaService($pdo))->assinarNoPortal($token, $nome, ($_POST['aceite'] ?? '') === '1', $hashVisualizado);
            $mensagem['ok'] = 'assinado';
        } catch (Throwable $e) {
            $mensagem['erro'] = $e instanceof RuntimeException && !$e instanceof PDOException
                ? $e->getMessage() : 'Não foi possível concluir a operação. Tente novamente ou fale com o RH.';
            $mensagem['nome'] = $nome;
        }
        $_SESSION['folha_mensagem'] = [$token => $mensagem];
        header('Location: /assinar/' . rawurlencode($token), true, 303);
        exit;
    }

    public function portalDivergencia(string $token = ''): void
    {
        $this->headersPortal();
        $csrf = $_POST['csrf'] ?? '';
        if (!is_string($csrf) || empty($_SESSION['folha_csrf']) || !hash_equals($_SESSION['folha_csrf'], $csrf)) {
            $this->renderPortalErro(
                403,
                'Sessão expirada',
                'Sua sessão de conferência expirou. Volte para a folha e tente enviar o apontamento novamente.',
                '/assinar/' . rawurlencode($token)
            );
            return;
        }
        $mensagem = [];
        try {
            $pdo = Db::tryConnection();
            if (!$pdo instanceof PDO) {
                throw new RuntimeException('Sistema indisponível. Tente novamente em instantes.');
            }
            $motivo = is_string($_POST['motivo'] ?? null) ? $_POST['motivo'] : '';
            $dataOcorrencia = is_string($_POST['data_ocorrencia'] ?? null) ? $_POST['data_ocorrencia'] : null;
            (new AssinaturaEletronicaService($pdo))->registrarDivergencia($token, $motivo, $dataOcorrencia);
            $mensagem['ok'] = 'divergencia';
        } catch (Throwable $e) {
            $mensagem['erro'] = $e instanceof RuntimeException && !$e instanceof PDOException
                ? $e->getMessage() : 'Não foi possível registrar a divergência. Tente novamente ou contate o RH.';
        }
        $_SESSION['folha_mensagem'] = [$token => $mensagem];
        header('Location: /assinar/' . rawurlencode($token), true, 303);
        exit;
    }

    private function renderPortalErro(int $status, string $titulo, string $descricao, ?string $voltarUrl = null): void
    {
        http_response_code($status);
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $btn = $voltarUrl !== null
            ? '<a href="' . $e($voltarUrl) . '" style="display:inline-block;margin-top:16px;padding:12px 18px;border-radius:10px;background:#EA580C;color:#fff;font-weight:700;text-decoration:none;">Voltar para a folha</a>'
            : '';
        echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . $e($titulo) . ' — Arcadis RH</title></head>'
            . '<body style="margin:0;padding:24px;background:#F1F5F9;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',sans-serif;color:#0F172A;display:grid;place-items:center;min-height:100vh;">'
            . '<div style="max-width:460px;width:100%;background:#fff;border:1px solid #E2E8F0;border-radius:14px;padding:24px;box-shadow:0 10px 25px rgba(15,23,42,.08);">'
            . '<div style="font-size:12px;font-weight:700;color:#EA580C;text-transform:uppercase;letter-spacing:.06em;">Arcadis · Portal de Assinatura</div>'
            . '<h1 style="margin:8px 0 10px;font-size:20px;">' . $e($titulo) . '</h1>'
            . '<p style="margin:0;color:#475569;line-height:1.55;">' . $e($descricao) . '</p>'
            . $btn
            . '</div></body></html>';
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
        $assinado = (($doc['status'] ?? '') === 'assinado');
        $name = $assinado
            ? 'cartao_ponto_assinado_' . (int) $doc['id'] . '.pdf'
            : 'folha_' . (int) $doc['id'] . '.pdf';
        \App\Helpers\PdfInline::stream(
            $path,
            $assinado ? 'Cartão de Ponto assinado | Arcadis RH' : 'Cartão de Ponto | Arcadis RH',
            $name
        );
        exit;
    }

    private function headersPortal(): void
    {
        @ini_set('display_errors', '0');
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
                'SELECT d.*, c.nome, c.matricula,
                        YEAR(pp.data_inicio) AS ano, MONTH(pp.data_inicio) AS mes,
                        pp.data_inicio AS periodo_cartao_inicio, pp.data_fim AS periodo_cartao_fim
                 FROM documentos d
                 INNER JOIN ponto_espelhos e ON e.id = d.espelho_id
                 INNER JOIN rh_colaboradores c ON c.id = e.colaborador_id
                 INNER JOIN ponto_periodos pp ON pp.id = e.periodo_id
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
        $storageRoot = realpath(dirname(__DIR__, 3) . '/storage');
        $path = $rel !== '' ? realpath(dirname(__DIR__, 3) . '/' . ltrim($rel, '/')) : false;
        if ($storageRoot === false || $path === false || !str_starts_with($path, $storageRoot . DIRECTORY_SEPARATOR) || !is_file($path)) {
            http_response_code(404);
            echo 'Arquivo ausente';
            return;
        }
        $expectedHash = $versaoAssinada
            ? (string) ($doc['arquivo_assinado_sha256'] ?? '')
            : (string) ($doc['hash_sha256'] ?? '');
        if ($expectedHash !== '' && !hash_equals($expectedHash, (string) hash_file('sha256', $path))) {
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
