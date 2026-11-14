<?php

use App\Helpers\View;

$dbOk = $dbOk ?? false;
$periodosGerados = $periodosGerados ?? [];
$dataInicio = $dataInicio ?? '';
$dataFim = $dataFim ?? '';
$painel = $painel ?? null;
$flash = $flash ?? null;
$error = $error ?? null;

$resumo = is_array($painel) ? ($painel['resumo'] ?? []) : [];
$colaboradores = is_array($painel) ? ($painel['colaboradores'] ?? []) : [];
$etapasDef = is_array($painel) ? ($painel['etapas'] ?? []) : [];
$periodoLabel = is_array($painel) ? (string) ($painel['periodo']['label'] ?? '') : '';
$total = (int) ($resumo['total'] ?? 0);
$concluidos = (int) ($resumo['concluidos'] ?? 0);
$pct = (int) ($resumo['percentual'] ?? 0);
$prontoDossie = !empty($resumo['pronto_dossie']);
$proximaAcao = $resumo['proxima_acao'] ?? null;
$elegiveis = is_array($resumo['elegiveis'] ?? null) ? $resumo['elegiveis'] : [];
$filtro = (string) ($filtro ?? '');
$qsBase = ['data_inicio' => $dataInicio, 'data_fim' => $dataFim];
$qs = http_build_query($qsBase);
$acaoHeroMap = [
    'conferir' => ['label' => 'Conferir pontos', 'filtro' => 'conferir', 'verbo' => 'Conferir'],
    'fechar' => ['label' => 'Fechar pontos', 'filtro' => 'fechar', 'verbo' => 'Fechar'],
    'pdf' => ['label' => 'Gerar PDFs', 'filtro' => 'pdf', 'verbo' => 'Gerar PDFs'],
    'link' => ['label' => 'Gerar links de assinatura', 'filtro' => 'link', 'verbo' => 'Gerar links'],
    'email' => ['label' => 'Enviar e-mails', 'filtro' => 'email', 'verbo' => 'Enviar e-mails'],
];
$proximaHero = is_string($proximaAcao) ? ($acaoHeroMap[$proximaAcao] ?? null) : null;
$proximaQtd = ($proximaHero !== null) ? (int) ($elegiveis[$proximaAcao] ?? 0) : 0;

$pendenteNaEtapa = static function (array $c, string $filtro): bool {
    $et = $c['etapas'] ?? [];
    return match ($filtro) {
        'conferir' => empty($et['tratado']),
        'fechar' => !empty($et['tratado']) && empty($et['fechado']),
        'pdf' => !empty($et['fechado']) && empty($et['pdf']),
        'link' => !empty($et['pdf']) && empty($et['link']),
        'email' => !empty($et['link']) && empty($et['email']),
        'assinaturas' => !empty($et['email']) && empty($et['assinaturas']),
        default => true,
    };
};

$colaboradoresFiltrados = $colaboradores;
$modoConsultaLinks = false;
$modoDossie = $filtro === 'dossie';
if ($filtro !== '' && !$modoDossie) {
    $colaboradoresFiltrados = array_values(array_filter(
        $colaboradores,
        static fn(array $c): bool => $pendenteNaEtapa($c, $filtro)
    ));
    // Etapa "Gerar links" zerada: consulta quem já tem link (abrir / copiar).
    if ($filtro === 'link' && $colaboradoresFiltrados === []) {
        $comLink = array_values(array_filter(
            $colaboradores,
            static fn(array $c): bool => !empty($c['portal_url']) || !empty($c['etapas']['link'])
        ));
        if ($comLink !== []) {
            $colaboradoresFiltrados = $comLink;
            $modoConsultaLinks = true;
        }
    }
} elseif ($modoDossie && !$prontoDossie) {
    // Dossiê ainda bloqueado: mostra quem falta chegar a 100%.
    $colaboradoresFiltrados = array_values(array_filter(
        $colaboradores,
        static fn(array $c): bool => empty($c['concluido'])
    ));
}

$statusLabel = static function (string $status): string {
    return match ($status) {
        'em_aberto' => 'Em aberto',
        'em_tratamento' => 'Em tratamento',
        'conferido' => 'Conferido',
        'fechado' => 'Fechado',
        'enviado' => 'Enviado',
        'assinado' => 'Assinado',
        default => $status !== '' ? $status : '—',
    };
};

$proximaEtapa = static function (array $etapas) use ($etapasDef): string {
    foreach ($etapasDef as $def) {
        $key = (string) $def['key'];
        if (empty($etapas[$key])) {
            return (string) ($def['acao'] ?? $def['label']);
        }
    }
    return 'Concluído';
};

$urlFiltro = static function (string $acao) use ($qsBase): string {
    $params = $qsBase;
    if ($acao !== '') {
        $params['filtro'] = $acao;
    }
    return '/rh/medicao?' . http_build_query($params);
};

