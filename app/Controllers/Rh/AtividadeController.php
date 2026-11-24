<?php

declare(strict_types=1);

namespace App\Controllers\Rh;

use App\Helpers\Auth;
use App\Helpers\Db;
use App\Helpers\View;
use App\Services\AtividadeService;
use App\Services\PerfilService;
use PDO;
use Throwable;

final class AtividadeController
{
    public function index(): void
    {
        Auth::requirePermission('atividade.ver');
        $pdo = Db::tryConnection();
        $dbOk = $pdo instanceof PDO;
        $usuarios = [];
        $online = [];
        $resumo = ['online' => 0, 'sessoes_hoje' => 0, 'usuarios_ativos_7d' => 0];
        $busca = trim((string) ($_GET['q'] ?? ''));

        if ($dbOk) {
            try {
                (new PerfilService($pdo))->ensureCatalog();
                $svc = new AtividadeService($pdo);
                $svc->ensureSchema();
                $usuarios = $svc->listarUsuariosComPresenca($busca !== '' ? $busca : null);
                $online = $svc->listarOnline();
                $resumo = $svc->resumoGeral();
            } catch (Throwable $e) {
                $dbOk = false;
            }
        }

        View::render('rh/atividade/index', [
            'pageTitle' => 'Atividade de usuários',
            'pageSubtitle' => 'Online agora, histórico de login/logout e uso',
            'activeMenu' => 'atividade',
            'extraCss' => ['css/atividade.css?v=avatar-20261116'],
            'extraJs' => ['js/atividade-painel.js?v=avatar-20261116'],
            'dbOk' => $dbOk,
            'usuarios' => $usuarios,
            'online' => $online,
            'resumo' => $resumo,
            'busca' => $busca,
        ]);
    }

    public function show(string $id = '0'): void
    {
        Auth::requirePermission('atividade.ver');
        $pdo = Db::tryConnection();
        $usuarioId = (int) $id;
        if (!$pdo instanceof PDO || $usuarioId <= 0) {
            header('Location: /rh/atividade');
            exit;
        }

        $svc = new AtividadeService($pdo);
        $svc->ensureSchema();
        $st = $pdo->prepare('SELECT id, nome, email, avatar_path, status, last_login_at FROM usuarios WHERE id = ? LIMIT 1');
        $st->execute([$usuarioId]);
        $usuario = $st->fetch(PDO::FETCH_ASSOC);
        if ($usuario === false) {
            header('Location: /rh/atividade?erro=' . rawurlencode('Usuário não encontrado'));
            exit;
        }

        $dias = max(7, min(90, (int) ($_GET['dias'] ?? 30)));
        $dashboard = $svc->dashboardUsuario($usuarioId, $dias);
        $historico = $svc->historicoUsuario($usuarioId, 120);

        ob_start();
        ?>
        <a class="btn btn-secondary btn-sm" href="/rh/atividade">Voltar ao painel</a>
        <?php
        $topbarActions = ob_get_clean() ?: '';

        View::render('rh/atividade/show', [
            'pageTitle' => 'Uso · ' . (string) $usuario['nome'],
            'pageSubtitle' => (string) $usuario['email'],
            'activeMenu' => 'atividade',
            'topbarActions' => $topbarActions,
            'extraCss' => ['css/atividade.css?v=charts-fix-20261116'],
            'extraJs' => [
                'js/chart.umd.min.js',
                'js/atividade-usuario.js?v=charts-fix-20261116',
            ],
            'usuario' => $usuario,
            'dashboard' => $dashboard,
            'historico' => $historico,
            'dias' => $dias,
        ]);
    }

    /** Feed em tempo real do painel (polling). */
    public function live(): void
    {
        Auth::requirePermission('atividade.ver');
        $pdo = Db::tryConnection();
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        if (!$pdo instanceof PDO) {
            echo json_encode(['ok' => false, 'type' => 'error', 'message' => 'Banco indisponível']);
            exit;
        }
        try {
            $svc = new AtividadeService($pdo);
            $busca = trim((string) ($_GET['q'] ?? ''));
            echo json_encode([
                'ok' => true,
                'type' => 'live',
                'ts' => \App\Helpers\AppClock::now()->format('c'),
                'resumo' => $svc->resumoGeral(),
                'online' => $svc->listarOnline(),
                'usuarios' => $svc->listarUsuariosComPresenca($busca !== '' ? $busca : null),
            ], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'type' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }

    /** Heartbeat de presença — qualquer usuário autenticado. */
    public function heartbeat(): void
    {
        Auth::requireLogin();
        $pdo = Db::tryConnection();
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        $uid = Auth::id();
        if (!$pdo instanceof PDO || $uid === null) {
            echo json_encode(['ok' => false]);
            exit;
        }
        try {
            Auth::start();
            $sid = session_id();
            if ($sid !== '') {
                (new AtividadeService($pdo))->heartbeat($uid, $sid);
            }
            echo json_encode(['ok' => true, 'ts' => time()]);
        } catch (Throwable) {
            echo json_encode(['ok' => false]);
        }
        exit;
    }
}
