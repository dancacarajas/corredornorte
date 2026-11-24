<?php

use App\Helpers\Csrf;
use App\Helpers\View;

$itens = $itens ?? [];
$editando = is_array($editando ?? null) ? $editando : null;
$flash = $flash ?? null;
$error = $error ?? null;
$dbOk = $dbOk ?? true;
$podeGerir = !empty($podeGerir);
$categoria = (string) ($categoria ?? 'equipamento');
$basePath = (string) ($basePath ?? '/rh/entregas-equipamentos/equipamentos');
$isEdit = $editando !== null;
$titulo = $categoria === 'periferico' ? 'Periféricos' : 'Equipamentos';
?>

<?php if (!$dbOk): ?><div class="alert alert-warning">Banco indisponível.</div><?php endif; ?>
<?php if ($flash): ?><div class="alert alert-success"><?= View::e((string) $flash) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= View::e((string) $error) ?></div><?php endif; ?>

<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title"><?= View::e($titulo) ?></h2>
            <p class="card-subtitle">Cadastre os itens usados nas entregas (lista suspensa no formulário).</p>
        </div>
        <a class="btn btn-secondary btn-sm" href="/rh/entregas-equipamentos">Ver entregas</a>
    </div>
    <div class="card-body section-stack">
        <?php if ($podeGerir): ?>
        <form method="post" action="<?= View::e($basePath) ?>" class="form-grid cols-2" style="align-items:end;">
            <?= Csrf::field() ?>
            <?php if ($isEdit): ?>
                <input type="hidden" name="id" value="<?= (int) $editando['id'] ?>">
            <?php endif; ?>
            <div class="form-field">
                <label class="form-label">Nome *</label>
                <input class="form-control" name="nome" maxlength="120" required
                       value="<?= View::e((string) ($editando['nome'] ?? '')) ?>"
                       placeholder="<?= $categoria === 'periferico' ? 'Ex.: Mouse, Headset' : 'Ex.: Notebook, Celular' ?>">
            </div>
            <div class="form-field">
                <label class="form-label">Status</label>
                <select class="form-control" name="status">
                    <option value="ativo" <?= (($editando['status'] ?? 'ativo') === 'ativo') ? 'selected' : '' ?>>Ativo</option>
                    <option value="inativo" <?= (($editando['status'] ?? '') === 'inativo') ? 'selected' : '' ?>>Inativo</option>
                </select>
            </div>
            <div class="form-field" style="grid-column:1/-1;display:flex;gap:.5rem;flex-wrap:wrap;">
                <button class="btn btn-primary" type="submit"><?= $isEdit ? 'Salvar alterações' : 'Adicionar' ?></button>
                <?php if ($isEdit): ?>
                    <a class="btn btn-secondary" href="<?= View::e($basePath) ?>">Cancelar edição</a>
                <?php endif; ?>
            </div>
        </form>
        <hr>
        <?php endif; ?>

        <?php if ($itens === []): ?>
            <div class="empty-state">
                <h3>Nenhum item</h3>
                <p>Cadastre o primeiro <?= $categoria === 'periferico' ? 'periférico' : 'equipamento' ?>.</p>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                    <tr>
                        <th>Nome</th>
                        <th>Status</th>
                        <?php if ($podeGerir): ?><th></th><?php endif; ?>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($itens as $row): ?>
                        <tr>
                            <td><strong><?= View::e((string) ($row['nome'] ?? '')) ?></strong></td>
                            <td><span class="badge"><?= View::e((string) ($row['status'] ?? '')) ?></span></td>
                            <?php if ($podeGerir): ?>
                            <td style="text-align:right;white-space:nowrap;">
                                <a class="btn btn-secondary btn-sm" href="<?= View::e($basePath) ?>?editar=<?= (int) $row['id'] ?>">Editar</a>
                                <?php if (($row['status'] ?? '') !== 'inativo'): ?>
                                <form method="post" action="<?= View::e($basePath) ?>/<?= (int) $row['id'] ?>/excluir"
                                      style="display:inline;" data-confirm="Inativar este item?">
                                    <?= Csrf::field() ?>
                                    <button class="btn btn-secondary btn-sm" type="submit">Inativar</button>
                                </form>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>
