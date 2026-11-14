<?php

use App\Helpers\View;
use App\Services\FolhaPontoService;
use App\Services\PeriodoPonto;

/** @var bool $dbOk */
/** @var list<array<string,mixed>> $docs */
?>

<?php
$q = $q ?? '';
$statusFiltro = $status ?? '';
$dataInicio = $dataInicio ?? '';
$dataFim = $dataFim ?? '';
?>

<?php if (!$dbOk): ?>
    <div class="alert alert-warning">Banco indisponível.</div>
<?php endif; ?>

<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Documentos gerados</h2>
            <p class="card-subtitle">Controle de PDF, envio e assinatura</p>
        </div>
        <a class="btn btn-secondary btn-sm" href="/rh/ponto">Ir para espelhos</a>
    </div>
    <form method="get" class="card-body form-grid cols-4" style="padding-top:0;align-items:end;">
        <div class="form-field">
            <label class="form-label" for="folhaBusca">Colaborador / Matrícula</label>
            <input class="form-control" id="folhaBusca" type="search" name="q" value="<?= View::e((string) $q) ?>" placeholder="Nome ou matrícula...">
        </div>
        <div class="form-field">
            <label class="form-label" for="folhaStatus">Status</label>
            <select class="form-control" id="folhaStatus" name="status">
                <option value="">Todos</option>
                <?php foreach (['gerado' => 'Gerado', 'enviado' => 'Enviado', 'assinado' => 'Assinado', 'invalidado' => 'Invalidado'] as $k => $lbl): ?>
                    <option value="<?= $k ?>" <?= $statusFiltro === $k ? 'selected' : '' ?>><?= $lbl ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-field">
            <label class="form-label" for="folhaIni">Período início</label>
            <input class="form-control" id="folhaIni" type="date" name="data_inicio" value="<?= View::e((string) $dataInicio) ?>">
        </div>
        <div class="form-field" style="display:flex;gap:.5rem;align-items:end;">
            <div style="flex:1;">
                <label class="form-label" for="folhaFim">Período fim</label>
                <input class="form-control" id="folhaFim" type="date" name="data_fim" value="<?= View::e((string) $dataFim) ?>">
            </div>
            <button class="btn btn-secondary" type="submit">Filtrar</button>
        </div>
    </form>
    <?php if ($docs === []): ?>
        <div class="empty-state">
            <h3>Nenhuma folha encontrada</h3>
            <p><?= ($q !== '' || $statusFiltro !== '' || $dataInicio !== '') ? 'Ajuste os filtros aplicados ou gere um novo PDF.' : 'Feche um espelho de ponto e gere o PDF pela ficha da folha.' ?></p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>Colaborador</th>
                    <th>Período</th>
                    <th>Status doc</th>
                    <th>Verificação</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($docs as $d):
                    $modeloAnterior = (int) ($d['layout_pdf'] ?? 0) !== FolhaPontoService::LAYOUT_PDF_ATUAL;
                    $pendente = in_array($d['status'], ['gerado', 'enviado'], true);
                    $periodo = PeriodoPonto::selecionar($d['periodo_inicio'], $d['periodo_fim'], (int) $d['ano'], (int) $d['mes']);
                ?>
                    <tr>
                        <td>
                            <div class="fw-700"><?= View::e((string) $d['nome']) ?></div>
                            <div class="text-xs muted">Mat. <?= View::e((string) $d['matricula']) ?></div>
                        </td>
                        <td><?= View::e(PeriodoPonto::rotulo($periodo)) ?></td>
                        <td><span class="pill pill-brand"><?= $pendente && $modeloAnterior ? 'Modelo anterior' : View::e((string) $d['status']) ?></span></td>
                        <td class="text-xs"><?= View::e(substr((string) $d['codigo_verificacao'], 0, 12)) ?>…</td>
                        <td>
                            <a class="btn btn-ghost btn-sm" href="/rh/folha/<?= (int) $d['espelho_id'] ?>?<?= View::e(PeriodoPonto::consulta($periodo)) ?>">Abrir</a>
                            <a class="btn btn-secondary btn-sm" href="/rh/folha/documento/<?= (int) $d['id'] ?>"><?= $modeloAnterior ? 'PDF anterior' : 'PDF' ?></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
