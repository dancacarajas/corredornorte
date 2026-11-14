<?php

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
            default => 'Atualizado com sucesso.',
        };
        ?>
    </div>
<?php endif; ?>
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
                    · Período <?= View::e($rotuloPeriodo) ?>
                </div>
            </div>
            <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;">
                <span class="pill pill-brand">Período · <?= View::e($statusPeriodo) ?></span>
                <?php if ($periodoBloqueado): ?>
                    <button class="btn btn-secondary btn-sm" type="button" data-modal-open="reabrirModal"
                            data-espelho-id="<?= (int) $espelhoAcaoId ?>"
                            data-competencia="<?= View::e($rotuloPeriodo) ?>">Reabrir período</button>
                <?php else: ?>
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

    <?php foreach ($dias as $diaForm):
        // Folga/feriado também têm formulário: trabalho nesses dias gera HE (100% só domingo/feriado).
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
                <tr class="<?= View::e($rowClass) ?>">
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

                    <?php
                    $isDescanso = $isFolga || $isFeriado;
                    $temBatidaDescanso = $isDescanso && (
                        !empty($dia['ent1']) || !empty($dia['sai1']) || !empty($dia['ent2']) || !empty($dia['sai2'])
                        || (int) ($dia['total_trab_min'] ?? 0) > 0
                    );
                    $rotuloDescanso = $isFeriado
                        ? ('Feriado' . (!empty($dia['ocorrencia_resumo']) ? ' · ' . (string) $dia['ocorrencia_resumo'] : ''))
                        : 'Folga';
                    ?>
                    <?php if ($isDescanso): ?>
                        <td colspan="4">
                            <div class="ponto-folga-cell" style="margin-bottom:.35rem;">
                                <?= View::e($rotuloDescanso) ?>
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
                        <td><?= $cell((int)($dia['total_trab_min'] ?? 0), $calc) ?></td>
                        <td class="val-muted">—</td>
                        <td class="val-muted">—</td>
                        <td class="val-muted">—</td>
                        <td class="val-muted">—</td>
                        <td class="val-muted">—</td>
                        <td><?= $temBatidaDescanso ? $cell((int)($dia['he_100_min'] ?? 0), $calc) : '<span class="val-muted">—</span>' ?></td>
                        <td><?= $cell((int)($dia['adn_min'] ?? 0), $calc) ?></td>
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
        <div class="box"><span>HE <?= (int) $pctHeLabel ?>%</span><strong><?= View::e($calc->fmt($totHe50)) ?></strong></div>
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

<?php if ($heDeclaracoes !== []): ?>
<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">HE Cliente (Relatório de Atividades)</h2>
            <p class="card-subtitle">Confira no SharePoint e aplique no cartão, ou marque divergência.</p>
        </div>
    </div>
    <div class="card-body table-responsive">
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
                                        onclick="return confirm('Aplicar esta HE no cartão (ajustar saída)?');">Aplicar</button>
                            </form>
                            <form method="post" action="/rh/ponto/<?= (int) $espelho['id'] ?>/he-ra/<?= (int) $decl['ra_dia_id'] ?>/divergente" style="display:inline;">
                                <button class="btn btn-ghost btn-sm" type="submit"
                                        onclick="return confirm('Marcar como divergente (não aplica no ponto)?');">Divergente</button>
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
</section>
<?php endif; ?>

