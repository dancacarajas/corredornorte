<?php

use App\Helpers\View;

/** @var string $entity */
/** @var array $meta */
/** @var array $row */
/** @var array $lookups */
/** @var bool $dbOk */
/** @var array $errors */
/** @var string $action */
?>

<?php if (!$dbOk): ?>
    <div class="alert alert-warning">Banco indisponível.</div>
<?php endif; ?>
<?php if (!empty($errors['geral'])): ?>
    <div class="alert alert-danger"><?= View::e($errors['geral']) ?></div>
<?php endif; ?>

<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title"><?= View::e($meta['title']) ?></h2>
            <p class="card-subtitle"><?= View::e($meta['subtitle']) ?></p>
        </div>
        <div style="display:flex;gap:.5rem;">
            <?php if ($entity === 'localidades' && !empty($row['id'])): ?>
                <a class="btn btn-primary btn-sm" href="/rh/localidades/<?= (int) $row['id'] ?>/feriados">Feriados da cidade</a>
            <?php endif; ?>
            <a class="btn btn-secondary btn-sm" href="/rh/<?= View::e($entity) ?>">Voltar</a>
        </div>
    </div>
    <form method="post" action="<?= View::e($action) ?>" class="card-body form-grid cols-3">
        <?php
        $fieldLabels = [
            'carga_diaria_min' => 'Carga diária',
            'carga_semanal_min' => 'Carga semanal',
            'empresa_id' => 'Empresa',
            'contrato_id' => 'Contrato',
            'centro_custo_id' => 'Centro de custo',
            'ordem_servico_id' => 'Ordem de serviço',
            'localidade_id' => 'Localidade (cidade)',
            'jornada_id' => 'Jornada',
            'escala_id' => 'Escala',
            'calendario_id' => 'Calendário (opcional · contrato)',
            'trabalha_sabado' => 'Trabalha sábado',
            'trabalha_domingo' => 'Trabalha domingo',
            'trabalha_feriado' => 'Trabalha feriado',
            'adicional_noturno' => 'Adicional noturno',
            'intervalo_inicio' => 'Intervalo início',
            'intervalo_fim' => 'Intervalo fim',
            'percentual_he' => '% Hora extra (adicional)',
            'cliente' => 'Cliente',
            'matricula' => 'Matrícula',
            'telefone' => 'Telefone',
            'email' => 'E-mail',
        ];
        $fieldLinks = [
            'contrato_id' => ['href' => '/rh/contratos', 'label' => 'Abrir contratos'],
            'centro_custo_id' => ['href' => '/rh/centros-custo', 'label' => 'Abrir centros de custo'],
            'ordem_servico_id' => ['href' => '/rh/ordens-servico', 'label' => 'Abrir OS'],
            'localidade_id' => ['href' => '/rh/localidades', 'label' => 'Abrir localidades'],
            'jornada_id' => ['href' => '/rh/jornadas', 'label' => 'Abrir jornadas'],
            'escala_id' => ['href' => '/rh/escalas', 'label' => 'Abrir escalas'],
            'calendario_id' => ['href' => '/rh/calendarios', 'label' => 'Abrir feriados'],
            'empresa_id' => ['href' => '/rh/empresas', 'label' => 'Abrir empresas'],
        ];
        ?>
        <?php if ($entity === 'lotacoes'): ?>
            <div style="grid-column:1/-1;display:flex;flex-direction:column;gap:.65rem;margin-bottom:.25rem;">
                <p class="text-sm muted" style="margin:0;">
                    A lotação só monta o pacote. Use os botões para cadastrar cada item. Feriados da cidade vêm da <strong>Localidade</strong>.
                </p>
                <div style="display:flex;flex-wrap:wrap;gap:.5rem;">
                    <a class="btn btn-secondary btn-sm" href="/rh/contratos">Contratos</a>
                    <a class="btn btn-secondary btn-sm" href="/rh/centros-custo">Centros de custo</a>
                    <a class="btn btn-secondary btn-sm" href="/rh/ordens-servico">Ordens de serviço</a>
                    <a class="btn btn-primary btn-sm" href="/rh/localidades">Localidades</a>
                    <a class="btn btn-secondary btn-sm" href="/rh/jornadas">Jornadas</a>
                    <a class="btn btn-secondary btn-sm" href="/rh/escalas">Escalas</a>
                    <a class="btn btn-secondary btn-sm" href="/rh/calendarios">Feriados</a>
                </div>
            </div>
        <?php endif; ?>
        <?php foreach ($meta['fields'] as $field => $type): ?>
            <div class="form-field">
                <label class="form-label"><?= View::e($fieldLabels[$field] ?? ucwords(str_replace('_', ' ', $field))) ?></label>
                <?php if (in_array($type, ['empresa','contrato','centro_custo','ordem_servico','localidade','jornada','escala','calendario'], true)): ?>
                    <select class="form-control" name="<?= View::e($field) ?>" <?= $type === 'calendario' ? '' : 'required' ?>>
                        <option value="">Selecione</option>
                        <?php foreach (($lookups[$type] ?? []) as $opt): ?>
                            <option value="<?= (int) $opt['id'] ?>"
                                <?= isset($opt['contrato_id']) ? ' data-contrato-id="' . (int) $opt['contrato_id'] . '"' : '' ?>
                                <?= isset($opt['centro_custo_id']) ? ' data-centro-custo-id="' . (int) $opt['centro_custo_id'] . '"' : '' ?>
                                <?= ((string) ($row[$field] ?? '') === (string) $opt['id']) ? 'selected' : '' ?>>
                                <?= View::e((string) $opt['label']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (!empty($fieldLinks[$field])): ?>
                        <div style="margin-top:.4rem;">
                            <a class="btn btn-ghost btn-sm" href="<?= View::e($fieldLinks[$field]['href']) ?>" target="_blank" rel="noopener">
                                <?= View::e($fieldLinks[$field]['label']) ?>
                            </a>
                            <?php if ($field === 'localidade_id'): ?>
                                <span class="form-hint" style="display:inline;margin-left:.35rem;">define feriados da cidade</span>
                            <?php elseif ($field === 'calendario_id'): ?>
                                <span class="form-hint" style="display:inline;margin-left:.35rem;">opcional</span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php elseif ($type === 'status'): ?>
                    <select class="form-control" name="<?= View::e($field) ?>">
                        <?php foreach (['ativo','inativo'] as $st): ?>
                            <option value="<?= $st ?>" <?= (($row[$field] ?? 'ativo') === $st) ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php elseif ($type === 'status3'): ?>
                    <select class="form-control" name="<?= View::e($field) ?>">
                        <?php foreach (['ativo','inativo','encerrado'] as $st): ?>
                            <option value="<?= $st ?>" <?= (($row[$field] ?? 'ativo') === $st) ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php elseif ($type === 'escala_tipo'): ?>
                    <select class="form-control" name="<?= View::e($field) ?>" required>
                        <?php foreach (['5x2','6x1','12x36','seg_sex','seg_sab','ciclo','personalizada'] as $tp): ?>
                            <option value="<?= $tp ?>" <?= (($row[$field] ?? '') === $tp) ? 'selected' : '' ?>><?= $tp ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php elseif ($type === 'percentual_he'): ?>
                    <input class="form-control" type="number" name="<?= View::e($field) ?>"
                           min="1" max="200" step="1" required
                           value="<?= View::e((string) ($row[$field] ?? '50')) ?>"
                           placeholder="50">
                    <div class="form-hint">Ex.: 50, 60 ou 70. Digite só o número (sem o %).</div>
                <?php elseif ($type === 'bool'): ?>
                    <label class="text-sm"><input type="checkbox" name="<?= View::e($field) ?>" value="1" <?= !empty($row[$field]) ? 'checked' : '' ?>> Sim</label>
                <?php elseif ($type === 'hours'): ?>
                    <?php
                    $rawHours = (string) ($row[$field] ?? '');
                    if ($rawHours !== '' && str_contains($rawHours, ':')) {
                        $hoursDisplay = $rawHours;
                    } else {
                        $mins = (int) ($row[$field] ?? 0);
                        $hoursDisplay = $mins > 0 || $rawHours === '0'
                            ? sprintf('%02d:%02d', intdiv($mins, 60), $mins % 60)
                            : '';
                    }
                    ?>
                    <input class="form-control" type="text" name="<?= View::e($field) ?>"
                           value="<?= View::e($hoursDisplay) ?>"
                           placeholder="HH:MM" inputmode="numeric" pattern="\d{1,3}:[0-5]\d"
                           data-carga-field="<?= View::e($field) ?>"
                           title="Calculado automaticamente a partir dos horários">
                    <div class="form-hint">Calculado em horas (HH:MM)</div>
                <?php else: ?>
                    <input class="form-control" type="<?= View::e($type === 'number' ? 'number' : ($type === 'date' ? 'date' : ($type === 'time' ? 'time' : 'text'))) ?>"
                           name="<?= View::e($field) ?>"
                           value="<?= View::e((string) ($row[$field] ?? '')) ?>"
                           <?= in_array($field, ['nome', 'codigo'], true) ? 'required' : '' ?>>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        <div class="stack-row" style="grid-column:1/-1;">
            <a class="btn btn-secondary" href="/rh/<?= View::e($entity) ?>">Cancelar</a>
            <button class="btn btn-primary" type="submit" <?= $dbOk ? '' : 'disabled' ?>>Salvar</button>
        </div>
    </form>
</section>

<?php if ($entity === 'jornadas'): ?>
<script>
(function () {
    const form = document.querySelector('form.card-body');
    if (!form) return;

    const entrada = form.querySelector('[name="entrada"]');
    const intIni = form.querySelector('[name="intervalo_inicio"]');
    const intFim = form.querySelector('[name="intervalo_fim"]');
    const saida = form.querySelector('[name="saida"]');
    const diaria = form.querySelector('[name="carga_diaria_min"]');
    const semanal = form.querySelector('[name="carga_semanal_min"]');
    const sab = form.querySelector('[name="trabalha_sabado"]');
    const dom = form.querySelector('[name="trabalha_domingo"]');
    if (!entrada || !saida || !diaria || !semanal) return;

    function toMin(v) {
        if (!v) return null;
        const m = String(v).trim().match(/^(\d{1,2}):(\d{2})(?::\d{2})?$/);
        if (!m) return null;
        return parseInt(m[1], 10) * 60 + parseInt(m[2], 10);
    }

    function fmt(mins) {
        mins = Math.max(0, Math.round(mins));
        const h = Math.floor(mins / 60);
        const m = mins % 60;
        return String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0');
    }

    function calc() {
        let e = toMin(entrada.value);
        let s = toMin(saida.value);
        if (e === null || s === null) return;

        // Cruza meia-noite
        if (s <= e) s += 24 * 60;

        let bruto = s - e;
        const ii = toMin(intIni && intIni.value);
        const iff = toMin(intFim && intFim.value);
        if (ii !== null && iff !== null && iff > ii) {
            bruto -= (iff - ii);
        }

        diaria.value = fmt(bruto);

        let dias = 5;
        if (sab && sab.checked) dias += 1;
        if (dom && dom.checked) dias += 1;
        // Plantão c/ sáb+dom: média semanal ~3,5 turnos (ex.: 12x36 → 42h)
        if (sab && sab.checked && dom && dom.checked) dias = 3.5;

        semanal.value = fmt(bruto * dias);
    }

    ['change', 'input'].forEach((ev) => {
        [entrada, intIni, intFim, saida, sab, dom].forEach((el) => {
            if (el) el.addEventListener(ev, calc);
        });
    });

    calc();
})();
</script>
<?php endif; ?>

<script>
(() => {
  const ctSel = document.querySelector('select[name="contrato_id"]');
  const ccSel = document.querySelector('select[name="centro_custo_id"]');
  const osSel = document.querySelector('select[name="ordem_servico_id"]');
  if (!ccSel && !osSel) return;

  const filterSelect = (sel, predicate) => {
    if (!sel) return;
    const opts = Array.from(sel.options);
    let currentStillVisible = false;
    opts.forEach((opt) => {
      if (!opt.value) {
        opt.hidden = false;
        opt.disabled = false;
        return;
      }
      const match = predicate(opt);
      opt.hidden = !match;
      opt.disabled = !match;
      if (match && opt.value === sel.value) {
        currentStillVisible = true;
      }
    });
    if (sel.value && !currentStillVisible) {
      sel.value = '';
    }
  };

  const syncCascade = () => {
    const ctVal = ctSel ? ctSel.value : '';
    if (ccSel && ctSel) {
      filterSelect(ccSel, (opt) => !ctVal || (opt.dataset.contratoId || '') === ctVal);
    }
    const ccVal = ccSel ? ccSel.value : '';
    if (osSel) {
      filterSelect(osSel, (opt) => {
        if (ccVal) return (opt.dataset.centroCustoId || '') === ccVal;
        if (ctVal) return (opt.dataset.contratoId || '') === ctVal;
        return true;
      });
    }
  };

  if (ctSel) ctSel.addEventListener('change', syncCascade);
  if (ccSel) {
    ccSel.addEventListener('change', () => {
      const selOpt = ccSel.options[ccSel.selectedIndex];
      if (ctSel && selOpt && selOpt.dataset.contratoId && !ctSel.value) {
        ctSel.value = selOpt.dataset.contratoId;
      }
      syncCascade();
    });
  }
  syncCascade();
})();
</script>

