<?php

use App\Helpers\View;
use App\Services\CalculoPontoService;

/** @var array $espelho */
/** @var list<array> $competencias */
/** @var list<array> $dias */
/** @var list<array> $tiposJustificativa */
/** @var list<array> $justificativas */
/** @var CalculoPontoService $calc */
/** @var string $dataInicio */
/** @var string $dataFim */

$dataInicio = $dataInicio ?? sprintf('%04d-%02d-01', (int)$espelho['ano'], (int)$espelho['mes']);
$dataFim = $dataFim ?? date('Y-m-t', strtotime($dataInicio));

$diasSemana = ['DOM','SEG','TER','QUA','QUI','SEX','SAB'];
$fmtH = static function (?string $t): string {
    if (!$t) {
        return '';
    }
    return substr($t, 0, 5);
};
$cell = static function (int $min, CalculoPontoService $calc, bool $red = false): string {
    if ($min <= 0) {
        return '<span class="val-muted">—</span>';
    }
    $cls = $red ? ' class="val-red"' : '';
    return '<span' . $cls . '>' . View::e($calc->fmt($min)) . '</span>';
};

$totHe50 = 0;
$totHe100 = 0;
$totAdn = 0;
$totDesc = 0;
$totFalta = 0;
$totAtraso = 0;
foreach ($dias as $dSum) {
    $totHe50 += (int) ($dSum['he_50_min'] ?? 0);
    $totHe100 += (int) ($dSum['he_100_min'] ?? 0);
    $totAdn += (int) ($dSum['adn_min'] ?? 0);
    $totDesc += (int) ($dSum['desconto_min'] ?? 0);
    $totFalta += (int) ($dSum['horas_falta_min'] ?? 0);
    $totAtraso += (int) ($dSum['atraso_bruto_min'] ?? 0);
}
$statusBloqueados = ['fechado', 'enviado', 'assinado'];
$inicioMes = date('m/Y', strtotime($dataInicio));
$fimMes = date('m/Y', strtotime($dataFim));
$maisDeUmaCompetencia = $inicioMes !== $fimMes;
?>

<?php if (!empty($flash)): ?><div class="alert alert-success">Atualizado com sucesso.</div><?php endif; ?>
<?php if (!empty($error)): ?><div class="alert alert-danger"><?= View::e((string)$error) ?></div><?php endif; ?>

