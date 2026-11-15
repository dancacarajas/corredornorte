<?php

use App\Helpers\Csrf;
use App\Helpers\View;

$error = $error ?? null;
$token = $token ?? '';
$tokenValido = (bool) ($tokenValido ?? false);
$nome = $nome ?? null;
$pageTitle = 'Redefinir senha';

require __DIR__ . '/_layout_start.php';
?>

    <h1 class="auth-title">Nova senha</h1>
    <p class="auth-lead">
        <?php if ($tokenValido): ?>
            Olá<?= $nome ? ', ' . View::e((string) $nome) : '' ?>. Defina uma senha com no mínimo 8 caracteres, com letras e números.
        <?php else: ?>
            Não foi possível validar este link.
        <?php endif; ?>
    </p>

    <?php if ($error): ?>
        <div class="alert alert-danger" style="margin-bottom:1rem;"><?= View::e($error) ?></div>
    <?php endif; ?>

    <?php if ($tokenValido): ?>
        <form method="post" action="/redefinir-senha/<?= View::e($token) ?>" class="section-stack">
            <?= Csrf::field() ?>
            <div class="form-field">
                <label class="form-label" for="novaSenha">Nova senha</label>
                <div class="password-field">
                    <input class="form-control" id="novaSenha" type="password" name="senha" required minlength="8" autocomplete="new-password">
                    <button class="password-toggle" type="button" data-password-toggle="novaSenha" aria-label="Mostrar senha" aria-pressed="false">
                        <svg class="icon-show" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
                        <svg class="icon-hide" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 3l18 18"/><path d="M10.6 10.6a2.5 2.5 0 0 0 3.5 3.5"/><path d="M9.9 5.1A10.5 10.5 0 0 1 12 5c6.5 0 10 7 10 7a17.7 17.7 0 0 1-3.2 3.9"/><path d="M6.1 6.1C3.9 7.7 2 12 2 12a17.8 17.8 0 0 0 6.7 5.6"/></svg>
                    </button>
                </div>
            </div>
            <div class="form-field">
                <label class="form-label" for="novaSenha2">Confirmar senha</label>
                <input class="form-control" id="novaSenha2" type="password" name="senha_confirmacao" required minlength="8" autocomplete="new-password">
            </div>
            <button class="btn btn-primary" type="submit" style="width:100%;">Salvar nova senha</button>
        </form>
    <?php else: ?>
        <a class="btn btn-primary" href="/esqueci-senha" style="width:100%;display:inline-flex;justify-content:center;">Solicitar novo link</a>
    <?php endif; ?>

    <div class="auth-links">
        <a href="/login">Voltar ao login</a>
    </div>

<?php if ($tokenValido): ?>
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
        });
    });
})();
</script>
<?php endif; ?>
<?php require __DIR__ . '/_layout_end.php'; ?>
