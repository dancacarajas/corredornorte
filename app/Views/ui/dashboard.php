<?php

use App\Helpers\View;

/** @var bool $dbOk */
/** @var array<string,int> $kpis */
/** @var string $periodoLabel */
/** @var string $dataInicio */
/** @var string $dataFim */
/** @var string $consultaPeriodo */
/** @var string|null $erroPeriodo */
/** @var list<array<string,mixed>> $colaboradores */
?>

<?php if (empty($dbOk)): ?>
    <div class="alert alert-warning">MySQL indisponível. Rode <code>php scripts/install_db.php</code>.</div>
<?php endif; ?>
<?php if (!empty($erroPeriodo)): ?>
    <div class="alert alert-danger" role="alert"><?= View::e($erroPeriodo) ?></div>
<?php endif; ?>

<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Período de <?= View::e($periodoLabel) ?></h2>
            <p class="card-subtitle">Cada colaborador é contado uma vez. Os status consideram o período completo.</p>
        </div>
    </div>
    <div class="card-body">
        <div class="kpi-grid">
            <article class="kpi-card" style="box-shadow:none;border:1px solid var(--border);border-radius:12px;">
                <div class="kpi-label">Esperadas</div>
                <div class="kpi-value"><?= (int) ($kpis['esperadas'] ?? 0) ?></div>
            </article>
            <article class="kpi-card" style="box-shadow:none;border:1px solid var(--border);border-radius:12px;">
                <div class="kpi-label">Tratadas</div>
                <div class="kpi-value"><?= (int) ($kpis['tratadas'] ?? 0) ?></div>
            </article>
            <article class="kpi-card" style="box-shadow:none;border:1px solid var(--border);border-radius:12px;">
                <div class="kpi-label">Fechadas</div>
                <div class="kpi-value"><?= (int) ($kpis['fechadas'] ?? 0) ?></div>
            </article>
            <article class="kpi-card" style="box-shadow:none;border:1px solid var(--border);border-radius:12px;">
                <div class="kpi-label">Enviadas / Assinadas</div>
                <div class="kpi-value" style="font-size:1.35rem;">
                    <?= (int) ($kpis['enviadas'] ?? 0) ?> / <?= (int) ($kpis['assinadas'] ?? 0) ?>
                </div>
            </article>
        </div>
    </div>
</section>

<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Colaboradores</h2>
            <p class="card-subtitle">Status de assinatura no período</p>
        </div>
    </div>
    <form method="get" action="/" class="card-body form-grid cols-3" style="padding-bottom:0;">
        <div class="form-field">
            <label class="form-label">Período inicial</label>
            <input class="form-control" type="date" name="data_inicio" value="<?= View::e($dataInicio) ?>" required>
        </div>
        <div class="form-field">
            <label class="form-label">Período final</label>
            <input class="form-control" type="date" name="data_fim" value="<?= View::e($dataFim) ?>" required>
        </div>
        <div class="form-field" style="display:flex;align-items:flex-end;">
            <button class="btn btn-primary" type="submit">Filtrar</button>
        </div>
    </form>

    <div class="table-wrap">
        <table class="table">
            <thead>
            <tr>
                <th>Matrícula</th>
                <th>Colaborador</th>
                <th>Localidade</th>
                <th>Lotação</th>
                <th>Status</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php if ($colaboradores === []): ?>
                <tr><td colspan="6" class="muted">Nenhum colaborador ativo no período.</td></tr>
            <?php else: ?>
                <?php foreach ($colaboradores as $row):
                    $assinado = $row['periodo_assinado'];
                    $statusLabel = $assinado ? 'Assinado' : ($row['periodo_enviado'] ? 'Enviado' : 'Pendente');
                    $bg = $assinado ? 'background:var(--brand-primary-soft);' : '';
                ?>
                    <tr style="<?= $bg ?>">
                        <td class="fw-700"><?= View::e((string) $row['matricula']) ?></td>
                        <td><?= View::e((string) $row['nome']) ?></td>
                        <td>
                            <?php if (!empty($row['municipio'])): ?>
                                <?= View::e((string) $row['municipio']) ?>/<?= View::e((string) $row['uf']) ?>
                            <?php else: ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?= View::e((string) ($row['lotacao_codigo'] ?? '—')) ?></td>
                        <td>
                            <span class="pill <?= $assinado ? 'pill-brand' : 'pill-neutral' ?>"><?= View::e($statusLabel) ?></span>
                        </td>
                        <td style="white-space:nowrap;">
                            <?php if (!empty($row['espelho_id'])): ?>
                                <a class="btn btn-ghost btn-sm" href="/rh/ponto/<?= (int) $row['espelho_id'] ?>?<?= View::e($consultaPeriodo) ?>">Abrir ponto</a>
                                <a class="btn btn-secondary btn-sm" href="/rh/folha/<?= (int) $row['espelho_id'] ?>?<?= View::e($consultaPeriodo) ?>">Folha</a>
                            <?php else: ?>
                                <span class="muted text-sm">Sem espelho no período</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
