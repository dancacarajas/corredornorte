<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

require $root . '/vendor/autoload.php';

use App\Install\Installer;

$installer = new Installer($root);
$installed = $installer->isInstalled();
$error = null;
$result = null;

$rawHost = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
$hostHeader = preg_replace('/[^a-zA-Z0-9.\-:]/', '', $rawHost) ?: 'localhost';
$isLocal = str_starts_with($hostHeader, '127.0.0.1')
    || str_starts_with($hostHeader, 'localhost');

$defaults = $isLocal
    ? [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => getenv('DB_PORT') ?: '3306',
        'database' => getenv('DB_NAME') ?: 'arcadis_rh',
        'username' => getenv('DB_USER') ?: 'root',
        'password' => '',
    ]
    : [
        'host' => getenv('DB_HOST') ?: 'localhost',
        'port' => getenv('DB_PORT') ?: '3306',
        'database' => getenv('DB_NAME') ?: '',
        'username' => getenv('DB_USER') ?: '',
        'password' => '',
    ];

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$script = $_SERVER['SCRIPT_NAME'] ?? '/install/index.php';
$basePath = rtrim(str_replace('\\', '/', dirname(dirname($script))), '/');
if ($basePath === '/' || $basePath === '\\') {
    $basePath = '';
}
$appUrl = $scheme . '://' . $hostHeader . $basePath;

