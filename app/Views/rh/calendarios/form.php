<?php use App\Helpers\View; /** @var array $row */ /** @var array $lookups */ /** @var string $action */ /** @var array $errors */ /** @var bool $dbOk */ ?>
<?php if (!empty($errors['geral'])): ?><div class="alert alert-danger"><?= View::e($errors['geral']) ?></div><?php endif; ?>
<section class="card">
    <div class="card-header"><h2 class="card-title">Calendário</h2><a class="btn btn-secondary btn-sm" href="/rh/calendarios">Voltar</a></div>
    <form method="post" action="<?= View::e($action) ?>" class="card-body form-grid cols-3">
        <div class="form-field"><label class="form-label">Código</label><input class="form-control" name="codigo" required value="<?= View::e((string)($row['codigo']??'')) ?>"></div>
        <div class="form-field"><label class="form-label">Nome</label><input class="form-control" name="nome" required value="<?= View::e((string)($row['nome']??'')) ?>"></div>
        <div class="form-field"><label class="form-label">Escopo</label>
            <select class="form-control" name="escopo" required>
                <?php foreach (['nacional','estadual','municipal','contrato'] as $e): ?>
                    <option value="<?= $e ?>" <?= (($row['escopo']??'')===$e)?'selected':'' ?>><?= ucfirst($e) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-field"><label class="form-label">UF</label><input class="form-control" name="uf" maxlength="2" value="<?= View::e((string)($row['uf']??'')) ?>"></div>
        <div class="form-field"><label class="form-label">Localidade</label>
            <select class="form-control" name="localidade_id"><option value="">—</option>
                <?php foreach (($lookups['localidades']??[]) as $o): ?>
                    <option value="<?= (int)$o['id'] ?>" <?= ((string)($row['localidade_id']??'')===(string)$o['id'])?'selected':'' ?>><?= View::e((string)$o['label']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-field"><label class="form-label">Contrato</label>
            <select class="form-control" name="contrato_id"><option value="">—</option>
                <?php foreach (($lookups['contratos']??[]) as $o): ?>
                    <option value="<?= (int)$o['id'] ?>" <?= ((string)($row['contrato_id']??'')===(string)$o['id'])?'selected':'' ?>><?= View::e((string)$o['label']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-field"><label class="form-label">Status</label>
            <select class="form-control" name="status">
                <option value="ativo" <?= (($row['status']??'ativo')==='ativo')?'selected':'' ?>>Ativo</option>
                <option value="inativo" <?= (($row['status']??'')==='inativo')?'selected':'' ?>>Inativo</option>
            </select>
        </div>
        <div class="stack-row" style="grid-column:1/-1;">
            <button class="btn btn-primary" type="submit" <?= $dbOk?'':'disabled' ?>>Salvar</button>
        </div>
    </form>
</section>