$filtroPorEtapaKey = [
    'gerado' => '',
    'tratado' => 'conferir',
    'fechado' => 'fechar',
    'pdf' => 'pdf',
    'link' => 'link',
    'email' => 'email',
    'assinaturas' => 'assinaturas',
];
$dossieUrl = '/rh/medicao/dossie?' . $qs;
$errosLote = is_array($errosLote ?? null) ? $errosLote : [];
?>

<?php if ($flash): ?><div class="alert alert-success"><?= View::e((string) $flash) ?></div><?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger">
        <div><?= View::e((string) $error) ?></div>
        <?php if ($errosLote !== []): ?>
            <ul class="batch-errors-list" style="margin-top:.5rem;">
                <?php foreach ($errosLote as $errMsg): ?>
                    <li><?= View::e((string) $errMsg) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
<?php endif; ?>
<?php if (!$dbOk): ?><div class="alert alert-warning">Banco indisponível.</div><?php endif; ?>

<section class="medicao-hero card">
    <div class="card-body medicao-hero-grid">
        <div class="medicao-hero-main">
            <p class="medicao-kicker">Medição do período</p>
            <h2 class="medicao-title">Do cartão ao dossiê, sem perder o fio</h2>
            <p class="medicao-lead">
                Você prepara o lote; o colaborador assina no portal.
                O ZIP só libera quando <strong>todos</strong> tiverem ponto e RA concluídos.
            </p>

            <form method="get" action="/rh/medicao" class="medicao-periodo-form">
                <label class="form-label" for="periodoGerado">Período</label>
                <div class="medicao-periodo-row">
                    <select class="form-control" id="periodoGerado" name="periodo_pick"
                            aria-describedby="periodoHint"
                            onchange="
                        const o=this.options[this.selectedIndex];
                        if(!o||!o.value)return;
                        const [i,f]=o.value.split('|');
                        this.form.data_inicio.value=i;
                        this.form.data_fim.value=f;
                        this.form.submit();
                    ">
                        <option value="">Escolha o período…</option>
                        <?php foreach ($periodosGerados as $p): ?>
                            <?php
                            $val = $p['data_inicio'] . '|' . $p['data_fim'];
                            $sel = ($p['data_inicio'] === $dataInicio && $p['data_fim'] === $dataFim);
                            ?>
                            <option value="<?= View::e($val) ?>" <?= $sel ? 'selected' : '' ?>>
                                <?= View::e($p['label']) ?> · <?= (int) $p['espelhos'] ?> colaboradores
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <input type="hidden" name="data_inicio" value="<?= View::e($dataInicio) ?>">
                    <input type="hidden" name="data_fim" value="<?= View::e($dataFim) ?>">
                </div>
                <p class="form-hint" id="periodoHint">A lista abaixo muda ao trocar o período.</p>
            </form>

            <?php if ($painel && $total > 0 && !$prontoDossie && $proximaHero !== null && $proximaQtd > 0): ?>
                <div class="medicao-next">
                    <div>
                        <span class="medicao-next-label">Próximo passo</span>
                        <strong><?= View::e((string) $proximaHero['label']) ?></strong>
                        <span class="muted">· <?= $proximaQtd ?> colaborador<?= $proximaQtd === 1 ? '' : 'es' ?></span>
                    </div>
                    <a class="btn btn-primary" href="<?= View::e($urlFiltro((string) $proximaHero['filtro'])) ?>">
                        <?= View::e((string) $proximaHero['verbo']) ?>
                    </a>
                </div>
            <?php elseif ($painel && $prontoDossie): ?>
                <div class="medicao-next is-ready">
                    <div>
                        <span class="medicao-next-label">Pronto</span>
                        <strong>Todos concluíram — dossiê liberado</strong>
                    </div>
                    <a class="btn btn-primary" href="/rh/medicao/dossie?<?= View::e($qs) ?>">Baixar ZIP</a>
                </div>
            <?php endif; ?>
        </div>

        <aside class="medicao-score <?= $prontoDossie ? 'is-complete' : '' ?>" aria-label="Progresso do período">
            <div class="medicao-score-ring" style="--pct: <?= max(0, min(100, $pct)) ?>">
                <strong><?= $pct ?>%</strong>
                <span>médio</span>
            </div>
            <div class="medicao-score-meta">
                <p class="medicao-score-ready">
                    <strong><?= $concluidos ?></strong>
                    <span>de <?= $total ?> prontos para o dossiê</span>
                </p>
                <?php if ($periodoLabel !== ''): ?>
                    <p class="medicao-score-period"><?= View::e($periodoLabel) ?></p>
                <?php endif; ?>
                <p class="medicao-score-hint">
                    <?= $prontoDossie
                        ? 'Pacote completo para download.'
                        : 'O % médio sobe a cada etapa; o dossiê exige 100% por pessoa.' ?>
                </p>
                <?php if ($prontoDossie): ?>
                    <a class="btn btn-primary" href="/rh/medicao/dossie?<?= View::e($qs) ?>">Baixar dossiê (ZIP)</a>
                <?php else: ?>
                    <button class="btn btn-secondary" type="button" disabled
                            title="Disponível quando todos estiverem 100%">
                        Dossiê bloqueado
                    </button>
                <?php endif; ?>
            </div>
        </aside>
    </div>
