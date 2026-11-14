<?php

use App\Helpers\Csrf;
use App\Helpers\View;

$error = $error ?? null;
$email = $email ?? '';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Entrar · Arcadis</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@500;600;700;800&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= View::asset('css/tokens.css') ?>">
    <link rel="stylesheet" href="<?= View::asset('css/components.css') ?>">
    <style>
        body {
            min-height: 100vh;
            display: grid;
            place-items: center;
            background:
                radial-gradient(circle at top left, rgba(224,96,8,.18), transparent 40%),
                radial-gradient(circle at bottom right, rgba(17,17,17,.08), transparent 35%),
                var(--canvas);
            padding: 1.5rem;
        }
        .login-card {
            width: min(420px, 100%);
            background: var(--surface);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-lg);
            border: 1px solid var(--border);
            padding: 1.75rem;
        }
        .login-brand {
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1.5rem;
            width: 100%;
            text-align: center;
        }
        .login-brand img {
            display: block;
            width: auto;
            max-width: 100%;
            height: auto;
            max-height: 72px;
            margin: 0 auto;
            object-fit: contain;
            object-position: center;
            background: transparent;
        }
        .password-field {
            position: relative;
        }
        .password-field .form-control {
            padding-right: 2.75rem;
        }
        .password-toggle {
            position: absolute;
            right: .45rem;
            top: 50%;
            transform: translateY(-50%);
            width: 2.1rem;
            height: 2.1rem;
            border: 0;
            background: transparent;
            color: var(--ink-muted);
            border-radius: var(--radius-md);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }
        .password-toggle:hover,
        .password-toggle:focus-visible {
            color: var(--ink);
            background: var(--canvas);
            outline: none;
        }
        .password-toggle svg {
            width: 18px;
            height: 18px;
        }
        .password-toggle .icon-hide { display: none; }
        .password-toggle.is-visible .icon-show { display: none; }
        .password-toggle.is-visible .icon-hide { display: block; }
    </style>
</head>
<body>
<div class="login-card">
    <div class="login-brand">
        <img src="<?= View::asset('img/logo.png') ?>" alt="Arcadis">
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger" style="margin-bottom:1rem;"><?= View::e($error) ?></div>
    <?php endif; ?>

    <form method="post" action="/login" class="section-stack">
        <?= Csrf::field() ?>
        <div class="form-field">
            <label class="form-label" for="loginEmail">E-mail</label>
            <input class="form-control" id="loginEmail" type="email" name="email" value="<?= View::e($email) ?>" required autocomplete="email">
        </div>
        <div class="form-field">
            <label class="form-label" for="loginPassword">Senha</label>
            <div class="password-field">
                <input class="form-control" id="loginPassword" type="password" name="password" value="" required autocomplete="current-password">
                <button class="password-toggle" type="button" id="togglePassword" aria-label="Mostrar senha" aria-pressed="false" title="Mostrar senha">
                    <svg class="icon-show" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/>
                        <circle cx="12" cy="12" r="3"/>
                    </svg>
                    <svg class="icon-hide" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path d="M3 3l18 18"/>
                        <path d="M10.6 10.6a2.5 2.5 0 0 0 3.5 3.5"/>
                        <path d="M9.9 5.1A10.5 10.5 0 0 1 12 5c6.5 0 10 7 10 7a17.7 17.7 0 0 1-3.2 3.9"/>
                        <path d="M6.1 6.1C3.9 7.7 2 12 2 12a17.8 17.8 0 0 0 6.7 5.6"/>
                    </svg>
                </button>
            </div>
        </div>
        <button class="btn btn-primary" type="submit" style="width:100%;">Entrar</button>
    </form>
</div>
<script>
(() => {
    const input = document.getElementById('loginPassword');
    const btn = document.getElementById('togglePassword');
    if (!input || !btn) return;
    btn.addEventListener('click', () => {
        const show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        btn.classList.toggle('is-visible', show);
        btn.setAttribute('aria-pressed', show ? 'true' : 'false');
        btn.setAttribute('aria-label', show ? 'Ocultar senha' : 'Mostrar senha');
        btn.title = show ? 'Ocultar senha' : 'Mostrar senha';
    });
})();
</script>
</body>
</html>
