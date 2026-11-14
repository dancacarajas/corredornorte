<?php

use App\Helpers\View;

$configs = $configs ?? [];
$contratos = $contratos ?? [];
$cfgAtiva = $cfgAtiva ?? null;
$editando = $editando ?? null;
$visualizando = $visualizando ?? null;
$calculo = $calculo ?? [];
$dataInicio = $dataInicio ?? '';
$dataFim = $dataFim ?? '';
$erroCalc = $erroCalc ?? null;
$flash = $flash ?? null;
$error = $error ?? null;

$isEdit = is_array($editando);
$valorForm = $isEdit ? (string) $editando['valor_dia'] : '';
$tipoForm = $isEdit ? (string) $editando['tipo'] : 'dias_trabalhados';
$iniForm = $isEdit ? (string) $editando['vigencia_inicio'] : date('Y-m-d');
$fimForm = $isEdit && !empty($editando['vigencia_fim']) ? (string) $editando['vigencia_fim'] : '';
$todosForm = $isEdit ? !empty($editando['todos_contratos']) : true;
$contratosForm = $isEdit ? (array) ($editando['contrato_ids'] ?? []) : [];

$fmtData = static function (?string $d): string {
    if ($d === null || $d === '' || str_starts_with($d, '0000-')) {
        return '—';
    }
    $ts = strtotime($d);
    return $ts ? date('d/m/Y', $ts) : '—';
};
?>

<?php if ($flash): ?><div class="alert alert-success"><?= View::e((string) $flash) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= View::e((string) $error) ?></div><?php endif; ?>
<?php if ($erroCalc): ?><div class="alert alert-warning"><?= View::e((string) $erroCalc) ?></div><?php endif; ?>

