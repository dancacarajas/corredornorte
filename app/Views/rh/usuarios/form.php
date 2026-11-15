<?php

use App\Helpers\View;

/** @var ?array<string,mixed> $usuario */
/** @var list<array<string,mixed>> $perfis */
/** @var ?string $error */

$selected = [];
foreach (($usuario['perfis'] ?? []) as $p) {
    $selected[(int) ($p['id'] ?? 0)] = true;
}
$isEdit = !empty($usuario['id']);
$flashOk = $_GET['ok'] ?? null;
$flashErro = $_GET['erro'] ?? null;
$okMsgs = [
    'reset_enviado' => 'E-mail de redefinição enviado (se a conta estiver ativa).',
];
?>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger"><?= View::e((string) $error) ?></div>
<?php endif; ?>
<?php if (!empty($flashOk)): ?>
    <div class="alert alert-success"><?= View::e($okMsgs[(string) $flashOk] ?? 'OK') ?></div>
<?php endif; ?>
<?php if (!empty($flashErro)): ?>
    <div class="alert alert-danger"><?= View::e((string) $flashErro) ?></div>
<?php endif; ?>

<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title"><?= $isEdit ? 'Editar usuário' : 'Novo usuário' ?></h2>
            <p class="card-subtitle">Dados de acesso e perfis vinculados</p>
        </div>
        <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
            <?php if ($isEdit): ?>
                <form method="post" action="/rh/usuarios/<?= (int) $usuario['id'] ?>/enviar-reset"
                      onsubmit="return confirm('Enviar e-mail de redefinição de senha para este usuário?');">
                    <button class="btn btn-secondary btn-sm" type="submit">Enviar reset por e-mail</button>
                </form>
            <?php endif; ?>
            <a class="btn btn-ghost btn-sm" href="/rh/usuarios">Voltar</a>
        </div>
    </div>
    <form method="post" action="<?= $isEdit ? '/rh/usuarios/' . (int) $usuario['id'] : '/rh/usuarios' ?>" class="card-body form-grid cols-2">
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
            <input class="form-control" type="tel" name="telefone" value="<?= View::e((string) ($usuario['telefone'] ?? '')) ?>">
        </div>
        <div class="form-field">
            <label class="form-label">Status</label>
            <select class="form-control" name="status">
                <?php foreach (['ativo', 'inativo'] as $st): ?>
                    <option value="<?= $st ?>" <?= (($usuario['status'] ?? 'ativo') === $st) ? 'selected' : '' ?>><?= $st ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-field">
            <label class="form-label"><?= $isEdit ? 'Nova senha (opcional)' : 'Senha' ?></label>
            <input class="form-control" type="password" name="senha" <?= $isEdit ? '' : 'required' ?> minlength="8" autocomplete="new-password"
                   placeholder="Mín. 8 caracteres, letras e números">
        </div>
        <?php if ($isEdit): ?>
            <div class="form-field">
                <label class="form-label">Último acesso</label>
                <input class="form-control" type="text" disabled value="<?= View::e(
                    !empty($usuario['last_login_at'])
                        ? date('d/m/Y H:i', strtotime((string) $usuario['last_login_at']))
                        : 'Nunca'
                ) ?>">
            </div>
        <?php else: ?>
            <div class="form-field" style="display:flex;align-items:flex-end;">
                <label class="text-sm" style="display:flex;gap:.5rem;align-items:center;">
                    <input type="checkbox" name="enviar_boas_vindas" value="1" checked>
                    Enviar e-mail com link para definir a senha (sem enviar senha em claro)
                </label>
            </div>
        <?php endif; ?>
        <div class="form-field" style="grid-column:1/-1;">
            <label class="form-label">Perfis</label>
            <div style="display:grid;gap:.45rem;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));">
                <?php foreach ($perfis as $p): ?>
                    <label class="text-sm" style="border:1px solid var(--border);border-radius:8px;padding:.65rem;display:flex;gap:.5rem;align-items:flex-start;">
                        <input type="checkbox" name="perfil_ids[]" value="<?= (int) $p['id'] ?>"
                            <?= !empty($selected[(int) $p['id']]) ? 'checked' : '' ?>>
                        <span>
                            <strong><?= View::e((string) $p['nome']) ?></strong>
                            <div class="text-xs muted"><?= View::e((string) ($p['descricao'] ?? $p['codigo'])) ?></div>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="stack-row" style="grid-column:1/-1;">
            <button class="btn btn-primary" type="submit">Salvar</button>
            <a class="btn btn-ghost" href="/rh/usuarios">Cancelar</a>
        </div>
    </form>
</section>
