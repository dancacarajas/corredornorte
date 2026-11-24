<?php

declare(strict_types=1);

namespace App\Controllers\Rh;

use App\Helpers\Auth;
use App\Helpers\Csrf;
use App\Helpers\Db;
use App\Helpers\View;
use App\Services\AlertasService;
use App\Services\MedicaoService;
use App\Services\PeriodoPonto;
use PDO;
use RuntimeException;

final class AlertaController
{
    public function index(): void
    {
        if (!Auth::canAny('alertas.ver', 'dashboard.ver')) {
            Auth::requirePermission('alertas.ver');
        }
        $pdo = Db::tryConnection();
        $usuarioId = (int) (Auth::id() ?? 0);

        $periodosGerados = [];
        $periodoSel = isset($_GET['periodo_sel']) ? (string) $_GET['periodo_sel'] : null;
        $todosPeriodos = $periodoSel === '|'
            || (string) ($_GET['todos_periodos'] ?? '') === '1';
        $dataInicio = isset($_GET['data_inicio']) ? trim((string) $_GET['data_inicio']) : '';
        $dataFim = isset($_GET['data_fim']) ? trim((string) $_GET['data_fim']) : '';
        if ($todosPeriodos) {
            $dataInicio = '';
            $dataFim = '';
        }
        if ($pdo instanceof PDO) {
            $periodosGerados = (new MedicaoService($pdo))->listarPeriodosGerados(12);
            // Sem escolha explícita de período → mesmo default do badge: período gerado mais recente.
            if (!$todosPeriodos && ($dataInicio === '' || $dataFim === '') && $periodosGerados !== []) {
                $dataInicio = (string) $periodosGerados[0]['data_inicio'];
                $dataFim = (string) $periodosGerados[0]['data_fim'];
            }
        }

        $status = (string) ($_GET['status'] ?? 'abertos');
        if (!in_array($status, ['abertos', 'nao_lidos', 'meus', 'resolvidos', 'dispensados'], true)) {
            $status = 'abertos';
        }

        $filtros = [
            'status' => $status,
            'severidade' => (string) ($_GET['severidade'] ?? ''),
            'codigo' => (string) ($_GET['codigo'] ?? ''),
            'q' => trim((string) ($_GET['q'] ?? '')),
            'periodo_inicio' => $dataInicio !== '' ? $dataInicio : null,
            'periodo_fim' => $dataFim !== '' ? $dataFim : null,
            'somente_nao_lidos' => $status === 'nao_lidos',
            'ignorar_periodo' => $todosPeriodos,
        ];

        $itens = [];
        $naoLidos = 0;
        $contadores = [
            'abertos' => 0,
            'nao_lidos' => 0,
            'criticos' => 0,
            'altas' => 0,
            'cobrancas' => 0,
            'meus' => 0,
        ];
        $outrosPeriodosNaoLidos = 0;
        $usuariosAtribuicao = [];
        $tiposCatalogo = [];
        $erro = null;
        $alertasBadge = 0;
        if ($pdo instanceof PDO && $usuarioId > 0) {
            try {
                $svc = new AlertasService($pdo);
                $itens = $svc->listar($usuarioId, $filtros);
                $contadores = $svc->contarResumo($usuarioId, [
                    'periodo_inicio' => $todosPeriodos ? '' : $dataInicio,
                    'periodo_fim' => $todosPeriodos ? '' : $dataFim,
                ]);
                $naoLidos = $contadores['nao_lidos'];
                // Badge do menu = não lidos do período padrão (ou da visão atual se "todos").
                $alertasBadge = $svc->contarNaoLidos($usuarioId, false, [
                    'todos_periodos' => $todosPeriodos,
                    'periodo_inicio' => $dataInicio,
                    'periodo_fim' => $dataFim,
                ]);
                if (!$todosPeriodos && $dataInicio !== '' && $dataFim !== '') {
                    $todosResumo = $svc->contarResumo($usuarioId, [
                        'periodo_inicio' => '',
                        'periodo_fim' => '',
                    ]);
                    $outrosPeriodosNaoLidos = max(0, (int) $todosResumo['nao_lidos'] - (int) $contadores['nao_lidos']);
                }
                $usuariosAtribuicao = $svc->listarUsuariosAtribuicao();
                $tiposCatalogo = $svc->listarTipos();
            } catch (\Throwable $e) {
                $erro = $e->getMessage();
            }
        }

        $periodoLabel = '';
        if ($dataInicio !== '' && $dataFim !== '') {
            try {
                $periodoLabel = PeriodoPonto::rotulo([
                    'data_inicio' => $dataInicio,
                    'data_fim' => $dataFim,
                ]);
            } catch (RuntimeException) {
                $periodoLabel = $dataInicio . ' a ' . $dataFim;
            }
        }

        $isHistorico = in_array($status, ['resolvidos', 'dispensados'], true);

        View::render('rh/alertas/index', [
            'pageTitle' => 'Central de Alertas',
            'pageSubtitle' => $isHistorico
                ? 'Auditoria de alertas resolvidos e arquivados nos últimos 30 dias'
                : 'Pendências operacionais que exigem decisão para destravar o fechamento',
            'activeMenu' => 'alertas',
            'extraCss' => ['css/alertas.css?v=20261116b'],
            'extraJs' => ['js/alertas.js?v=20261116b'],
            'itens' => $itens,
            'naoLidos' => $naoLidos,
            'contadores' => $contadores,
            'alertasBadge' => $alertasBadge,
            'filtros' => $filtros,
            'periodosGerados' => $periodosGerados,
            'dataInicio' => $dataInicio,
            'dataFim' => $dataFim,
            'todosPeriodos' => $todosPeriodos,
            'outrosPeriodosNaoLidos' => $outrosPeriodosNaoLidos,
            'periodoLabel' => $periodoLabel,
            'usuariosAtribuicao' => $usuariosAtribuicao,
            'tiposCatalogo' => $tiposCatalogo,
            'usuarioId' => $usuarioId,
            'isHistorico' => $isHistorico,
            'podeConfigurar' => Auth::canAny('alertas.gerenciar', 'alertas_config.ver', 'alertas_config.editar', 'alertas_config.operar'),
            'podeOperar' => Auth::canAny('alertas.operar', 'alertas.ver'),
            'flashOk' => isset($_GET['ok']) ? trim((string) $_GET['ok']) : null,
            'erro' => $erro,
            'dbOk' => $pdo instanceof PDO,
        ]);
    }

