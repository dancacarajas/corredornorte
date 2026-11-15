<?php

declare(strict_types=1);

$config = [
    'name' => 'Arcadis',
    'tagline' => 'RH Operacional',
    'env' => 'local',
    'url' => getenv('ARCADIS_APP_URL') ?: '',
    'timezone' => 'America/Belem',
    'ponto' => [
        // Preenche batidas automaticamente com variação diária (±N min), editáveis na gestão
        'horario_britanico' => true,
        'variacao_minutos' => 3,
    ],
    'mail' => [
        // true = usa mail() do PHP como fallback; false = só OAuth ou registro local
        'enabled' => getenv('ARCADIS_MAIL_ENABLED') === '1',
        'from' => getenv('ARCADIS_MAIL_FROM') ?: 'noreply@arcadis.local',
        'from_name' => 'Arcadis RH',
        // Preferência: conta Gmail/Microsoft conectada em /rh/email
    ],
    // Segredo do endpoint HTTP /cron/* (Hostinger wget/curl). Preferir ARCADIS_CRON_SECRET ou storage/cron.secret.
    'cron_secret' => getenv('ARCADIS_CRON_SECRET') ?: '',
];

$local = __DIR__ . '/app.local.php';
if (is_file($local)) {
    $override = require $local;
    if (is_array($override)) {
        $config = array_replace_recursive($config, $override);
        if (isset($override['name']) && !isset($override['mail']['from_name'])) {
            $config['mail']['from_name'] = $override['name'];
        }
    }
}

return $config;
