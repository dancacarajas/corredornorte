<?php

declare(strict_types=1);

namespace App\Controllers\He;

use App\Helpers\HePortalAuth;
use App\Helpers\View;
use App\Services\HeAutorizacaoService;
use App\Services\RelatorioAssinaturaService;
use App\Services\RelatorioAtividadesExcel;
use App\Services\RelatorioAtividadesPdf;
use App\Services\RelatorioAtividadesService;
use RuntimeException;
use Throwable;

final class PortalController
{
    public function showLogin(): void
    {
        HePortalAuth::start();
        if (HePortalAuth::check()) {
            header('Location: /he');
            exit;
        }
        View::render('he/login', [
            'pageTitle' => 'Portal do Colaborador',
            'error' => null,
            'cpf' => '',
        ], null);
    }

    public function login(): void
    {
        HePortalAuth::start();
        $agora = time();
        $janela = array_values(array_filter(
            (array) ($_SESSION['_he_login_fails'] ?? []),
            static fn($t): bool => is_int($t) && ($agora - $t) < 300
        ));
        if (count($janela) >= 10) {
            $_SESSION['_he_login_fails'] = $janela;
            View::render('he/login', [
                'pageTitle' => 'Portal do Colaborador',
                'error' => 'Muitas tentativas em sequência. Aguarde alguns minutos e tente novamente.',
                'cpf' => '',
            ], null);
            return;
        }

        $cpf = preg_replace('/\D+/', '', (string) ($_POST['cpf'] ?? '')) ?: '';
        $svc = new HeAutorizacaoService();
        $colab = $svc->buscarColaboradorPorCpf($cpf);

        if ($colab === null || (string) $colab['status'] !== 'ativo') {
            $janela[] = $agora;
            $_SESSION['_he_login_fails'] = $janela;
            View::render('he/login', [
                'pageTitle' => 'Portal do Colaborador',
                'error' => 'CPF não encontrado ou colaborador inativo.',
                'cpf' => $cpf,
            ], null);
            return;
        }

        unset($_SESSION['_he_login_fails']);
        HePortalAuth::attempt($colab);
        header('Location: /he');
        exit;
    }

    public function logout(): void
    {
        HePortalAuth::logout();
        header('Location: /he/login');
        exit;
    }

    public function overview(): void
    {
        HePortalAuth::requireLogin();
        $user = HePortalAuth::user();
        $ra = new RelatorioAtividadesService();
        $id = (int) $user['id'];
        try {
            $ra->backfillColaborador($id);
        } catch (Throwable) {
            // migração pode ainda não existir
        }

        $relatoriosAbertos = 0;
        try {
            $relatoriosAbertos = $ra->contarAbertos($id);
        } catch (Throwable) {
            $relatoriosAbertos = 0;
        }

        View::render('he/overview', [
            'pageTitle' => 'Visão Geral',
            'pageSubtitle' => 'Portal do colaborador',
            'activeMenu' => 'overview',
            'user' => $user,
            'relatoriosAbertos' => $relatoriosAbertos,
            'flash' => $_SESSION['he_flash'] ?? null,
            'error' => $_SESSION['he_error'] ?? null,
        ], 'layouts/portal');
        unset($_SESSION['he_flash'], $_SESSION['he_error']);
    }

    /** Rotas antigas de solicitação/aprovação HE → portal. */
    public function fluxoHeDesativado(string $id = '0'): void
    {
        HePortalAuth::requireLogin();
        $_SESSION['he_flash'] = 'A solicitação de HE pelo portal foi desativada. Use o Relatório de Atividades.';
        header('Location: /he/relatorios');
        exit;
    }

    public function relatorios(): void
    {
        HePortalAuth::requireLogin();
        $user = HePortalAuth::user();
        $ra = new RelatorioAtividadesService();
        $id = (int) $user['id'];
        $lista = [];
        try {
            $lista = $ra->listarDoColaborador($id);
        } catch (Throwable $e) {
            $_SESSION['he_error'] = 'Relatórios indisponíveis. Contate o RH se o problema persistir.';
        }

        View::render('he/relatorios_index', [
            'pageTitle' => 'Relatório de Atividades',
            'pageSubtitle' => 'Períodos do seu cartão de ponto',
            'activeMenu' => 'relatorios',
            'user' => $user,
            'relatorios' => $lista,
            'flash' => $_SESSION['he_flash'] ?? null,
            'error' => $_SESSION['he_error'] ?? null,
        ], 'layouts/portal');
        unset($_SESSION['he_flash'], $_SESSION['he_error']);
    }