<section class="card">
    <div class="card-body">
        <div class="ponto-head">
            <div>
                <h2><?= View::e((string)$espelho['nome']) ?></h2>
                <div class="meta">
                    <?= View::e(strtoupper((string)($espelho['cargo'] ?: $espelho['funcao'] ?: 'COLABORADOR'))) ?>
                    · Mat. <?= View::e((string)$espelho['matricula']) ?>
                    · <?= View::e(($espelho['contrato_codigo'] ?? '') . ' / ' . ($espelho['cc_codigo'] ?? '') . ' / ' . ($espelho['os_codigo'] ?? '')) ?>
                    · <?= View::e(($espelho['municipio'] ?? '') . '/' . ($espelho['uf'] ?? '')) ?>
                    · <?= $maisDeUmaCompetencia ? 'Competências ' . $inicioMes . ' a ' . $fimMes : 'Competência ' . $inicioMes ?>
                    · Período <?= date('d/m/Y', strtotime($dataInicio)) ?> a <?= date('d/m/Y', strtotime($dataFim)) ?>
                </div>
            </div>
            <div style="display:grid;gap:.5rem;">
                <?php foreach ($competencias as $competencia):
                    $competenciaId = (int) $competencia['id'];
                    $competenciaRotulo = sprintf('%02d/%d', $competencia['mes'], $competencia['ano']);
                ?>
                    <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
                        <span class="pill pill-brand"><?= $competenciaRotulo ?> · <?= View::e((string) $competencia['status']) ?> · v<?= (int) $competencia['versao'] ?></span>
                        <?php if (in_array($competencia['status'], $statusBloqueados, true)): ?>
                            <button class="btn btn-secondary btn-sm" type="button" data-modal-open="reabrirModal"
                                    data-espelho-id="<?= $competenciaId ?>" data-competencia="<?= $competenciaRotulo ?>">Reabrir<?= $maisDeUmaCompetencia ? ' ' . $competenciaRotulo : '' ?></button>
                        <?php else: ?>
                            <form method="post" action="/rh/ponto/<?= $competenciaId ?>/status" style="display:inline-flex;gap:.5rem;flex-wrap:wrap;">
                                <input type="hidden" name="espelho_retorno" value="<?= (int) $espelho['id'] ?>">
                                <input type="hidden" name="data_inicio" value="<?= View::e($dataInicio) ?>">
                                <input type="hidden" name="data_fim" value="<?= View::e($dataFim) ?>">
                                <button class="btn btn-secondary btn-sm" type="submit" name="status" value="conferido">Conferir<?= $maisDeUmaCompetencia ? ' ' . $competenciaRotulo : '' ?></button>
                                <button class="btn btn-primary btn-sm" type="submit" name="status" value="fechado">Fechar <?= $maisDeUmaCompetencia ? $competenciaRotulo : 'ponto' ?></button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <?php foreach ($dias as $diaForm):
        // Folga não tem batida; feriado tem (trabalho no feriado = HE 100%)
        if (in_array($diaForm['tipo_dia'], ['folga', 'folga_escala'], true)) {
            continue;
        }
        $fid = 'batida-' . (int)$diaForm['id'];
    ?>
        <form id="<?= $fid ?>" method="post" action="/rh/ponto/<?= (int) $diaForm['espelho_id'] ?>/dia" class="batida-form">
            <input type="hidden" name="ponto_dia_id" value="<?= (int)$diaForm['id'] ?>">
            <input type="hidden" name="espelho_retorno" value="<?= (int) $espelho['id'] ?>">
            <input type="hidden" name="data_inicio" value="<?= View::e($dataInicio) ?>">
            <input type="hidden" name="data_fim" value="<?= View::e($dataFim) ?>">
        </form>
    <?php endforeach; ?>

    <div class="ponto-table-wrap">
        <table class="ponto-table">
            <thead>
            <tr>
                <th>Data / Dia</th>
                <th>OK</th>
                <th>Ações</th>
                <th>Ent. 1</th>
                <th>Sai. 1</th>
                <th>Ent. 2</th>
                <th>Sai. 2</th>
                <th>Total Trab.</th>
                <th>H. Previstas</th>
                <th>Dia Falta</th>
                <th>Horas Falta</th>
                <th>Atraso Bruto</th>
                <th>HE 50%</th>
                <th>HE 100%</th>
                <th>Ad. Noturno</th>
                <th>Desconto</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($dias === []): ?>
                <tr><td colspan="16">Nenhum dia registrado no período selecionado.</td></tr>
            <?php endif; ?>
            <?php foreach ($dias as $dia):
                $ts = strtotime((string)$dia['data']);
                $dow = $diasSemana[(int)date('w', $ts)];
                $isFolga = in_array($dia['tipo_dia'], ['folga','folga_escala'], true);
                $isFeriado = ($dia['tipo_dia'] ?? '') === 'feriado';
                $ok = (string)($dia['ok_status'] ?? 'pendente');
                $rowClass = $isFolga ? 'is-folga' : ((!empty($dia['dia_falta']) ? 'is-falta' : ''));
                $formId = 'batida-' . (int)$dia['id'];
                $locked = in_array($dia['espelho_status'], $statusBloqueados, true);
            ?>
                <tr class="<?= $rowClass ?>">
                    <td class="dia"><?= date('d/m', $ts) ?> <?= $dow ?></td>
                    <td>
                        <?php if ($ok === 'ok' || $ok === 'folga' || $ok === 'feriado'): ?>
                            <span class="ponto-ok <?= View::e($ok) ?>" title="<?= View::e($ok) ?>">✓</span>
                        <?php else: ?>
                            <span class="ponto-ok falta" title="<?= View::e($ok) ?>">✕</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!$locked): ?>
                            <button class="ponto-actions-menu" type="button" title="Adicionar justificativa"
                                    data-modal-open="justModal"
                                    data-espelho-id="<?= (int) $dia['espelho_id'] ?>"
                                    data-dia-id="<?= (int) $dia['id'] ?>"
                                    data-dia-data="<?= View::e((string) $dia['data']) ?>">⋮</button>
                        <?php endif; ?>
                    </td>

                    <?php if ($isFolga): ?>
                        <td colspan="4"><div class="ponto-folga-cell">Folga</div></td>
                        <td class="val-muted">—</td>
                        <td class="val-muted">—</td>
                        <td class="val-muted">—</td>
                        <td class="val-muted">—</td>
                        <td class="val-muted">—</td>
                        <td class="val-muted">—</td>
                        <td class="val-muted">—</td>
                        <td class="val-muted">—</td>
                        <td class="val-muted">—</td>
                    <?php else: ?>
                        <?php if ($isFeriado): ?>
                            <td colspan="4">
                                <div class="ponto-folga-cell" style="margin-bottom:.35rem;">
                                    Feriado<?= !empty($dia['ocorrencia_resumo']) ? ' · ' . View::e((string) $dia['ocorrencia_resumo']) : '' ?>
                                    <span class="text-sm muted"> — batidas geram HE 100%</span>
                                </div>
                                <div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:.35rem;">
                                    <input class="ponto-time" form="<?= $formId ?>" type="text" inputmode="numeric"
                                           name="ent1" placeholder="ENT" maxlength="5" pattern="^([01]\d|2[0-3]):[0-5]\d$"
                                           title="HH:MM" value="<?= View::e($fmtH($dia['ent1'] ?? null)) ?>" <?= $locked ? 'readonly' : '' ?>>
                                    <input class="ponto-time" form="<?= $formId ?>" type="text" inputmode="numeric"
                                           name="sai1" placeholder="SAI" maxlength="5" pattern="^([01]\d|2[0-3]):[0-5]\d$"
                                           title="HH:MM" value="<?= View::e($fmtH($dia['sai1'] ?? null)) ?>" <?= $locked ? 'readonly' : '' ?>>
                                    <input class="ponto-time" form="<?= $formId ?>" type="text" inputmode="numeric"
                                           name="ent2" placeholder="ENT" maxlength="5" pattern="^([01]\d|2[0-3]):[0-5]\d$"
                                           title="HH:MM" value="<?= View::e($fmtH($dia['ent2'] ?? null)) ?>" <?= $locked ? 'readonly' : '' ?>>
                                    <input class="ponto-time" form="<?= $formId ?>" type="text" inputmode="numeric"
                                           name="sai2" placeholder="SAI" maxlength="5" pattern="^([01]\d|2[0-3]):[0-5]\d$"
                                           title="HH:MM" value="<?= View::e($fmtH($dia['sai2'] ?? null)) ?>" <?= $locked ? 'readonly' : '' ?>>
                                </div>
                            </td>
                        <?php else: ?>
                        <td class="batida-cell">
                            <input class="ponto-time" form="<?= $formId ?>" type="text" inputmode="numeric"
                                   name="ent1" placeholder="--:--" maxlength="5" pattern="^([01]\d|2[0-3]):[0-5]\d$"
                                   title="HH:MM" value="<?= View::e($fmtH($dia['ent1'] ?? null)) ?>" <?= $locked ? 'readonly' : '' ?>>
                        </td>
                        <td class="batida-cell">
                            <input class="ponto-time" form="<?= $formId ?>" type="text" inputmode="numeric"
                                   name="sai1" placeholder="--:--" maxlength="5" pattern="^([01]\d|2[0-3]):[0-5]\d$"
                                   title="HH:MM" value="<?= View::e($fmtH($dia['sai1'] ?? null)) ?>" <?= $locked ? 'readonly' : '' ?>>
                        </td>
                        <td class="batida-cell">
                            <input class="ponto-time" form="<?= $formId ?>" type="text" inputmode="numeric"
                                   name="ent2" placeholder="--:--" maxlength="5" pattern="^([01]\d|2[0-3]):[0-5]\d$"
                                   title="HH:MM" value="<?= View::e($fmtH($dia['ent2'] ?? null)) ?>" <?= $locked ? 'readonly' : '' ?>>
                        </td>
                        <td class="batida-cell">
                            <input class="ponto-time" form="<?= $formId ?>" type="text" inputmode="numeric"
                                   name="sai2" placeholder="--:--" maxlength="5" pattern="^([01]\d|2[0-3]):[0-5]\d$"
                                   title="HH:MM" value="<?= View::e($fmtH($dia['sai2'] ?? null)) ?>" <?= $locked ? 'readonly' : '' ?>>
                        </td>
                        <?php endif; ?>
                        <td><?= $cell((int)($dia['total_trab_min'] ?? 0), $calc) ?></td>
                        <td><?= $cell((int)($dia['horas_previstas_min'] ?? 0), $calc) ?></td>
                        <td class="<?= !empty($dia['dia_falta']) ? 'val-red' : 'val-muted' ?>"><?= !empty($dia['dia_falta']) ? '1' : '—' ?></td>
                        <td><?= $cell((int)($dia['horas_falta_min'] ?? 0), $calc, true) ?></td>
                        <td><?= $cell((int)($dia['atraso_bruto_min'] ?? 0), $calc, true) ?></td>
                        <td><?= $cell((int)($dia['he_50_min'] ?? 0), $calc) ?></td>
                        <td><?= $cell((int)($dia['he_100_min'] ?? 0), $calc) ?></td>
                        <td><?= $cell((int)($dia['adn_min'] ?? 0), $calc) ?></td>
                        <td><?= $cell((int)($dia['desconto_min'] ?? 0), $calc, true) ?></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="ponto-totais">
        <div class="box"><span>HE 50%</span><strong><?= View::e($calc->fmt($totHe50)) ?></strong></div>
        <div class="box"><span>HE 100%</span><strong><?= View::e($calc->fmt($totHe100)) ?></strong></div>
        <div class="box"><span>Ad. Noturno</span><strong><?= View::e($calc->fmt($totAdn)) ?></strong></div>
        <div class="box"><span>Horas falta</span><strong class="val-red"><?= View::e($calc->fmt($totFalta)) ?></strong></div>
        <div class="box"><span>Atrasos</span><strong><?= View::e($calc->fmt($totAtraso)) ?></strong></div>
        <div class="box"><span>Descontos</span><strong class="val-red"><?= View::e($calc->fmt($totDesc)) ?></strong></div>
        <div class="box"><span>Jornada / Escala</span><strong><?= View::e(($espelho['jornada_codigo'] ?? '—').' · '.($espelho['escala_codigo'] ?? '')) ?></strong></div>
    </div>
