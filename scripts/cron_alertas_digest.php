<?php

declare(strict_types=1);

/**
 * Digest diário da Central de Alertas.
 *
 * Uso CLI:
 *   php scripts/cron_alertas_digest.php
 *   php scripts/cron_alertas_digest.php --force   (ignora horário)
 *
 * HTTP (preferencial na Hostinger):
 *   curl -fsS -H "X-Cron-Key: SEGREDO" https://DOMINIO/cron/alertas-digest
 */

use App\Helpers\Db;
use App\Services\AlertasService;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$config = require $root . '/config/app.php';
date_default_timezone_set((string) ($config['timezone'] ?? 'America/Sao_Paulo'));

$pdo = Db::tryConnection();
if (!$pdo instanceof PDO) {
    fwrite(STDERR, "MySQL indisponível.\n");
    exit(1);
}

$force = in_array('--force', $argv ?? [], true);
$svc = new AlertasService($pdo);
$agora = $force ? null : null;
if ($force) {
    $cfg = $svc->obterConfig();
    $hoje = (new DateTimeImmutable('now'))->format('Y-m-d');
    $hora = (string) ($cfg['digest_hora'] ?? '08:00');
    $result = $svc->executarDigest($hoje . ' ' . $hora . ':00');
} else {
    $result = $svc->executarDigest(null);
}

$line = sprintf(
    "[%s] ok=%s enviados=%d pulados=%d%s\n",
    date('c'),
    !empty($result['ok']) ? '1' : '0',
    (int) ($result['enviados'] ?? 0),
    (int) ($result['pulados'] ?? 0),
    isset($result['erro']) ? (' erro=' . $result['erro']) : ''
);
echo $line;

$logDir = dirname(__DIR__) . '/storage/logs';
if (is_dir($logDir) || @mkdir($logDir, 0775, true)) {
    @file_put_contents($logDir . '/cron_alertas_digest.log', $line, FILE_APPEND);
}