<?php if ($heAutorizadas !== []): ?>
<style>
#heDetalheModal .modal-he-detalhe{width:min(720px,100%);padding:0;overflow:hidden;border:1px solid rgba(224,96,8,.14);box-shadow:0 28px 70px rgba(17,17,17,.22)}
#heDetalheModal .he-detalhe-panel[hidden]{display:none!important}
#heDetalheModal .he-modal-hero{padding:1.3rem 1.4rem 1.15rem;background:radial-gradient(circle at top right,rgba(255,255,255,.22),transparent 42%),linear-gradient(135deg,#FF8A2B 0%,#E06008 48%,#C85206 100%);color:#fff}
#heDetalheModal .he-modal-hero-top{display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;margin-bottom:1rem}
#heDetalheModal .he-modal-kicker{font-size:.72rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:rgba(255,255,255,.82);margin-bottom:.25rem}
#heDetalheModal .he-modal-title{margin:0;font-family:"Plus Jakarta Sans",Manrope,sans-serif;font-size:1.5rem;font-weight:800;letter-spacing:-.02em}
#heDetalheModal .he-modal-hero-actions{display:inline-flex;align-items:center;gap:.55rem}
#heDetalheModal .he-modal-hero-actions .btn-ghost{color:rgba(255,255,255,.92)}
#heDetalheModal .he-modal-hero-actions .btn-ghost:hover{background:rgba(255,255,255,.14);color:#fff}
#heDetalheModal .he-status-pill{display:inline-flex;align-items:center;padding:.28rem .7rem;border-radius:999px;background:rgba(255,255,255,.2);border:1px solid rgba(255,255,255,.42);color:#fff;font-size:.75rem;font-weight:800}
#heDetalheModal .he-hero-metrics{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.65rem}
#heDetalheModal .he-metric{padding:.75rem .85rem;border-radius:12px;background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.22);backdrop-filter:blur(4px)}
#heDetalheModal .he-metric span{display:block;font-size:.68rem;text-transform:uppercase;letter-spacing:.06em;color:rgba(255,255,255,.8);margin-bottom:.25rem}
#heDetalheModal .he-metric strong{font-size:1.02rem;font-weight:800;font-variant-numeric:tabular-nums;color:#fff}
#heDetalheModal .he-modal-body{padding:1.2rem 1.35rem .2rem;background:linear-gradient(180deg,#FFF8F2 0%,#fff 30%)}
#heDetalheModal .he-meta-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.75rem;margin-bottom:1.15rem}
#heDetalheModal .he-meta-card{padding:.9rem;border-radius:14px;background:#fff;border:1px solid #E8E8EA;box-shadow:0 8px 22px rgba(17,17,17,.04)}
#heDetalheModal .he-meta-card span{display:block;font-size:.7rem;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:#6B7280;margin-bottom:.3rem}
#heDetalheModal .he-meta-card strong{display:block;font-size:.95rem;font-weight:800;color:#111}
#heDetalheModal .he-meta-card em{display:block;margin-top:.15rem;font-style:normal;font-size:.8rem;color:#6B7280}
#heDetalheModal .he-meta-card-wide{grid-column:1/-1}
#heDetalheModal .he-meta-card-wide p{margin:0;font-size:.92rem;line-height:1.45;color:#111;white-space:pre-wrap}
#heDetalheModal .he-timeline-wrap{margin:0 0 1.15rem;padding:1.05rem 1.05rem 1.1rem;border-radius:16px;background:#fff;border:1px solid #E5E7EB}
#heDetalheModal .he-timeline-head{margin-bottom:1rem}
#heDetalheModal .he-timeline-head h4{margin:0;font-family:"Plus Jakarta Sans",Manrope,sans-serif;font-size:1.02rem;font-weight:800}
#heDetalheModal .he-timeline-head span{display:block;margin-top:.18rem;font-size:.82rem;color:#6B7280}
#heDetalheModal .he-timeline{margin:0;padding:0;display:flex;flex-direction:column;gap:0}
#heDetalheModal .he-timeline-item{display:grid;grid-template-columns:32px minmax(0,1fr);gap:.9rem;position:relative;padding-bottom:1.05rem}
#heDetalheModal .he-timeline-item:last-child{padding-bottom:0}
#heDetalheModal .he-timeline-rail{position:relative;width:32px;display:flex;justify-content:center}
#heDetalheModal .he-timeline-item:not(:last-child) .he-timeline-rail::after{content:"";position:absolute;top:28px;bottom:-14px;left:50%;width:3px;transform:translateX(-50%);background:linear-gradient(180deg,#E06008 0%,#F0B989 100%);border-radius:999px}
#heDetalheModal .he-timeline-dot{width:24px;height:24px;min-width:24px;min-height:24px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;background:#E06008;color:#fff;box-shadow:0 0 0 4px rgba(224,96,8,.16);z-index:2;font-size:13px;font-weight:800;line-height:1}
#heDetalheModal .he-timeline-dot-send::before{content:"+"}
#heDetalheModal .he-timeline-dot-ok{background:#12B76A;box-shadow:0 0 0 4px rgba(18,183,106,.16)}
#heDetalheModal .he-timeline-dot-ok::before{content:"✓";font-size:12px}
#heDetalheModal .he-timeline-card{padding:.9rem 1rem;border-radius:14px;background:linear-gradient(180deg,#FFF8F1 0%,#fff 100%);border:1px solid #F0E2D6}
#heDetalheModal .he-timeline-item.is-success .he-timeline-card{background:linear-gradient(180deg,#F1FBF6 0%,#fff 100%);border-color:#D7F0E3}
#heDetalheModal .he-timeline-card-top{display:flex;align-items:baseline;justify-content:space-between;gap:.75rem;margin-bottom:.35rem}
#heDetalheModal .he-timeline-card-top strong{font-size:.96rem;font-weight:800}
#heDetalheModal .he-timeline-card-top time{font-size:.78rem;color:#6B7280;white-space:nowrap;font-variant-numeric:tabular-nums}
#heDetalheModal .he-timeline-card p{margin:0;font-size:.88rem;color:#374151;line-height:1.4}
#heDetalheModal .he-timeline-result{margin-top:.7rem;padding:.6rem .75rem;border-radius:10px;background:rgba(18,183,106,.09);color:#027A48;font-size:.84rem;font-weight:700}
#heDetalheModal .he-modal-footer{display:flex;justify-content:flex-end;padding:0 1.35rem 1.25rem}
@media (max-width:700px){
#heDetalheModal .he-hero-metrics,#heDetalheModal .he-meta-grid{grid-template-columns:1fr}
#heDetalheModal .he-modal-hero-top{flex-direction:column}
#heDetalheModal .he-timeline-card-top{flex-direction:column;align-items:flex-start;gap:.2rem}
}
</style>
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
</script>
