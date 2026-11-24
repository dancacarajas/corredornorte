<?php

use App\Helpers\View;

$relatorios = $relatorios ?? [];
$status = $status ?? '';
$dataInicio = $dataInicio ?? '';
$dataFim = $dataFim ?? '';
$q = $q ?? '';
$flash = $flash ?? null;
$error = $error ?? null;
$dbOk = $dbOk ?? true;
?>

<?php if (!$dbOk): ?><div class="alert alert-warning">Banco indisponível.</div><?php endif; ?>
<?php if ($flash): ?><div class="alert alert-success"><?= View::e((string) $flash) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= View::e((string) $error) ?></div><?php endif; ?>

<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Relatórios de Atividades</h2>
            <p class="card-subtitle">Filtre por colaborador, período do cartão e status de envio.</p>
        </div>
    </div>
    <form method="get" class="card-body ponto-filtros" style="padding-top:0;">
        <div class="form-field">
            <label class="form-label">Colaborador / Matrícula</label>
            <input class="form-control" type="search" name="q" value="<?= View::e((string) $q) ?>" placeholder="Buscar nome ou matrícula...">
        </div>
        <div class="form-field">
            <label class="form-label">Início</label>
            <input class="form-control" type="date" name="data_inicio" value="<?= View::e($dataInicio) ?>">
        </div>
        <div class="form-field">
            <label class="form-label">Fim</label>
            <input class="form-control" type="date" name="data_fim" value="<?= View::e($dataFim) ?>">
        </div>
        <div class="form-field">
            <label class="form-label">Status</label>
            <select class="form-control" name="status">
                <option value="">Todos</option>
                <?php foreach (['rascunho','enviado','aprovado'] as $st): ?>
                    <option value="<?= $st ?>" <?= $status === $st ? 'selected' : '' ?>><?= View::e($st) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-field ponto-filtros-actions">
            <button class="btn btn-secondary" type="submit">Filtrar</button>
        </div>
    </form>

    <div class="card-body" style="padding-top:0;">
        <?php if ($relatorios === []): ?>
            <div class="empty-state"><h3>Nenhum relatório</h3><p>Gere cartões de ponto para criar os relatórios automaticamente.</p></div>
        <?php else: ?>
            <form method="post" action="/rh/relatorios-atividades/lote">
                <?= \App\Helpers\Csrf::field() ?>
                <div style="display:flex;flex-wrap:wrap;gap:.5rem;align-items:center;justify-content:space-between;margin-bottom:.75rem;padding:.6rem .85rem;background:var(--canvas);border:1px solid var(--border);border-radius:var(--radius-md);">
                    <label style="display:inline-flex;align-items:center;gap:.45rem;font-size:.875rem;cursor:pointer;">
                        <input type="checkbox" id="raCheckTodos" onclick="document.querySelectorAll('.js-ra-check').forEach(function(cb){cb.checked=document.getElementById('raCheckTodos').checked;});">
                        <span>Selecionar todos da página</span>
                    </label>
                    <div style="display:flex;flex-wrap:wrap;gap:.5rem;">
                        <button class="btn btn-secondary btn-sm" type="submit" name="acao" value="gerar_link">
                            Gerar PDF + Link selecionados
                        </button>
                        <button class="btn btn-primary btn-sm" type="submit" name="acao" value="aprovar">
                            Aprovar selecionados
                        </button>
                    </div>
                </div>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                        <tr>
                            <th style="width:2.2rem;"></th>
                            <th>Colaborador</th>
                            <th>Período</th>
                            <th>OS</th>
                            <th>Status RA</th>
                            <th>Assinatura</th>
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
                            $rotulo = ($ini && $fim) ? date('d/m/Y', strtotime($ini)) . ' a ' . date('d/m/Y', strtotime($fim)) : '—';
                            $docSt = (string) ($r['doc_status'] ?? '');
                            $pToken = (string) ($r['portal_token'] ?? '');
                            ?>
                            <tr>
                                <td>
                                    <input type="checkbox" class="js-ra-check" name="relatorio_ids[]" value="<?= (int) $r['id'] ?>">
                                </td>
                                <td>
                                    <strong><?= View::e((string) $r['nome']) ?></strong><br>
                                    <span class="muted text-sm"><?= View::e((string) $r['matricula']) ?></span>
                                </td>
                                <td><?= View::e($rotulo) ?></td>
                                <td><?= View::e((string) ($r['os_codigo'] ?? '—')) ?></td>
                                <td><?= View::e((string) $r['status']) ?></td>
                                <td>
                                    <?php if ($docSt === 'assinado'): ?>
                                        <span class="badge badge-success">Assinado</span>
                                    <?php elseif ($docSt === 'enviado' && $pToken !== ''): ?>
                                        <a href="/assinar-relatorio/<?= View::e(rawurlencode($pToken)) ?>" target="_blank" rel="noopener" class="badge badge-warning" title="Abrir link de assinatura">Aguardando assinatura ↗</a>
                                    <?php else: ?>
                                        <span class="muted text-sm">Sem link</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= (int) ($r['dias_remoto'] ?? 0) ?></td>
                                <td><?= (int) ($r['dias_site'] ?? 0) ?></td>
                                <td><?= (int) ($r['dias_viagem'] ?? 0) ?></td>
                                <td><a class="btn btn-ghost btn-sm" href="/rh/relatorios-atividades/<?= (int) $r['id'] ?>">Abrir</a></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </form>
        <?php endif; ?>
    </div>
</section>