    public function relatorioShow(string $id = '0'): void
    {
        HePortalAuth::requireLogin();
        $user = HePortalAuth::user();
        $he = new HeAutorizacaoService();
        $ra = new RelatorioAtividadesService();
        $relatorioId = (int) $id;
        $dados = null;
        try {
            $dados = $ra->carregar($relatorioId, (int) $user['id']);
        } catch (Throwable) {
            $dados = null;
        }
        if ($dados === null) {
            $_SESSION['he_error'] = 'Relatório não encontrado.';
            header('Location: /he/relatorios');
            exit;
        }

        $ini = (string) ($dados['relatorio']['data_inicio'] ?? '');
        $fim = (string) ($dados['relatorio']['data_fim'] ?? '');
        $rotuloPeriodo = ($ini !== '' && $fim !== '')
            ? date('d/m/Y', strtotime($ini)) . ' a ' . date('d/m/Y', strtotime($fim))
            : 'Relatório de atividades';

        $documentoAssinatura = null;
        try {
            $documentoAssinatura = (new RelatorioAssinaturaService(\App\Config\Database::connection()))
                ->buscarPorRelatorio($relatorioId);
        } catch (Throwable) {
            // A visualização do relatório continua disponível antes da migração da assinatura.
        }

        $statusRa = (string) ($dados['relatorio']['status'] ?? 'rascunho');
        $docStatus = is_array($documentoAssinatura) ? (string) ($documentoAssinatura['status'] ?? '') : '';
        $pageTitle = match (true) {
            $docStatus === 'assinado' => 'Relatório concluído',
            in_array($statusRa, ['enviado', 'aprovado'], true) => 'Assinar relatório',
            default => 'Preencher relatório',
        };

        View::render('he/relatorio_show', [
            'pageTitle' => $pageTitle,
            'pageSubtitle' => $rotuloPeriodo,
            'activeMenu' => 'relatorios',
            'user' => $user,
            'dados' => $dados,
            'contratos' => $he->listarContratos(),
            'ordens' => $he->listarOrdensServico(
                isset($dados['relatorio']['contrato_id']) ? (int) $dados['relatorio']['contrato_id'] : null
            ),
            'flash' => $_SESSION['he_flash'] ?? null,
            'error' => $_SESSION['he_error'] ?? null,
            'somenteLeitura' => in_array($statusRa, ['enviado', 'aprovado'], true),
            'documentoAssinatura' => $documentoAssinatura,
            'linkAssinatura' => $_SESSION['ra_portal_link'] ?? null,
            'textoAceite' => RelatorioAssinaturaService::TEXTO_ACEITE,
            'nomeAssinaturaInformado' => (string) ($_SESSION['ra_nome_informado'] ?? ''),
        ], 'layouts/portal');
        unset($_SESSION['he_flash'], $_SESSION['he_error'], $_SESSION['ra_portal_link'], $_SESSION['ra_nome_informado']);
    }

