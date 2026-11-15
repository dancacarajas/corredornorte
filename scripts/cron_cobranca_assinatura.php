<?php

declare(strict_types=1);

/**
 * Cron CLI — cobrança automática de assinatura (folha + RA).
 *
 * Hostinger (recomendado):
 *   0 9  * * 1-5  /usr/bin/php /home/.../corredornorte/scripts/cron_cobranca_assinatura.php manha
 *   0 15 * * 1-5  /usr/bin/php /home/.../corredornorte/scripts/cron_cobranca_assinatura.php tarde
 *
 * Ou HTTP (só header — nunca ?key=):
 *   curl -fsS -H "X-Cron-Key: SEGREDO" "https://SEU-DOMINIO/cron/cobranca-assinatura"
 *
 * Uso local:
 *   php scripts/cron_cobranca_assinatura.php manha
 *   php scripts/cron_cobranca_assinatura.php tarde --agora="2026-11-16 09:05:00"
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$config = require $root . '/config/app.php';
date_default_timezone_set((string) ($config['timezone'] ?? 'America/Belem'));

use App\Helpers\Db;
use App\Services\CobrancaAssinaturaService;

$slot = 'auto';
$agora = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--agora=')) {
        $agora = substr($arg, 8);
        continue;
    }
    if (in_array($arg, ['manha', 'manhã', 'tarde', 'auto', 'morning', 'afternoon'], true)) {
        $slot = $arg === 'manhã' ? 'manha' : $arg;
    }
}

$pdo = Db::tryConnection();
if ($pdo === null) {
    fwrite(STDERR, "ERRO: MySQL indisponível.\n");
    exit(1);
}

$svc = new CobrancaAssinaturaService($pdo);
$result = $svc->executar($slot, $agora);

$linha = sprintf(
    "[%s] slot=%s hoje=%s enviados=%d falhas=%d ignorados=%d%s\n",
    date('c'),
    $result['slot'],
    $result['hoje'],
    $result['enviados'],
    $result['falhas'],
    $result['ignorados'],
    $result['skipped'] !== null ? ' skipped=' . $result['skipped'] : ''
);
echo $linha;

$logDir = $root . '/storage/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
@file_put_contents($logDir . '/cron_cobranca.log', $linha, FILE_APPEND);

exit(($result['falhas'] ?? 0) > 0 ? 2 : 0);
