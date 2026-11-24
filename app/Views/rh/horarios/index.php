<?php

use App\Helpers\View;

/** @var list<array<string,mixed>> $horarios */
$labelsTipo = ['semanal' => 'Semanal', 'ciclico' => 'Cíclico'];
?>
<?php if (!empty($flash)): ?>
    <div class="alert alert-success">
        <?= $flash === 'migrado' ? 'Horários sincronizados nas lotações.' : 'Horário salvo.' ?>
    </div>
<?php endif; ?>
<?php if (!empty($error)): ?><div class="alert alert-danger"><?= View::e((string) $error) ?></div><?php endif; ?>

<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Horários (modelo RHiD)</h2>
            <p class="card-subtitle">
                Semanal: cada dia da semana com suas batidas (ex.: sexta mais curta).
                Cíclico: 12×36, 5×1 etc. Dia em branco / folga = sem previsto.
            </p>
        </div>
        <a class="btn btn-primary" href="/rh/horarios/novo">Novo horário</a>
    </div>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Código</th>
                    <th>Nome</th>
                    <th>Tipo</th>
                    <th>Dias trabalho</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if ($horarios === []): ?>
                    <tr><td colspan="5" class="muted">Nenhum horário. Abra esta tela de novo para migrar das lotações, ou crie um novo.</td></tr>
                <?php endif; ?>
                <?php foreach ($horarios as $h): ?>
                    <tr>
                        <td><?= View::e((string) $h['codigo']) ?></td>
                        <td><?= View::e((string) $h['nome']) ?></td>
                        <td><span class="pill"><?= View::e($labelsTipo[(string) ($h['tipo'] ?? '')] ?? (string) $h['tipo']) ?></span></td>
                        <td><?= (int) ($h['dias_trabalho'] ?? 0) ?></td>
                        <td><a class="btn btn-secondary btn-sm" href="/rh/horarios/<?= (int) $h['id'] ?>">Editar dias</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