    public function relatorioPdf(string $id = '0'): void
    {
        @ini_set('display_errors', '0');
        $dados = $this->relatorioParaExportar((int) $id);
        $arquivo = null;
        if (in_array((string) $dados['relatorio']['status'], ['enviado', 'aprovado'], true)) {
            try {
                $assinatura = new RelatorioAssinaturaService(\App\Config\Database::connection());
                $documento = $assinatura->buscarPorRelatorio((int) $id);
                if ($documento !== null) {
                    $assinado = (string) $documento['status'] === 'assinado';
                    $integridade = $assinatura->verificarIntegridade($documento);
                    if ($integridade[$assinado ? 'assinado' : 'original']) {
                        $arquivo = $assinatura->caminhoArquivo($documento, $assinado);
                    }
                }
            } catch (Throwable) {
                $arquivo = null;
            }
        }
        try {
            $arquivo ??= (new RelatorioAtividadesPdf())->gerarArquivo($dados);
        } catch (Throwable) {
            http_response_code(500);
            echo 'Não foi possível gerar o PDF do relatório.';
            return;
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="relatorio_atividades_' . (int) $id . '.pdf"');
        header('Cache-Control: private, no-store');
        readfile($arquivo);
    }

    public function relatorioExcel(string $id = '0'): void
    {
        @ini_set('display_errors', '0');
        $dados = $this->relatorioParaExportar((int) $id);
        try {
            $arquivo = (new RelatorioAtividadesExcel())->gerarArquivo($dados);
        } catch (Throwable) {
            http_response_code(500);
            echo 'Não foi possível gerar o Excel do relatório.';
            return;
        }
        try {
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="relatorio_atividades_' . (int) $id . '.xlsx"');
            header('Content-Length: ' . filesize($arquivo));
            header('Cache-Control: private, no-store');
            readfile($arquivo);
        } finally {
            @unlink($arquivo);
        }
    }

    /** @return array{relatorio:array<string,mixed>,dias:list<array<string,mixed>>,contadores:array<string,int>} */
    private function relatorioParaExportar(int $id): array
    {
        HePortalAuth::requireLogin();
        $user = HePortalAuth::user();
        $dados = $id > 0
            ? (new RelatorioAtividadesService())->carregar($id, (int) $user['id'])
            : null;
        if ($dados === null) {
            http_response_code(404);
            exit('Relatório não encontrado.');
        }
        return $dados;
    }

    public function relatorioSalvar(string $id = '0'): void
    {
        HePortalAuth::requireLogin();
        $user = HePortalAuth::user();
        $ra = new RelatorioAtividadesService();
        $relatorioId = (int) $id;
        $enviar = isset($_POST['enviar']) && (string) $_POST['enviar'] === '1';
        $dias = $_POST['dias'] ?? [];
        if (!is_array($dias)) {
            $dias = [];
        }

        try {
            $ra->salvar(
                $relatorioId,
                (int) $user['id'],
                $dias,
                isset($_POST['fiscal_vale']) ? (string) $_POST['fiscal_vale'] : null,
                (int) ($_POST['ordem_servico_id'] ?? 0) ?: null,
                (int) ($_POST['contrato_id'] ?? 0) ?: null,
                $enviar
            );
            $_SESSION['he_flash'] = $enviar
                ? 'Relatório enviado com sucesso.'
                : 'Rascunho salvo.';
        } catch (RuntimeException $e) {
            $_SESSION['he_error'] = $e->getMessage();
        } catch (Throwable) {
            $_SESSION['he_error'] = 'Não foi possível salvar o relatório.';
        }
        header('Location: /he/relatorios/' . $relatorioId);
        exit;
    }

    public function relatorioAssinar(string $id = '0'): void
    {
        HePortalAuth::requireLogin();
        $user = HePortalAuth::user();
        $relatorioId = (int) $id;
        $nome = is_string($_POST['nome'] ?? null) ? $_POST['nome'] : '';
        $aceite = ($_POST['aceite'] ?? '') === '1';

        try {
            $dados = (new RelatorioAtividadesService())->carregar($relatorioId, (int) $user['id']);
            if ($dados === null) {
                throw new RuntimeException('Relatório não encontrado.');
            }
            $status = (string) ($dados['relatorio']['status'] ?? '');
            if (!in_array($status, ['enviado', 'aprovado'], true)) {
                throw new RuntimeException('Envie o relatório antes de assinar.');
            }

            $assinatura = new RelatorioAssinaturaService(\App\Config\Database::connection());
            $doc = $assinatura->buscarPorRelatorio($relatorioId);
            if ($doc === null || (string) ($doc['status'] ?? '') !== 'enviado') {
                if (is_array($doc) && (string) ($doc['status'] ?? '') === 'assinado') {
                    throw new RuntimeException('Este relatório já foi assinado eletronicamente.');
                }
                $pdf = (new RelatorioAtividadesPdf())->gerarArquivo($dados);
                $assinatura->gerarLinkPortal($relatorioId, $pdf, (int) $user['id']);
                $doc = $assinatura->buscarPorRelatorio($relatorioId);
            }
            if ($doc === null || empty($doc['portal_token']) || empty($doc['hash_sha256'])) {
                throw new RuntimeException('Não foi possível preparar o documento para assinatura.');
            }

            $assinatura->assinarNoPortal(
                (string) $doc['portal_token'],
                $nome,
                $aceite,
                (string) $doc['hash_sha256']
            );
            $_SESSION['he_flash'] = 'Relatório assinado eletronicamente com sucesso!';
        } catch (RuntimeException $e) {
            $_SESSION['he_error'] = $e->getMessage();
            $_SESSION['ra_nome_informado'] = $nome;
        } catch (Throwable) {
            $_SESSION['he_error'] = 'Não foi possível concluir a assinatura.';
            $_SESSION['ra_nome_informado'] = $nome;
        }

        header('Location: /he/relatorios/' . $relatorioId, true, 303);
        exit;
    }

    public function ordensJson(): void
    {
        HePortalAuth::requireLogin();
        $contratoId = (int) ($_GET['contrato_id'] ?? 0);
        $svc = new HeAutorizacaoService();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($svc->listarOrdensServico($contratoId > 0 ? $contratoId : null), JSON_UNESCAPED_UNICODE);
    }
}
