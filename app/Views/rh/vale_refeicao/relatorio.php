<?php

use App\Helpers\View;

$calculo = $calculo ?? [];
$periodos = $periodos ?? [];
$dataInicio = $dataInicio ?? '';
$dataFim = $dataFim ?? '';
$labelPeriodo = $labelPeriodo ?? '';
$gerou = !empty($gerou);
$erroCalc = $erroCalc ?? null;
$qs = $qs ?? '';
$flash = $flash ?? null;
$error = $error ?? null;

$soma = 0.0;
$dias = 0;
foreach ($calculo as $row) {
    $soma += (float) ($row['valor_vr'] ?? 0);
    $dias += (int) ($row['dias_remoto'] ?? 0);
}
$temResultado = $gerou && $calculo !== [] && $erroCalc === null;
$periodoKey = $dataInicio . '|' . $dataFim;
?>

<?php if ($flash): ?><div class="alert alert-success"><?= View::e((string) $flash) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= View::e((string) $error) ?></div><?php endif; ?>
<?php if ($erroCalc): ?><div class="alert alert-warning"><?= View::e((string) $erroCalc) ?></div><?php endif; ?>

<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Gerar relatório de VR</h2>
            <p class="card-subtitle">Escolha o período da medição. Regra específica do contrato tem prioridade sobre a geral.</p>
        </div>
        <a class="btn btn-ghost btn-sm" href="/rh/vale-refeicao">Configurações</a>
    </div>

    <form method="get" action="/rh/vale-refeicao/relatorio" class="card-body" style="padding-bottom:.85rem;" id="vrRelatorioForm">
        <div class="vr-toolbar">
            <div class="form-field">
                <label class="form-label" for="vrPeriodo">Período de medição</label>
                <select class="form-control" id="vrPeriodo" name="periodo_sel">
                    <option value="">Personalizado…</option>
                    <?php foreach ($periodos as $p):
                        $val = $p['data_inicio'] . '|' . $p['data_fim'];
                        $sel = $val === $periodoKey;
                        ?>
                        <option value="<?= View::e($val) ?>"
                                data-inicio="<?= View::e($p['data_inicio']) ?>"
                                data-fim="<?= View::e($p['data_fim']) ?>"
                            <?= $sel ? 'selected' : '' ?>>
                            <?= View::e($p['label']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-field">
                <label class="form-label" for="vrIni">Início</label>
                <input class="form-control" id="vrIni" type="date" name="data_inicio"
                       value="<?= View::e($dataInicio) ?>" required>
            </div>
            <div class="form-field">
                <label class="form-label" for="vrFim">Fim</label>
                <input class="form-control" id="vrFim" type="date" name="data_fim"
                       value="<?= View::e($dataFim) ?>" required>
            </div>
            <div class="vr-toolbar-actions">
                <button class="btn btn-primary" type="submit">Ver na tela</button>
            </div>
        </div>
    </form>

    <?php if ($labelPeriodo !== ''): ?>
        <div class="vr-period-banner">
            <div>
                <span class="kicker">Período selecionado</span>
                <strong><?= View::e($labelPeriodo) ?></strong>
            </div>
            <?php if ($temResultado): ?>
                <span class="muted text-sm"><?= count($calculo) ?> colaborador(es) no cálculo</span>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($temResultado): ?>
        <div class="vr-export-bar">
            <span class="muted text-sm">Exportar com o mesmo visual da folha de ponto</span>
            <div class="actions">
                <a class="btn btn-secondary btn-sm" href="/rh/vale-refeicao/relatorio/pdf?<?= View::e($qs) ?>" target="_blank" rel="noopener">PDF</a>
                <a class="btn btn-secondary btn-sm" href="/rh/vale-refeicao/relatorio/excel?<?= View::e($qs) ?>">Excel</a>
            </div>
        </div>
    <?php endif; ?>
</section>

<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title"><?= $temResultado ? 'Resultado' : 'Pré-visualização' ?></h2>
            <p class="card-subtitle">
                <?php if (!$gerou): ?>
                    Selecione o período e clique em “Ver na tela”.
                <?php elseif ($temResultado): ?>
                    Totais do período com base nos dias Remoto do RA.
                <?php else: ?>
                    Nenhum colaborador encontrado neste período.
                <?php endif; ?>
            </p>
        </div>
    </div>
    <div class="card-body">
        <?php if (!$gerou): ?>
            <div class="vr-empty">
                <strong>Pronto para gerar</strong>
                <p class="muted" style="margin:0;">Use o seletor de período da medição (ou datas manuais) e visualize o VR antes de exportar.</p>
            </div>
        <?php elseif (!$temResultado): ?>
            <div class="vr-empty">
                <strong>Sem dados</strong>
                <p class="muted" style="margin:0;">Confira se há configuração vigente e Relatórios de Atividades no intervalo.</p>
            </div>
        <?php else: ?>
            <div class="vr-kpi-grid">
                <div class="vr-kpi">
                    <span class="label">Colaboradores</span>
                    <span class="value"><?= count($calculo) ?></span>
                </div>
                <div class="vr-kpi">
                    <span class="label">Dias remoto</span>
                    <span class="value"><?= (int) $dias ?></span>
                </div>
                <div class="vr-kpi is-accent">
                    <span class="label">Total VR</span>
                    <span class="value">R$ <?= View::e(number_format($soma, 2, ',', '.')) ?></span>
                </div>
            </div>

            <div class="table-wrap">
                <table class="table vr-table">
                    <thead>
                    <tr>
                        <th>Colaborador</th>
                        <th>RA</th>
                        <th>Configuração</th>
                        <th>Dias</th>
                        <th>Valor/dia</th>
                        <th>Total</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($calculo as $row): ?>
                        <tr>
                            <td>
                                <strong><?= View::e((string) $row['nome']) ?></strong><br>
                                <span class="muted text-sm"><?= View::e((string) $row['matricula']) ?></span>
                            </td>
                            <td><?= View::e((string) $row['status']) ?></td>
                            <td>
                                <?= View::e((string) ($row['config_nome'] ?? '')) ?><br>
                                <span class="muted text-sm"><?= View::e((string) ($row['config_alocacao'] ?? '')) ?></span>
                            </td>
                            <td><?= (int) $row['dias_remoto'] ?></td>
                            <td>R$ <?= View::e(number_format((float) $row['valor_dia'], 2, ',', '.')) ?></td>
                            <td><strong>R$ <?= View::e(number_format((float) $row['valor_vr'], 2, ',', '.')) ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                    <tr>
                        <td colspan="3"><strong>Total</strong></td>
                        <td><?= (int) $dias ?></td>
                        <td></td>
                        <td><strong>R$ <?= View::e(number_format($soma, 2, ',', '.')) ?></strong></td>
                    </tr>
                    </tfoot>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>

<script>
(function () {
  const sel = document.getElementById('vrPeriodo');
  const ini = document.getElementById('vrIni');
  const fim = document.getElementById('vrFim');
  if (!sel || !ini || !fim) return;

  const syncFromSelect = () => {
    const opt = sel.options[sel.selectedIndex];
    if (!opt || !opt.value) return;
    const a = opt.getAttribute('data-inicio') || '';
    const b = opt.getAttribute('data-fim') || '';
    if (a) ini.value = a;
    if (b) fim.value = b;
  };

  sel.addEventListener('change', syncFromSelect);

  const markCustom = () => {
    const key = ini.value + '|' + fim.value;
    let found = false;
    Array.from(sel.options).forEach((o) => {
      if (o.value === key) {
        sel.value = key;
        found = true;
      }
    });
    if (!found) sel.value = '';
  };
  ini.addEventListener('change', markCustom);
  fim.addEventListener('change', markCustom);
})();
</script>
