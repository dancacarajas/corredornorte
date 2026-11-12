<?php

use App\Helpers\View;

/** @var bool $dbOk */
/** @var list<array<string,mixed>> $imports */
?>

<?php if (!$dbOk): ?>
    <div class="alert alert-warning">MySQL indisponível. A importação com prévia exige o schema do Efetivo.</div>
<?php endif; ?>

<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Histórico de importações</h2>
            <p class="card-subtitle">Nenhuma gravação ocorre sem confirmação da prévia</p>
        </div>
    </div>
    <?php if ($imports === []): ?>
        <div class="empty-state">
            <h3>Nenhuma importação ainda</h3>
            <p>Envie a planilha mestre para validar diferenças antes de atualizar o efetivo.</p>
            <div style="margin-top:1rem;">
                <a class="btn btn-primary" href="/rh/importacoes/nova">Nova importação</a>
            </div>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>Arquivo</th>
                    <th>Status</th>
                    <th>Registros</th>
                    <th>Diff</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($imports as $imp): ?>
                    <tr>
                        <td>
                            <div class="fw-700"><?= View::e((string) $imp['arquivo_nome']) ?></div>
                            <div class="text-xs muted"><?= View::e((string) $imp['created_at']) ?></div>
                        </td>
                        <td><span class="pill pill-brand"><?= View::e((string) $imp['status']) ?></span></td>
                        <td><?= (int) $imp['total_registros'] ?></td>
                        <td class="text-sm">
                            =<?= (int) $imp['total_iguais'] ?>
                            · ↑<?= (int) $imp['total_atualizar'] ?>
                            · +<?= (int) $imp['total_criar'] ?>
                            · !<?= (int) $imp['total_erros'] ?>
                        </td>
                        <td><a class="btn btn-ghost btn-sm" href="/rh/importacoes/<?= (int) $imp['id'] ?>">Abrir</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
