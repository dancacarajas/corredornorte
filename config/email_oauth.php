<?php

declare(strict_types=1);

/**
 * Credenciais OAuth (podem ser sobrescritas na tela Sistema → Conta de e-mail).
 * Configure no ambiente ou pela UI:
 *   ARCADIS_GMAIL_CLIENT_ID / ARCADIS_GMAIL_CLIENT_SECRET
 *   ARCADIS_MS_CLIENT_ID / ARCADIS_MS_CLIENT_SECRET / ARCADIS_MS_TENANT
 */
$app = require __DIR__ . '/app.php';
$base = rtrim((string) ($app['url'] ?? ''), '/');
if ($base === '') {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $base = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost:8080');
}

return [
    'encryption_key' => getenv('ARCADIS_APP_KEY') ?: 'arcadis-local-dev-key-change-me',
    'providers' => [
        'gmail' => [
            'label' => 'Gmail',
            'client_id' => getenv('ARCADIS_GMAIL_CLIENT_ID') ?: '',
            'client_secret' => getenv('ARCADIS_GMAIL_CLIENT_SECRET') ?: '',
            'auth_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token_url' => 'https://oauth2.googleapis.com/token',
            'userinfo_url' => 'https://www.googleapis.com/oauth2/v2/userinfo',
            'send_url' => 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send',
            'scopes' => [
                'openid',
                'email',
                'https://www.googleapis.com/auth/gmail.send',
                'https://www.googleapis.com/auth/userinfo.email',
            ],
            'redirect_uri' => $base . '/rh/email/oauth/gmail/callback',
        ],
        'microsoft' => [
            'label' => 'Microsoft',
            'client_id' => getenv('ARCADIS_MS_CLIENT_ID') ?: '',
            'client_secret' => getenv('ARCADIS_MS_CLIENT_SECRET') ?: '',
            'tenant' => getenv('ARCADIS_MS_TENANT') ?: 'common',
            'auth_url' => 'https://login.microsoftonline.com/{tenant}/oauth2/v2.0/authorize',
            'token_url' => 'https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token',
            'userinfo_url' => 'https://graph.microsoft.com/v1.0/me',
            'send_url' => 'https://graph.microsoft.com/v1.0/me/sendMail',
            'scopes' => [
                'openid',
                'email',
                'offline_access',
                'User.Read',
                'Mail.Send',
            ],
            'redirect_uri' => $base . '/rh/email/oauth/microsoft/callback',
        ],
    ],
];