    public function sincronizar(): void
    {
        Auth::requirePermission('alertas.operar');
        Csrf::requireValid();
        $pdo = Db::tryConnection();
        $dataInicio = trim((string) ($_POST['data_inicio'] ?? ''));
        $dataFim = trim((string) ($_POST['data_fim'] ?? ''));
        if ($pdo instanceof PDO) {
            try {
                (new AlertasService($pdo))->sincronizarOperacional(
                    $dataInicio !== '' ? $dataInicio : null,
                    $dataFim !== '' ? $dataFim : null
                );
            } catch (\Throwable) {
            }
        }
        $qs = ['ok' => 'Fila de alertas sincronizada com os dados mais recentes da operação.'];
        if ($dataInicio !== '' && $dataFim !== '') {
            $qs['data_inicio'] = $dataInicio;
            $qs['data_fim'] = $dataFim;
        }
        header('Location: /rh/alertas?' . http_build_query($qs));
        exit;
    }

    public function marcarLido(string $id = ''): void
    {
        Auth::requirePermission('alertas.operar');
        Csrf::requireValid();
        $alertaId = (int) $id;
        $usuarioId = (int) (Auth::id() ?? 0);
        $pdo = Db::tryConnection();
        if ($pdo instanceof PDO && $alertaId > 0 && $usuarioId > 0) {
            (new AlertasService($pdo))->marcarLido($usuarioId, $alertaId);
        }
        $this->jsonOrRedirect(true);
    }

    public function marcarTodosLidos(): void
    {
        Auth::requirePermission('alertas.operar');
        Csrf::requireValid();
        $usuarioId = (int) (Auth::id() ?? 0);
        $pdo = Db::tryConnection();
        $n = 0;
        if ($pdo instanceof PDO && $usuarioId > 0) {
            $todos = (string) ($_POST['todos_periodos'] ?? '') === '1'
                || (string) ($_POST['periodo_sel'] ?? '') === '|';
            $pi = trim((string) ($_POST['data_inicio'] ?? ''));
            $pf = trim((string) ($_POST['data_fim'] ?? ''));
            $n = (new AlertasService($pdo))->marcarTodosLidos($usuarioId, [
                'todos_periodos' => $todos,
                'periodo_inicio' => $pi,
                'periodo_fim' => $pf,
            ]);
        }
        $this->jsonOrRedirect(true, ['marcados' => $n]);
    }

    public function dispensar(string $id = ''): void
    {
        Auth::requirePermission('alertas.operar');
        Csrf::requireValid();
        $alertaId = (int) $id;
        $usuarioId = (int) (Auth::id() ?? 0);
        $motivo = trim((string) ($_POST['motivo'] ?? ''));
        $pdo = Db::tryConnection();
        if ($pdo instanceof PDO && $alertaId > 0 && $usuarioId > 0) {
            (new AlertasService($pdo))->dispensar($usuarioId, $alertaId, $motivo !== '' ? $motivo : null);
        }
        $this->jsonOrRedirect(true);
    }

    public function atribuir(string $id = ''): void
    {
        Auth::requirePermission('alertas.operar');
        Csrf::requireValid();
        $alertaId = (int) $id;
        $atorId = (int) (Auth::id() ?? 0);
        $para = (int) ($_POST['usuario_id'] ?? 0);
        $pdo = Db::tryConnection();
        if ($pdo instanceof PDO && $alertaId > 0 && $atorId > 0) {
            $svc = new AlertasService($pdo);
            if ($para > 0) {
                $svc->atribuir($alertaId, $para, $atorId);
            } else {
                $svc->desatribuir($alertaId, $atorId);
            }
        }
        $this->jsonOrRedirect(true, ['atribuido_usuario_id' => $para > 0 ? $para : null]);
    }

    /** @param array<string,mixed> $extra */
    private function jsonOrRedirect(bool $ok, array $extra = []): void
    {
        $wantsJson = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
            || (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');
        if ($wantsJson) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => $ok] + $extra, JSON_UNESCAPED_UNICODE);
            exit;
        }
        $back = (string) ($_POST['redirect'] ?? '/rh/alertas');
        if (!str_starts_with($back, '/')) {
            $back = '/rh/alertas';
        }
        header('Location: ' . $back);
        exit;
    }
}
