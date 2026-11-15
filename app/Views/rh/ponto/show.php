<?php

use App\Helpers\Auth;
use App\Helpers\Csrf;
use App\Helpers\View;
use App\Services\CalculoPontoService;

/** @var array $espelho */
/** @var list<array> $competencias */
/** @var list<array> $dias */
/** @var array<string,array> $heAutorizadas */
/** @var list<array> $tiposJustificativa */
/** @var list<array> $justificativas */
/** @var CalculoPontoService $calc */
/** @var string $dataInicio */
/** @var string $dataFim */
/** @var string $statusPeriodo */
/** @var bool $periodoBloqueado */
/** @var int $espelhoAcaoId */

$heAutorizadas = $heAutorizadas ?? [];
$heDeclaracoes = $heDeclaracoes ?? [];

$dataInicio = $dataInicio ?? sprintf('%04d-%02d-01', (int)$espelho['ano'], (int)$espelho['mes']);
$dataFim = $dataFim ?? date('Y-m-t', strtotime($dataInicio));
$statusPeriodo = $statusPeriodo ?? (string) ($espelho['status'] ?? 'em_aberto');
$periodoBloqueado = $periodoBloqueado ?? in_array($statusPeriodo, ['fechado', 'enviado', 'assinado'], true);
$espelhoAcaoId = $espelhoAcaoId ?? (int) $espelho['id'];

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
$pctHeCounts = [];
foreach ($dias as $dSum) {
    $totHe50 += (int) ($dSum['he_50_min'] ?? 0);
    $totHe100 += (int) ($dSum['he_100_min'] ?? 0);
    $totAdn += (int) ($dSum['adn_min'] ?? 0);
    $totDesc += (int) ($dSum['desconto_min'] ?? 0);
    $totFalta += (int) ($dSum['horas_falta_min'] ?? 0);
    $totAtraso += (int) ($dSum['atraso_bruto_min'] ?? 0);
    $pctDia = (int) ($dSum['percentual_he'] ?? 50);
    if ($pctDia < 1) {
        $pctDia = 50;
    }
    $pctHeCounts[$pctDia] = ($pctHeCounts[$pctDia] ?? 0) + 1;
}
arsort($pctHeCounts);
$pctHeLabel = (int) (array_key_first($pctHeCounts) ?: 50);
$rotuloPeriodo = date('d/m/Y', strtotime($dataInicio)) . ' a ' . date('d/m/Y', strtotime($dataFim));
$statusBloqueados = ['fechado', 'enviado', 'assinado'];
?>

<?php if (!empty($flash)): ?>
    <div class="alert alert-success">
        <?php
        echo match ((string) $flash) {
            'he-aplicada' => 'HE aplicada no cartão de ponto.',
            'he-divergente' => 'HE marcada como divergente (não aplicada).',
            'previsto-aplicado' => 'Horários previstos aplicados nos dias úteis pendentes.',
            'divergencia-resolvida' => 'Divergência concluída! O espelho voltou para tratamento.',
            'justificativa' => 'Justificativa salva. O ponto do período foi recalculado.',
            'justificativa-removida' => 'Justificativa removida. O ponto do período foi recalculado.',
            default => 'Atualizado com sucesso.',
        };
        ?>
    </div>
<?php endif; ?>
<?php if (!empty($error)): ?><div class="alert alert-danger"><?= View::e((string)$error) ?></div><?php endif; ?>

<?php if (!empty($divergenciasAbertas) || $statusPeriodo === 'em_divergencia'): ?>
<section class="ponto-div-banner" aria-label="Divergência apontada pelo colaborador">
    <div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:flex-start;gap:.75rem;">
        <div>
            <strong style="color:#991B1B;">Divergência apontada pelo colaborador — assinatura pausada</strong>
            <p class="text-sm" style="margin:.25rem 0 0;color:#7F1D1D;">Ajuste as batidas ou justificativas abaixo e conclua o tratamento para liberar o cartão para fechamento e nova assinatura.</p>
        </div>
    </div>
    <?php if (!empty($divergenciasAbertas)): ?>
        <ul class="ponto-div-list">
            <?php foreach ($divergenciasAbertas as $div): ?>
                <li>
                    <strong><?= !empty($div['data_ocorrencia']) ? date('d/m/Y', strtotime((string) $div['data_ocorrencia'])) : 'Geral do período' ?>:</strong>
                    <?= View::e((string) ($div['motivo'] ?? '')) ?>
                    <span class="muted">(<?= !empty($div['created_at']) ? date('d/m/Y H:i', strtotime((string) $div['created_at'])) : '' ?>)</span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <form method="post" action="/rh/ponto/<?= (int) $espelhoAcaoId ?>/resolver-divergencia" style="display:flex;flex-wrap:wrap;gap:.5rem;align-items:center;">
        <input type="hidden" name="espelho_retorno" value="<?= (int) $espelho['id'] ?>">
        <input type="hidden" name="data_inicio" value="<?= View::e($dataInicio) ?>">
        <input type="hidden" name="data_fim" value="<?= View::e($dataFim) ?>">
        <input class="form-control" type="text" name="resolucao_obs" maxlength="500"
               placeholder="Observação da correção para o histórico (opcional)"
               style="flex:1;min-width:220px;background:#fff;">
        <button class="btn btn-primary btn-sm" type="submit">Concluir divergência e liberar</button>
    </form>