</section>

<?php if ($painel && $etapasDef !== []): ?>
<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Etapas da jornada</h2>
            <p class="card-subtitle">RH prepara o lote · colaborador conclui com RA e ponto assinados</p>
        </div>
    </div>
    <div class="card-body">
        <ol class="medicao-journey">
            <?php foreach ($etapasDef as $i => $etapa): ?>
                <?php
                $key = (string) $etapa['key'];
                $doneCount = 0;
                foreach ($colaboradores as $c) {
                    if (!empty($c['etapas'][$key])) {
                        $doneCount++;
                    }
                }
                $pendentesEtapa = $total - $doneCount;
                $etapaPct = $total > 0 ? (int) round(($doneCount / $total) * 100) : 0;
                $filtroEtapa = $filtroPorEtapaKey[$key] ?? '';
                $selecionada = ($filtroEtapa !== '' && $filtro === $filtroEtapa) || ($filtroEtapa === '' && $filtro === '');
                $estado = $etapaPct >= 100 ? 'is-done' : ($etapaPct > 0 ? 'is-active' : 'is-todo');
                if ($selecionada && $filtroEtapa !== '') {
                    $estado .= ' is-selected';
                }
                ?>
                <li class="medicao-step <?= $estado ?>">
                    <?php if ($filtroEtapa !== ''): ?>
                        <a class="medicao-step-link" href="<?= View::e($urlFiltro($filtro === $filtroEtapa ? '' : $filtroEtapa)) ?>">
                    <?php endif; ?>
                    <span class="medicao-step-num"><?= $i + 1 ?></span>
                    <div>
                        <strong><?= View::e((string) $etapa['label']) ?></strong>
                        <p><?= View::e((string) $etapa['hint']) ?></p>
                        <div class="progress <?= $etapaPct >= 100 ? 'is-success' : '' ?>"><span style="width:<?= $etapaPct ?>%"></span></div>
                        <small><?= $doneCount ?>/<?= $total ?><?php if ($pendentesEtapa > 0): ?> · <?= $pendentesEtapa ?> pendente<?= $pendentesEtapa === 1 ? '' : 's' ?><?php endif; ?></small>
                    </div>
                    <?php if ($filtroEtapa !== ''): ?>
                        </a>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
            <?php
            $dossieSel = $filtro === 'dossie';
            $dossieEstado = $prontoDossie ? 'is-done' : ($concluidos === $total && $total > 0 ? 'is-active' : 'is-todo');
            if ($dossieSel) {
                $dossieEstado .= ' is-selected';
            }
            ?>
            <li class="medicao-step <?= $dossieEstado ?>">
                <a class="medicao-step-link" href="<?= View::e($urlFiltro($dossieSel ? '' : 'dossie')) ?>">
                    <span class="medicao-step-num">8</span>
                    <div>
                        <strong>8. Baixar dossiê</strong>
                        <p>ZIP com pastas por colaborador (ponto + RA)</p>
                        <div class="progress <?= $prontoDossie ? 'is-success' : '' ?>"><span style="width:<?= $prontoDossie ? 100 : 0 ?>%"></span></div>
                        <small><?= $prontoDossie ? '1/1 disponível' : '0/1 · libera com 100%' ?></small>
                    </div>
                </a>
            </li>
        </ol>
    </div>
</section>

