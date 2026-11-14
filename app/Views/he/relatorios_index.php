<?php

use App\Helpers\View;

$relatorios = $relatorios ?? [];
$flash = $flash ?? null;
$error = $error ?? null;
?>

<?php if ($flash): ?>
    <div class="alert alert-success"><?= View::e((string) $flash) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><?= View::e((string) $error) ?></div>
<?php endif; ?>

<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Meus relatórios</h2>
            <p class="card-subtitle">Gerados automaticamente a partir do período do cartão de ponto.</p>
        </div>
    </div>
    <div class="card-body" style="padding-top:0;">
        <?php if ($relatorios === []): ?>
            <div class="empty-state">
                <h3>Nenhum relatório disponível</h3>
                <p>Quando o RH gerar o cartão de ponto do período, o relatório aparecerá aqui para preenchimento.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                    <tr>
                        <th>Período</th>
                        <th>OS</th>
                        <th>Status</th>
                        <th>Remoto</th>
                        <th>Site</th>
                        <th>Viagem</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($relatorios as $r):
                        $ini = (string) ($r['data_inicio'] ?? '');
                        $fim = (string) ($r['data_fim'] ?? '');
                        $rotulo = ($ini && $fim)
                            ? date('d/m/Y', strtotime($ini)) . ' a ' . date('d/m/Y', strtotime($fim))
                            : '—';
                        $st = (string) ($r['status'] ?? 'rascunho');
                        ?>
                        <tr>
                            <td><?= View::e($rotulo) ?></td>
                            <td><?= View::e((string) ($r['os_codigo'] ?? '—')) ?></td>
                            <td><span class="pill"><?= View::e($st) ?></span></td>
                            <td><?= (int) ($r['dias_remoto'] ?? 0) ?></td>
                            <td><?= (int) ($r['dias_site'] ?? 0) ?></td>
                            <td><?= (int) ($r['dias_viagem'] ?? 0) ?></td>
                            <td>
                                <a class="btn btn-primary btn-sm" href="/he/relatorios/<?= (int) $r['id'] ?>">
                                    <?= $st === 'rascunho' ? 'Preencher' : 'Abrir' ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>