<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title"><?= $isEdit ? 'Editar configuração' : 'Configuração' ?></h2>
            <p class="card-subtitle">VR = valor/dia × dias Remoto do relatório.</p>
        </div>
        <?php if ($cfgAtiva && !$isEdit): ?>
            <p class="muted text-sm" style="margin:0;">
                Atual: R$ <?= View::e(number_format((float) $cfgAtiva['valor_dia'], 2, ',', '.')) ?>
                · fim de semana <?= !empty($cfgAtiva['tipo']) && $cfgAtiva['tipo'] === 'dias_uteis' ? 'não paga' : 'paga' ?>
                · <?= View::e((string) ($cfgAtiva['contratos_label'] ?? 'Todos')) ?>
            </p>
        <?php endif; ?>
    </div>
    <div class="card-body section-stack">
        <form method="post" action="/rh/vale-refeicao/config" class="form-grid cols-2" id="vrConfigForm">
            <?php if ($isEdit): ?>
                <input type="hidden" name="id" value="<?= (int) $editando['id'] ?>">
            <?php endif; ?>
            <div class="form-field">
                <label class="form-label">Valor por dia (R$)</label>
                <input class="form-control" name="valor_dia" type="number" step="0.01" min="0.01" required
                       value="<?= View::e($valorForm) ?>">
            </div>
            <div class="form-field">
                <label class="form-label">Fim de semana Remoto</label>
                <select class="form-control" name="tipo" required>
                    <option value="dias_trabalhados" <?= $tipoForm === 'dias_trabalhados' ? 'selected' : '' ?>>Paga</option>
                    <option value="dias_uteis" <?= $tipoForm === 'dias_uteis' ? 'selected' : '' ?>>Não paga</option>
                </select>
            </div>
            <div class="form-field" style="grid-column:1/-1;">
                <label class="form-label">Alocação (contratos)</label>
                <label class="text-sm" style="display:flex;align-items:center;gap:.5rem;margin-bottom:.6rem;">
                    <input type="checkbox" name="todos_contratos" value="1" id="vrTodos" <?= $todosForm ? 'checked' : '' ?>>
                    Todos os contratos
                </label>
                <div class="chip-group" id="vrContratos">
                    <?php
                    $selectedContratos = array_map('intval', $contratosForm);
                    foreach ($contratos as $ct):
                        $cid = (int) $ct['id'];
                        $checked = in_array($cid, $selectedContratos, true);
                    ?>
                        <label class="chip-check">
                            <input type="checkbox" name="contrato_ids[]" value="<?= $cid ?>"
                                <?= $checked ? 'checked' : '' ?>
                                <?= $todosForm ? 'disabled' : '' ?>>
                            <span><?= View::e($ct['codigo'] . ' — ' . $ct['nome']) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <p class="muted text-sm" style="margin:.4rem 0 0;">Clique nos chips para selecionar os contratos cobertos pela vigência.</p>
            </div>
            <div class="form-field">
                <label class="form-label">Início</label>
                <input class="form-control" type="date" name="vigencia_inicio" value="<?= View::e($iniForm) ?>" required>
            </div>
            <div class="form-field">
                <label class="form-label">Fim (opcional)</label>
                <input class="form-control" type="date" name="vigencia_fim" value="<?= View::e($fimForm) ?>">
            </div>
            <div class="form-field" style="grid-column:1/-1;display:flex;gap:.5rem;flex-wrap:wrap;">
                <button class="btn btn-primary" type="submit"><?= $isEdit ? 'Atualizar' : 'Salvar' ?></button>
                <?php if ($isEdit): ?>
                    <a class="btn btn-ghost" href="/rh/vale-refeicao">Cancelar</a>
                <?php endif; ?>
            </div>
        </form>

        <?php if ($configs !== []): ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                    <tr>
                        <th>Valor</th>
                        <th>Fim de semana</th>
                        <th>Alocação</th>
                        <th>Início</th>
                        <th>Fim</th>
                        <th>Ativo</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($configs as $c): ?>
                        <tr>
                            <td>R$ <?= View::e(number_format((float) $c['valor_dia'], 2, ',', '.')) ?></td>
                            <td><?= (string) $c['tipo'] === 'dias_uteis' ? 'Não paga' : 'Paga' ?></td>
                            <td><?= View::e((string) $c['contratos_label']) ?></td>
                            <td><?= View::e($fmtData((string) $c['vigencia_inicio'])) ?></td>
                            <td><?= View::e($fmtData($c['vigencia_fim'] !== null ? (string) $c['vigencia_fim'] : null)) ?></td>
                            <td><?= !empty($c['ativo']) ? 'sim' : 'não' ?></td>
                            <td style="white-space:nowrap;">
                                <a class="btn btn-ghost btn-sm" href="/rh/vale-refeicao?ver=<?= (int) $c['id'] ?>">Ver</a>
                                <a class="btn btn-ghost btn-sm" href="/rh/vale-refeicao?editar=<?= (int) $c['id'] ?>">Editar</a>
                                <form method="post" action="/rh/vale-refeicao/config/<?= (int) $c['id'] ?>/excluir"
                                      style="display:inline;"
                                      data-confirm-title="Excluir configuração?"
                                      data-confirm="Esta ação não pode ser desfeita."
                                      data-confirm-ok="Excluir"
                                      data-confirm-danger>
                                    <button class="btn btn-ghost btn-sm" type="submit">Excluir</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>