<?php
$elegiveis = is_array($resumo['elegiveis'] ?? null) ? $resumo['elegiveis'] : [];
$proximaAcao = $resumo['proxima_acao'] ?? null;
$pendentesFiltro = [
    'conferir' => 0,
    'fechar' => 0,
    'pdf' => 0,
    'link' => 0,
    'email' => 0,
    'assinaturas' => 0,
];
foreach ($colaboradores as $c) {
    foreach ($pendentesFiltro as $acao => $_) {
        if ($pendenteNaEtapa($c, $acao)) {
            $pendentesFiltro[$acao]++;
        }
    }
}
$loteBotoes = [
    [
        'acao' => 'conferir',
        'route' => '/rh/medicao/lote/conferir',
        'label' => '2. Conferir pontos',
        'confirm' => 'Conferir os elegíveis desta etapa (sem HE pendente)?',
    ],
    [
        'acao' => 'fechar',
        'route' => '/rh/medicao/lote/fechar',
        'label' => '3. Fechar pontos',
        'confirm' => 'Fechar os elegíveis já conferidos?',
    ],
    [
        'acao' => 'pdf',
        'route' => '/rh/medicao/lote/pdf',
        'label' => '4. Gerar PDFs',
        'confirm' => 'Gerar PDF dos elegíveis já fechados?',
    ],
    [
        'acao' => 'link',
        'route' => '/rh/medicao/lote/link',
        'label' => '5. Gerar links',
        'confirm' => 'Gerar links dos elegíveis com PDF?',
    ],
    [
        'acao' => 'email',
        'route' => '/rh/medicao/lote/email',
        'label' => '6. Enviar e-mails',
        'confirm' => 'Enviar e-mail só de quem já tem link de assinatura (etapa 5)?',
    ],
];
$rotulosAcao = [
    'conferir' => 'Conferir pontos',
    'fechar' => 'Fechar pontos',
    'pdf' => 'Gerar PDFs',
    'link' => 'Gerar links',
    'email' => 'Enviar e-mails',
    'assinaturas' => 'Assinaturas',
    'dossie' => 'Baixar dossiê',
];
$filtroAtivoLabel = $filtro !== '' ? ($rotulosAcao[$filtro] ?? $filtro) : '';
$qtdFiltro = $filtro !== '' && $filtro !== 'dossie' ? (int) ($pendentesFiltro[$filtro] ?? 0) : $total;
$qtdExecutar = ($filtro !== '' && $filtro !== 'dossie') ? (int) ($elegiveis[$filtro] ?? 0) : 0;
$btnExecutar = null;
foreach ($loteBotoes as $b) {
    if ($b['acao'] === $filtro) {
        $btnExecutar = $b;
        break;
    }
}
?>
<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Ações em lote</h2>
            <p class="card-subtitle">
                Clique na etapa para consultar quem está pendente. Trate até zerar.
                <?php if ($prontoDossie): ?>
                    Período 100% — <strong>Baixar dossiê</strong> disponível.
                <?php elseif (is_string($proximaAcao) && isset($rotulosAcao[$proximaAcao])): ?>
                    Gargalo do período: <strong><?= View::e($rotulosAcao[$proximaAcao]) ?></strong>
                    (<?= (int) ($pendentesFiltro[$proximaAcao] ?? 0) ?>).
                <?php endif; ?>
            </p>
        </div>
    </div>
    <div class="card-body medicao-lote-actions">
        <a class="btn <?= $filtro === '' ? 'btn-secondary is-filter-on' : 'btn-ghost' ?>"
           href="<?= View::e($urlFiltro('')) ?>">Todos
            <span class="medicao-lote-count"><?= $total ?></span>
        </a>
        <?php foreach ($loteBotoes as $btn): ?>
            <?php
            $pend = (int) ($pendentesFiltro[$btn['acao']] ?? 0);
            $eleg = (int) ($elegiveis[$btn['acao']] ?? 0);
            $selecionado = $filtro === $btn['acao'];
            $ehGargalo = $proximaAcao === $btn['acao'];
            if ($selecionado) {
                $classe = 'btn btn-primary is-filter-on';
            } elseif ($ehGargalo) {
                $classe = 'btn btn-secondary';
            } else {
                $classe = 'btn btn-ghost';
            }
            $titulo = $pend === 0
                ? 'Etapa zerada — clique para consultar'
                : ($pend . ' pendente(s)' . ($eleg > 0 ? ', ' . $eleg . ' elegível(is) para lote' : ''));
            ?>
            <a class="<?= View::e($classe) ?>"
               href="<?= View::e($urlFiltro($selecionado ? '' : $btn['acao'])) ?>"
               title="<?= View::e($titulo) ?>">
                <?= View::e($btn['label']) ?>
                <span class="medicao-lote-count"><?= $pend ?></span>
            </a>
        <?php endforeach; ?>
        <a class="btn <?= $filtro === 'assinaturas' ? 'btn-primary is-filter-on' : 'btn-ghost' ?>"
           href="<?= View::e($urlFiltro($filtro === 'assinaturas' ? '' : 'assinaturas')) ?>"
           title="Colaboradores aguardando assinatura de ponto e RA">
            7. Assinaturas
            <span class="medicao-lote-count"><?= (int) $pendentesFiltro['assinaturas'] ?></span>
        </a>
        <?php if ($prontoDossie): ?>
            <a class="btn <?= $filtro === 'dossie' ? 'btn-primary is-filter-on' : 'btn-primary' ?>"
               href="<?= View::e($dossieUrl) ?>"
               title="Download ZIP do dossiê completo">
                8. Baixar dossiê
            </a>
        <?php else: ?>
            <button class="btn btn-ghost" type="button" disabled
                    title="Disponível somente quando todos os colaboradores estiverem 100%">
                8. Baixar dossiê
                <span class="medicao-lote-count"><?= $concluidos ?>/<?= $total ?></span>
            </button>
        <?php endif; ?>
    </div>
    <?php if ($btnExecutar !== null && $qtdExecutar > 0): ?>
        <div class="card-body medicao-lote-exec" style="padding-top:0;">
            <form method="post" action="<?= View::e((string) $btnExecutar['route']) ?>"
                  id="medicaoBatchForm"
                  data-confirm-title="Executar lote?"
                  data-confirm-base="<?= View::e($btnExecutar['confirm']) ?>"
                  data-elegiveis-total="<?= $qtdExecutar ?>">
                <input type="hidden" name="data_inicio" value="<?= View::e($dataInicio) ?>">
                <input type="hidden" name="data_fim" value="<?= View::e($dataFim) ?>">
                <input type="hidden" name="filtro" value="<?= View::e($filtro) ?>">
                <button class="btn btn-primary" type="submit" id="medicaoBatchSubmit">
                    Executar lote: <?= View::e((string) $btnExecutar['label']) ?>
                    <span class="medicao-lote-count" id="medicaoBatchCount"><?= $qtdExecutar ?></span>
                </button>
                <span class="muted text-sm" id="medicaoBatchHint">Aplica aos elegíveis desta etapa (ou apenas aos marcados na tabela).</span>
            </form>
            <div class="batch-progress-card" id="medicaoBatchProgress" hidden style="margin-top:.75rem;">
                <div class="batch-progress-head">
                    <strong id="medicaoBatchTitle">Processando lote...</strong>
                    <span id="medicaoBatchStats" class="muted">0 / 0</span>
                </div>
                <div class="progress"><span id="medicaoBatchBar" style="width:0%"></span></div>
                <ul class="batch-errors-list" id="medicaoBatchErrors" hidden></ul>
                <div id="medicaoBatchReloadWrap" hidden>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="window.location.reload()">Atualizar painel</button>
                </div>
            </div>
        </div>
    <?php elseif ($filtro === 'dossie' && $prontoDossie): ?>
        <div class="card-body medicao-lote-exec" style="padding-top:0;">
            <a class="btn btn-primary" href="<?= View::e($dossieUrl) ?>">Baixar dossiê (ZIP)</a>
            <span class="muted text-sm">Pastas por colaborador com ponto e RA assinados.</span>
        </div>
    <?php elseif ($filtro === 'dossie'): ?>
        <div class="card-body" style="padding-top:0;">
            <p class="muted text-sm" style="margin:0;">Dossiê indisponível — <?= $concluidos ?>/<?= $total ?> concluídos. Finalize as assinaturas restantes.</p>
        </div>
    <?php elseif ($filtro === 'link' && $qtdFiltro === 0): ?>
        <div class="card-body" style="padding-top:0;">
            <p class="muted text-sm" style="margin:0;">Nenhum link pendente — abaixo estão os links já gerados (abrir / copiar).</p>
        </div>
    <?php elseif ($filtro !== '' && $filtro !== 'assinaturas' && $qtdFiltro === 0): ?>
        <div class="card-body" style="padding-top:0;">
            <p class="muted text-sm" style="margin:0;">Etapa zerada — ninguém pendente em <?= View::e($filtroAtivoLabel) ?>.</p>
        </div>
    <?php elseif ($filtro !== '' && $filtro !== 'assinaturas' && $qtdExecutar === 0 && $qtdFiltro > 0): ?>
        <div class="card-body" style="padding-top:0;">
            <p class="muted text-sm" style="margin:0;">Há pendentes, mas ainda não elegíveis para lote (ex.: HE aberta ou etapa anterior incompleta).</p>
        </div>
    <?php endif; ?>
