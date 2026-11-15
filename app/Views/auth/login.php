<?php

use App\Helpers\Csrf;
use App\Helpers\View;

$error = $error ?? null;
$email = $email ?? '';
$ok = $ok ?? null;
$pageTitle = 'Entrar';

$okMsgs = [
    'senha_redefinida' => 'Senha redefinida. Entre com a nova senha.',
];

require __DIR__ . '/_layout_start.php';
?>

    <h1 class="auth-title">Entrar</h1>
    <p class="auth-lead">Acesse o Arcadis RH com seu e-mail corporativo.</p>

    <?php if (!empty($ok)): ?>
        <div class="alert alert-success" style="margin-bottom:1rem;"><?= View::e($okMsgs[(string) $ok] ?? 'Pronto.') ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger" style="margin-bottom:1rem;"><?= View::e($error) ?></div>
    <?php endif; ?>

    <form method="post" action="/login" class="section-stack">
        <?= Csrf::field() ?>
        <div class="form-field">
            <label class="form-label" for="loginEmail">E-mail</label>
            <input class="form-control" id="loginEmail" type="email" name="email" value="<?= View::e($email) ?>" required autocomplete="email" autofocus>
        </div>
        <div class="form-field">
            <label class="form-label" for="loginPassword">Senha</label>
            <div class="password-field">
                <input class="form-control" id="loginPassword" type="password" name="password" value="" required autocomplete="current-password">
                <button class="password-toggle" type="button" data-password-toggle="loginPassword" aria-label="Mostrar senha" aria-pressed="false" title="Mostrar senha">
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

    <div class="auth-links">
        <a href="/esqueci-senha">Esqueci minha senha</a>
    </div>

<script>
(() => {
    document.querySelectorAll('[data-password-toggle]').forEach((btn) => {
        const input = document.getElementById(btn.getAttribute('data-password-toggle'));
        if (!input) return;
        btn.addEventListener('click', () => {
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.classList.toggle('is-visible', show);
            btn.setAttribute('aria-pressed', show ? 'true' : 'false');
            btn.setAttribute('aria-label', show ? 'Ocultar senha' : 'Mostrar senha');
            btn.title = show ? 'Ocultar senha' : 'Mostrar senha';
        });
    });
})();
</script>
<?php require __DIR__ . '/_layout_end.php'; ?>
