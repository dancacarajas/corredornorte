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
/** @var list<array{id:int,data_inicio:string,data_fim:string,status:string,espelhos:int,label:string}> $periodosGerados */
$periodosGerados = $periodosGerados ?? [];
$divergenciasAbertas = $divergenciasAbertas ?? [];
?>

<?php if (empty($dbOk)): ?>
    <div class="alert alert-warning">MySQL indisponível. Rode <code>php scripts/install_db.php</code>.</div>
<?php endif; ?>
<?php if (!empty($erroPeriodo)): ?>
    <div class="alert alert-danger" role="alert"><?= View::e($erroPeriodo) ?></div>
<?php endif; ?>
<?php if ($divergenciasAbertas !== []): ?>
    <section class="card" style="border-left:4px solid var(--brand-primary);background:linear-gradient(180deg,#FFF7ED 0%,#FFFFFF 65%);" aria-label="Divergências reportadas pelos colaboradores">
        <div class="card-header">
            <div>
                <h2 class="card-title" style="color:#9A3412;">Divergências de ponto aguardando análise do RH (<?= count($divergenciasAbertas) ?>)</h2>
                <p class="card-subtitle">Colaboradores pausaram a assinatura no portal e solicitaram revisão.</p>
            </div>
        </div>
        <div class="card-body" style="display:grid;gap:.65rem;">
            <?php foreach ($divergenciasAbertas as $div):
                $qsDiv = http_build_query([
                    'data_inicio' => (string) ($div['data_inicio'] ?? ''),
                    'data_fim' => (string) ($div['data_fim'] ?? ''),
                ]);
                ?>
                <div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:.75rem;padding:.75rem .9rem;border:1px solid #FED7AA;border-radius:10px;background:#FFFFFF;">
                    <div style="min-width:220px;flex:1 1 280px;">
                        <div class="fw-700">
                            <?= View::e((string) $div['colaborador_nome']) ?>
                            <span class="muted text-sm">(Mat. <?= View::e((string) ($div['matricula'] ?? '—')) ?>)</span>
                            <?php if (!empty($div['data_ocorrencia'])): ?>
                                · <span class="badge-danger" style="padding:.15rem .45rem;border-radius:999px;font-size:.75rem;">Dia <?= View::e(date('d/m/Y', strtotime((string) $div['data_ocorrencia']))) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="text-sm" style="margin-top:.25rem;color:#334155;">“<?= View::e((string) $div['motivo']) ?>”</div>
                    </div>
                    <a class="btn btn-primary btn-sm" href="/rh/ponto/<?= (int) $div['espelho_id'] ?>?<?= View::e($qsDiv) ?>">Abrir ponto e corrigir</a>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Períodos com ponto gerado</h2>
            <p class="card-subtitle">Selecione um período já gerado para ver o andamento das assinaturas.</p>
        </div>
    </div>
    <div class="card-body section-stack">
        <?php if ($periodosGerados === []): ?>
            <p class="muted" style="margin:0;">Nenhum período com espelho gerado ainda. Gere o ponto em <a href="/rh/ponto">Gestão de Ponto</a>.</p>
        <?php else: ?>
            <div style="display:flex;flex-wrap:wrap;gap:.5rem;">
                <?php foreach ($periodosGerados as $pg):
                    $ativo = $pg['data_inicio'] === $dataInicio && $pg['data_fim'] === $dataFim;
                    ?>
                    <a class="btn <?= $ativo ? 'btn-primary' : 'btn-secondary' ?> btn-sm"
                       href="/?<?= View::e(http_build_query([
                           'data_inicio' => $pg['data_inicio'],
                           'data_fim' => $pg['data_fim'],
                       ])) ?>">
                        <?= View::e($pg['label']) ?>
                        <span class="muted" style="<?= $ativo ? 'color:rgba(255,255,255,.85)' : '' ?>">
                            · <?= (int) $pg['espelhos'] ?> espelho(s)
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

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
            <p class="card-subtitle">Status de assinatura no período selecionado</p>
        </div>
    </div>

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
