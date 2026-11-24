<?php

use App\Helpers\View;

/** @var array<string,mixed> $horario */
/** @var list<array<string,mixed>> $dias */
$id = (int) ($horario['id'] ?? 0);
$tipo = (string) ($horario['tipo'] ?? 'semanal');
$labels = [1 => 'Segunda', 2 => 'Terça', 3 => 'Quarta', 4 => 'Quinta', 5 => 'Sexta', 6 => 'Sábado', 7 => 'Domingo'];
$fmt = static function (mixed $t): string {
    $s = trim((string) $t);
    return $s === '' ? '' : substr($s, 0, 5);
};
?>
<?php if (!empty($flash)): ?><div class="alert alert-success">Horário salvo. Os próximos espelhos de ponto usarão estes previstos por dia.</div><?php endif; ?>
<?php if (!empty($error)): ?><div class="alert alert-danger"><?= View::e((string) $error) ?></div><?php endif; ?>

<form method="post" action="<?= $id > 0 ? '/rh/horarios/' . $id : '/rh/horarios' ?>" class="section-stack">
    <section class="card">
        <div class="card-header">
            <div>
                <h2 class="card-title"><?= $id > 0 ? 'Editar horário' : 'Novo horário' ?></h2>
                <p class="card-subtitle">Deixe o dia como Folga (sem batidas) para DSR/folga de escala.</p>
            </div>
            <div style="display:flex;gap:.5rem;">
                <a class="btn btn-secondary btn-sm" href="/rh/horarios">Voltar</a>
                <button class="btn btn-primary" type="submit">Salvar</button>
            </div>
        </div>
        <div class="card-body form-grid cols-3">
            <div class="form-field">
                <label class="form-label">Código</label>
                <input class="form-control" name="codigo" required value="<?= View::e((string) ($horario['codigo'] ?? '')) ?>">
            </div>
            <div class="form-field" style="grid-column: span 2;">
                <label class="form-label">Nome</label>
                <input class="form-control" name="nome" required value="<?= View::e((string) ($horario['nome'] ?? '')) ?>">
            </div>
            <div class="form-field">
                <label class="form-label">Tipo</label>
                <select class="form-control" name="tipo" id="horarioTipo">
                    <option value="semanal" <?= $tipo === 'semanal' ? 'selected' : '' ?>>Semanal (seg–dom)</option>
                    <option value="ciclico" <?= $tipo === 'ciclico' ? 'selected' : '' ?>>Cíclico (12×36, 5×1…)</option>
                </select>
            </div>
            <div class="form-field" id="diasCicloWrap" <?= $tipo === 'ciclico' ? '' : 'hidden' ?>>
                <label class="form-label">Dias do ciclo</label>
                <input class="form-control" type="number" min="1" max="31" name="dias_ciclo"
                       value="<?= (int) ($horario['dias_ciclo'] ?? (count($dias) ?: 2)) ?>">
            </div>
            <div class="form-field">
                <label class="form-label">% HE (opcional)</label>
                <input class="form-control" type="number" min="1" max="200" name="percentual_he"
                       value="<?= View::e((string) ($horario['percentual_he'] ?? '')) ?>" placeholder="Herdar da lotação">
            </div>
            <div class="form-field">
                <label class="form-label">Status</label>
                <select class="form-control" name="status">
                    <option value="ativo" <?= (($horario['status'] ?? '') === 'ativo') ? 'selected' : '' ?>>Ativo</option>
                    <option value="inativo" <?= (($horario['status'] ?? '') === 'inativo') ? 'selected' : '' ?>>Inativo</option>
                </select>
            </div>
            <label class="ra-switch"><input type="checkbox" name="trabalha_feriado" value="1" <?= !empty($horario['trabalha_feriado']) ? 'checked' : '' ?>><span>Trabalha feriado</span></label>
            <label class="ra-switch"><input type="checkbox" name="adicional_noturno" value="1" <?= !empty($horario['adicional_noturno']) ? 'checked' : '' ?>><span>Adicional noturno</span></label>
            <label class="ra-switch"><input type="checkbox" name="almoco_livre" value="1" <?= !empty($horario['almoco_livre']) ? 'checked' : '' ?>><span>Almoço livre</span></label>
            <label class="ra-switch"><input type="checkbox" name="compensado" value="1" <?= !empty($horario['compensado']) ? 'checked' : '' ?>><span>Compensado</span></label>
            <label class="ra-switch"><input type="checkbox" name="neutro" value="1" <?= !empty($horario['neutro']) ? 'checked' : '' ?>><span>Neutro</span></label>
        </div>
    </section>

    <section class="card">
        <div class="card-header">
            <div>
                <h2 class="card-title">Grade de dias</h2>
                <p class="card-subtitle">ENT.1 · SAI.1 · ENT.2 · SAI.2 — sexta pode diferir de seg–qui</p>
            </div>
        </div>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Dia</th>
                        <th>Tipo</th>
                        <th>ENT.1</th>
                        <th>SAI.1</th>
                        <th>ENT.2</th>
                        <th>SAI.2</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($dias as $i => $d):
                        $indice = (int) ($d['indice'] ?? ($i + 1));
                        $lab = $tipo === 'semanal'
                            ? ($labels[$indice] ?? ('Dia ' . $indice))
                            : ('Dia ' . $indice . ' do ciclo');
                        $td = (string) ($d['tipo_dia'] ?? 'folga');
                        ?>
                        <tr>
                            <td>
                                <strong><?= View::e($lab) ?></strong>
                                <input type="hidden" name="dias[<?= $i ?>][indice]" value="<?= $indice ?>">
                            </td>
                            <td>
                                <select class="form-control" name="dias[<?= $i ?>][tipo_dia]" data-tipo-dia>
                                    <option value="trabalho" <?= $td === 'trabalho' ? 'selected' : '' ?>>Trabalho</option>
                                    <option value="folga" <?= $td === 'folga' ? 'selected' : '' ?>>Folga</option>
                                </select>
                            </td>
                            <?php foreach (['ent1', 'sai1', 'ent2', 'sai2'] as $campo): ?>
                                <td>
                                    <input class="form-control" type="time" name="dias[<?= $i ?>][<?= $campo ?>]"
                                           value="<?= View::e($fmt($d[$campo] ?? null)) ?>"
                                           data-batida <?= $td === 'folga' ? 'disabled' : '' ?>>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</form>
<script>
document.getElementById('horarioTipo')?.addEventListener('change', (e) => {
  const wrap = document.getElementById('diasCicloWrap');
  if (wrap) wrap.hidden = e.target.value !== 'ciclico';
});
document.querySelectorAll('[data-tipo-dia]').forEach((sel) => {
  sel.addEventListener('change', () => {
    const tr = sel.closest('tr');
    const off = sel.value === 'folga';
    tr?.querySelectorAll('[data-batida]').forEach((inp) => {
      inp.disabled = off;
      if (off) inp.value = '';
    });
  });
});
</script>
