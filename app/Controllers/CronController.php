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
        // Preferir header; query string só como fallback legado (aparece em logs).
        $key = trim((string) ($_SERVER['HTTP_X_CRON_KEY'] ?? $_GET['key'] ?? ''));
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

        // Em HTTP: nunca aceitar override de data/slot (evita disparo forçado).
        // Overrides só via CLI (scripts/cron_cobranca_assinatura.php).
        $slot = 'auto';
        $agora = null;

        $svc = new CobrancaAssinaturaService($pdo);
        $result = $svc->executar($slot, $agora);

        // Sem PII na resposta HTTP.
        $safe = [
            'ok' => (bool) ($result['ok'] ?? true),
            'slot' => (string) ($result['slot'] ?? $slot),
            'enviados' => (int) ($result['enviados'] ?? 0),
            'falhas' => (int) ($result['falhas'] ?? 0),
            'ignorados' => (int) ($result['ignorados'] ?? 0),
        ];
        if (isset($result['erro'])) {
            $safe['erro'] = (string) $result['erro'];
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($safe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
