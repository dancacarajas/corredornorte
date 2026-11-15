<?php

use App\Helpers\Csrf;
use App\Helpers\View;

/** @var list<array<string,mixed>> $tipos */
/** @var bool $podeEditar */
/** @var bool $dbOk */
/** @var int $editId */
/** @var ?string $flashOk */
/** @var ?string $flashErro */

$tipos = $tipos ?? [];
$podeEditar = (bool) ($podeEditar ?? false);
$editId = (int) ($editId ?? 0);
$editRow = null;
foreach ($tipos as $t) {
    if ((int) ($t['id'] ?? 0) === $editId) {
        $editRow = $t;
        break;
    }
}
?>

<?php if (empty($dbOk)): ?>
    <div class="alert alert-warning" role="alert">Banco indisponível.</div>
<?php endif; ?>
<?php if (!empty($flashOk)): ?>
    <div class="alert alert-success" role="alert"><?= View::e((string) $flashOk) ?></div>
<?php endif; ?>
<?php if (!empty($flashErro)): ?>
    <div class="alert alert-danger" role="alert"><?= View::e((string) $flashErro) ?></div>
<?php endif; ?>

<section class="card" style="margin-bottom:1rem;">
    <div class="card-body">
        <p class="muted" style="margin:0;">
            No cartão de ponto, dia útil <strong>sem marcação</strong> vira <strong>falta</strong>.
            Tipos com <strong>Abona falta</strong> removem essa falta nos dias da justificativa (status Justificado, sem desconto).
            Tipos já usados não são apagados — ficam <strong>inativos</strong> e deixam de abonar.
            <a href="/rh/ponto">Voltar à Gestão de Ponto</a>
        </p>
    </div>
</section>

<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Tipos cadastrados</h2>
            <p class="card-subtitle"><?= count($tipos) ?> registro(s)</p>
        </div>
    </div>
    <?php if ($tipos === []): ?>
        <div class="empty-state">
            <h3>Nenhum tipo</h3>
            <p>Cadastre o primeiro tipo abaixo.</p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>Código</th>
                    <th>Nome</th>
                    <th>Abona falta</th>
                    <th>Status</th>
                    <th>Usos</th>
                    <?php if ($podeEditar): ?><th></th><?php endif; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($tipos as $t):
                    $id = (int) $t['id'];
                    $ativo = ((string) ($t['status'] ?? '')) === 'ativo';
                    $usos = (int) ($t['usos'] ?? 0);
                    ?>
                    <tr>
                        <td><code><?= View::e((string) $t['codigo']) ?></code></td>
                        <td class="fw-700"><?= View::e((string) $t['nome']) ?></td>
                        <td><?= !empty($t['abona_falta']) ? 'Sim' : 'Não' ?></td>
                        <td>
                            <span class="pill <?= $ativo ? 'pill-brand' : 'pill-neutral' ?>">
                                <?= $ativo ? 'Ativo' : 'Inativo' ?>
                            </span>
                        </td>
                        <td><?= $usos ?></td>
                        <?php if ($podeEditar): ?>
                            <td>
                                <div class="stack-row" style="justify-content:flex-end;gap:.35rem;">
                                    <a class="btn btn-secondary btn-sm" href="/rh/ponto/justificativa-tipos?editar=<?= $id ?>#formTipo">Editar</a>
                                    <form method="post" action="/rh/ponto/justificativa-tipos/<?= $id ?>/excluir" style="display:inline;"
                                          onsubmit="return confirm('<?= $usos > 0 ? 'Este tipo já foi usado. Desativar?' : 'Excluir este tipo?' ?>');">
                                        <input type="hidden" name="_csrf" value="<?= View::e(Csrf::token()) ?>">
                                        <button class="btn btn-ghost btn-sm" type="submit"><?= $usos > 0 ? 'Desativar' : 'Excluir' ?></button>
                                    </form>
                                </div>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php if ($podeEditar): ?>
<section class="card" id="formTipo" style="margin-top:1rem;">
    <div class="card-header">
        <div>
            <h2 class="card-title" id="novoTipo"><?= $editRow ? 'Editar tipo' : 'Novo tipo' ?></h2>
            <p class="card-subtitle">
                <?= $editRow
                    ? 'Alterando <code>' . View::e((string) $editRow['codigo']) . '</code>'
                    : 'Código vira identificador interno (ex.: atestado, ferias).' ?>
            </p>
        </div>
        <?php if ($editRow): ?>
            <a class="btn btn-ghost btn-sm" href="/rh/ponto/justificativa-tipos">Cancelar edição</a>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <form method="post"
              action="<?= $editRow ? '/rh/ponto/justificativa-tipos/' . (int) $editRow['id'] : '/rh/ponto/justificativa-tipos' ?>"
              class="form-grid cols-2">
            <input type="hidden" name="_csrf" value="<?= View::e(Csrf::token()) ?>">
            <div class="form-field">
                <label class="form-label" for="jtCodigo">Código</label>
                <input class="form-control" id="jtCodigo" name="codigo" required maxlength="40"
                       pattern="[A-Za-z0-9_]+"
                       value="<?= View::e((string) ($editRow['codigo'] ?? '')) ?>"
                       placeholder="ex.: atestado">
            </div>
            <div class="form-field">
                <label class="form-label" for="jtNome">Nome</label>
                <input class="form-control" id="jtNome" name="nome" required maxlength="120"
                       value="<?= View::e((string) ($editRow['nome'] ?? '')) ?>"
                       placeholder="ex.: Atestado médico">
            </div>
            <div class="form-field">
                <label class="form-label" for="jtStatus">Status</label>
                <select class="form-control" id="jtStatus" name="status">
                    <option value="ativo" <?= (($editRow['status'] ?? 'ativo') === 'ativo') ? 'selected' : '' ?>>Ativo</option>
                    <option value="inativo" <?= (($editRow['status'] ?? '') === 'inativo') ? 'selected' : '' ?>>Inativo</option>
                </select>
            </div>
            <div class="form-field" style="justify-content:end;">
                <label class="form-label" style="visibility:hidden;">Abona</label>
                <label class="text-sm" style="display:flex;align-items:center;gap:.45rem;min-height:44px;">
                    <input type="checkbox" name="abona_falta" value="1" <?= ($editRow !== null && !empty($editRow['abona_falta'])) ? 'checked' : '' ?>>
                    Abona falta no cálculo (dia sem batida → Justificado)
                </label>
            </div>
            <div class="form-field" style="grid-column:1/-1;">
                <button class="btn btn-primary" type="submit"><?= $editRow ? 'Salvar alterações' : 'Cadastrar tipo' ?></button>
            </div>
        </form>
    </div>
</section>
<?php endif; ?>