</section>

<section class="card" id="lista-colaboradores">
    <div class="card-header medicao-colab-header">
        <div>
            <h2 class="card-title">Colaboradores</h2>
            <p class="card-subtitle">
                <?php if ($modoDossie && $prontoDossie): ?>
                    Dossiê liberado — todos em 100%
                    · <a href="<?= View::e($urlFiltro('')) ?>">limpar filtro</a>
                <?php elseif ($modoDossie): ?>
                    Dossiê bloqueado — falta <?= count($colaboradoresFiltrados) ?> em 100%
                    (<?= $concluidos ?>/<?= $total ?> concluídos)
                    · <a href="<?= View::e($urlFiltro('')) ?>">limpar filtro</a>
                <?php elseif ($modoConsultaLinks): ?>
                    Links gerados (etapa zerada) — <?= count($colaboradoresFiltrados) ?> colaborador<?= count($colaboradoresFiltrados) === 1 ? '' : 'es' ?>
                    · <a href="<?= View::e($urlFiltro('')) ?>">limpar filtro</a>
                <?php elseif ($filtro !== ''): ?>
                    Filtro: <strong><?= View::e($filtroAtivoLabel) ?></strong>
                    — <?= count($colaboradoresFiltrados) ?> pendente<?= count($colaboradoresFiltrados) === 1 ? '' : 's' ?>
                    · <a href="<?= View::e($urlFiltro('')) ?>">limpar filtro</a>
                <?php else: ?>
                    Verde = missão completa (ponto + RA assinados e etapas anteriores)
                <?php endif; ?>
            </p>
        </div>
        <?php if ($colaboradoresFiltrados !== [] && !($modoDossie && $prontoDossie)): ?>
            <div class="medicao-colab-search">
                <label class="visually-hidden" for="medicaoBuscaNome">Buscar colaborador</label>
                <input type="search" class="form-control" id="medicaoBuscaNome"
                       placeholder="Buscar por nome ou matrícula…"
                       autocomplete="off">
            </div>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <?php if ($colaboradores === []): ?>
            <p class="muted">Nenhum cartão de ponto neste período. Gere o período em Gestão de Ponto.</p>
        <?php elseif ($modoDossie && $prontoDossie): ?>
            <div class="medicao-dossie-ready">
                <p>Todos os colaboradores concluíram a jornada. Baixe o ZIP com uma pasta por colaborador contendo o cartão de ponto e o relatório de atividades assinados.</p>
                <a class="btn btn-primary" href="<?= View::e($dossieUrl) ?>">Baixar dossiê (ZIP)</a>
            </div>
        <?php elseif ($colaboradoresFiltrados === []): ?>
            <p class="muted">Ninguém pendente nesta etapa — filtrado zerado.</p>
        <?php elseif ($modoConsultaLinks): ?>
            <div class="table-wrap">
                <table class="table medicao-table medicao-links-table">
                    <thead>
                    <tr>
                        <th>Colaborador</th>
                        <th>Link de assinatura</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($colaboradoresFiltrados as $c): ?>
                        <?php
                        $portalUrl = (string) ($c['portal_url'] ?? '');
                        $portalPath = !empty($c['portal_token']) ? '/assinar/' . rawurlencode((string) $c['portal_token']) : $portalUrl;
                        $buscaKey = mb_strtolower(trim((string) ($c['nome'] ?? '') . ' ' . (string) ($c['matricula'] ?? '')), 'UTF-8');
                        ?>
                        <tr class="medicao-colab-row" data-search="<?= View::e($buscaKey) ?>">
                            <td>
                                <strong><?= View::e((string) $c['nome']) ?></strong>
                                <div class="muted text-sm"><?= View::e((string) $c['matricula']) ?></div>
                            </td>
                            <td class="medicao-link-cell">
                                <?php if ($portalUrl !== ''): ?>
                                    <code class="medicao-link-url" title="<?= View::e($portalUrl) ?>"><?= View::e($portalUrl) ?></code>
                                <?php else: ?>
                                    <span class="muted">Sem token</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-right medicao-link-actions">
                                <?php if ($portalPath !== ''): ?>
                                    <a class="btn btn-secondary btn-sm" href="<?= View::e($portalPath) ?>" target="_blank" rel="noopener noreferrer">Abrir</a>
                                    <button type="button" class="btn btn-ghost btn-sm medicao-copy-link"
                                            data-link="<?= View::e($portalUrl !== '' ? $portalUrl : $portalPath) ?>">Copiar</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <script>
            (function () {
              document.querySelectorAll('.medicao-copy-link').forEach(function (btn) {
                btn.addEventListener('click', async function () {
                  var link = btn.getAttribute('data-link') || '';
                  if (!link) return;
                  if (window.copyTextWithToast) {
                    const ok = await window.copyTextWithToast(link, 'Link copiado para a área de transferência!');
                    if (ok) {
                      var old = btn.textContent;
                      btn.textContent = 'Copiado';
                      setTimeout(function () { btn.textContent = old; }, 1200);
                    }
                  }
                });
              });
            })();
            </script>
            <p class="muted medicao-colab-empty" id="medicaoBuscaVazioLinks" hidden>Nenhum colaborador encontrado com esse nome.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table medicao-table">
                    <thead>
                    <tr>
                        <th style="width:2.25rem;"><input type="checkbox" id="medicaoSelectAll" aria-label="Selecionar todos"></th>
                        <th>Colaborador</th>
                        <th>Status do ponto</th>
                        <th>Progresso</th>
                        <th>Próxima ação</th>
                        <th>Jornada</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($colaboradoresFiltrados as $c): ?>
                        <?php
                        $done = !empty($c['concluido']);
                        $pctC = (int) ($c['percentual'] ?? 0);
                        $etapas = $c['etapas'] ?? [];
                        $stEsp = (string) ($c['espelho_status'] ?? '');
                        $elegivelAtual = match ($filtro) {
                            'conferir' => empty($etapas['tratado']) && empty($c['he_pendente']),
                            'fechar' => !empty($etapas['tratado']) && empty($etapas['fechado']),
                            'pdf' => !empty($etapas['fechado']) && empty($etapas['pdf']),
                            'link' => !empty($etapas['pdf']) && empty($etapas['link']),
                            'email' => !empty($etapas['link']) && empty($etapas['email']),
                            default => !$done,
                        };
                        $buscaKey = mb_strtolower(trim((string) ($c['nome'] ?? '') . ' ' . (string) ($c['matricula'] ?? '')), 'UTF-8');
                        ?>
                        <tr class="medicao-colab-row <?= $done ? 'medicao-row-done' : '' ?>" data-search="<?= View::e($buscaKey) ?>">
                            <td>
                                <input type="checkbox"
                                       class="medicao-check"
                                       value="<?= (int) $c['espelho_id'] ?>"
                                       data-elegivel="<?= $elegivelAtual ? '1' : '0' ?>"
                                       aria-label="Selecionar <?= View::e((string) $c['nome']) ?>"
                                       <?= $done ? 'disabled' : '' ?>>
                            </td>
                            <td>
                                <strong><?= View::e((string) $c['nome']) ?></strong>
                                <div class="muted text-sm"><?= View::e((string) $c['matricula']) ?></div>
                            </td>
                            <td>
                                <span class="badge <?= in_array($stEsp, ['fechado', 'enviado', 'assinado', 'conferido'], true) ? 'badge-aprovada' : 'badge-pendente' ?>">
                                    <?= View::e($statusLabel($stEsp)) ?>
                                </span>
                                <?php if (!empty($c['he_pendente'])): ?>
                                    <div class="muted text-sm">HE pendente</div>
                                <?php endif; ?>
                            </td>
                            <td style="min-width:9rem;">
                                <div class="medicao-pct <?= $done ? 'is-done' : '' ?>"><?= $pctC ?>%</div>
                                <div class="progress <?= $done ? 'is-success' : ($pctC >= 50 ? 'is-info' : 'is-warning') ?>">
                                    <span style="width:<?= $pctC ?>%"></span>
                                </div>
                            </td>
                            <td>
                                <?php if ($done): ?>
                                    <span class="badge badge-aprovada">100% concluído</span>
                                <?php else: ?>
                                    <?= View::e($proximaEtapa($etapas)) ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="medicao-dots" title="Etapas">
                                    <?php foreach ($etapasDef as $def): ?>
                                        <?php $ok = !empty($etapas[(string) $def['key']]); ?>
                                        <span class="medicao-dot <?= $ok ? 'is-on' : '' ?>" title="<?= View::e((string) $def['label']) ?>"></span>
                                    <?php endforeach; ?>
                                </div>
                            </td>
                            <td class="text-right">
                                <a class="btn btn-ghost btn-sm" href="/rh/ponto/<?= (int) $c['espelho_id'] ?>?<?= View::e($qs) ?>">Ponto</a>
                                <?php if (!empty($c['documento_id'])): ?>
                                    <a class="btn btn-ghost btn-sm" href="/rh/folha/<?= (int) $c['espelho_id'] ?>?<?= View::e($qs) ?>">Folha</a>
                                <?php endif; ?>
                                <?php if (!empty($c['relatorio_id'])): ?>
                                    <a class="btn btn-ghost btn-sm" href="/rh/relatorios-atividades/<?= (int) $c['relatorio_id'] ?>">RA</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="muted medicao-colab-empty" id="medicaoBuscaVazio" hidden>Nenhum colaborador encontrado com esse nome.</p>
        <?php endif; ?>
    </div>
