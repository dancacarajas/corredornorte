<?php

use App\Helpers\View;

$folhas = $folhas ?? [];
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
            <h2 class="card-title">Folha de ponto</h2>
            <p class="card-subtitle">Quando o RH gera o link na medição, a folha aparece aqui para você assinar.</p>
        </div>
    </div>
    <div class="card-body" style="padding-top:0;">
        <?php if ($folhas === []): ?>
            <div class="empty-state">
                <h3>Nenhuma folha liberada</h3>
                <p>Assim que a medição gerar o link de assinatura do seu cartão, ele ficará disponível nesta lista.</p>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                    <tr>
                        <th>Período</th>
                        <th>Status</th>
                        <th>Assinado em</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($folhas as $f):
                        $ini = (string) ($f['data_inicio'] ?? '');
                        $fim = (string) ($f['data_fim'] ?? '');
                        $rotulo = ($ini && $fim)
                            ? date('d/m/Y', strtotime($ini)) . ' a ' . date('d/m/Y', strtotime($fim))
                            : '—';
                        $st = (string) ($f['status'] ?? '');
                        $assinadoEm = (string) ($f['assinatura_em'] ?? '');
                        ?>
                        <tr>
                            <td><?= View::e($rotulo) ?></td>
                            <td>
                                <span class="pill <?= $st === 'assinado' ? 'ra-status-aprovado' : 'ra-status-enviado' ?>">
                                    <?= $st === 'assinado' ? 'Assinada' : 'Aguardando assinatura' ?>
                                </span>
                            </td>
                            <td>
                                <?= $assinadoEm !== ''
                                    ? View::e(date('d/m/Y H:i', strtotime($assinadoEm) ?: time()))
                                    : '—' ?>
                            </td>
                            <td>
                                <a class="btn <?= $st === 'assinado' ? 'btn-secondary' : 'btn-primary' ?> btn-sm"
                                   href="/he/folhas/<?= (int) $f['id'] ?>">
                                    <?= $st === 'assinado' ? 'Abrir' : 'Assinar agora' ?>
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