</section>
<?php endif; ?>

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
                    · Período <?= View::e($rotuloPeriodo) ?>
                </div>
            </div>
            <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;">
                <span class="ponto-save-pill" id="pontoSaveState" aria-live="polite"></span>
                <span class="pill pill-brand" id="pontoStatusBadge">Período · <?= View::e($statusPeriodo) ?></span>
                <?php if ($periodoBloqueado): ?>
                    <button class="btn btn-secondary btn-sm" type="button" data-modal-open="reabrirModal"
                            data-espelho-id="<?= (int) $espelhoAcaoId ?>"
                            data-competencia="<?= View::e($rotuloPeriodo) ?>">Reabrir período</button>
                <?php else: ?>
                    <form method="post" action="/rh/ponto/<?= (int) $espelhoAcaoId ?>/apontar-previsto" style="display:inline;">
                        <input type="hidden" name="espelho_retorno" value="<?= (int) $espelho['id'] ?>">
                        <input type="hidden" name="data_inicio" value="<?= View::e($dataInicio) ?>">
                        <input type="hidden" name="data_fim" value="<?= View::e($dataFim) ?>">
                        <button class="btn btn-ghost btn-sm" type="submit"
                                data-confirm-title="Preencher dias pendentes?"
                                data-confirm="Aplica os horários previstos da escala apenas nos dias úteis que ainda estão totalmente em branco."
                                data-confirm-ok="Aplicar previstos">Preencher previstos</button>
                    </form>
                    <form method="post" action="/rh/ponto/<?= (int) $espelhoAcaoId ?>/status" style="display:inline-flex;gap:.5rem;flex-wrap:wrap;">
                        <input type="hidden" name="espelho_retorno" value="<?= (int) $espelho['id'] ?>">
                        <input type="hidden" name="aplicar_periodo" value="1">
                        <input type="hidden" name="data_inicio" value="<?= View::e($dataInicio) ?>">
                        <input type="hidden" name="data_fim" value="<?= View::e($dataFim) ?>">
                        <button class="btn btn-secondary btn-sm" type="submit" name="status" value="conferido">Conferir período</button>
                        <button class="btn btn-primary btn-sm" type="submit" name="status" value="fechado">Fechar período</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($heDeclaracoes !== [] || $justificativas !== []): ?>
    <div class="ponto-top-tabs" role="tablist" aria-label="Visões do cartão de ponto">
        <button type="button" class="ponto-tab-btn is-active" role="tab" aria-selected="true" data-ponto-tab="grade">Cartão diário</button>
        <?php if ($heDeclaracoes !== []):
            $hePend = count(array_filter($heDeclaracoes, static fn(array $d): bool => ($d['he_status'] ?? '') === 'declarada'));
        ?>
            <button type="button" class="ponto-tab-btn" role="tab" aria-selected="false" data-ponto-tab="he">
                HE Cliente <span class="ponto-tab-count"><?= $hePend > 0 ? $hePend : count($heDeclaracoes) ?></span>
            </button>
        <?php endif; ?>
        <?php if ($justificativas !== []): ?>
            <button type="button" class="ponto-tab-btn" role="tab" aria-selected="false" data-ponto-tab="just">
                Justificativas <span class="ponto-tab-count"><?= count($justificativas) ?></span>
            </button>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php foreach ($dias as $diaForm):
        // Folga/feriado também têm formulário: trabalho nesses dias gera HE (100% só domingo/feriado).
        $fid = 'batida-' . (int)$diaForm['id'];
    ?>
        <form id="<?= $fid ?>" method="post" action="/rh/ponto/<?= (int) $diaForm['espelho_id'] ?>/dia" class="batida-form" data-dia-id="<?= (int) $diaForm['id'] ?>">
            <input type="hidden" name="ponto_dia_id" value="<?= (int)$diaForm['id'] ?>">
            <input type="hidden" name="espelho_retorno" value="<?= (int) $espelho['id'] ?>">
            <input type="hidden" name="data_inicio" value="<?= View::e($dataInicio) ?>">
            <input type="hidden" name="data_fim" value="<?= View::e($dataFim) ?>">
        </form>
    <?php endforeach; ?>

    <div class="ponto-table-wrap ponto-tab-panel" data-ponto-panel="grade">
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
                <th>HE <?= (int) $pctHeLabel ?>%</th>
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
                $heDia = $heAutorizadas[(string) $dia['data']] ?? null;
                $temHeAutorizada = is_array($heDia);
                $rowClass = trim(
                    ($isFolga ? 'is-folga' : (!empty($dia['dia_falta']) ? 'is-falta' : ''))
                    . ($temHeAutorizada ? ' is-he-autorizada' : '')
                );
                $formId = 'batida-' . (int)$dia['id'];
                $locked = in_array($dia['espelho_status'], $statusBloqueados, true);
                $heTitulo = '';
                $heMinLabel = '';
                if ($temHeAutorizada) {
                    $heMin = (int) ($heDia['minutos_he'] ?? 0);
                    $heMinLabel = sprintf('%02d:%02d', intdiv($heMin, 60), $heMin % 60);
                    $heTitulo = sprintf(
                        'HE autorizada %s–%s · %s · %s',
                        substr((string) ($heDia['hora_inicial'] ?? ''), 0, 5),
                        substr((string) ($heDia['hora_final'] ?? ''), 0, 5),
                        $heMinLabel,
                        (string) ($heDia['modalidade'] ?? '')
                    );
                }
            ?>
                <tr class="<?= View::e($rowClass) ?>" data-dia-row="<?= (int) $dia['id'] ?>" data-is-descanso="<?= ($isFolga || $isFeriado) ? '1' : '0' ?>">
                    <td class="dia">
                        <div class="ponto-dia-cell">
                            <span class="ponto-dia-label"><?= date('d/m', $ts) ?> <?= $dow ?></span>
                            <?php if ($temHeAutorizada): ?>
                                <button class="ponto-he-chip" type="button"
                                        title="<?= View::e($heTitulo) ?>"
                                        aria-label="<?= View::e($heTitulo) ?>"
                                        data-modal-open="heDetalheModal"
                                        data-he-id="<?= (int) $heDia['id'] ?>">HE</button>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td data-col="ok">
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

                    <?php
                    $isDescanso = $isFolga || $isFeriado;
                    $dowNum = (int) date('w', $ts); // 0=dom .. 6=sab
                    // Regra rígida: HE 100% só domingo ou feriado. Sábado/folga de escala → % da lotação.
                    $he100Aplicavel = $isFeriado || $dowNum === 0;
                    $pctDia = (int) ($dia['percentual_he'] ?? $pctHeLabel);
                    if ($pctDia < 1) {
                        $pctDia = $pctHeLabel;
                    }
                    $temBatidaDescanso = $isDescanso && (
                        !empty($dia['ent1']) || !empty($dia['sai1']) || !empty($dia['ent2']) || !empty($dia['sai2'])
                        || (int) ($dia['total_trab_min'] ?? 0) > 0
                    );
                    if ($isFeriado) {
                        $resumoOc = trim((string) ($dia['ocorrencia_resumo'] ?? ''));
                        $rotuloDescanso = ($resumoOc !== '' && str_starts_with($resumoOc, 'Feriado'))
                            ? $resumoOc
                            : 'Feriado';
                    } else {
                        $rotuloDescanso = match ($dowNum) {
                            6 => 'Folga (sábado)',
                            0 => 'Folga (domingo)',
                            default => 'Folga',
                        };
                    }
                    $hintHeDescanso = $he100Aplicavel
                        ? ' — batidas geram HE 100%'
                        : (' — batidas geram HE ' . $pctDia . '%');
                    ?>
                    <?php if ($isDescanso): ?>
                        <td colspan="4">
                            <div class="ponto-folga-cell" style="margin-bottom:.35rem;">
                                <?= View::e($rotuloDescanso) ?>
                                <span class="text-sm muted"><?= View::e($hintHeDescanso) ?></span>
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
                        <td data-col="trab"><?= $cell((int)($dia['total_trab_min'] ?? 0), $calc) ?></td>
                        <td class="val-muted">—</td>
                        <td class="val-muted">—</td>
                        <td class="val-muted">—</td>
                        <td class="val-muted">—</td>
                        <td data-col="he50"><?= $temBatidaDescanso ? $cell((int)($dia['he_50_min'] ?? 0), $calc) : '<span class="val-muted">—</span>' ?></td>
                        <td data-col="he100"><?= $temBatidaDescanso ? $cell((int)($dia['he_100_min'] ?? 0), $calc) : '<span class="val-muted">—</span>' ?></td>
                        <td data-col="adn"><?= $cell((int)($dia['adn_min'] ?? 0), $calc) ?></td>
                        <td class="val-muted">—</td>
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
                        <td data-col="trab"><?= $cell((int)($dia['total_trab_min'] ?? 0), $calc) ?></td>
                        <td data-col="prev"><?= $cell((int)($dia['horas_previstas_min'] ?? 0), $calc) ?></td>
                        <td data-col="dia_falta" class="<?= !empty($dia['dia_falta']) ? 'val-red' : 'val-muted' ?>"><?= !empty($dia['dia_falta']) ? '1' : '—' ?></td>
                        <td data-col="falta"><?= $cell((int)($dia['horas_falta_min'] ?? 0), $calc, true) ?></td>
                        <td data-col="atraso"><?= $cell((int)($dia['atraso_bruto_min'] ?? 0), $calc, true) ?></td>
                        <td data-col="he50"><?= $cell((int)($dia['he_50_min'] ?? 0), $calc) ?></td>
                        <td data-col="he100"><?= $cell((int)($dia['he_100_min'] ?? 0), $calc) ?></td>
                        <td data-col="adn"><?= $cell((int)($dia['adn_min'] ?? 0), $calc) ?></td>
                        <td data-col="desc"><?= $cell((int)($dia['desconto_min'] ?? 0), $calc, true) ?></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="ponto-totais ponto-tab-panel" data-ponto-panel="grade">
        <div class="box"><span>HE <?= (int) $pctHeLabel ?>%</span><strong data-tot="he50"><?= View::e($calc->fmt($totHe50)) ?></strong></div>
        <div class="box"><span>HE 100%</span><strong data-tot="he100"><?= View::e($calc->fmt($totHe100)) ?></strong></div>
        <div class="box"><span>Ad. Noturno</span><strong data-tot="adn"><?= View::e($calc->fmt($totAdn)) ?></strong></div>
        <div class="box"><span>Horas falta</span><strong class="val-red" data-tot="falta"><?= View::e($calc->fmt($totFalta)) ?></strong></div>
        <div class="box"><span>Atrasos</span><strong data-tot="atraso"><?= View::e($calc->fmt($totAtraso)) ?></strong></div>
        <div class="box"><span>Descontos</span><strong class="val-red" data-tot="desc"><?= View::e($calc->fmt($totDesc)) ?></strong></div>
        <div class="box"><span>Jornada / Escala</span><strong><?= View::e(($espelho['jornada_codigo'] ?? '—').' · '.($espelho['escala_codigo'] ?? '')) ?></strong></div>
    </div>

    <?php if ($heDeclaracoes !== []): ?>
    <div class="ponto-tab-panel" data-ponto-panel="he" hidden>
        <div class="card-header">
            <div>
                <h2 class="card-title">HE Cliente (Relatório de Atividades)</h2>
                <p class="card-subtitle">Confira no SharePoint e aplique no cartão, ou marque divergência.</p>
            </div>
        </div>
        <div class="card-body table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>Data</th>
                    <th>Início</th>
                    <th>Fim</th>
                    <th>Minutos</th>
                    <th>Status</th>
                    <th>Gestor Cliente</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($heDeclaracoes as $decl):
                    $st = (string) ($decl['he_status'] ?? '');
                    $min = (int) ($decl['minutos'] ?? 0);
                    ?>
                    <tr>
                        <td><?= View::e(date('d/m/Y', strtotime((string) $decl['data']))) ?></td>
                        <td><?= View::e(substr((string) $decl['he_hora_inicio'], 0, 5)) ?></td>
                        <td><?= View::e(substr((string) $decl['he_hora_fim'], 0, 5)) ?></td>
                        <td><?= sprintf('%02d:%02d', intdiv($min, 60), $min % 60) ?></td>
                        <td><?= View::e($st) ?></td>
                        <td><?= View::e((string) ($decl['gestor_cliente_nome'] ?? '—')) ?></td>
                        <td style="white-space:nowrap;">
                            <?php if ($st === 'declarada' && empty($periodoBloqueado)): ?>
                                <form method="post" action="/rh/ponto/<?= (int) $espelho['id'] ?>/he-ra/<?= (int) $decl['ra_dia_id'] ?>/aplicar" style="display:inline;">
                                    <button class="btn btn-primary btn-sm" type="submit"
                                            data-confirm-title="Aplicar HE no cartão?"
                                            data-confirm="A saída do dia será ajustada com base na HE declarada no relatório."
                                            data-confirm-ok="Aplicar"
                                            data-confirm-tone="brand">Aplicar</button>
                                </form>
                                <form method="post" action="/rh/ponto/<?= (int) $espelho['id'] ?>/he-ra/<?= (int) $decl['ra_dia_id'] ?>/divergente" style="display:inline;">
                                    <button class="btn btn-ghost btn-sm" type="submit"
                                            data-confirm-title="Marcar como divergente?"
                                            data-confirm="A HE não será aplicada no cartão de ponto."
                                            data-confirm-ok="Marcar divergente"
                                            data-confirm-tone="warning">Divergente</button>
                                </form>
                            <?php elseif ($st === 'aplicada'): ?>
                                <span class="muted text-sm">Aplicada<?= !empty($decl['he_aplicada_em']) ? ' em ' . date('d/m/Y H:i', strtotime((string) $decl['he_aplicada_em'])) : '' ?></span>
                            <?php else: ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($justificativas !== []): ?>
    <div class="ponto-tab-panel" data-ponto-panel="just" hidden>
        <div class="card-header">
            <div>
                <h2 class="card-title">Justificativas lançadas</h2>
                <p class="card-subtitle">Tipos que abonam falta removem a falta dos dias cobertos no cartão.</p>
            </div>
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Período</th><th>Tipo</th><th>Efeito</th><th>Obs.</th><th>Anexo</th><?php if (!$periodoBloqueado && Auth::can('ponto.editar')): ?><th></th><?php endif; ?></tr></thead>
                <tbody>
                <?php foreach ($justificativas as $j): ?>
                    <tr>
                        <td><?= View::e($j['data_inicio'].' → '.$j['data_fim']) ?></td>
                        <td><?= View::e((string)$j['tipo_nome']) ?></td>
                        <td><?= !empty($j['abona_falta']) ? 'Abona falta' : 'Registro (não abona)' ?></td>
                        <td><?= View::e((string)($j['observacao'] ?? '')) ?></td>
                        <td><?= !empty($j['anexo_path']) ? '<a href="/rh/ponto/anexos/' . (int) $j['id'] . '" target="_blank" rel="noopener noreferrer">Arquivo</a>' : '—' ?></td>
                        <?php if (!$periodoBloqueado && Auth::can('ponto.editar')): ?>
                            <td>
                                <form method="post"
                                      action="/rh/ponto/<?= (int) $espelho['id'] ?>/justificativa/<?= (int) $j['id'] ?>/excluir"
                                      onsubmit="return confirm('Remover esta justificativa? Dias abonados voltam a ser calculados (falta se sem batida).');">
                                    <input type="hidden" name="_csrf" value="<?= View::e(Csrf::token()) ?>">
                                    <button class="btn btn-ghost btn-sm" type="submit">Remover</button>
                                </form>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</section>