</section>

<?php if ($justificativas !== []): ?>
<section class="card">
    <div class="card-header"><div><h2 class="card-title">Justificativas</h2></div></div>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Período</th><th>Tipo</th><th>Obs.</th><th>Anexo</th></tr></thead>
            <tbody>
            <?php foreach ($justificativas as $j): ?>
                <tr>
                    <td><?= View::e($j['data_inicio'].' → '.$j['data_fim']) ?></td>
                    <td><?= View::e((string)$j['tipo_nome']) ?></td>
                    <td><?= View::e((string)($j['observacao'] ?? '')) ?></td>
                    <td><?= !empty($j['anexo_path']) ? '<a href="/'.$j['anexo_path'].'" target="_blank">Arquivo</a>' : '—' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<div class="modal-backdrop" id="justModal">
    <div class="modal">
        <form id="justForm" method="post" action="/rh/ponto/<?= (int)$espelho['id'] ?>/justificativa" enctype="multipart/form-data">
            <div class="modal-header"><h3 class="card-title">Adicionar justificativa</h3><button class="btn btn-ghost btn-sm" type="button" data-modal-close>Fechar</button></div>
            <div class="modal-body form-grid cols-2">
                <input type="hidden" name="ponto_dia_id" id="justDiaId" value="">
                <input type="hidden" name="espelho_retorno" value="<?= (int) $espelho['id'] ?>">
                <input type="hidden" name="retorno_data_inicio" value="<?= View::e($dataInicio) ?>">
                <input type="hidden" name="retorno_data_fim" value="<?= View::e($dataFim) ?>">
                <div class="form-field" style="grid-column:1/-1;">
                    <label class="form-label">Tipo</label>
                    <select class="form-control" name="tipo_id" required>
                        <?php foreach ($tiposJustificativa as $t): ?>
                            <option value="<?= (int)$t['id'] ?>"><?= View::e($t['nome']) ?><?= !empty($t['abona_falta']) ? ' (abona falta)' : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-field"><label class="form-label" for="justInicio">Início</label><input class="form-control" type="date" name="data_inicio" id="justInicio" required value="<?= View::e($dataInicio) ?>"></div>
                <div class="form-field"><label class="form-label" for="justFim">Fim</label><input class="form-control" type="date" name="data_fim" id="justFim" required value="<?= View::e($dataInicio) ?>"></div>
                <div class="form-field" style="grid-column:1/-1;"><label class="form-label">Observação</label><textarea class="form-control" name="observacao"></textarea></div>
                <div class="form-field" style="grid-column:1/-1;"><label class="form-label">Anexo</label><input class="form-control" type="file" name="anexo"></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" type="button" data-modal-close>Cancelar</button>
                <button class="btn btn-primary" type="submit">Salvar</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-backdrop" id="tiposJustModal">
    <div class="modal">
        <form method="post" action="/rh/ponto/justificativa-tipos">
            <input type="hidden" name="espelho_id" value="<?= (int)$espelho['id'] ?>">
            <input type="hidden" name="data_inicio" value="<?= View::e($dataInicio) ?>">
            <input type="hidden" name="data_fim" value="<?= View::e($dataFim) ?>">
            <div class="modal-header"><h3 class="card-title">Tipos de justificativa</h3><button class="btn btn-ghost btn-sm" type="button" data-modal-close>Fechar</button></div>
            <div class="modal-body section-stack">
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>Código</th><th>Nome</th><th>Abona</th></tr></thead>
                        <tbody>
                        <?php foreach ($tiposJustificativa as $t): ?>
                            <tr>
                                <td><?= View::e((string)$t['codigo']) ?></td>
                                <td><?= View::e((string)$t['nome']) ?></td>
                                <td><?= !empty($t['abona_falta']) ? 'Sim' : 'Não' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="form-grid cols-2">
                    <div class="form-field"><label class="form-label">Código</label><input class="form-control" name="codigo" required></div>
                    <div class="form-field"><label class="form-label">Nome</label><input class="form-control" name="nome" required></div>
                    <div class="form-field"><label class="text-sm"><input type="checkbox" name="abona_falta" value="1"> Abona falta</label></div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" type="button" data-modal-close>Fechar</button>
                <button class="btn btn-primary" type="submit">Adicionar tipo</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-backdrop" id="reabrirModal">
    <div class="modal">
        <form id="reabrirForm" method="post" action="/rh/ponto/<?= (int)$espelho['id'] ?>/status">
            <div class="modal-header"><h3 class="card-title" id="reabrirTitulo">Reabrir espelho</h3><button class="btn btn-ghost btn-sm" type="button" data-modal-close>Fechar</button></div>
            <div class="modal-body">
                <input type="hidden" name="status" value="em_tratamento">
                <input type="hidden" name="espelho_retorno" value="<?= (int) $espelho['id'] ?>">
                <input type="hidden" name="data_inicio" value="<?= View::e($dataInicio) ?>">
                <input type="hidden" name="data_fim" value="<?= View::e($dataFim) ?>">
                <div class="alert alert-warning">Reabertura cria nova versão e invalida PDFs anteriores.</div>
                <div class="form-field"><label class="form-label">Justificativa</label><textarea class="form-control" name="justificativa" required></textarea></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" type="button" data-modal-close>Cancelar</button>
                <button class="btn btn-primary" type="submit">Reabrir</button>
            </div>
        </form>
    </div>
