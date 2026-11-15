<?php

use App\Helpers\Csrf;
use App\Helpers\View;

$error = $error ?? null;
$ok = $ok ?? null;
$email = $email ?? '';
$pageTitle = 'Recuperar senha';

require __DIR__ . '/_layout_start.php';
?>

    <h1 class="auth-title">Recuperar senha</h1>
    <p class="auth-lead">Informe o e-mail da conta. Se estiver cadastrado, enviaremos um link válido por 1 hora.</p>

    <?php if (!empty($ok)): ?>
        <div class="alert alert-success" style="margin-bottom:1rem;"><?= View::e((string) $ok) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger" style="margin-bottom:1rem;"><?= View::e($error) ?></div>
    <?php endif; ?>

    <form method="post" action="/esqueci-senha" class="section-stack">
        <?= Csrf::field() ?>
        <div class="form-field">
            <label class="form-label" for="forgotEmail">E-mail</label>
            <input class="form-control" id="forgotEmail" type="email" name="email" value="<?= View::e($email) ?>" required autocomplete="email" autofocus>
        </div>
        <button class="btn btn-primary" type="submit" style="width:100%;">Enviar link</button>
    </form>

    <div class="auth-links">
        <a href="/login">Voltar ao login</a>
    </div>

<?php require __DIR__ . '/_layout_end.php'; ?>
