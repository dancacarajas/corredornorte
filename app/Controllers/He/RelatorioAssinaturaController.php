<?php

declare(strict_types=1);

namespace App\Controllers\He;

use App\Helpers\Db;
use App\Helpers\HePortalAuth;
use App\Helpers\View;
use App\Services\RelatorioAssinaturaService;
use App\Services\RelatorioAtividadesPdf;
use App\Services\RelatorioAtividadesService;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/** Portal de assinatura do relatório, espelhando o portal público da folha. */
final class RelatorioAssinaturaController
{
    public function gerarLink(string $id = '0'): void
    {
        @ini_set('display_errors', '0');
        HePortalAuth::requireLogin();
        $user = HePortalAuth::user();
        $relatorioId = (int) $id;
        try {
            $pdo = Db::tryConnection();
            if (!$pdo instanceof PDO || $relatorioId <= 0) {
                throw new RuntimeException('Relatório inválido.');
            }
            $dados = (new RelatorioAtividadesService($pdo))->carregar($relatorioId, (int) $user['id']);
            if ($dados === null) {
                throw new RuntimeException('Relatório não encontrado.');
            }
            if (!in_array((string) $dados['relatorio']['status'], ['enviado', 'aprovado'], true)) {
                throw new RuntimeException('Envie o relatório antes de gerar o link de assinatura.');
            }
            $arquivo = (new RelatorioAtividadesPdf())->gerarArquivo($dados);
            $resultado = (new RelatorioAssinaturaService($pdo))->gerarLinkPortal($relatorioId, $arquivo);
            $_SESSION['ra_portal_link'] = $resultado['portal_url'];
            $_SESSION['he_flash'] = 'Link de assinatura gerado.';
        } catch (Throwable $erro) {
            $_SESSION['he_error'] = $erro instanceof RuntimeException && !$erro instanceof PDOException
                ? $erro->getMessage() : 'Não foi possível gerar o link de assinatura.';
        }
        header('Location: /he/relatorios/' . $relatorioId, true, 303);
        exit;
    }

    public function portalAssinatura(string $token = ''): void
    {
        $this->headersPortal();
        $pdo = Db::tryConnection();
        $doc = $pdo instanceof PDO ? (new RelatorioAssinaturaService($pdo))->buscarPorToken($token) : null;
        $mensagem = $_SESSION['ra_assinatura_mensagem'][$token] ?? [];
        unset($_SESSION['ra_assinatura_mensagem'][$token]);
        $_SESSION['ra_assinatura_csrf'] ??= bin2hex(random_bytes(32));
        View::render('he/relatorio_assinatura', [
            'documento' => $doc,
            'token' => $token,
            'csrf' => $_SESSION['ra_assinatura_csrf'],
            'assinaturaConfigurada' => RelatorioAssinaturaService::assinaturaConfigurada(),
            'textoAceite' => RelatorioAssinaturaService::TEXTO_ACEITE,
            'flash' => $mensagem['ok'] ?? null,
            'error' => $mensagem['erro'] ?? null,
        ], null);
    }

    public function portalAssinar(string $token = ''): void
    {
        @ini_set('display_errors', '0');
        $this->headersPortal();
        $csrf = $_POST['csrf'] ?? '';
        if (!is_string($csrf) || empty($_SESSION['ra_assinatura_csrf'])
            || !hash_equals($_SESSION['ra_assinatura_csrf'], $csrf)) {
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
            $hash = is_string($_POST['hash_sha256'] ?? null) ? $_POST['hash_sha256'] : '';
            (new RelatorioAssinaturaService($pdo))->assinarNoPortal(
                $token, $nome, ($_POST['aceite'] ?? '') === '1', $hash
            );
            $mensagem['ok'] = 'assinado';
        } catch (Throwable $erro) {
            $mensagem['erro'] = $erro instanceof RuntimeException && !$erro instanceof PDOException
                ? $erro->getMessage() : 'Não foi possível concluir a operação. Tente novamente ou fale com o RH.';
        }
        $_SESSION['ra_assinatura_mensagem'] = [$token => $mensagem];
        header('Location: /assinar-relatorio/' . rawurlencode($token), true, 303);
        exit;
    }

    public function portalPdf(string $token = ''): void
    {
        @ini_set('display_errors', '0');
        $this->headersPortal();
        $pdo = Db::tryConnection();
        $service = $pdo instanceof PDO ? new RelatorioAssinaturaService($pdo) : null;
        $doc = $service?->buscarPorToken($token);
        if ($doc === null) {
            http_response_code(404);
            echo 'Documento não encontrado';
            return;
        }
        $assinado = $doc['status'] === 'assinado';
        $hash = (string) ($assinado ? ($doc['arquivo_assinado_sha256'] ?? '') : $doc['hash_sha256']);
        if (array_key_exists('versao', $_GET)
            && (!is_string($_GET['versao']) || !hash_equals($hash, $_GET['versao']))) {
            http_response_code(409);
            echo 'A via do relatório mudou. Atualize a página para conferir o PDF atual.';
            return;
        }
        try {
            $arquivo = $service->caminhoArquivo($doc, $assinado);
            $integridade = $service->verificarIntegridade($doc);
        } catch (RuntimeException) {
            http_response_code(404);
            echo 'PDF indisponível';
            return;
        }
        if (!is_file($arquivo) || !$integridade[$assinado ? 'assinado' : 'original']) {
            http_response_code(409);
            echo 'Integridade do PDF comprometida';
            return;
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="relatorio_' . (int) $doc['relatorio_id']
            . ($assinado ? '_assinado' : '') . '.pdf"');
        readfile($arquivo);
        exit;
    }

    public function verificar(string $codigo = ''): void
    {
        $this->headersPortal();
        $pdo = Db::tryConnection();
        $service = $pdo instanceof PDO ? new RelatorioAssinaturaService($pdo) : null;
        $doc = $service?->buscarPorCodigo($codigo);
        try {
            $integridade = $doc !== null ? $service->verificarIntegridade($doc) : null;
        } catch (RuntimeException) {
            $integridade = ['original' => false, 'assinado' => false];
        }
        View::render('he/relatorio_verificar', [
            'documento' => $doc,
            'codigo' => $codigo,
            'integridade' => $integridade,
        ], null);
    }

    private function headersPortal(): void
    {
        header('Cache-Control: no-store');
        header('Referrer-Policy: no-referrer');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
    }
}
