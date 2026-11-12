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
?>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger"><?= View::e((string) $error) ?></div>
<?php endif; ?>

<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title"><?= $isEdit ? 'Editar usuário' : 'Novo usuário' ?></h2>
            <p class="card-subtitle">Dados de acesso e perfis vinculados</p>
        </div>
        <a class="btn btn-ghost btn-sm" href="/rh/usuarios">Voltar</a>
    </div>
    <form method="post" action="<?= $isEdit ? '/rh/usuarios/' . (int) $usuario['id'] : '/rh/usuarios' ?>" class="card-body form-grid cols-2">
        <div class="form-field">
            <label class="form-label">Nome</label>
            <input class="form-control" name="nome" required value="<?= View::e((string) ($usuario['nome'] ?? '')) ?>">
        </div>
        <div class="form-field">
            <label class="form-label">E-mail</label>
            <input class="form-control" type="email" name="email" required value="<?= View::e((string) ($usuario['email'] ?? '')) ?>">
        </div>
        <div class="form-field">
            <label class="form-label"><?= $isEdit ? 'Nova senha (opcional)' : 'Senha' ?></label>
            <input class="form-control" type="password" name="senha" <?= $isEdit ? '' : 'required' ?> minlength="6" autocomplete="new-password">
        </div>
        <div class="form-field">
            <label class="form-label">Status</label>
            <select class="form-control" name="status">
                <?php foreach (['ativo', 'inativo'] as $st): ?>
                    <option value="<?= $st ?>" <?= (($usuario['status'] ?? 'ativo') === $st) ? 'selected' : '' ?>><?= $st ?></option>
                <?php endforeach; ?>
            </select>
        </div>
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