<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Cálculo do período</h2>
            <p class="card-subtitle">Mesmo intervalo do cartão de ponto.</p>
        </div>
        <?php if ($calculo !== []): ?>
            <a class="btn btn-secondary btn-sm"
               href="/rh/vale-refeicao/exportar?<?= View::e(http_build_query(['data_inicio' => $dataInicio, 'data_fim' => $dataFim])) ?>">Exportar CSV</a>
        <?php endif; ?>
    </div>
    <form method="get" class="card-body ponto-filtros" style="padding-top:0;">
        <div class="form-field">
            <label class="form-label">Início</label>
            <input class="form-control" type="date" name="data_inicio" value="<?= View::e($dataInicio) ?>" required>
        </div>
        <div class="form-field">
            <label class="form-label">Fim</label>
            <input class="form-control" type="date" name="data_fim" value="<?= View::e($dataFim) ?>" required>
        </div>
        <div class="form-field ponto-filtros-actions">
            <button class="btn btn-secondary" type="submit">Calcular</button>
        </div>
    </form>
    <div class="card-body" style="padding-top:0;">
        <?php if ($calculo === []): ?>
            <p class="muted">Sem dados neste período.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                    <tr>
                        <th>Colaborador</th>
                        <th>Relatório</th>
                        <th>Dias Remoto</th>
                        <th>Valor/dia</th>
                        <th>Total VR</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php
                    $soma = 0.0;
                    foreach ($calculo as $row):
                        $soma += (float) $row['valor_vr'];
                        ?>
                        <tr>
                            <td>
                                <strong><?= View::e((string) $row['nome']) ?></strong><br>
                                <span class="muted text-sm"><?= View::e((string) $row['matricula']) ?></span>
                            </td>
                            <td><?= View::e((string) $row['status']) ?></td>
                            <td><?= (int) $row['dias_remoto'] ?></td>
                            <td>R$ <?= View::e(number_format((float) $row['valor_dia'], 2, ',', '.')) ?></td>
                            <td><strong>R$ <?= View::e(number_format((float) $row['valor_vr'], 2, ',', '.')) ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                    <tr>
                        <td colspan="4"><strong>Total</strong></td>
                        <td><strong>R$ <?= View::e(number_format($soma, 2, ',', '.')) ?></strong></td>
                    </tr>
                    </tfoot>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php if (is_array($visualizando)): ?>
    <div class="modal-backdrop is-open" id="vrVerModal" role="dialog" aria-modal="true">
        <div class="modal">
            <div class="modal-header">
                <h3 class="card-title">Configuração #<?= (int) $visualizando['id'] ?></h3>
                <a class="btn btn-ghost btn-sm" href="/rh/vale-refeicao">Fechar</a>
            </div>
            <div class="modal-body section-stack">
                <p><strong>Valor/dia:</strong> R$ <?= View::e(number_format((float) $visualizando['valor_dia'], 2, ',', '.')) ?></p>
                <p><strong>Fim de semana Remoto:</strong> <?= (string) $visualizando['tipo'] === 'dias_uteis' ? 'Não paga' : 'Paga' ?></p>
                <p><strong>Alocação:</strong> <?= View::e((string) $visualizando['contratos_label']) ?></p>
                <p><strong>Início:</strong> <?= View::e($fmtData((string) $visualizando['vigencia_inicio'])) ?></p>
                <p><strong>Fim:</strong> <?= View::e($fmtData($visualizando['vigencia_fim'] !== null ? (string) $visualizando['vigencia_fim'] : null)) ?></p>
                <p><strong>Ativo:</strong> <?= !empty($visualizando['ativo']) ? 'sim' : 'não' ?></p>
            </div>
            <div class="modal-footer">
                <a class="btn btn-secondary" href="/rh/vale-refeicao?editar=<?= (int) $visualizando['id'] ?>">Editar</a>
                <a class="btn btn-ghost" href="/rh/vale-refeicao">Fechar</a>
            </div>
        </div>
    </div>
<?php endif; ?>

<script>
(function () {
  const todos = document.getElementById('vrTodos');
  const box = document.getElementById('vrContratos');
  if (!todos || !box) return;
  const sync = () => {
    const off = todos.checked;
    box.style.opacity = off ? '0.5' : '1';
    box.querySelectorAll('input[type="checkbox"]').forEach((chk) => {
      chk.disabled = off;
      if (off) chk.checked = false;
    });
  };
  todos.addEventListener('change', sync);
  sync();
})();
</script>

