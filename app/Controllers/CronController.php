<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\CronSecret;
use App\Helpers\Db;
use App\Services\CobrancaAssinaturaService;

/** Endpoints HTTP para cron da Hostinger (wget/curl). */
final class CronController
{
    public function cobrancaAssinatura(): void
    {
        $key = trim((string) ($_GET['key'] ?? $_SERVER['HTTP_X_CRON_KEY'] ?? ''));
        if (!CronSecret::matches($key)) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'erro' => 'Chave de cron inválida.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $pdo = Db::tryConnection();
        if ($pdo === null) {
            http_response_code(503);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'erro' => 'Banco indisponível.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $slot = isset($_GET['slot']) ? (string) $_GET['slot'] : 'auto';
        $agora = isset($_GET['agora']) ? (string) $_GET['agora'] : null;

        $svc = new CobrancaAssinaturaService($pdo);
        $result = $svc->executar($slot, $agora);

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
