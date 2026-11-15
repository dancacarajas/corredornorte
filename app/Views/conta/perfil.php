<?php

use App\Helpers\Csrf;
use App\Helpers\View;

/** @var array<string,mixed> $usuario */
/** @var ?string $flashOk */
/** @var ?string $flashErro */

$msgs = [
    'perfil' => 'Dados do perfil atualizados.',
    'senha' => 'Senha alterada com sucesso.',
    'avatar' => 'Foto atualizada.',
    'avatar_removido' => 'Foto removida.',
    'reset_enviado' => 'Se o e-mail estiver ativo, enviamos um link para redefinir a senha.',
];

$avatarUrl = !empty($usuario['avatar_path'])
    ? '/conta/avatar?id=' . (int) $usuario['id'] . '&v=' . substr(sha1((string) $usuario['avatar_path']), 0, 8)
    : null;
$inicial = mb_strtoupper(mb_substr((string) ($usuario['nome'] ?? 'U'), 0, 1));
?>

<?php if (!empty($flashOk)): ?>
    <div class="alert alert-success"><?= View::e($msgs[(string) $flashOk] ?? 'OK') ?></div>
<?php endif; ?>
<?php if (!empty($flashErro)): ?>
    <div class="alert alert-danger"><?= View::e((string) $flashErro) ?></div>
<?php endif; ?>

<section class="conta-grid">
    <article class="card conta-avatar-card">
        <div class="card-header">
            <div>
                <h2 class="card-title">Foto do perfil</h2>
                <p class="card-subtitle">JPG, PNG ou WebP · máx. 2 MB</p>
            </div>
        </div>
        <div class="card-body conta-avatar-body">
            <div class="conta-avatar-preview" aria-hidden="true">
                <?php if ($avatarUrl): ?>
                    <img src="<?= View::e($avatarUrl) ?>" alt="">
                <?php else: ?>
                    <span><?= View::e($inicial) ?></span>
                <?php endif; ?>
            </div>
            <form method="post" action="/conta/avatar" enctype="multipart/form-data" class="conta-avatar-form">
                <?= Csrf::field() ?>
                <input class="form-control" type="file" name="avatar" accept="image/jpeg,image/png,image/webp" required>
                <button class="btn btn-primary btn-sm" type="submit">Enviar foto</button>
            </form>
            <?php if ($avatarUrl): ?>
                <form method="post" action="/conta/avatar/remover" onsubmit="return confirm('Remover a foto do perfil?');">
                    <?= Csrf::field() ?>
                    <button class="btn btn-ghost btn-sm" type="submit">Remover foto</button>
                </form>
            <?php endif; ?>
        </div>
    </article>

    <article class="card">
        <div class="card-header">
            <div>
                <h2 class="card-title">Dados da conta</h2>
                <p class="card-subtitle"><?= View::e((string) ($usuario['email'] ?? '')) ?></p>
            </div>
        </div>
        <form method="post" action="/conta/perfil" class="card-body form-grid cols-2">
            <?= Csrf::field() ?>
            <div class="form-field">
                <label class="form-label">Nome</label>
                <input class="form-control" name="nome" required minlength="3" value="<?= View::e((string) ($usuario['nome'] ?? '')) ?>">
            </div>
            <div class="form-field">
                <label class="form-label">E-mail</label>
                <input class="form-control" type="email" name="email" required value="<?= View::e((string) ($usuario['email'] ?? '')) ?>">
            </div>
            <div class="form-field">
                <label class="form-label">Telefone</label>
                <input class="form-control" type="tel" name="telefone" value="<?= View::e((string) ($usuario['telefone'] ?? '')) ?>" placeholder="(xx) xxxxx-xxxx">
            </div>
            <div class="form-field">
                <label class="form-label">Último acesso</label>
                <input class="form-control" type="text" disabled value="<?= View::e(
                    !empty($usuario['last_login_at'])
                        ? date('d/m/Y H:i', strtotime((string) $usuario['last_login_at']))
                        : '—'
                ) ?>">
            </div>
            <div class="stack-row" style="grid-column:1/-1;">
                <button class="btn btn-primary" type="submit">Salvar dados</button>
            </div>
        </form>
    </article>

    <article class="card" style="grid-column:1/-1;">
        <div class="card-header">
            <div>
                <h2 class="card-title">Alterar senha</h2>
                <p class="card-subtitle">Mínimo 8 caracteres, com letras e números</p>
            </div>
        </div>
        <form method="post" action="/conta/senha" class="card-body form-grid cols-3">
            <?= Csrf::field() ?>
            <div class="form-field">
                <label class="form-label">Senha atual</label>
                <input class="form-control" type="password" name="senha_atual" required autocomplete="current-password">
            </div>
            <div class="form-field">
                <label class="form-label">Nova senha</label>
                <input class="form-control" type="password" name="nova_senha" required minlength="8" autocomplete="new-password">
            </div>
            <div class="form-field">
                <label class="form-label">Confirmar nova senha</label>
                <input class="form-control" type="password" name="nova_senha_confirmacao" required minlength="8" autocomplete="new-password">
            </div>
            <div class="stack-row" style="grid-column:1/-1;">
                <button class="btn btn-primary" type="submit">Atualizar senha</button>
            </div>
        </form>
        <div class="card-body" style="padding-top:0;">
            <form method="post" action="/conta/solicitar-reset">
                <?= Csrf::field() ?>
                <button class="btn btn-secondary btn-sm" type="submit">Enviar link de redefinição por e-mail</button>
            </form>
        </div>
    </article>
</section>
