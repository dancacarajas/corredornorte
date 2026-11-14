<?php

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

<?php $entity = $entity ?? ''; ?>
<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title"><?= View::e($entityLabel) ?></h2>
            <p class="card-subtitle"><?= count($rows) ?> registro(s)</p>
        </div>
        <div style="display:flex;gap:.5rem;">
            <?php if ($entity !== ''): ?>
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
                            <?php if ($entity !== ''): ?>
                                <a class="btn btn-ghost btn-sm" href="/rh/<?= View::e($entity) ?>/<?= (int) $row['id'] ?>/editar">Editar</a>
                                <?php if ($entity === 'localidades'): ?>
                                    <a class="btn btn-secondary btn-sm" href="/rh/localidades/<?= (int) $row['id'] ?>/feriados">Feriados</a>
                                <?php endif; ?>
                                <?php if ($entity === 'escalas'): ?>
                                    <a class="btn btn-secondary btn-sm" href="/rh/escalas/<?= (int) $row['id'] ?>">Ciclo</a>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
