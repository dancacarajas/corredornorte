<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\CronSecret;
use App\Helpers\Db;
use App\Services\CobrancaAssinaturaService;

/**
 * Endpoint HTTP de cron. Autenticação apenas por header
 * (X-Cron-Key ou Authorization: Bearer) — nunca por ?key= (vaza em logs).
 *
 * Exemplo Hostinger:
 *   curl -fsS -H "X-Cron-Key: SEGREDO" https://DOMINIO/cron/cobranca-assinatura
 */
final class CronController
{
    public function cobrancaAssinatura(): void
    {
        $key = $this->extractKey();
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

        $svc = new CobrancaAssinaturaService($pdo);
        $result = $svc->executar('auto', null);

        $safe = [
            'ok' => (bool) ($result['ok'] ?? true),
            'slot' => (string) ($result['slot'] ?? 'auto'),
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

    private function extractKey(): string
    {
        $header = trim((string) ($_SERVER['HTTP_X_CRON_KEY'] ?? ''));
        if ($header !== '') {
            return $header;
        }
        $auth = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
        if (preg_match('/^Bearer\s+(\S+)$/i', $auth, $m) === 1) {
            return trim($m[1]);
        }

        return '';
    }
}
