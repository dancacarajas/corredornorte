<?php

declare(strict_types=1);

namespace App\Controllers\He;

use App\Config\Database;
use App\Helpers\HePortalAuth;
use App\Helpers\View;
use App\Services\AssinaturaEletronicaService;
use App\Services\HeAutorizacaoService;
use App\Services\RelatorioAssinaturaService;
use App\Services\RelatorioAtividadesExcel;
use App\Services\RelatorioAtividadesPdf;
use App\Services\RelatorioAtividadesService;
use PDOException;
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

        $assinaturaFolha = new AssinaturaEletronicaService(Database::connection());
        $relatorios = [];
        $folhas = [];
        try {
            $relatorios = $ra->listarDoColaborador($id);
        } catch (Throwable) {
            $relatorios = [];
        }
        try {
            $folhas = $assinaturaFolha->listarDoColaborador($id);
        } catch (Throwable) {
            $folhas = [];
        }

        $raAssinaturas = [];
        try {
            $raAssinaturas = $ra->assinaturasDoColaborador($id);
        } catch (Throwable) {
            $raAssinaturas = [];
        }

        $tarefas = [];
        $concluidas = [];
        foreach ($folhas as $folha) {
            $ini = (string) ($folha['data_inicio'] ?? '');
            $fim = (string) ($folha['data_fim'] ?? '');
            $periodo = $this->rotuloPeriodo($ini, $fim);
            $statusFolha = (string) ($folha['status'] ?? '');
            if ($statusFolha === 'enviado') {
                $tarefas[] = [
                    'tipo' => 'folha',
                    'prioridade' => 1,
                    'titulo' => 'Assinar folha de ponto',
                    'descricao' => 'Liberada pela medição — confira o PDF e assine eletronicamente.',
                    'periodo' => $periodo,
                    'periodo_inicio' => $ini,
                    'cta' => 'Assinar agora',
                    'href' => '/he/folhas/' . (int) $folha['id'],
                    'tom' => 'urgente',
                ];
                continue;
            }
            if ($statusFolha === 'assinado') {
                $quando = (string) ($folha['assinatura_em'] ?? '');
                $concluidas[] = [
                    'tipo' => 'folha',
                    'titulo' => 'Folha de ponto assinada',
                    'periodo' => $periodo,
                    'quando' => $quando,
                    'quando_ts' => $quando !== '' ? (strtotime($quando) ?: 0) : 0,
                    'cta' => 'Ver PDF',
                    'href' => '/he/folhas/' . (int) $folha['id'],
                ];
            }
        }
        foreach ($relatorios as $rel) {
            $status = (string) ($rel['status'] ?? 'rascunho');
            $relId = (int) ($rel['id'] ?? 0);
            $ini = (string) ($rel['data_inicio'] ?? '');
            $fim = (string) ($rel['data_fim'] ?? '');
            $periodo = $this->rotuloPeriodo($ini, $fim);
            if ($status === 'rascunho') {
                $tarefas[] = [
                    'tipo' => 'ra_preencher',
                    'prioridade' => 2,
                    'titulo' => 'Preencher relatório de atividades',
                    'descricao' => 'Descreva os dias do período e, se houver, declare a hora extra.',
                    'periodo' => $periodo,
                    'periodo_inicio' => $ini,
                    'cta' => 'Continuar preenchimento',
                    'href' => '/he/relatorios/' . $relId,
                    'tom' => 'acao',
                ];
                continue;
            }
            if (isset($raAssinaturas[$relId])) {
                $quando = (string) ($raAssinaturas[$relId]['assinatura_em'] ?? '');
                $concluidas[] = [
                    'tipo' => 'ra',
                    'titulo' => 'Relatório de atividades assinado',
                    'periodo' => $periodo,
                    'quando' => $quando,
                    'quando_ts' => $quando !== '' ? (strtotime($quando) ?: 0) : 0,
                    'cta' => 'Abrir',
                    'href' => '/he/relatorios/' . $relId,
                ];
                continue;
            }
            if (in_array($status, ['enviado', 'aprovado'], true)) {
                $tarefas[] = [
                    'tipo' => 'ra_assinar',
                    'prioridade' => 1,
                    'titulo' => 'Assinar relatório de atividades',
                    'descricao' => 'Relatório enviado — finalize com a assinatura eletrônica.',
                    'periodo' => $periodo,
                    'periodo_inicio' => $ini,
                    'cta' => 'Assinar relatório',
                    'href' => '/he/relatorios/' . $relId,
                    'tom' => 'urgente',
                ];
            }
        }

        usort($tarefas, static function (array $a, array $b): int {
            $p = ($a['prioridade'] ?? 99) <=> ($b['prioridade'] ?? 99);
            if ($p !== 0) {
                return $p;
            }
            return strcmp((string) ($b['periodo_inicio'] ?? ''), (string) ($a['periodo_inicio'] ?? ''));
        });
        usort($concluidas, static function (array $a, array $b): int {
            return ((int) ($b['quando_ts'] ?? 0)) <=> ((int) ($a['quando_ts'] ?? 0));
        });

        $primeiroNome = trim(explode(' ', (string) ($user['nome'] ?? ''), 2)[0] ?: 'olá');
        $qtdPend = count($tarefas);
        $qtdOk = count($concluidas);

        View::render('he/overview', [
            'pageTitle' => 'Olá, ' . $primeiroNome,
            'pageSubtitle' => $qtdPend > 0
                ? ($qtdPend === 1 ? '1 pendência na fila' : $qtdPend . ' pendências na fila')
                : ($qtdOk > 0 ? 'Tudo em dia — veja o histórico abaixo' : 'Portal do colaborador'),
            'activeMenu' => 'overview',
            'user' => $user,
            'tarefas' => $tarefas,
            'concluidas' => $concluidas,
            'flash' => $_SESSION['he_flash'] ?? null,
            'error' => $_SESSION['he_error'] ?? null,
        ], 'layouts/portal');
        unset($_SESSION['he_flash'], $_SESSION['he_error']);
    }

    private function rotuloPeriodo(string $ini, string $fim): string
    {
        if ($ini !== '' && $fim !== '') {
            return date('d/m/Y', strtotime($ini) ?: time()) . ' a ' . date('d/m/Y', strtotime($fim) ?: time());
        }
        return 'Período não informado';
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
                // Auditoria FK aponta para usuarios — no portal HE não há usuario_id de sistema.
                $assinatura->gerarLinkPortal($relatorioId, $pdf, null);
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
        } catch (PDOException) {
            $_SESSION['he_error'] = 'Não foi possível concluir a assinatura. Tente novamente.';
            $_SESSION['ra_nome_informado'] = $nome;
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

    public function folhas(): void
    {
        HePortalAuth::requireLogin();
        $user = HePortalAuth::user();
        $lista = [];
        try {
            $lista = (new AssinaturaEletronicaService(Database::connection()))
                ->listarDoColaborador((int) $user['id']);
        } catch (Throwable $e) {
            error_log('HE folhas listar: ' . $e->getMessage());
            $_SESSION['he_error'] = 'Folhas indisponíveis. Contate o RH se o problema persistir.';
        }

        View::render('he/folhas_index', [
            'pageTitle' => 'Folha de ponto',
            'pageSubtitle' => 'Assinatura liberada pela medição',
            'activeMenu' => 'folhas',
            'user' => $user,
            'folhas' => $lista,
            'flash' => $_SESSION['he_flash'] ?? null,
            'error' => $_SESSION['he_error'] ?? null,
        ], 'layouts/portal');
        unset($_SESSION['he_flash'], $_SESSION['he_error']);
    }

    public function folhaShow(string $id = '0'): void
    {
        HePortalAuth::requireLogin();
        $user = HePortalAuth::user();
        $documentoId = (int) $id;
        $doc = null;
        try {
            $doc = (new AssinaturaEletronicaService(Database::connection()))
                ->buscarDoColaborador($documentoId, (int) $user['id']);
        } catch (Throwable) {
            $doc = null;
        }
        if ($doc === null) {
            $_SESSION['he_error'] = 'Folha não encontrada ou ainda sem link de assinatura.';
            header('Location: /he/folhas');
            exit;
        }

        $ini = (string) ($doc['periodo_cartao_inicio'] ?? '');
        $fim = (string) ($doc['periodo_cartao_fim'] ?? '');
        $rotulo = ($ini !== '' && $fim !== '')
            ? date('d/m/Y', strtotime($ini)) . ' a ' . date('d/m/Y', strtotime($fim))
            : 'Folha de ponto';

        View::render('he/folha_show', [
            'pageTitle' => ((string) ($doc['status'] ?? '') === 'assinado') ? 'Folha assinada' : 'Assinar folha de ponto',
            'pageSubtitle' => $rotulo,
            'activeMenu' => 'folhas',
            'user' => $user,
            'documento' => $doc,
            'textoAceite' => AssinaturaEletronicaService::TEXTO_ACEITE,
            'nomeAssinaturaInformado' => (string) ($_SESSION['folha_nome_informado'] ?? ''),
            'flash' => $_SESSION['he_flash'] ?? null,
            'error' => $_SESSION['he_error'] ?? null,
        ], 'layouts/portal');
        unset($_SESSION['he_flash'], $_SESSION['he_error'], $_SESSION['folha_nome_informado']);
    }

    public function folhaPdf(string $id = '0'): void
    {
        HePortalAuth::requireLogin();
        $user = HePortalAuth::user();
        $svc = new AssinaturaEletronicaService(Database::connection());
        $doc = $svc->buscarDoColaborador((int) $id, (int) $user['id']);
        if ($doc === null) {
            http_response_code(404);
            echo 'Folha não encontrada.';
            return;
        }
        try {
            $assinado = (string) ($doc['status'] ?? '') === 'assinado';
            $arquivo = $svc->caminhoArquivoPortal($doc, $assinado);
        } catch (Throwable) {
            http_response_code(500);
            echo 'Não foi possível abrir o PDF da folha.';
            return;
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="folha_ponto_' . (int) $id . '.pdf"');
        header('Cache-Control: private, no-store');
        readfile($arquivo);
    }

    public function folhaAssinar(string $id = '0'): void
    {
        HePortalAuth::requireLogin();
        $user = HePortalAuth::user();
        $documentoId = (int) $id;
        $nome = is_string($_POST['nome'] ?? null) ? $_POST['nome'] : '';
        $aceite = ($_POST['aceite'] ?? '') === '1';

        try {
            $svc = new AssinaturaEletronicaService(Database::connection());
            $doc = $svc->buscarDoColaborador($documentoId, (int) $user['id']);
            if ($doc === null) {
                throw new RuntimeException('Folha não encontrada ou ainda sem link de assinatura.');
            }
            if ((string) ($doc['status'] ?? '') === 'assinado') {
                throw new RuntimeException('Esta folha já foi assinada eletronicamente.');
            }
            if ((string) ($doc['status'] ?? '') !== 'enviado' || empty($doc['portal_token']) || empty($doc['hash_sha256'])) {
                throw new RuntimeException('Aguarde o RH liberar o link de assinatura desta folha.');
            }

            $svc->assinarNoPortal(
                (string) $doc['portal_token'],
                $nome,
                $aceite,
                (string) $doc['hash_sha256']
            );
            $_SESSION['he_flash'] = 'Folha de ponto assinada eletronicamente com sucesso!';
        } catch (PDOException) {
            $_SESSION['he_error'] = 'Não foi possível concluir a assinatura. Tente novamente.';
            $_SESSION['folha_nome_informado'] = $nome;
        } catch (RuntimeException $e) {
            $_SESSION['he_error'] = $e->getMessage();
            $_SESSION['folha_nome_informado'] = $nome;
        } catch (Throwable) {
            $_SESSION['he_error'] = 'Não foi possível concluir a assinatura.';
            $_SESSION['folha_nome_informado'] = $nome;
        }

        header('Location: /he/folhas/' . $documentoId, true, 303);
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