<?php if ($heAutorizadas !== []): ?>
<div class="modal-backdrop" id="heDetalheModal">
    <div class="modal modal-he-detalhe">
        <?php foreach ($heAutorizadas as $heItem):
            $heId = (int) $heItem['id'];
            $heMin = (int) ($heItem['minutos_he'] ?? 0);
            $heDur = sprintf('%02d:%02d', intdiv($heMin, 60), $heMin % 60);
            $criadoEm = !empty($heItem['created_at']) ? date('d/m/Y H:i', strtotime((string) $heItem['created_at'])) : '—';
            $aprovadoEm = !empty($heItem['aprovado_em']) ? date('d/m/Y H:i', strtotime((string) $heItem['aprovado_em'])) : '—';
            $horaIni = substr((string) ($heItem['hora_inicial'] ?? ''), 0, 5);
            $horaFim = substr((string) ($heItem['hora_final'] ?? ''), 0, 5);
            $sai2Ant = !empty($heItem['sai2_anterior']) ? substr((string) $heItem['sai2_anterior'], 0, 5) : '—';
            $modalidadeLabel = match ((string) ($heItem['modalidade'] ?? '')) {
                'viagem' => 'Viagem',
                'remoto' => 'Remoto',
                default => 'Presencial',
            };
            $gestorNome = (string) ($heItem['gestor_nome'] ?? '—');
            $aprovadorNome = (string) ($heItem['aprovador_nome'] ?? $heItem['gestor_nome'] ?? '—');
        ?>
        <div class="he-detalhe-panel" data-he-panel="<?= $heId ?>" hidden>
            <div class="he-modal-hero">
                <div class="he-modal-hero-top">
                    <div>
                        <div class="he-modal-kicker">Autorização de HE</div>
                        <h3 class="he-modal-title"><?= View::e(date('d/m/Y', strtotime((string) $heItem['data']))) ?></h3>
                    </div>
                    <div class="he-modal-hero-actions">
                        <span class="he-status-pill">Aprovada</span>
                        <button class="btn btn-ghost btn-sm" type="button" data-modal-close>Fechar</button>
                    </div>
                </div>
                <div class="he-hero-metrics">
                    <div class="he-metric">
                        <span>Período</span>
                        <strong><?= View::e($horaIni) ?> – <?= View::e($horaFim) ?></strong>
                    </div>
                    <div class="he-metric">
                        <span>Duração</span>
                        <strong><?= View::e($heDur) ?></strong>
                    </div>
                    <div class="he-metric">
                        <span>Ponto</span>
                        <strong><?= View::e($sai2Ant) ?> → <?= View::e($horaFim) ?></strong>
                    </div>
                </div>
            </div>

            <div class="he-modal-body">
                <div class="he-meta-grid">
                    <article class="he-meta-card">
                        <span>Modalidade</span>
                        <strong><?= View::e($modalidadeLabel) ?></strong>
                    </article>
                    <article class="he-meta-card">
                        <span>Contrato Vale</span>
                        <strong><?= View::e((string) ($heItem['contrato_codigo'] ?? '')) ?></strong>
                        <em><?= View::e((string) ($heItem['contrato_nome'] ?? '')) ?></em>
                    </article>
                    <article class="he-meta-card">
                        <span>Frente de serviço</span>
                        <strong><?= View::e((string) ($heItem['os_codigo'] ?? '')) ?></strong>
                        <em><?= View::e((string) ($heItem['os_nome'] ?? '')) ?></em>
                    </article>
                    <article class="he-meta-card he-meta-card-wide">
                        <span>Relatório da atividade</span>
                        <p><?= nl2br(View::e((string) ($heItem['relatorio_atividade'] ?? ''))) ?></p>
                    </article>
                </div>

                <div class="he-timeline-wrap">
                    <div class="he-timeline-head">
                        <h4>Linha do tempo</h4>
                        <span>Histórico da solicitação e aprovação</span>
                    </div>
                    <div class="he-timeline">
                        <div class="he-timeline-item is-done">
                            <div class="he-timeline-rail" aria-hidden="true">
                                <span class="he-timeline-dot he-timeline-dot-send"></span>
                            </div>
                            <div class="he-timeline-card">
                                <div class="he-timeline-card-top">
                                    <strong>Solicitação enviada</strong>
                                    <time><?= View::e($criadoEm) ?></time>
                                </div>
                                <p>Encaminhada para <strong><?= View::e($gestorNome) ?></strong><?php if (!empty($heItem['gestor_matricula'])): ?> · Mat. <?= View::e((string) $heItem['gestor_matricula']) ?><?php endif; ?>.</p>
                            </div>
                        </div>
                        <div class="he-timeline-item is-done is-success">
                            <div class="he-timeline-rail" aria-hidden="true">
                                <span class="he-timeline-dot he-timeline-dot-ok"></span>
                            </div>
                            <div class="he-timeline-card">
                                <div class="he-timeline-card-top">
                                    <strong>De acordo do gestor</strong>
                                    <time><?= View::e($aprovadoEm) ?></time>
                                </div>
                                <p>Aprovado por <strong><?= View::e($aprovadorNome) ?></strong><?php if (!empty($heItem['aprovador_matricula']) || !empty($heItem['gestor_matricula'])): ?> · Mat. <?= View::e((string) ($heItem['aprovador_matricula'] ?? $heItem['gestor_matricula'])) ?><?php endif; ?>.</p>
                                <div class="he-timeline-result">
                                    Última batida ajustada de <b><?= View::e($sai2Ant) ?></b> para <b><?= View::e($horaFim) ?></b>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="he-modal-footer">
                <button class="btn btn-secondary" type="button" data-modal-close>Fechar</button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="modal-backdrop" id="justModal">
    <div class="modal">
        <form id="justForm" method="post" action="/rh/ponto/<?= (int)$espelho['id'] ?>/justificativa" enctype="multipart/form-data">
            <input type="hidden" name="_csrf" value="<?= View::e(Csrf::token()) ?>">
            <div class="modal-header"><h3 class="card-title">Adicionar justificativa</h3><button class="btn btn-ghost btn-sm" type="button" data-modal-close>Fechar</button></div>
            <div class="modal-body">
                <input type="hidden" name="ponto_dia_id" id="justDiaId" value="">
                <input type="hidden" name="espelho_retorno" value="<?= (int) $espelho['id'] ?>">
                <input type="hidden" name="retorno_data_inicio" value="<?= View::e($dataInicio) ?>">
                <input type="hidden" name="retorno_data_fim" value="<?= View::e($dataFim) ?>">
                <p class="text-sm muted" style="margin:0 0 .75rem;">Dia útil sem batida é falta. Se o tipo <strong>abona falta</strong>, esses dias passam a Justificado (sem desconto).</p>
                <div class="form-grid cols-2">
                    <div class="form-field" style="grid-column:1/-1;">
                        <label class="form-label" for="justTipo">Tipo</label>
                        <select class="form-control" name="tipo_id" id="justTipo" required>
                            <?php if ($tiposJustificativa === []): ?>
                                <option value="" disabled selected>Nenhum tipo ativo — cadastre em Tipos de justificativa</option>
                            <?php else: ?>
                                <option value="" disabled selected>Selecione o tipo</option>
                                <?php foreach ($tiposJustificativa as $t): ?>
                                    <option value="<?= (int)$t['id'] ?>"><?= View::e($t['nome']) ?><?= !empty($t['abona_falta']) ? ' (abona falta)' : '' ?></option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="form-field"><label class="form-label" for="justInicio">Início</label><input class="form-control" type="date" name="data_inicio" id="justInicio" required value="<?= View::e($dataInicio) ?>"></div>
                    <div class="form-field"><label class="form-label" for="justFim">Fim</label><input class="form-control" type="date" name="data_fim" id="justFim" required value="<?= View::e($dataInicio) ?>"></div>
                    <div class="form-field" style="grid-column:1/-1;"><label class="form-label">Observação</label><textarea class="form-control" name="observacao"></textarea></div>
                    <div class="form-field" style="grid-column:1/-1;"><label class="form-label">Anexo</label><input class="form-control" type="file" name="anexo"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" type="button" data-modal-close>Cancelar</button>
                <button class="btn btn-primary" type="submit">Salvar</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-backdrop" id="reabrirModal">
    <div class="modal">
        <form id="reabrirForm" method="post" action="/rh/ponto/<?= (int)$espelhoAcaoId ?>/status">
            <div class="modal-header"><h3 class="card-title" id="reabrirTitulo">Reabrir período</h3><button class="btn btn-ghost btn-sm" type="button" data-modal-close>Fechar</button></div>
            <div class="modal-body">
                <input type="hidden" name="status" value="em_tratamento">
                <input type="hidden" name="aplicar_periodo" value="1">
                <input type="hidden" name="espelho_retorno" value="<?= (int) $espelho['id'] ?>">
                <input type="hidden" name="data_inicio" value="<?= View::e($dataInicio) ?>">
                <input type="hidden" name="data_fim" value="<?= View::e($dataFim) ?>">
                <div class="alert alert-warning">Reabertura cria nova versão e invalida PDFs anteriores do período.</div>
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
(() => {
  const savePill = document.getElementById('pontoSaveState');
  const statusBadge = document.getElementById('pontoStatusBadge');
  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

  const setPill = (text, tone) => {
    if (!savePill) return;
    savePill.textContent = text;
    savePill.className = 'ponto-save-pill' + (tone ? ' ' + tone : '');
  };

  const renderMinCell = (cell, min, fmt, isRed) => {
    if (!cell) return;
    if (Number(min || 0) <= 0) {
      cell.innerHTML = '<span class="val-muted">—</span>';
      return;
    }
    const span = document.createElement('span');
    if (isRed) span.className = 'val-red';
    span.textContent = fmt || '00:00';
    cell.innerHTML = '';
    cell.appendChild(span);
  };

  const updateRowAndTotals = (diaId, data) => {
    const tr = document.querySelector(`tr[data-dia-row="${diaId}"]`);
    const dia = data.dia || {};
    const totais = data.totais || {};
    if (tr) {
      tr.classList.remove('is-saving', 'is-save-error');
      const isDescanso = tr.getAttribute('data-is-descanso') === '1';
      if (!isDescanso) {
        tr.classList.toggle('is-falta', Number(dia.dia_falta || 0) > 0);
      }

      const okTd = tr.querySelector('td[data-col="ok"]');
      if (okTd) {
        const ok = String(dia.ok_status || 'pendente');
        const span = document.createElement('span');
        if (ok === 'ok' || ok === 'folga' || ok === 'feriado') {
          span.className = `ponto-ok ${ok}`;
          span.title = ok;
          span.textContent = '✓';
        } else {
          span.className = 'ponto-ok falta';
          span.title = ok;
          span.textContent = '✕';
        }
        okTd.innerHTML = '';
        okTd.appendChild(span);
      }

      renderMinCell(tr.querySelector('td[data-col="trab"]'), dia.total_trab_min, dia.total_trab_fmt, false);
      renderMinCell(tr.querySelector('td[data-col="prev"]'), dia.horas_previstas_min, dia.horas_previstas_fmt, false);

      const diaFaltaTd = tr.querySelector('td[data-col="dia_falta"]');
      if (diaFaltaTd) {
        const falta = Number(dia.dia_falta || 0) > 0;
        diaFaltaTd.className = falta ? 'val-red' : 'val-muted';
        diaFaltaTd.textContent = falta ? '1' : '—';
      }

      renderMinCell(tr.querySelector('td[data-col="falta"]'), dia.horas_falta_min, dia.horas_falta_fmt, true);
      renderMinCell(tr.querySelector('td[data-col="atraso"]'), dia.atraso_bruto_min, dia.atraso_bruto_fmt, true);
      renderMinCell(tr.querySelector('td[data-col="he50"]'), dia.he_50_min, dia.he_50_fmt, false);
      renderMinCell(tr.querySelector('td[data-col="he100"]'), dia.he_100_min, dia.he_100_fmt, false);
      renderMinCell(tr.querySelector('td[data-col="adn"]'), dia.adn_min, dia.adn_fmt, false);
      renderMinCell(tr.querySelector('td[data-col="desc"]'), dia.desconto_min, dia.desconto_fmt, true);
    }

    Object.entries({
      he50: totais.he50_fmt,
      he100: totais.he100_fmt,
      adn: totais.adn_fmt,
      falta: totais.falta_fmt,
      atraso: totais.atraso_fmt,
      desc: totais.desconto_fmt,
    }).forEach(([key, val]) => {
      const el = document.querySelector(`[data-tot="${key}"]`);
      if (el && val !== undefined) el.textContent = val;
    });

    if (statusBadge && data.espelho_status) {
      statusBadge.textContent = 'Período · ' + data.espelho_status;
    }
  };

  const pendingSaves = new Map();
  const submitInlineDia = async (form) => {
    const diaId = form.getAttribute('data-dia-id') || '';
    const tr = diaId ? document.querySelector(`tr[data-dia-row="${diaId}"]`) : null;
    if (tr) {
      tr.classList.remove('is-save-error');
      tr.classList.add('is-saving');
    }
    setPill('Salvando…', 'is-saving');

    const fd = new FormData(form);
    if (csrfToken && !fd.has('_csrf')) fd.append('_csrf', csrfToken);
    document.querySelectorAll(`input[form="${form.id}"]`).forEach((inp) => {
      if (inp.name) fd.set(inp.name, inp.value || '');
    });

    const seq = (pendingSaves.get(diaId) || 0) + 1;
    pendingSaves.set(diaId, seq);

    try {
      const res = await fetch(form.action, {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          'X-CSRF-Token': csrfToken,
        },
        body: fd,
      });
      if (pendingSaves.get(diaId) !== seq) return;
      const data = await res.json();
      if (!res.ok || !data.ok) {
        throw new Error(data.error || 'Falha ao salvar batida.');
      }
      updateRowAndTotals(diaId, data);
      const hhmm = new Date().toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
      setPill('Salvo ' + hhmm, 'is-saved');
    } catch (err) {
      if (tr) {
        tr.classList.remove('is-saving');
        tr.classList.add('is-save-error');
      }
      const msg = (err && err.message) ? err.message : 'Erro ao salvar batida.';
      setPill('Erro ao salvar', 'is-error');
      if (window.Toast && typeof window.Toast.show === 'function') {
        window.Toast.show(msg, 'danger');
      }
    }
  };

  const timeInputs = Array.from(document.querySelectorAll('.ponto-time:not([readonly])'));
  timeInputs.forEach((input, idx) => {
    input.addEventListener('input', () => {
      let v = input.value.replace(/[^\d]/g, '').slice(0, 4);
      if (v.length >= 3) v = v.slice(0, 2) + ':' + v.slice(2);
      input.value = v;
    });
    input.addEventListener('change', () => {
      const form = document.getElementById(input.getAttribute('form') || '');
      if (!form) return;
      submitInlineDia(form);
    });
    input.addEventListener('keydown', (ev) => {
      if (ev.key === 'Enter') {
        ev.preventDefault();
        const form = document.getElementById(input.getAttribute('form') || '');
        if (form) submitInlineDia(form);
        const next = timeInputs[idx + 1];
        if (next) next.focus();
      }
    });
  });

  document.querySelectorAll('[data-ponto-tab]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const target = btn.getAttribute('data-ponto-tab');
      document.querySelectorAll('[data-ponto-tab]').forEach((b) => {
        const active = b.getAttribute('data-ponto-tab') === target;
        b.classList.toggle('is-active', active);
        b.setAttribute('aria-selected', active ? 'true' : 'false');
      });
      document.querySelectorAll('[data-ponto-panel]').forEach((panel) => {
        panel.hidden = panel.getAttribute('data-ponto-panel') !== target;
      });
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
      document.getElementById('reabrirTitulo').textContent = 'Reabrir período ' + (btn.dataset.competencia || '');
    });
  });
  document.querySelectorAll('[data-modal-open="heDetalheModal"]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const heId = btn.dataset.heId || '';
      document.querySelectorAll('[data-he-panel]').forEach((panel) => {
        panel.hidden = panel.getAttribute('data-he-panel') !== heId;
      });
    });
  });
})();
</script>

