<?php

declare(strict_types=1);

namespace App\Controllers\Rh;

use App\Helpers\Auth;
use App\Helpers\Db;
use App\Helpers\View;
use App\Services\AssinaturaEletronicaService;
use App\Services\FolhaPontoService;
use App\Services\MedicaoService;
use App\Services\MotorPontoService;
use PDO;
use RuntimeException;
use Throwable;

final class MedicaoController
{
    public function index(): void
    {
        $pdo = Db::tryConnection();
        $dbOk = $pdo instanceof PDO;
        $periodos = [];
        $painel = null;
        $erro = null;

        $dataInicio = (string) ($_GET['data_inicio'] ?? '');
        $dataFim = (string) ($_GET['data_fim'] ?? '');
        $filtro = (string) ($_GET['filtro'] ?? '');
        $filtrosValidos = ['conferir', 'fechar', 'pdf', 'link', 'email', 'assinaturas', 'dossie'];
        if (!in_array($filtro, $filtrosValidos, true)) {
            $filtro = '';
        }

        if ($dbOk) {
            $svc = new MedicaoService($pdo);
            $periodos = $svc->listarPeriodosGerados();
            if (($dataInicio === '' || $dataFim === '') && $periodos !== []) {
                $dataInicio = $periodos[0]['data_inicio'];
                $dataFim = $periodos[0]['data_fim'];
            }
            if (
                preg_match('/^\d{4}-\d{2}-\d{2}$/D', $dataInicio)
                && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $dataFim)
            ) {
                try {
                    $painel = $svc->painel($dataInicio, $dataFim);
                } catch (Throwable $e) {
                    $erro = $e->getMessage();
                }
            }
        }

