<?php

use App\Helpers\View;

/** @var bool $dbOk */
/** @var list<array<string,mixed>> $espelhos */
/** @var bool $mesUnico */
/** @var string $dataInicio */
/** @var string $dataFim */
/** @var ?string $avisoPeriodo */
?>

<?php if (!$dbOk): ?>
    <div class="alert alert-warning">Banco indisponível.</div>
<?php endif; ?>

<?php if (!empty($_GET['ok'])): ?>
    <div class="alert alert-success">Espelhos gerados para a competência <?= date('m/Y', strtotime($dataInicio)) ?>.</div>
<?php endif; ?>
<?php if (!empty($_GET['erro'])): ?>
    <div class="alert alert-danger"><?= View::e((string) $_GET['erro']) ?></div>
<?php endif; ?>
<?php if (!empty($avisoPeriodo)): ?>
    <div class="alert alert-warning"><?= View::e($avisoPeriodo) ?></div>
<?php endif; ?>

<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Espelhos · <?= $mesUnico ? 'competência ' . date('m/Y', strtotime($dataInicio)) : 'período selecionado' ?></h2>
            <p class="card-subtitle">Cada competência tem seu próprio status de conferência e fechamento.</p>
        </div>
    </div>
    <form method="get" action="/rh/ponto" class="card-body form-grid cols-3" style="padding-top:0;">
        <div class="form-field">
            <label class="form-label">Período inicial</label>
            <input class="form-control" type="date" name="data_inicio" required value="<?= View::e($dataInicio) ?>">
        </div>
        <div class="form-field">
            <label class="form-label">Período final</label>
            <input class="form-control" type="date" name="data_fim" required value="<?= View::e($dataFim) ?>">
        </div>
        <div class="form-field" style="display:flex;align-items:flex-end;gap:.5rem;flex-wrap:wrap;">
            <button class="btn btn-secondary" type="submit">Filtrar</button>
            <?php if ($mesUnico): ?>
                <button class="btn btn-primary" type="submit" formaction="/rh/ponto/gerar" formmethod="post" data-ponto-gerar>Gerar espelhos</button>
            <?php endif; ?>
        </div>
    </form>

    <?php if ($espelhos === []): ?>
        <div class="empty-state">
            <h3>Nenhum espelho no período</h3>
            <p>Selecione uma competência para gerar espelhos.</p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>Colaborador</th>
                    <th>Competência</th>
                    <th>Status</th>
                    <th>HE</th>
                    <th>Faltas</th>
                    <th>Atrasos</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($espelhos as $e): ?>
                    <?php
                    $pill = match ((string) $e['status']) {
                        'fechado', 'enviado', 'assinado' => 'pill-success',
                        'em_tratamento', 'conferido' => 'pill-warning',
                        default => 'pill-neutral',
                    };
                    ?>
                    <tr>
                        <td>
                            <div class="fw-700"><?= View::e((string) $e['nome']) ?></div>
                            <div class="text-xs muted">Mat. <?= View::e((string) $e['matricula']) ?></div>
                        </td>
                        <td><?= sprintf('%02d/%d', (int)$e['mes'], (int)$e['ano']) ?></td>
                        <td><span class="pill <?= $pill ?>"><?= View::e((string) $e['status']) ?></span></td>
                        <td><?= (int) $e['periodo_he_min'] ?> min</td>
                        <td><?= (int) $e['periodo_falta_min'] ?> min</td>
                        <td><?= (int) $e['periodo_atraso_min'] ?> min</td>
                        <td>
                            <a class="btn btn-ghost btn-sm"
                               href="/rh/ponto/<?= (int) $e['id'] ?>?<?= View::e(http_build_query(['data_inicio' => $dataInicio, 'data_fim' => $dataFim])) ?>">
                                Abrir
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php if ($mesUnico): ?>
    <dialog class="modal ponto-progresso" id="ponto-progresso" aria-labelledby="ponto-progresso-titulo">
        <div class="modal-header">
            <h2 class="card-title" id="ponto-progresso-titulo" tabindex="-1" autofocus>Geração de espelhos</h2>
        </div>
        <div class="modal-body section-stack">
            <div class="ponto-progresso-resumo text-sm">
                <span id="ponto-progresso-status" role="status"></span>
                <strong id="ponto-progresso-porcentagem" aria-hidden="true">0%</strong>
            </div>
            <div class="progress" role="progressbar" aria-labelledby="ponto-progresso-titulo" aria-valuenow="0">
                <span></span>
            </div>
        </div>
        <form method="dialog" class="modal-footer" hidden>
            <button class="btn btn-primary btn-sm" type="submit">Fechar</button>
        </form>
    </dialog>
    <script src="<?= View::asset('js/ponto-progresso.js') ?>" defer></script>
<?php endif; ?>
