<?php

use App\Helpers\View;

/** @var ?array<string,mixed> $perfil */
/** @var list<array<string,mixed>> $permissoes */
/** @var ?string $error */

$isEdit = !empty($perfil['id']);
$selected = [];
foreach (($perfil['permissao_ids'] ?? []) as $pid) {
    $selected[(int) $pid] = true;
}

$grupos = [];
foreach ($permissoes as $perm) {
    $g = (string) ($perm['grupo'] ?? 'Outros');
    $grupos[$g][] = $perm;
}
?>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger"><?= View::e((string) $error) ?></div>
<?php endif; ?>

<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title"><?= $isEdit ? 'Editar perfil' : 'Novo perfil' ?></h2>
            <p class="card-subtitle">Marque as permissões deste papel</p>
        </div>
        <a class="btn btn-ghost btn-sm" href="/rh/perfis">Voltar</a>
    </div>
    <form method="post" action="<?= $isEdit ? '/rh/perfis/' . (int) $perfil['id'] : '/rh/perfis' ?>" class="card-body form-grid cols-2">
        <div class="form-field">
            <label class="form-label">Código</label>
            <input class="form-control" name="codigo" required
                   value="<?= View::e((string) ($perfil['codigo'] ?? '')) ?>"
                   <?= $isEdit ? 'readonly' : '' ?>
                   placeholder="ex.: auditor">
        </div>
        <div class="form-field">
            <label class="form-label">Nome</label>
            <input class="form-control" name="nome" required value="<?= View::e((string) ($perfil['nome'] ?? '')) ?>">
        </div>
        <div class="form-field" style="grid-column:1/-1;">
            <label class="form-label">Descrição</label>
            <input class="form-control" name="descricao" value="<?= View::e((string) ($perfil['descricao'] ?? '')) ?>">
        </div>
        <div class="form-field">
            <label class="form-label">Status</label>
            <select class="form-control" name="status" <?= !empty($perfil['sistema']) ? 'disabled' : '' ?>>
                <?php foreach (['ativo', 'inativo'] as $st): ?>
                    <option value="<?= $st ?>" <?= (($perfil['status'] ?? 'ativo') === $st) ? 'selected' : '' ?>><?= $st ?></option>
                <?php endforeach; ?>
            </select>
            <?php if (!empty($perfil['sistema'])): ?>
                <input type="hidden" name="status" value="ativo">
            <?php endif; ?>
        </div>

        <div style="grid-column:1/-1;display:flex;flex-direction:column;gap:1rem;">
            <div class="fw-700">Permissões</div>
            <?php foreach ($grupos as $grupo => $items): ?>
                <div style="border:1px solid var(--border);border-radius:10px;padding:.85rem;">
                    <div class="fw-700 text-sm" style="margin-bottom:.5rem;"><?= View::e($grupo) ?></div>
                    <div style="display:grid;gap:.4rem;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));">
                        <?php foreach ($items as $perm): ?>
                            <label class="text-sm" style="display:flex;gap:.45rem;align-items:flex-start;">
                                <input type="checkbox" name="permissao_ids[]" value="<?= (int) $perm['id'] ?>"
                                    <?= !empty($selected[(int) $perm['id']]) ? 'checked' : '' ?>>
                                <span>
                                    <strong><?= View::e((string) $perm['nome']) ?></strong>
                                    <div class="text-xs muted"><code><?= View::e((string) $perm['codigo']) ?></code></div>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="stack-row" style="grid-column:1/-1;">
            <button class="btn btn-primary" type="submit">Salvar perfil</button>
            <a class="btn btn-ghost" href="/rh/perfis">Cancelar</a>
        </div>
    </form>
</section>
