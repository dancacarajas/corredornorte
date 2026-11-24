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
$heAnexosPorDia = $heAnexosPorDia ?? [];
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
            'he-todas-aplicadas' => 'Todas as HEs declaradas do período foram aplicadas no cartão de ponto.',
            'he-divergente' => 'HE marcada como divergente (não aplicada).',
            'previsto-aplicado' => 'Horários previstos aplicados nos dias úteis pendentes.',
            'divergencia-resolvida' => 'Divergência liberada para conferência. Próximos passos: Conferir → Fechar → Gerar PDF → Reenviar e-mail.',
            'divergencia-reenviada' => 'Divergência liberada para conferência. Próximos passos: Conferir → Fechar → Gerar PDF → Reenviar e-mail.',
            'folha-pdf-link' => 'PDF gerado e link de assinatura pronto neste cartão.',
            'folha-email' => 'E-mail com link de assinatura enviado ao colaborador.',
            'justificativa' => 'Justificativa salva. O ponto do período foi recalculado.',
            'justificativa-removida' => 'Justificativa removida. O ponto do período foi recalculado.',
            'turno-autorizado' => 'Alteração temporária de turno aplicada com anexo PDF. Ponto e adicional noturno recalculados.',
            'turno-removido' => 'Autorização de alteração de turno removida.',
            default => 'Atualizado com sucesso.',
        };
        ?>
    </div>
<?php endif; ?>
<?php if (!empty($error)): ?><div class="alert alert-danger"><?= View::e((string)$error) ?></div><?php endif; ?>

<?php
$docFolha = $docFolha ?? null;
$portalUrlFolha = $portalUrlFolha ?? null;
$cltConformidade = $cltConformidade ?? [
    'por_dia' => [],
    'resumo' => ['total' => 0, 'bloqueios' => 0, 'alertas' => 0, 'dias_afetados' => 0, 'contexto_escala' => ''],
];
$cltPorDia = is_array($cltConformidade['por_dia'] ?? null) ? $cltConformidade['por_dia'] : [];
$cltResumo = is_array($cltConformidade['resumo'] ?? null) ? $cltConformidade['resumo'] : ['total' => 0, 'bloqueios' => 0, 'alertas' => 0, 'dias_afetados' => 0, 'contexto_escala' => ''];
$turnoAutorizacoes = $turnoAutorizacoes ?? [];
$turnoAutorizacoesPorData = $turnoAutorizacoesPorData ?? [];
?>

<?php if ($turnoAutorizacoes !== []): ?>
<section class="card" style="margin-bottom:1rem;">
    <div class="card-header">
        <div>
            <h2 class="card-title">Alterações temporárias de turno autorizadas</h2>
            <p class="card-subtitle">Períodos com troca de horário/escala liberados mediante anexo PDF do cliente.</p>
        </div>
    </div>
    <div class="card-body" style="display:flex;flex-direction:column;gap:.6rem;">
        <?php foreach ($turnoAutorizacoes as $ta): ?>
            <div class="turno-auth-row">
                <div class="turno-auth-info">
                    <strong><?= date('d/m/Y', strtotime((string) $ta['data_inicio'])) ?> a <?= date('d/m/Y', strtotime((string) $ta['data_fim'])) ?></strong>
                    <span class="badge badge-neutral"><?= View::e(substr((string) $ta['entrada1'], 0, 5)) ?><?= !empty($ta['saida1']) ? ' / ' . View::e(substr((string) $ta['saida1'], 0, 5)) . ' / ' . View::e(substr((string) ($ta['entrada2'] ?? ''), 0, 5)) : '' ?> / <?= View::e(substr((string) $ta['saida2'], 0, 5)) ?></span>
                    <?php if (!empty($ta['adicional_noturno'])): ?>
                        <span class="badge badge-warning">Ad. Noturno</span>
                    <?php endif; ?>
                    <span class="text-sm muted"><?= View::e((string) ($ta['motivo'] ?? '')) ?></span>
                </div>
                <div class="turno-auth-actions">
                    <a class="btn btn-secondary btn-sm" href="/rh/ponto/<?= (int) $espelho['id'] ?>/turno-autorizacao/<?= (int) $ta['id'] ?>" target="_blank" rel="noopener">Abrir PDF</a>
                    <?php if (!$periodoBloqueado): ?>
                        <form method="post" action="/rh/ponto/<?= (int) $espelho['id'] ?>/turno-autorizacao/<?= (int) $ta['id'] ?>/excluir" style="display:inline;">
                            <input type="hidden" name="_csrf" value="<?= View::e(Csrf::token()) ?>">
                            <input type="hidden" name="espelho_retorno" value="<?= (int) $espelho['id'] ?>">
                            <input type="hidden" name="data_inicio" value="<?= View::e($dataInicio) ?>">
                            <input type="hidden" name="data_fim" value="<?= View::e($dataFim) ?>">
                            <button class="btn btn-ghost btn-sm" type="submit"
                                    data-confirm-title="Remover autorização de turno?"
                                    data-confirm="O anexo PDF da alteração temporária de turno será excluído."
                                    data-confirm-ok="Remover">Remover</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<section class="ponto-clt-banner<?= ((int) ($cltResumo['total'] ?? 0) === 0) ? ' is-hidden' : '' ?>" id="pontoCltBanner" aria-label="Conformidade trabalhista CLT">
    <div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:.75rem;">
        <div>
            <strong style="color:#991B1B;" id="pontoCltBannerTitle">
                ⚠ Conformidade CLT: <?= (int) ($cltResumo['bloqueios'] ?? 0) ?> ilegalidade(s) e <?= (int) ($cltResumo['alertas'] ?? 0) ?> alerta(s) em <?= (int) ($cltResumo['dias_afetados'] ?? 0) ?> dia(s)
            </strong>
            <p class="text-sm" style="margin:.2rem 0 0;color:#7F1D1D;" id="pontoCltBannerSub">
                Regra aplicada conforme lotação/escala: <strong><?= View::e((string) ($cltResumo['contexto_escala'] ?? 'Padrão CLT')) ?></strong>. Clique no selo <strong>CLT</strong> em cada dia abaixo para ver o artigo violado e o motivo legal.
            </p>
        </div>
    </div>