        View::render('rh/medicao/index', [
            'pageTitle' => 'Medição',
            'pageSubtitle' => 'Jornada do período até o dossiê assinado',
            'activeMenu' => 'medicao',
            'extraCss' => ['css/medicao.css?v=busca-nome-20261114'],
            'dbOk' => $dbOk,
            'periodosGerados' => $periodos,
            'dataInicio' => $dataInicio,
            'dataFim' => $dataFim,
            'filtro' => $filtro,
            'painel' => $painel,
            'errosLote' => $_SESSION['medicao_errors_list'] ?? [],
            'flash' => $_SESSION['medicao_flash'] ?? null,
            'error' => $erro ?? ($_SESSION['medicao_error'] ?? null),
        ]);
        unset($_SESSION['medicao_flash'], $_SESSION['medicao_error'], $_SESSION['medicao_errors_list']);
    }

    public function conferirLote(): void
    {
        $this->rodarLote(static function (PDO $pdo, array $row): string {
            $status = (string) ($row['espelho_status'] ?? '');
            if (in_array($status, ['conferido', 'fechado', 'enviado', 'assinado'], true)) {
                return 'já conferido';
            }
            if (!empty($row['he_pendente'])) {
                throw new RuntimeException('Há HE declarada pendente de aplicação.');
            }
            $motor = new MotorPontoService($pdo);
            if (in_array($status, ['em_aberto'], true)) {
                $motor->alterarStatus((int) $row['espelho_id'], 'em_tratamento', 'Início via Medição (lote)', Auth::id());
            }
            $motor->alterarStatus(
                (int) $row['espelho_id'],
                'conferido',
                'Conferência via Medição (lote)',
                Auth::id()
            );
            return 'conferido';
        }, 'Pontos conferidos em lote.');
    }

    public function fecharLote(): void
    {
        $this->rodarLote(static function (PDO $pdo, array $row): string {
            $status = (string) ($row['espelho_status'] ?? '');
            if (in_array($status, ['fechado', 'enviado', 'assinado'], true)) {
                return 'já fechado';
            }
            if (!empty($row['he_pendente'])) {
                throw new RuntimeException('Há HE declarada pendente de aplicação.');
            }
            if (!in_array($status, ['conferido'], true)) {
                throw new RuntimeException('Confera o período antes de fechar (status atual: ' . $status . ').');
            }
            (new MotorPontoService($pdo))->alterarStatus(
                (int) $row['espelho_id'],
                'fechado',
                'Fechamento via Medição (lote)',
                Auth::id()
            );
            return 'fechado';
        }, 'Pontos fechados em lote.');
    }

    public function gerarPdfLote(): void
    {
        $this->rodarLote(static function (PDO $pdo, array $row, string $ini, string $fim): string {
            if (!empty($row['etapas']['pdf'])) {
                return 'PDF já existe';
            }
            if (!empty($row['he_pendente'])) {
                throw new RuntimeException('HE pendente — trate antes de gerar PDF.');
            }
            $status = (string) ($row['espelho_status'] ?? '');
            if (!in_array($status, ['fechado', 'enviado', 'assinado'], true)) {
                throw new RuntimeException('Feche o período antes de gerar o PDF (status: ' . $status . ').');
            }
            (new FolhaPontoService($pdo))->gerarPdf((int) $row['espelho_id'], Auth::id(), $ini, $fim);
            return 'PDF gerado';
        }, 'PDFs gerados em lote.');
    }

    public function linkLote(): void
    {
        $this->rodarLote(static function (PDO $pdo, array $row): string {
            $docId = (int) ($row['documento_id'] ?? 0);
            if ($docId <= 0) {
                throw new RuntimeException('Gere o PDF antes do link.');
            }
            if (!empty($row['etapas']['link'])) {
                return 'link já existe';
            }
            (new AssinaturaEletronicaService($pdo))->gerarLinkPortal($docId, Auth::id(), (int) $row['espelho_id']);
            return 'link gerado';
        }, 'Links de assinatura gerados em lote.');
    }

    public function emailLote(): void
    {
        @set_time_limit(120);
        @ini_set('max_execution_time', '120');
        $this->rodarLote(static function (PDO $pdo, array $row): string {
            $docId = (int) ($row['documento_id'] ?? 0);
            if ($docId <= 0) {
                throw new RuntimeException('Gere o PDF antes de enviar.');
            }
            if (empty($row['email_corp'])) {
                throw new RuntimeException('Sem e-mail corporativo.');
            }
            if (!empty($row['etapas']['email'])) {
                return 'já enviado';
            }
            // Jornada: etapa 5 (link) antes da 6 (e-mail) — sem link não há ação para o colaborador.
            if (empty($row['etapas']['link'])) {
                throw new RuntimeException('Gere o link de assinatura antes de enviar o e-mail (etapa 5).');
            }
            $result = (new FolhaPontoService($pdo))->enviarDocumento($docId, null, (int) $row['espelho_id']);
            if (empty($result['ok'])) {
                throw new RuntimeException((string) ($result['erro'] ?? 'Falha no envio'));
            }
            return 'e-mail enviado';
        }, 'E-mails enviados em lote.', true);
    }

    public function dossie(): void
    {
        $pdo = Db::tryConnection();
        $ini = (string) ($_GET['data_inicio'] ?? '');
        $fim = (string) ($_GET['data_fim'] ?? '');
        if (!$pdo instanceof PDO || $ini === '' || $fim === '') {
            $_SESSION['medicao_error'] = 'Selecione um período.';
            header('Location: /rh/medicao');
            exit;
        }
        try {
            $zip = (new MedicaoService($pdo))->gerarDossieZip($ini, $fim);
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $zip['filename'] . '"');
            header('Content-Length: ' . (string) filesize($zip['path']));
            header('Cache-Control: private, no-store');
            readfile($zip['path']);
            @unlink($zip['path']);
            exit;
        } catch (Throwable $e) {
            $_SESSION['medicao_error'] = $e->getMessage();
            header('Location: /rh/medicao?' . http_build_query(['data_inicio' => $ini, 'data_fim' => $fim]));
            exit;
        }
    }

    /**
     * @param callable(PDO,array,string,string):string $fn
     */
    /**
     * @param bool $requireChunkedJson Se true (e-mail), bloqueia POST clássico sem AJAX para evitar 504.
     */
    private function rodarLote(callable $fn, string $flashOk, bool $requireChunkedJson = false): void
    {
        $pdo = Db::tryConnection();
        $ini = (string) ($_POST['data_inicio'] ?? '');
        $fim = (string) ($_POST['data_fim'] ?? '');
        $filtro = (string) ($_POST['filtro'] ?? '');
        $wantsJson = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
            || strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';

        $qsParams = ['data_inicio' => $ini, 'data_fim' => $fim];
        if (in_array($filtro, ['conferir', 'fechar', 'pdf', 'link', 'email', 'assinaturas'], true)) {
            $qsParams['filtro'] = $filtro;
        }
        $qs = http_build_query($qsParams);
        if (!$pdo instanceof PDO || $ini === '' || $fim === '') {
            if ($wantsJson) {
                http_response_code(400);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'error' => 'Período inválido.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $_SESSION['medicao_error'] = 'Período inválido.';
            header('Location: /rh/medicao');
            exit;
        }

        $selectedIds = array_values(array_filter(
            array_map('intval', (array) ($_POST['espelho_ids'] ?? [])),
            static fn(int $v): bool => $v > 0
        ));
        $selectedMap = $selectedIds !== [] ? array_flip($selectedIds) : null;

        // E-mail em massa sem chunk AJAX → 504 no proxy. Exige ids + JSON.
        if ($requireChunkedJson && !$wantsJson) {
            $_SESSION['medicao_error'] = 'Envio de e-mails em lote precisa do modo progressivo. Recarregue a página (Ctrl+F5) e tente de novo.';
            header('Location: /rh/medicao?' . $qs);
            exit;
        }
        if ($requireChunkedJson && $selectedMap === null) {
            if ($wantsJson) {
                http_response_code(400);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'error' => 'Selecione colaboradores (chunk) para enviar e-mail.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }

        try {
            $painel = (new MedicaoService($pdo))->painel($ini, $fim);
            $ok = 0;
            $skipped = 0;
            $erros = [];
            foreach ($painel['colaboradores'] as $row) {
                $espId = (int) ($row['espelho_id'] ?? 0);
                if ($selectedMap !== null && !isset($selectedMap[$espId])) {
                    continue;
                }
                if (!empty($row['concluido'])) {
                    $skipped++;
                    continue;
                }
                try {
                    $resultado = $fn($pdo, $row, $ini, $fim);
                    // Strings "já ..." não contam como avanço real do lote.
                    if (is_string($resultado) && str_starts_with(mb_strtolower($resultado), 'já ')) {
                        $skipped++;
                        continue;
                    }
                    $ok++;
                } catch (Throwable $e) {
                    $erros[] = $row['nome'] . ': ' . $e->getMessage();
                }
            }

            if ($wantsJson) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'ok' => true,
                    'processed' => $ok,
                    'skipped' => $skipped,
                    'errors' => $erros,
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            if ($erros !== []) {
                $_SESSION['medicao_errors_list'] = $erros;
                $_SESSION['medicao_error'] = $flashOk . " ({$ok} ok). " . count($erros) . ' pendência(s) encontrada(s) — veja a lista completa abaixo.';
            } else {
                $_SESSION['medicao_flash'] = $flashOk . ($ok > 0 ? " ({$ok})" : ' (nada pendente)');
            }
        } catch (Throwable $e) {
            if ($wantsJson) {
                http_response_code(422);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $_SESSION['medicao_error'] = $e->getMessage();
        }
        header('Location: /rh/medicao?' . $qs);
        exit;
    }
}