</section>
<script>
(() => {
  const boot = () => {
    const busca = document.getElementById('medicaoBuscaNome');
    const rows = () => Array.from(document.querySelectorAll('#lista-colaboradores .medicao-colab-row'));
    const emptyMsg = document.getElementById('medicaoBuscaVazio')
      || document.getElementById('medicaoBuscaVazioLinks');
    const norm = (s) => String(s || '')
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .toLowerCase()
      .trim();
    if (busca) {
      const applyBusca = () => {
        const q = norm(busca.value);
        let visible = 0;
        rows().forEach((tr) => {
          const hay = norm(tr.getAttribute('data-search') || tr.textContent || '');
          const show = q === '' || hay.includes(q);
          tr.hidden = !show;
          if (show) visible += 1;
        });
        if (emptyMsg) emptyMsg.hidden = !(q !== '' && visible === 0);
      };
      busca.addEventListener('input', applyBusca);
      busca.addEventListener('search', applyBusca);
    }

    const form = document.getElementById('medicaoBatchForm');
    const selectAll = document.getElementById('medicaoSelectAll');
    const checks = () => Array.from(document.querySelectorAll('.medicao-check:not(:disabled)'))
      .filter((c) => !c.closest('tr')?.hidden);
    const countBadge = document.getElementById('medicaoBatchCount');
    const submitBtn = document.getElementById('medicaoBatchSubmit');
    const progCard = document.getElementById('medicaoBatchProgress');
    const progTitle = document.getElementById('medicaoBatchTitle');
    const progStats = document.getElementById('medicaoBatchStats');
    const progBar = document.getElementById('medicaoBatchBar');
    const progErrors = document.getElementById('medicaoBatchErrors');
    const progReload = document.getElementById('medicaoBatchReloadWrap');

    const getTargetIds = () => {
      const marked = checks().filter((c) => c.checked).map((c) => Number(c.value)).filter((n) => n > 0);
      if (marked.length > 0) return marked;
      return checks().filter((c) => c.getAttribute('data-elegivel') === '1').map((c) => Number(c.value)).filter((n) => n > 0);
    };

    const syncBatchCount = () => {
      if (!form || !countBadge) return;
      const marked = checks().filter((c) => c.checked).map((c) => Number(c.value)).filter((n) => n > 0);
      const totalElig = Number(form.getAttribute('data-elegiveis-total') || '0');
      countBadge.textContent = String(marked.length > 0 ? marked.length : totalElig);
    };

    if (selectAll) {
      selectAll.addEventListener('change', () => {
        checks().forEach((c) => { c.checked = selectAll.checked; });
        syncBatchCount();
      });
    }
    checks().forEach((c) => c.addEventListener('change', syncBatchCount));

    if (!form) return;
    // Sem BatchRunner o POST único estoura 504 no Hostinger (49 e-mails).
    if (!window.BatchRunner) {
      form.addEventListener('submit', (ev) => {
        ev.preventDefault();
        if (window.Toast) window.Toast.show('Recarregue a página: o enviador em lote não carregou.', 'danger');
      });
      return;
    }

    form.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      const ids = getTargetIds();
      if (ids.length === 0) {
        if (window.Toast) window.Toast.show('Nenhum colaborador elegível ou selecionado.', 'warning');
        return;
      }

      const baseMsg = form.getAttribute('data-confirm-base') || 'Executar esta etapa em lote?';
      const title = form.getAttribute('data-confirm-title') || 'Executar lote?';
      const confirmed = window.uiConfirm
        ? await window.uiConfirm({ title, message: `${baseMsg} (${ids.length} colaborador${ids.length === 1 ? '' : 'es'})`, confirmText: 'Executar', tone: 'brand' })
        : true;
      if (!confirmed) return;

      if (submitBtn) submitBtn.disabled = true;
      if (progCard) progCard.hidden = false;
      if (progErrors) { progErrors.hidden = true; progErrors.innerHTML = ''; }
      if (progReload) progReload.hidden = true;
      if (progBar) progBar.style.width = '5%';
      if (progStats) progStats.textContent = `0 / ${ids.length}`;

      const ini = form.querySelector('input[name="data_inicio"]')?.value || '';
      const fim = form.querySelector('input[name="data_fim"]')?.value || '';
      const filtro = form.querySelector('input[name="filtro"]')?.value || '';
      const isEmail = String(form.action || '').includes('/lote/email');
      // E-mail com PDF é lento (SMTP/OAuth): 1 por request evita 504.
      const chunkSize = isEmail ? 1 : 5;

      try {
        const summary = await window.BatchRunner.run({
          url: form.action,
          ids,
          chunkSize,
          params: { data_inicio: ini, data_fim: fim, filtro },
          onProgress(p) {
            const pct = Math.min(100, Math.round((p.done / Math.max(1, p.total)) * 100));
            if (progBar) progBar.style.width = `${pct}%`;
            if (progStats) progStats.textContent = `${p.done} / ${p.total} (${p.ok} aplicado${p.ok === 1 ? '' : 's'})`;
            if (progTitle) progTitle.textContent = isEmail ? 'Enviando e-mails...' : 'Processando lote...';
          },
        });

        if (summary.errors.length === 0) {
          if (progTitle) progTitle.textContent = `Concluído: ${summary.ok} processado(s).`;
          if (window.Toast) window.Toast.show(`Lote finalizado (${summary.ok} processados).`, 'success');
          window.setTimeout(() => window.location.reload(), 800);
          return;
        }

        if (progTitle) progTitle.textContent = `Concluído com ${summary.errors.length} pendência(s) (${summary.ok} ok):`;
        if (progErrors) {
          summary.errors.forEach((msg) => {
            const li = document.createElement('li');
            li.textContent = msg;
            progErrors.appendChild(li);
          });
          progErrors.hidden = false;
        }
        if (progReload) progReload.hidden = false;
        if (submitBtn) submitBtn.disabled = false;
        if (window.Toast) window.Toast.show(`${summary.ok} processado(s), ${summary.errors.length} pendência(s).`, 'warning');
      } catch (err) {
        const msg = (err && err.message) ? err.message : 'Erro ao executar lote.';
        if (progTitle) progTitle.textContent = msg;
        if (progReload) progReload.hidden = false;
        if (submitBtn) submitBtn.disabled = false;
        if (window.Toast) window.Toast.show(msg, 'danger');
      }
    });
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
</script>
<?php elseif ($dbOk && $periodosGerados === []): ?>
<section class="card">
    <div class="card-body">
        <p class="muted">Ainda não há períodos com cartões gerados. Comece em <a href="/rh/ponto">Gestão de Ponto</a> (etapa 01).</p>
    </div>
</section>
<?php endif; ?>