</section>

<?php if (!empty($divergenciasAbertas) || $statusPeriodo === 'em_divergencia'): ?>
<section class="ponto-div-banner" aria-label="Divergência apontada pelo colaborador">
    <div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:flex-start;gap:.75rem;">
        <div>
            <strong style="color:#991B1B;">Divergência apontada pelo colaborador — assinatura pausada</strong>
            <p class="text-sm" style="margin:.25rem 0 0;color:#7F1D1D;">
                Ajuste as batidas ou justificativas abaixo e libere o cartão para <strong>conferência</strong>.
                Depois: Conferir → Fechar → Gerar PDF → Reenviar e-mail (a Medição também volta a essas etapas).
            </p>
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
        <button class="btn btn-primary btn-sm" type="submit"
                data-confirm-title="Liberar para conferência?"
                data-confirm="O cartão volta para em tratamento. Você precisará conferir, fechar e reemitir o PDF antes de reenviar ao colaborador."
                data-confirm-ok="Liberar para conferência">Liberar para conferência</button>
    </form>
</section>
<?php endif; ?>

<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Folha / PDF / assinatura</h2>
            <p class="card-subtitle">Emita e envie sem sair deste cartão — o link nasce junto com o PDF</p>
        </div>
    </div>
    <div class="card-body" style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:center;">
        <form method="post" action="/rh/ponto/<?= (int) $espelho['id'] ?>/folha/gerar" style="display:inline;">
            <input type="hidden" name="espelho_retorno" value="<?= (int) $espelho['id'] ?>">
            <input type="hidden" name="data_inicio" value="<?= View::e($dataInicio) ?>">
            <input type="hidden" name="data_fim" value="<?= View::e($dataFim) ?>">
            <button class="btn btn-primary btn-sm" type="submit"
                    data-confirm-title="Gerar PDF e link?"
                    data-confirm="Fecha o período se necessário, gera o PDF e cria o token de assinatura no mesmo clique."
                    data-confirm-ok="Gerar PDF e link">Gerar PDF e link</button>
        </form>
        <?php if (is_array($docFolha) && !empty($docFolha['portal_token'])): ?>
            <form method="post" action="/rh/ponto/<?= (int) $espelho['id'] ?>/folha/enviar" style="display:inline;">
                <input type="hidden" name="espelho_retorno" value="<?= (int) $espelho['id'] ?>">
                <input type="hidden" name="data_inicio" value="<?= View::e($dataInicio) ?>">
                <input type="hidden" name="data_fim" value="<?= View::e($dataFim) ?>">
                <input type="hidden" name="documento_id" value="<?= (int) $docFolha['id'] ?>">
                <button class="btn btn-secondary btn-sm" type="submit">Enviar e-mail com link</button>
            </form>
            <a class="btn btn-ghost btn-sm" href="/rh/folha/documento/<?= (int) $docFolha['id'] ?>" target="_blank" rel="noopener noreferrer">Abrir PDF</a>
            <?php if (!empty($portalUrlFolha)): ?>
                <button class="btn btn-ghost btn-sm" type="button"
                        onclick="window.copyTextWithToast && window.copyTextWithToast(<?= json_encode($portalUrlFolha, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP) ?>, 'Link copiado!')">Copiar link</button>
            <?php endif; ?>
        <?php else: ?>
            <span class="text-sm muted">Ainda sem PDF ativo neste cartão.</span>
        <?php endif; ?>
    </div>