</div>

<script>
document.querySelectorAll('.ponto-time:not([readonly])').forEach((input) => {
  input.addEventListener('input', () => {
    let v = input.value.replace(/[^\d]/g, '').slice(0, 4);
    if (v.length >= 3) v = v.slice(0, 2) + ':' + v.slice(2);
    input.value = v;
  });
  input.addEventListener('change', () => {
    const form = document.getElementById(input.getAttribute('form'));
    if (form) form.requestSubmit();
  });
});
document.querySelectorAll('[data-modal-open="justModal"]').forEach((btn) => {
  btn.addEventListener('click', () => {
    document.getElementById('justForm').action = '/rh/ponto/' + btn.dataset.espelhoId + '/justificativa';
    document.getElementById('justDiaId').value = btn.dataset.diaId || '';
    const inicio = document.getElementById('justInicio');
    const fim = document.getElementById('justFim');
    inicio.value = btn.dataset.diaData || inicio.defaultValue;
    fim.value = btn.dataset.diaData || fim.defaultValue;
  });
});
document.querySelectorAll('[data-modal-open="reabrirModal"]').forEach((btn) => {
  btn.addEventListener('click', () => {
    document.getElementById('reabrirForm').action = '/rh/ponto/' + btn.dataset.espelhoId + '/status';
    document.getElementById('reabrirTitulo').textContent = 'Reabrir espelho de ' + btn.dataset.competencia;
  });
});
</script>
