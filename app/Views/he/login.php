<?php

use App\Helpers\Csrf;
use App\Helpers\View;

$error = $error ?? null;
$ok = $ok ?? null;
$cpf = $cpf ?? '';
$otpPending = is_array($otpPending ?? null) ? $otpPending : null;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Portal do Colaborador · Arcadis</title>
    <meta name="referrer" content="no-referrer">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@500;600;700;800&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= View::asset('css/tokens.css') ?>">
    <link rel="stylesheet" href="<?= View::asset('css/components.css') ?>">
    <style>
        body {
            min-height: 100vh; display: grid; place-items: center; padding: 1.5rem;
            background:
                radial-gradient(circle at top left, rgba(224,96,8,.18), transparent 40%),
                radial-gradient(circle at bottom right, rgba(17,17,17,.08), transparent 35%),
                var(--canvas);
        }
        .login-card {
            width: min(420px, 100%); background: var(--surface);
            border-radius: var(--radius-lg); box-shadow: var(--shadow-lg);
            border: 1px solid var(--border); padding: 1.75rem;
        }
        .login-brand { text-align: center; margin-bottom: 1.25rem; }
        .login-brand img { max-height: 64px; }
        .login-card h1 {
            margin: 0 0 .35rem; font-size: 1.2rem; text-align: center;
            font-family: "Plus Jakarta Sans", sans-serif;
        }
        .login-card p { text-align: center; color: var(--ink-muted); margin: 0 0 1.25rem; font-size: .92rem; }
    </style>
</head>
<body>
<div class="login-card">
    <div class="login-brand">
        <img src="<?= View::asset('img/logo.png') ?>" alt="Arcadis">
    </div>
    <h1>Portal do Colaborador</h1>
    <p><?= $otpPending
        ? 'Digite o código de 6 dígitos enviado ao seu e-mail.'
        : 'Informe o CPF. Enviaremos um código de acesso ao e-mail cadastrado.' ?></p>

    <?php if ($ok): ?>
        <div class="alert alert-success" style="margin-bottom:1rem;"><?= View::e((string) $ok) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger" style="margin-bottom:1rem;"><?= View::e($error) ?></div>
    <?php endif; ?>

    <?php if ($otpPending): ?>
        <form method="post" action="/he/login" class="section-stack">
            <?= Csrf::field() ?>
            <input type="hidden" name="otp_challenge" value="<?= View::e((string) ($otpPending['challenge'] ?? '')) ?>">
            <div class="form-field">
                <label class="form-label" for="otp">Código de acesso</label>
                <input class="form-control" id="otp" name="otp" inputmode="numeric" autocomplete="one-time-code"
                       placeholder="000000" maxlength="6" pattern="[0-9]{6}" required autofocus>
            </div>
            <button class="btn btn-primary" type="submit" style="width:100%;">Confirmar código</button>
        </form>
        <form method="post" action="/he/login" style="margin-top:.85rem;">
            <?= Csrf::field() ?>
            <input type="hidden" name="cpf" value="<?= View::e($cpf) ?>">
            <button class="btn btn-ghost btn-sm" type="submit" style="width:100%;">Reenviar código</button>
        </form>
    <?php else: ?>
        <form method="post" action="/he/login" class="section-stack">
            <?= Csrf::field() ?>
            <div class="form-field">
                <label class="form-label" for="cpf">CPF</label>
                <input class="form-control" id="cpf" name="cpf" inputmode="numeric" autocomplete="username"
                       placeholder="000.000.000-00" value="<?= View::e($cpf) ?>" required autofocus>
            </div>
            <button class="btn btn-primary" type="submit" style="width:100%;">Receber código</button>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