if ($installed) {
    http_response_code(403);
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $host = trim((string) ($_POST['host'] ?? $defaults['host']));
        $port = trim((string) ($_POST['port'] ?? $defaults['port']));
        $database = trim((string) ($_POST['database'] ?? $defaults['database']));
        $username = trim((string) ($_POST['username'] ?? $defaults['username']));
        $password = (string) ($_POST['password'] ?? '');
        if ($database === '' || $username === '') {
            throw new RuntimeException('Informe banco de dados e usuário MySQL.');
        }
        $result = $installer->install([
            'host' => $host !== '' ? $host : 'localhost',
            'port' => $port !== '' ? $port : '3306',
            'database' => $database,
            'username' => $username,
            'password' => $password,
            'app_url' => $appUrl,
            'app_name' => 'Corredor Norte RH',
        ]);
        $installed = true;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$loginUrl = ($basePath === '' ? '' : $basePath) . '/login';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Instalação — Corredor Norte RH</title>
    <style>
        :root {
            --bg: #0f172a;
            --card: #111827;
            --line: #1f2937;
            --text: #e5e7eb;
            --muted: #9ca3af;
            --accent: #0d9488;
            --accent-2: #14b8a6;
            --danger: #f87171;
            --ok: #34d399;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: "Segoe UI", system-ui, sans-serif;
            color: var(--text);
            background:
                radial-gradient(1200px 600px at 10% -10%, rgba(20,184,166,.25), transparent 55%),
                radial-gradient(900px 500px at 100% 0%, rgba(59,130,246,.18), transparent 50%),
                var(--bg);
            display: grid;
            place-items: center;
            padding: 24px;
        }
        .wrap {
            width: min(520px, 100%);
            background: rgba(17,24,39,.92);
            border: 1px solid var(--line);
            border-radius: 16px;
            padding: 28px;
            box-shadow: 0 24px 60px rgba(0,0,0,.35);
        }
        h1 {
            margin: 0 0 6px;
            font-size: 1.45rem;
            letter-spacing: -.02em;
        }
        p.lead {
            margin: 0 0 22px;
            color: var(--muted);
            line-height: 1.45;
            font-size: .95rem;
        }
        .meta {
            display: grid;
            gap: 8px;
            margin-bottom: 18px;
            padding: 12px 14px;
            border: 1px solid var(--line);
            border-radius: 10px;
            background: rgba(15,23,42,.55);
            font-size: .88rem;
        }
        .meta div { display: flex; justify-content: space-between; gap: 12px; }
        .meta span { color: var(--muted); }
        .meta strong { font-weight: 600; word-break: break-all; text-align: right; }
        label {
            display: block;
            margin-bottom: 6px;
            font-size: .9rem;
            color: var(--muted);
        }
        input[type=password] {
            width: 100%;
            padding: 12px 14px;
            border-radius: 10px;
            border: 1px solid #334155;
            background: #0b1220;
            color: var(--text);
            font-size: 1rem;
            margin-bottom: 16px;
        }
        input[type=password]:focus {
            outline: 2px solid rgba(20,184,166,.45);
            border-color: var(--accent);
        }
        button {
            width: 100%;
            border: 0;
            border-radius: 10px;
            padding: 13px 16px;
            font-size: 1rem;
            font-weight: 650;
            color: #042f2e;
            background: linear-gradient(135deg, var(--accent-2), var(--accent));
            cursor: pointer;
        }
        button:hover { filter: brightness(1.05); }
        .alert {
            padding: 12px 14px;
            border-radius: 10px;
            margin-bottom: 16px;
            font-size: .92rem;
            line-height: 1.4;
        }
        .alert-error {
            background: rgba(248,113,113,.12);
            border: 1px solid rgba(248,113,113,.35);
            color: #fecaca;
        }
        .alert-ok {
            background: rgba(52,211,153,.12);
            border: 1px solid rgba(52,211,153,.35);
            color: #a7f3d0;
        }
        .creds {
            margin-top: 14px;
            padding: 12px 14px;
            border-radius: 10px;
            background: rgba(15,23,42,.65);
            border: 1px solid var(--line);
            font-size: .9rem;
        }
        .creds code {
            display: block;
            margin-top: 4px;
            color: #fde68a;
        }
        a.btn {
            display: inline-block;
            margin-top: 16px;
            text-decoration: none;
            color: #042f2e;
            background: linear-gradient(135deg, var(--accent-2), var(--accent));
            padding: 11px 16px;
            border-radius: 10px;
            font-weight: 650;
        }
        ul.log {
            margin: 12px 0 0;
            padding-left: 18px;
            color: var(--muted);
            font-size: .85rem;
        }
    </style>
</head>
<body>
<main class="wrap">
    <h1>Kit de instalação</h1>
    <p class="lead"><?= $isLocal
        ? 'Ambiente local detectado. Use a senha do MySQL de desenvolvimento (padrão: root).'
        : 'Informe apenas a senha do MySQL criado na Hostinger. O restante já está preparado para o Corredor Norte.'
    ?></p>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if ($result): ?>
        <div class="alert alert-ok">Instalação concluída. O sistema está pronto para uso.</div>
        <div class="creds">
            Acesso inicial:
            <code>E-mail: <?= htmlspecialchars($result['admin_email'], ENT_QUOTES, 'UTF-8') ?></code>
            <code>Senha: <?= htmlspecialchars($result['admin_password'], ENT_QUOTES, 'UTF-8') ?></code>
        </div>
        <ul class="log">
            <?php foreach ($result['messages'] as $msg): ?>
                <li><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></li>
            <?php endforeach; ?>
        </ul>
        <a class="btn" href="<?= htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') ?>">Ir para o login</a>
    <?php elseif ($installed): ?>
        <div class="alert alert-ok">O sistema já foi instalado neste servidor.</div>
        <a class="btn" href="<?= htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') ?>">Ir para o login</a>
    <?php else: ?>
        <?php
            $formHost = (string) ($_POST['host'] ?? $defaults['host']);
            $formPort = (string) ($_POST['port'] ?? $defaults['port']);
            $formDatabase = (string) ($_POST['database'] ?? $defaults['database']);
            $formUsername = (string) ($_POST['username'] ?? $defaults['username']);
        ?>
        <form method="post" autocomplete="off">
            <label for="host">Host MySQL</label>
            <input id="host" name="host" type="text" required value="<?= htmlspecialchars($formHost, ENT_QUOTES, 'UTF-8') ?>" style="width:100%;padding:10px 12px;border-radius:10px;border:1px solid #334155;background:#0f172a;color:#e5e7eb;margin-bottom:10px;">
            <label for="port">Porta MySQL</label>
            <input id="port" name="port" type="text" required value="<?= htmlspecialchars($formPort, ENT_QUOTES, 'UTF-8') ?>" style="width:100%;padding:10px 12px;border-radius:10px;border:1px solid #334155;background:#0f172a;color:#e5e7eb;margin-bottom:10px;">
            <label for="database">Banco de dados</label>
            <input id="database" name="database" type="text" required value="<?= htmlspecialchars($formDatabase, ENT_QUOTES, 'UTF-8') ?>" style="width:100%;padding:10px 12px;border-radius:10px;border:1px solid #334155;background:#0f172a;color:#e5e7eb;margin-bottom:10px;">
            <label for="username">Usuário MySQL</label>
            <input id="username" name="username" type="text" required value="<?= htmlspecialchars($formUsername, ENT_QUOTES, 'UTF-8') ?>" style="width:100%;padding:10px 12px;border-radius:10px;border:1px solid #334155;background:#0f172a;color:#e5e7eb;margin-bottom:10px;">
            <label for="password">Senha do banco de dados</label>
            <input id="password" name="password" type="password" <?= $isLocal ? '' : 'required' ?> autofocus
                   value=""
                   placeholder="<?= $isLocal ? 'Senha do MySQL local (se houver)' : 'Senha MySQL do servidor' ?>">
            <button type="submit">Instalar</button>
        </form>
    <?php endif; ?>
</main>
</body>
</html>
