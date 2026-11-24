<?php

use App\Helpers\View;

$configs = $configs ?? [];
$contratos = $contratos ?? [];
$cfgAtiva = $cfgAtiva ?? null;
$editando = $editando ?? null;
$visualizando = $visualizando ?? null;
$flash = $flash ?? null;
$error = $error ?? null;
$podeCriar = !empty($podeCriar);
$podeEditar = !empty($podeEditar);
$podeExcluir = !empty($podeExcluir);

$isEdit = is_array($editando);
$nomeForm = $isEdit ? (string) ($editando['nome'] ?? '') : '';
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

<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title"><?= $isEdit ? 'Editar configuração' : 'Nova configuração' ?></h2>
            <p class="card-subtitle">VR = valor/dia × dias Remoto do relatório. Pode haver várias regras (geral + contratos específicos).</p>
        </div>
        <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;">
            <?php if ($cfgAtiva && !$isEdit): ?>
                <p class="muted text-sm" style="margin:0;">
                    Vigente hoje: <strong><?= View::e((string) ($cfgAtiva['nome'] ?? '')) ?></strong>
                    · R$ <?= View::e(number_format((float) $cfgAtiva['valor_dia'], 2, ',', '.')) ?>
                </p>
            <?php endif; ?>
            <a class="btn btn-secondary btn-sm" href="/rh/vale-refeicao/relatorio">Gerar relatório</a>
        </div>
    </div>
    <div class="card-body section-stack">
        <?php if (($isEdit && $podeEditar) || (!$isEdit && $podeCriar)): ?>
        <form method="post" action="/rh/vale-refeicao/config" class="form-grid cols-2" id="vrConfigForm">
            <?php if ($isEdit): ?>
                <input type="hidden" name="id" value="<?= (int) $editando['id'] ?>">
            <?php endif; ?>
            <div class="form-field" style="grid-column:1/-1;">
                <label class="form-label">Nome da configuração</label>
                <input class="form-control" name="nome" maxlength="120" required
                       placeholder="Ex.: VR geral 2026 · VR contrato CKS"
                       value="<?= View::e($nomeForm) ?>">
            </div>
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
                <p class="muted text-sm" style="margin:.4rem 0 0;">Desmarque “Todos” e selecione contratos para uma regra específica. A regra específica tem prioridade sobre a geral.</p>
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
        <?php elseif (!$podeCriar && !$podeEditar): ?>
            <p class="muted">Seu perfil só permite visualizar as configurações de VR.</p>
        <?php endif; ?>
    </div>
</section>

<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Configurações cadastradas</h2>
            <p class="card-subtitle"><?= count($configs) ?> regra(s) — geral e específicas aparecem juntas.</p>
        </div>
    </div>
    <div class="card-body">
        <?php if ($configs === []): ?>
            <p class="muted">Nenhuma configuração ainda. Cadastre a primeira acima.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                    <tr>
                        <th>Nome</th>
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
                            <td><strong><?= View::e((string) ($c['nome'] ?? '')) ?></strong></td>
                            <td>R$ <?= View::e(number_format((float) $c['valor_dia'], 2, ',', '.')) ?></td>
                            <td><?= (string) $c['tipo'] === 'dias_uteis' ? 'Não paga' : 'Paga' ?></td>
                            <td><?= View::e((string) $c['contratos_label']) ?></td>
                            <td><?= View::e($fmtData((string) $c['vigencia_inicio'])) ?></td>
                            <td><?= View::e($fmtData($c['vigencia_fim'] !== null ? (string) $c['vigencia_fim'] : null)) ?></td>
                            <td><?= !empty($c['ativo']) ? 'sim' : 'não' ?></td>
                            <td style="white-space:nowrap;">
                                <a class="btn btn-ghost btn-sm" href="/rh/vale-refeicao?ver=<?= (int) $c['id'] ?>">Ver</a>
                                <?php if ($podeEditar): ?>
                                <a class="btn btn-ghost btn-sm" href="/rh/vale-refeicao?editar=<?= (int) $c['id'] ?>">Editar</a>
                                <?php endif; ?>
                                <?php if ($podeExcluir): ?>
                                <form method="post" action="/rh/vale-refeicao/config/<?= (int) $c['id'] ?>/excluir"
                                      style="display:inline;"
                                      data-confirm-title="Excluir configuração?"
                                      data-confirm="Esta ação não pode ser desfeita."
                                      data-confirm-ok="Excluir"
                                      data-confirm-danger>
                                    <button class="btn btn-ghost btn-sm" type="submit">Excluir</button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php if (is_array($visualizando)): ?>
    <div class="modal-backdrop is-open" id="vrVerModal" role="dialog" aria-modal="true">
        <div class="modal">
            <div class="modal-header">
                <h3 class="card-title"><?= View::e((string) ($visualizando['nome'] ?? ('Configuração #' . (int) $visualizando['id']))) ?></h3>
                <a class="btn btn-ghost btn-sm" href="/rh/vale-refeicao">Fechar</a>
            </div>
            <div class="modal-body section-stack">
                <p><strong>Nome:</strong> <?= View::e((string) ($visualizando['nome'] ?? '')) ?></p>
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
    box.querySelectorAll('input[type="checkbox"]').forEach((el) => {
      el.disabled = off;
      if (off) el.checked = false;
    });
  };
  todos.addEventListener('change', sync);
  sync();
})();
</script>
