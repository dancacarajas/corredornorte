<?php

use App\Helpers\View;

$entregas = $entregas ?? [];
$q = $q ?? '';
$status = $status ?? '';
$flash = $flash ?? null;
$error = $error ?? null;
$dbOk = $dbOk ?? true;
$podeGerir = !empty($podeGerir);

$labels = [
    'rascunho' => 'Rascunho',
    'emitido' => 'Emitido',
    'assinado_anexado' => 'Assinado anexado',
    'devolvido' => 'Devolvido',
    'cancelado' => 'Cancelado',
];
?>

<?php if (!$dbOk): ?><div class="alert alert-warning">Banco indisponível.</div><?php endif; ?>
<?php if ($flash): ?><div class="alert alert-success"><?= View::e((string) $flash) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= View::e((string) $error) ?></div><?php endif; ?>

<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Entrega de Equipamentos</h2>
            <p class="card-subtitle">Cadastro, emissão do termo COR-JUR-FM-005 e devolução.</p>
        </div>
        <?php if ($podeGerir): ?>
            <a class="btn btn-primary" href="/rh/entregas-equipamentos/nova">Nova entrega</a>
        <?php endif; ?>
    </div>

    <form method="get" class="card-body ponto-filtros" style="padding-top:0;">
        <div class="form-field">
            <label class="form-label">Busca</label>
            <input class="form-control" type="search" name="q" value="<?= View::e((string) $q) ?>"
                   placeholder="Código, nome, CPF ou matrícula">
        </div>
        <div class="form-field">
            <label class="form-label">Status</label>
            <select class="form-control" name="status">
                <option value="">Todos</option>
                <?php foreach ($labels as $k => $lab): ?>
                    <option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= View::e($lab) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-field ponto-filtros-actions">
            <button class="btn btn-secondary" type="submit">Filtrar</button>
        </div>
    </form>

    <div class="card-body" style="padding-top:0;">
        <?php if ($entregas === []): ?>
            <div class="empty-state">
                <h3>Nenhuma entrega</h3>
                <p>Crie uma entrega para emitir o termo de comodato.</p>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                    <tr>
                        <th>Código</th>
                        <th>Colaborador</th>
                        <th>Matrícula</th>
                        <th>Data termo</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($entregas as $e):
                        $st = (string) ($e['status'] ?? '');
                        $dt = (string) ($e['data_termo'] ?? '');
                        ?>
                        <tr>
                            <td><strong><?= View::e((string) ($e['codigo'] ?? '')) ?></strong></td>
                            <td><?= View::e((string) ($e['colab_nome'] ?? '')) ?></td>
                            <td><?= View::e((string) ($e['matricula'] ?? '—')) ?></td>
                            <td><?= $dt !== '' ? View::e(date('d/m/Y', strtotime($dt) ?: time())) : '—' ?></td>
                            <td><span class="badge"><?= View::e($labels[$st] ?? $st) ?></span></td>
                            <td style="text-align:right;">
                                <a class="btn btn-secondary btn-sm" href="/rh/entregas-equipamentos/<?= (int) $e['id'] ?>">Abrir</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>
