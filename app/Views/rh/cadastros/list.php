<?php

use App\Helpers\Auth;
use App\Helpers\Csrf;
use App\Helpers\View;

/** @var bool $dbOk */
/** @var list<string> $columns */
/** @var list<array<string,mixed>> $rows */
/** @var string $entityLabel */
?>

<?php if (!$dbOk): ?>
    <div class="alert alert-warning">
        Banco indisponível. Configure o MySQL e importe o schema do Efetivo para popular este cadastro.
    </div>
<?php endif; ?>
<?php if (($flashOk ?? null) === 'excluido'): ?>
    <div class="alert alert-success">Registro excluído (inativado).</div>
<?php endif; ?>
<?php if (!empty($flashErro)): ?>
    <div class="alert alert-danger"><?= View::e((string) $flashErro) ?></div>
<?php endif; ?>

<?php
$entity = $entity ?? '';
$mapaCad = $entity !== '' ? (\App\Services\PerfilService::mapaCadastrosMenu()[$entity] ?? null) : null;
$podeCriar = $mapaCad
    ? Auth::canAny($mapaCad['criar'], $mapaCad['editar'], 'cadastros.editar')
    : Auth::can('cadastros.editar');
$podeEditar = $mapaCad
    ? Auth::canAny($mapaCad['editar'], 'cadastros.editar')
    : Auth::can('cadastros.editar');
$podeExcluir = $podeExcluir ?? ($mapaCad
    ? Auth::canAny($mapaCad['excluir'], 'cadastros.editar')
    : Auth::can('cadastros.editar'));
?>
<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title"><?= View::e($entityLabel) ?></h2>
            <p class="card-subtitle"><?= count($rows) ?> registro(s)</p>
        </div>
        <div style="display:flex;gap:.5rem;">
            <?php if ($entity !== '' && $podeCriar): ?>
                <a class="btn btn-primary btn-sm" href="/rh/<?= View::e($entity) ?>/novo">Novo</a>
            <?php endif; ?>
            <a class="btn btn-secondary btn-sm" href="/rh/efetivo">Voltar ao Efetivo</a>
        </div>
    </div>

    <?php if ($rows === []): ?>
        <div class="empty-state">
            <h3>Sem registros</h3>
            <p>Este cadastro ainda está vazio.</p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>ID</th>
                    <?php foreach ($columns as $col): ?>
                        <th><?= View::e(strtoupper(str_replace('_', ' ', $col))) ?></th>
                    <?php endforeach; ?>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php
                    $jaInativo = isset($row['status']) && (string) $row['status'] === 'inativo';
                    ?>
                    <tr>
                        <td><?= (int) $row['id'] ?></td>
                        <?php foreach ($columns as $col): ?>
                            <td><?php
                                $val = (string) ($row[$col] ?? '');
                                if ($col === 'percentual_he' && $val !== '') {
                                    $val .= '%';
                                }
                                echo View::e($val);
                            ?></td>
                        <?php endforeach; ?>
                        <td style="white-space:nowrap;">
                            <?php if ($entity !== '' && $podeEditar): ?>
                                <a class="btn btn-ghost btn-sm" href="/rh/<?= View::e($entity) ?>/<?= (int) $row['id'] ?>/editar">Editar</a>
                            <?php endif; ?>
                            <?php if ($entity === 'localidades'): ?>
                                <a class="btn btn-secondary btn-sm" href="/rh/localidades/<?= (int) $row['id'] ?>/feriados">Feriados</a>
                            <?php endif; ?>
                            <?php if ($entity !== '' && $podeExcluir && !$jaInativo): ?>
                                <form method="post" action="/rh/<?= View::e($entity) ?>/<?= (int) $row['id'] ?>/excluir" style="display:inline;"
                                      data-confirm-title="Excluir registro?"
                                      data-confirm="Inativa este cadastro. Ele deixa de aparecer nas listas ativas."
                                      data-confirm-ok="Excluir"
                                      data-confirm-tone="danger">
                                    <?= Csrf::field() ?>
                                    <button class="btn btn-ghost btn-sm" type="submit" style="color:#B42318;">Excluir</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