</section>

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
        <form id="<?= $fid ?>" method="post" action="/rh/ponto/<?= (int) $diaForm['espelho_id'] ?>/dia" class="batida-form" data-dia-id="<?= (int) $diaForm['id'] ?>" enctype="multipart/form-data">
            <input type="hidden" name="he_justificativa" id="he-just-<?= (int) $diaForm['id'] ?>" value="<?= View::e((string) (($heAnexosPorDia[(int) $diaForm['id']]['justificativa'] ?? ''))) ?>">
            <input type="file" name="he_autorizacao" id="he-file-<?= (int) $diaForm['id'] ?>" accept=".pdf,.png,.jpg,.jpeg,.webp,application/pdf,image/*" hidden>
            <input type="hidden" name="clt_confirmado" id="clt-conf-<?= (int) $diaForm['id'] ?>" value="0">
            <input type="hidden" name="clt_justificativa" id="clt-just-<?= (int) $diaForm['id'] ?>" value="">
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
                            <?php
                            $heAnexoDia = $heAnexosPorDia[(int) $dia['id']] ?? null;
                            $heMinDia = (int) ($dia['horas_extras_min'] ?? 0);
                            if ($heAnexoDia): ?>
                                <a class="ponto-he-chip" style="background:var(--success-soft);color:var(--success);text-decoration:none;"
                                   href="/rh/ponto/<?= (int) $dia['espelho_id'] ?>/he-autorizacao/<?= (int) $heAnexoDia['id'] ?>"
                                   target="_blank" rel="noopener"
                                   title="Autorização de HE anexada">HE OK</a>
                            <?php elseif ($heMinDia > 5 && !$locked): ?>
                                <button class="ponto-he-chip" type="button"
                                        style="background:var(--warning-soft);color:var(--ink);"
                                        data-he-upload="<?= (int) $dia['id'] ?>"
                                        title="Anexar autorização de HE (obrigatório)">+HE</button>
                            <?php elseif ($heMinDia > 5): ?>
                                <span class="ponto-he-chip" style="background:var(--danger-soft);color:var(--danger);" title="HE sem autorização anexada">HE?</span>
                            <?php endif; ?>
                            <?php
                            $turnoAuthDia = $turnoAutorizacoesPorData[(string) $dia['data']] ?? null;
                            if (is_array($turnoAuthDia)): ?>
                                <a class="ponto-he-chip" style="background:#FFF7ED;color:#C2410C;border:1px solid #FDBA74;text-decoration:none;"
                                   href="/rh/ponto/<?= (int) $espelho['id'] ?>/turno-autorizacao/<?= (int) $turnoAuthDia['id'] ?>"
                                   target="_blank" rel="noopener"
                                   title="Alteração de turno autorizada via PDF (<?= View::e(substr((string) $turnoAuthDia['entrada1'], 0, 5)) ?>–<?= View::e(substr((string) $turnoAuthDia['saida2'], 0, 5)) ?>)">TURNO PDF</a>
                            <?php endif; ?>
                            <?php
                            $cltViolacoesDia = $cltPorDia[(int) $dia['id']] ?? [];
                            if ($cltViolacoesDia !== []):
                                $temBloqueioClt = false;
                                foreach ($cltViolacoesDia as $cv) {
                                    if (($cv['severidade'] ?? '') === 'bloqueio') {
                                        $temBloqueioClt = true;
                                        break;
                                    }
                                }
                                $cltJson = json_encode($cltViolacoesDia, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
                            ?>
                                <button class="ponto-clt-chip <?= $temBloqueioClt ? 'is-bloqueio' : 'is-alerta' ?>"
                                        type="button"
                                        data-clt-dia-id="<?= (int) $dia['id'] ?>"
                                        data-clt-dia-data="<?= View::e(date('d/m/Y', $ts)) ?>"
                                        data-clt-violacoes="<?= View::e((string) $cltJson) ?>"
                                        title="<?= View::e($cltViolacoesDia[0]['codigo'] . ': ' . $cltViolacoesDia[0]['titulo']) ?>">
                                    CLT (<?= count($cltViolacoesDia) ?>)
                                </button>
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
        <div class="box"><span>Horário</span><strong><?= View::e((string) ($espelho['horario_codigo'] ?? $espelho['horario_nome'] ?? '—')) ?></strong></div>
    </div>

    <?php if ($heDeclaracoes !== []):
        $temHeDeclarada = false;
        foreach ($heDeclaracoes as $declCheck) {
            if (($declCheck['he_status'] ?? '') === 'declarada') {
                $temHeDeclarada = true;
                break;
            }
        }
    ?>
    <div class="ponto-tab-panel" data-ponto-panel="he" hidden>
        <div class="card-header" style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:.75rem;">
            <div>
                <h2 class="card-title">HE Cliente (Relatório de Atividades)</h2>
                <p class="card-subtitle">Confira no SharePoint e aplique no cartão, ou marque divergência.</p>
            </div>
            <?php if ($temHeDeclarada && empty($periodoBloqueado)): ?>
                <form method="post" action="/rh/ponto/<?= (int) $espelho['id'] ?>/he-cliente/aplicar-todas" style="display:inline;">
                    <input type="hidden" name="_csrf" value="<?= View::e(Csrf::token()) ?>">
                    <input type="hidden" name="espelho_retorno" value="<?= (int) $espelho['id'] ?>">
                    <input type="hidden" name="data_inicio" value="<?= View::e($dataInicio) ?>">
                    <input type="hidden" name="data_fim" value="<?= View::e($dataFim) ?>">
                    <button class="btn btn-primary btn-sm" type="submit"
                            data-confirm-title="Aplicar todas as HEs declaradas?"
                            data-confirm="Todas as horas extras com status declarada neste período serão aplicadas no cartão de ponto."
                            data-confirm-ok="Aplicar todas"
                            data-confirm-tone="brand">✓ Aplicar todas as HEs declaradas deste período</button>
                </form>
            <?php endif; ?>
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

<div class="modal-backdrop" id="heAutorizacaoModal">
    <div class="modal">
        <form id="heAutorizacaoForm" novalidate>
            <div class="modal-header">
                <h3 class="card-title">Autorização de hora extra</h3>
                <button class="btn btn-ghost btn-sm" type="button" data-modal-close>Fechar</button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="heModalDiaId" value="">
                <div class="alert alert-warning" id="heModalAlerta" style="margin-bottom:.9rem;">
                    Este dia gerou hora extra em relação à escala. Informe a justificativa e anexe a autorização.
                </div>
                <div class="form-grid cols-1">
                    <div class="form-field">
                        <label class="form-label" for="heModalJustificativa">Justificativa enviada ao gestor <span class="muted">(obrigatório)</span></label>
                        <textarea class="form-control" id="heModalJustificativa" rows="5" required
                                  placeholder="Cole aqui o texto que o colaborador enviou ao gestor para autorizar a HE. Esse conteúdo será lançado no Relatório de Atividades deste dia."></textarea>
                        <p class="form-hint">Evita contradição entre a autorização de HE e a atividade do dia no RA.</p>
                    </div>
                    <div class="form-field">
                        <label class="form-label" for="heModalAnexo">Anexo da autorização de HE <span class="muted">(obrigatório)</span></label>
                        <input class="form-control" type="file" id="heModalAnexo" required
                               accept=".pdf,.png,.jpg,.jpeg,.webp,application/pdf,image/*">
                        <p class="form-hint">PDF ou imagem do SharePoint (até 8 MB).</p>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" type="button" data-modal-close>Cancelar</button>
                <button class="btn btn-primary" type="submit" id="heModalSalvar">Salvar autorização</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-backdrop" id="turnoAutorizacaoModal" role="dialog" aria-modal="true" aria-labelledby="turnoModalTitulo">
    <div class="modal modal-turno-alt">
        <form method="post" action="/rh/ponto/<?= (int) $espelhoAcaoId ?>/turno-autorizacao" enctype="multipart/form-data">
            <input type="hidden" name="_csrf" value="<?= View::e(Csrf::token()) ?>">
            <input type="hidden" name="espelho_retorno" value="<?= (int) $espelho['id'] ?>">
            <input type="hidden" name="data_inicio" value="<?= View::e($dataInicio) ?>">
            <input type="hidden" name="data_fim" value="<?= View::e($dataFim) ?>">
            <div class="modal-header">
                <div>
                    <h3 class="card-title" id="turnoModalTitulo">Alteração temporária de turno</h3>
                    <p class="text-sm muted" style="margin:.15rem 0 0;">Troca de escala (ex.: noturna 19:00–05:00) mediante autorização em PDF do cliente.</p>
                </div>
                <button class="btn btn-ghost btn-sm" type="button" data-modal-close>Fechar</button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning" style="margin-bottom:1rem;">
                    Mantém a mesma carga horária prevista do colaborador, habilita a validação noturna (CLT-15) no período e anexa o PDF ao dossiê da Medição.
                </div>
                <div class="form-grid cols-2">
                    <div class="form-field">
                        <label class="form-label" for="turnoDataIni">Data início *</label>
                        <input class="form-control" type="date" id="turnoDataIni" name="turno_data_inicio"
                               value="<?= View::e($dataInicio) ?>" min="<?= View::e($dataInicio) ?>" max="<?= View::e($dataFim) ?>" required>
                    </div>
                    <div class="form-field">
                        <label class="form-label" for="turnoDataFim">Data fim *</label>
                        <input class="form-control" type="date" id="turnoDataFim" name="turno_data_fim"
                               value="<?= View::e($dataFim) ?>" min="<?= View::e($dataInicio) ?>" max="<?= View::e($dataFim) ?>" required>
                    </div>
                </div>
                <div class="form-grid cols-4 turno-horarios-grid" style="margin-top:.75rem;">
                    <div class="form-field">
                        <label class="form-label" for="turnoEnt1">Ent. 1 *</label>
                        <input class="form-control" type="time" id="turnoEnt1" name="turno_ent1" value="19:00" required>
                    </div>
                    <div class="form-field">
                        <label class="form-label" for="turnoSai1">Sai. 1</label>
                        <input class="form-control" type="time" id="turnoSai1" name="turno_sai1" value="00:00">
                    </div>
                    <div class="form-field">
                        <label class="form-label" for="turnoEnt2">Ent. 2</label>
                        <input class="form-control" type="time" id="turnoEnt2" name="turno_ent2" value="01:00">
                    </div>
                    <div class="form-field">
                        <label class="form-label" for="turnoSai2">Sai. 2 *</label>
                        <input class="form-control" type="time" id="turnoSai2" name="turno_sai2" value="05:00" required>
                    </div>
                </div>
                <div class="form-grid cols-1" style="margin-top:.75rem;">
                    <div class="form-field">
                        <label class="form-label" for="turnoMotivo">Motivo / solicitação do cliente *</label>
                        <textarea class="form-control" id="turnoMotivo" name="turno_motivo" rows="2" required
                                  placeholder="Ex.: Solicitação do gestor Vale para atuação temporária em turno noturno mantendo a carga horária."></textarea>
                    </div>
                    <div class="form-field">
                        <label class="form-label" for="turnoPdf">Anexo PDF da autorização *</label>
                        <input class="form-control" type="file" id="turnoPdf" name="turno_autorizacao_pdf" accept=".pdf,application/pdf" required>
                        <p class="form-hint">Somente PDF (até 10 MB). Fica vinculado ao cartão e ao pacote ZIP da Medição.</p>
                    </div>
                    <div class="turno-checks">
                        <label class="turno-check-item">
                            <input type="checkbox" name="turno_adicional_noturno" value="1" checked>
                            <span>Habilitar adicional noturno no período</span>
                        </label>
                        <label class="turno-check-item">
                            <input type="checkbox" name="turno_aplicar_batidas" value="1" checked>
                            <span>Aplicar horários nos dias úteis do intervalo</span>
                        </label>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" type="button" data-modal-close>Cancelar</button>
                <button class="btn btn-primary" type="submit">Salvar alteração</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-backdrop" id="cltAlertaModal" role="dialog" aria-modal="true" aria-labelledby="cltModalTitulo">
    <div class="modal modal-clt-alerta">
        <form id="cltAlertaForm" novalidate>
            <div class="modal-header">
                <div>
                    <h3 class="card-title" id="cltModalTitulo">Alerta de conformidade trabalhista (CLT)</h3>
                    <p class="text-sm muted" id="cltModalSubtitulo" style="margin:.15rem 0 0;"></p>
                </div>
                <button class="btn btn-ghost btn-sm" type="button" data-modal-close>Fechar</button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="cltModalDiaId" value="">
                <input type="hidden" id="cltModalMode" value="save">
                <div class="alert alert-danger" id="cltModalIntro" style="margin-bottom:.9rem;">
                    O lançamento diverge da legislação trabalhista (CLT) para a lotação/escala configurada. Revise a regra violada antes de prosseguir.
                </div>
                <div id="cltModalLista" class="clt-modal-list"></div>

                <div id="cltModalConfirmBox" class="clt-confirm-box">
                    <div class="form-field">
                        <label class="form-label" for="cltModalJustificativa">
                            Justificativa de exceção <span class="muted">(obrigatório para confirmar)</span>
                        </label>
                        <textarea class="form-control" id="cltModalJustificativa" rows="2"
                                  placeholder="Ex.: Força maior / serviço inadiável (Art. 61 CLT) ou registro fiel de campo para apuração."></textarea>
                        <p class="form-hint">Motivo e usuário ficam gravados na trilha de auditoria CLT (Portaria MTP nº 671/2021).</p>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-primary" type="button" id="cltModalCorrigir" data-modal-close>Voltar e corrigir</button>
                <button class="btn btn-danger-soft" type="submit" id="cltModalForcar">Confirmar exceção</button>
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
        if (data && data.code === 'clt_violation') {
          if (tr) tr.classList.remove('is-saving');
          setPill('Divergência CLT', 'is-error');
          openCltModal(diaId, data.clt || {}, 'save');
          return;
        }
        if (data && data.code === 'needs_he_anexo') {
          if (tr) tr.classList.remove('is-saving');
          setPill('Aguardando autorização HE', 'is-error');
          openHeAutorizacaoModal(diaId, data.error || '');
          return;
        }
        throw new Error((data && data.error) || 'Falha ao salvar batida.');
      }
      // Reset flag de confirmação CLT após salvamento bem-sucedido
      const cltConfReset = document.getElementById('clt-conf-' + diaId);
      const cltJustReset = document.getElementById('clt-just-' + diaId);
      if (cltConfReset) cltConfReset.value = '0';
      if (cltJustReset) cltJustReset.value = '';
      updateRowAndTotals(diaId, data);
      if (data.clt) {
        refreshCltBadges(data.clt);
      }
      if (data.dia && data.dia.he_anexo) {
        const existing = tr ? tr.querySelector('[data-he-upload], a.ponto-he-chip[href*="he-autorizacao"], span.ponto-he-chip') : null;
        const anexoId = Number(data.dia.he_anexo_id || 0);
        if (existing && anexoId > 0) {
          const a = document.createElement('a');
          a.className = 'ponto-he-chip';
          a.style.cssText = 'background:var(--success-soft);color:var(--success);text-decoration:none;';
          a.textContent = 'HE OK';
          a.title = 'Autorização de HE anexada';
          a.target = '_blank';
          a.rel = 'noopener';
          a.href = form.action.replace(/\/dia$/, '') + '/he-autorizacao/' + anexoId;
          existing.replaceWith(a);
        }
      }
      const hhmm = (window.AppClock && window.AppClock.nowTime)
        ? window.AppClock.nowTime(true)
        : new Date().toLocaleTimeString('pt-BR', { timeZone: 'America/Sao_Paulo', hour: '2-digit', minute: '2-digit', second: '2-digit' });
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

  const cltModal = document.getElementById('cltAlertaModal');
  const cltModalForm = document.getElementById('cltAlertaForm');
  const cltModalDiaId = document.getElementById('cltModalDiaId');
  const cltModalMode = document.getElementById('cltModalMode');
  const cltModalSub = document.getElementById('cltModalSubtitulo');
  const cltModalLista = document.getElementById('cltModalLista');
  const cltModalJust = document.getElementById('cltModalJustificativa');
  const cltModalConfirmBox = document.getElementById('cltModalConfirmBox');
  const cltModalForcar = document.getElementById('cltModalForcar');
  const cltModalCorrigir = document.getElementById('cltModalCorrigir');

  const escapeHtml = (str) => String(str || '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');

  const openCltModal = (diaId, cltData, mode) => {
    if (!cltModal || !cltModalLista) return;
    const violacoes = Array.isArray(cltData.violacoes) ? cltData.violacoes : [];
    const dataStr = cltData.data ? String(cltData.data) : '';
    const escalaStr = cltData.contexto_escala ? String(cltData.contexto_escala) : '';

    if (cltModalDiaId) cltModalDiaId.value = String(diaId || '');
    if (cltModalMode) cltModalMode.value = mode || 'save';
    if (cltModalJust) cltModalJust.value = '';
    if (cltModalSub) {
      cltModalSub.textContent = [dataStr ? ('Dia ' + dataStr) : '', escalaStr].filter(Boolean).join(' · ');
    }

    cltModalLista.innerHTML = violacoes.map((v) => {
      const isBloq = v.severidade === 'bloqueio';
      const pillCls = isBloq ? 'clt-pill-bloq' : 'clt-pill-alert';
      const pillTxt = isBloq ? 'ILEGALIDADE CLT' : 'ALERTA TRABALHISTA';
      return `
        <div class="clt-item ${isBloq ? 'is-bloqueio' : 'is-alerta'}">
          <div class="clt-item-head">
            <span class="clt-code">${escapeHtml(v.codigo)}</span>
            <span class="${pillCls}">${pillTxt}</span>
          </div>
          <div class="clt-item-title">${escapeHtml(v.titulo)}</div>
          <div class="clt-item-base"><strong>Base legal:</strong> ${escapeHtml(v.base_legal)}</div>
          <p class="clt-item-desc">${escapeHtml(v.explicacao)}</p>
          <div class="clt-item-rec"><strong>Orientação:</strong> ${escapeHtml(v.acao_recomendada)}</div>
        </div>
      `;
    }).join('');

    const isSaveMode = (mode === 'save');
    if (cltModalConfirmBox) {
      cltModalConfirmBox.hidden = !isSaveMode;
      cltModalConfirmBox.style.display = isSaveMode ? '' : 'none';
    }
    if (cltModalForcar) {
      cltModalForcar.hidden = !isSaveMode;
      cltModalForcar.style.display = isSaveMode ? '' : 'none';
    }
    if (cltModalCorrigir) {
      cltModalCorrigir.textContent = isSaveMode ? 'Voltar e corrigir' : 'Entendi';
    }

    cltModal.classList.add('is-open');
    if (cltModalCorrigir) cltModalCorrigir.focus();
  };

  const refreshCltBadges = (cltState) => {
    const porDia = (cltState && cltState.por_dia) ? cltState.por_dia : {};
    const resumo = (cltState && cltState.resumo) ? cltState.resumo : {};
    const banner = document.getElementById('pontoCltBanner');
    const bTitle = document.getElementById('pontoCltBannerTitle');
    const bSub = document.getElementById('pontoCltBannerSub');
    if (banner) {
      const tot = Number(resumo.total || 0);
      banner.classList.toggle('is-hidden', tot === 0);
      if (bTitle) {
        bTitle.textContent = `⚠ Conformidade CLT: ${Number(resumo.bloqueios || 0)} ilegalidade(s) e ${Number(resumo.alertas || 0)} alerta(s) em ${Number(resumo.dias_afetados || 0)} dia(s)`;
      }
      if (bSub && resumo.contexto_escala) {
        bSub.innerHTML = `Regra aplicada conforme lotação/escala: <strong>${escapeHtml(resumo.contexto_escala)}</strong>. Clique no selo <strong>CLT</strong> em cada dia abaixo para ver o artigo violado e o motivo legal.`;
      }
    }
    document.querySelectorAll('tr[data-dia-row]').forEach((tr) => {
      const dId = tr.getAttribute('data-dia-row') || '';
      const cell = tr.querySelector('.ponto-dia-cell');
      if (!cell) return;
      const oldBtn = cell.querySelector('.ponto-clt-chip');
      if (oldBtn) oldBtn.remove();
      const viols = porDia[dId] || [];
      if (Array.isArray(viols) && viols.length > 0) {
        const hasBloq = viols.some((x) => x.severidade === 'bloqueio');
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'ponto-clt-chip ' + (hasBloq ? 'is-bloqueio' : 'is-alerta');
        btn.setAttribute('data-clt-dia-id', dId);
        btn.setAttribute('data-clt-dia-data', (tr.querySelector('.ponto-dia-label')?.textContent || '').trim());
        btn.setAttribute('data-clt-violacoes', JSON.stringify(viols));
        btn.title = `${viols[0].codigo}: ${viols[0].titulo}`;
        btn.textContent = `CLT (${viols.length})`;
        cell.appendChild(btn);
      }
    });
  };

  if (cltModalForm) {
    cltModalForm.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      const diaId = cltModalDiaId ? cltModalDiaId.value : '';
      const just = (cltModalJust?.value || '').trim();
      if (!just) {
        if (window.Toast && typeof window.Toast.show === 'function') {
          window.Toast.show('Informe a justificativa obrigatória de exceção trabalhista.', 'warning');
        }
        cltModalJust?.focus();
        return;
      }
      const form = diaId ? document.getElementById('batida-' + diaId) : null;
      const confEl = diaId ? document.getElementById('clt-conf-' + diaId) : null;
      const justEl = diaId ? document.getElementById('clt-just-' + diaId) : null;
      if (!form || !confEl || !justEl) return;

      confEl.value = '1';
      justEl.value = just;
      cltModal.classList.remove('is-open');
      await submitInlineDia(form);
    });
  }

  document.addEventListener('click', (ev) => {
    const btn = ev.target.closest('.ponto-clt-chip');
    if (!btn) return;
    const diaId = btn.getAttribute('data-clt-dia-id') || '';
    const diaData = btn.getAttribute('data-clt-dia-data') || '';
    let viols = [];
    try {
      viols = JSON.parse(btn.getAttribute('data-clt-violacoes') || '[]');
    } catch (_) {
      viols = [];
    }
    openCltModal(diaId, {
      data: diaData,
      contexto_escala: <?= json_encode((string) ($cltResumo['contexto_escala'] ?? ''), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP) ?>,
      violacoes: viols,
    }, 'view');
  });

  const heModal = document.getElementById('heAutorizacaoModal');
  const heModalForm = document.getElementById('heAutorizacaoForm');
  const heModalDiaId = document.getElementById('heModalDiaId');
  const heModalJust = document.getElementById('heModalJustificativa');
  const heModalAnexo = document.getElementById('heModalAnexo');
  const heModalAlerta = document.getElementById('heModalAlerta');

  const openHeAutorizacaoModal = (diaId, mensagem) => {
    if (!heModal || !heModalDiaId || !heModalJust || !heModalAnexo) return;
    const justHidden = document.getElementById('he-just-' + diaId);
    heModalDiaId.value = String(diaId || '');
    heModalJust.value = (justHidden && justHidden.value) ? justHidden.value : '';
    heModalAnexo.value = '';
    if (heModalAlerta) {
      heModalAlerta.textContent = mensagem && String(mensagem).trim()
        ? String(mensagem).trim()
        : 'Este dia gerou hora extra em relação à escala. Informe a justificativa e anexe a autorização.';
    }
    heModal.classList.add('is-open');
    heModalJust.focus();
  };

  if (heModalForm) {
    heModalForm.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      const diaId = heModalDiaId ? heModalDiaId.value : '';
      const form = diaId ? document.getElementById('batida-' + diaId) : null;
      const justHidden = diaId ? document.getElementById('he-just-' + diaId) : null;
      const fileHidden = diaId ? document.getElementById('he-file-' + diaId) : null;
      const just = (heModalJust?.value || '').trim();
      const file = heModalAnexo?.files?.[0] || null;

      if (!just) {
        if (window.Toast && typeof window.Toast.show === 'function') {
          window.Toast.show('Informe a justificativa de HE.', 'warning');
        }
        heModalJust?.focus();
        return;
      }
      if (!file) {
        if (window.Toast && typeof window.Toast.show === 'function') {
          window.Toast.show('Anexe a autorização de HE (PDF ou imagem).', 'warning');
        }
        heModalAnexo?.focus();
        return;
      }
      if (!form || !justHidden || !fileHidden) {
        if (window.Toast && typeof window.Toast.show === 'function') {
          window.Toast.show('Não foi possível vincular o dia do cartão.', 'danger');
        }
        return;
      }

      justHidden.value = just;
      const dt = new DataTransfer();
      dt.items.add(file);
      fileHidden.files = dt.files;
      heModal.classList.remove('is-open');
      await submitInlineDia(form);
    });
  }

  document.querySelectorAll('[data-he-upload]').forEach((btn) => {
    btn.addEventListener('click', () => {
      openHeAutorizacaoModal(btn.getAttribute('data-he-upload') || '', '');
    });
  });

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

