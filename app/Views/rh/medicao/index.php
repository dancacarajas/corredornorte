<?php

use App\Helpers\View;
use App\Helpers\WhatsApp;

$dbOk = $dbOk ?? false;
$periodosGerados = $periodosGerados ?? [];
$dataInicio = $dataInicio ?? '';
$dataFim = $dataFim ?? '';
$painel = $painel ?? null;
$flash = $flash ?? null;
$error = $error ?? null;
$relatorioEnvios = is_array($relatorioEnvios ?? null) ? $relatorioEnvios : [];

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
$statusPonto = (string) ($statusPonto ?? '');
$subfiltro = (string) ($subfiltro ?? '');
if ($filtro !== 'assinaturas' || !in_array($statusPonto, ['enviado', 'assinado'], true)) {
    $statusPonto = '';
}
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
$modoConsultaEmail = false;
$modoDossie = $filtro === 'dossie';
$alvosReenvioEmail = array_values(array_filter(
    $colaboradores,
    static function (array $c): bool {
        $et = $c['etapas'] ?? [];
        return empty($c['concluido'])
            && !empty($et['link'])
            && !empty($et['email'])
            && empty($et['assinaturas'])
            && !empty($c['email_corp'])
            && !empty($c['documento_id']);
    }
));
$idsReenvioEmail = array_values(array_map(
    static fn(array $c): int => (int) $c['espelho_id'],
    $alvosReenvioEmail
));
$qtdReenvioEmail = count($idsReenvioEmail);
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
    // Etapa "Enviar e-mails" zerada: consulta quem já recebeu (reenvio).
    if ($filtro === 'email' && $colaboradoresFiltrados === [] && $alvosReenvioEmail !== [] && $subfiltro !== 'email_falha') {
        $colaboradoresFiltrados = $alvosReenvioEmail;
        $modoConsultaEmail = true;
    }
    // Etapa 7: subfiltro por status do ponto (enviado | assinado).
    if ($filtro === 'assinaturas' && $statusPonto !== '') {
        $colaboradoresFiltrados = array_values(array_filter(
            $colaboradoresFiltrados,
            static fn(array $c): bool => (string) ($c['espelho_status'] ?? '') === $statusPonto
        ));
    }
} elseif ($modoDossie && !$prontoDossie) {
    // Dossiê ainda bloqueado: mostra quem falta chegar a 100%.
    $colaboradoresFiltrados = array_values(array_filter(
        $colaboradores,
        static fn(array $c): bool => empty($c['concluido'])
    ));
}

if ($subfiltro === 'he_bloqueante') {
    $colaboradoresFiltrados = array_values(array_filter(
        $colaboradores,
        static fn(array $c): bool => !empty($c['he_pendente'])
    ));
} elseif ($subfiltro === 'email_falha') {
    $colaboradoresFiltrados = array_values(array_filter(
        $colaboradores,
        static fn(array $c): bool => \App\Services\PainelExcecoesService::diagnosticarFalhaEmail($c) !== null
    ));
}

$mostraZap = in_array($filtro, ['link', 'email', 'assinaturas'], true);
$renderMedicaoZap = static function (array $c, string $filtroAtual, string $periodoAtual): void {
    $portalZap = trim((string) ($c['portal_url'] ?? ''));
    $telZap = $c['telefone'] ?? null;
    $nomeZap = (string) ($c['nome'] ?? '');
    $periodoZap = $periodoAtual !== '' ? $periodoAtual : 'período atual';
    $msgZap = $filtroAtual === 'assinaturas'
        ? WhatsApp::mensagemLembreteFolha($nomeZap, $periodoZap, $portalZap)
        : WhatsApp::mensagemEnvioFolha($nomeZap, $periodoZap, $portalZap);
    $waPayload = ($portalZap !== '')
        ? WhatsApp::chatPayload(is_string($telZap) ? $telZap : null, $msgZap)
        : null;
    $waUrl = is_array($waPayload) ? (string) $waPayload['url'] : null;
    $waPhone = is_array($waPayload) ? (string) $waPayload['phone'] : '';
    $waMsgB64 = is_array($waPayload) ? base64_encode((string) $waPayload['message']) : '';
    $waTitle = $waUrl !== null
        ? 'Abrir WhatsApp'
        : ($portalZap === '' ? 'Sem link de assinatura' : 'Sem WhatsApp cadastrado');
    $waSvg = '<svg class="medicao-wa-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M12.04 2c-5.46 0-9.91 4.44-9.91 9.91 0 1.75.46 3.45 1.32 4.95L2.05 22l5.25-1.38c1.45.79 3.08 1.21 4.74 1.21h.01c5.46 0 9.91-4.44 9.91-9.91C21.96 6.44 17.5 2 12.04 2zm5.78 14.15c-.24.68-1.41 1.25-1.95 1.33-.5.08-1.14.11-1.84-.12-.42-.14-.97-.31-1.67-.61-2.94-1.27-4.85-4.23-5-4.42-.14-.19-1.18-1.57-1.18-3 0-1.42.74-2.12 1.01-2.41.26-.28.58-.35.77-.35h.56c.18 0 .42-.07.66.5.24.58.82 2 .89 2.15.07.14.12.31.02.5-.1.19-.14.31-.28.48-.14.17-.29.37-.42.5-.14.14-.28.29-.12.56.16.28.7 1.15 1.5 1.86 1.03.92 1.9 1.21 2.17 1.35.28.14.44.12.6-.07.17-.19.7-.81.88-1.09.19-.28.37-.23.63-.14.26.1 1.64.77 1.92.91.28.14.47.21.54.33.07.12.07.68-.17 1.36z"/></svg>';
    if ($waUrl !== null) {
        echo '<a class="medicao-wa-btn js-medicao-wa" href="' . View::e($waUrl) . '" target="_blank" rel="noopener noreferrer"'
            . ' data-wa-phone="' . View::e($waPhone) . '" data-wa-msg="' . View::e($waMsgB64) . '"'
            . ' title="' . View::e($waTitle) . '" aria-label="' . View::e($waTitle . ' — ' . $nomeZap) . '">'
            . $waSvg . '</a>';
        return;
    }
    echo '<span class="medicao-wa-btn is-disabled" title="' . View::e($waTitle) . '"'
        . ' aria-label="' . View::e($waTitle . ' — ' . $nomeZap) . '" aria-disabled="true">'
        . $waSvg . '</span>';
};

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

$urlFiltro = static function (string $acao, string $status = '') use ($qsBase): string {
    $params = $qsBase;
    if ($acao !== '') {
        $params['filtro'] = $acao;
    }
    if ($acao === 'assinaturas' && in_array($status, ['enviado', 'assinado'], true)) {
        $params['status_ponto'] = $status;
    }
    return '/rh/medicao?' . http_build_query($params);
};

$contagemStatusAssinaturas = ['enviado' => 0, 'assinado' => 0, 'outros' => 0];
if ($filtro === 'assinaturas') {
    foreach ($colaboradores as $cStatus) {
        if (!$pendenteNaEtapa($cStatus, 'assinaturas')) {
            continue;
        }
        $st = (string) ($cStatus['espelho_status'] ?? '');
        if ($st === 'enviado' || $st === 'assinado') {
            $contagemStatusAssinaturas[$st]++;
        } else {
            $contagemStatusAssinaturas['outros']++;
        }
    }
}
$qtdAssinaturasTodos = $contagemStatusAssinaturas['enviado']
    + $contagemStatusAssinaturas['assinado']
    + $contagemStatusAssinaturas['outros'];

$pendentesFiltro = [
    'conferir' => 0,
    'fechar' => 0,
    'pdf' => 0,
    'link' => 0,
    'email' => 0,
    'assinaturas' => 0,
];
foreach ($colaboradores as $cPend) {
    foreach ($pendentesFiltro as $acaoPend => $_) {
        if ($pendenteNaEtapa($cPend, $acaoPend)) {
            $pendentesFiltro[$acaoPend]++;
        }
    }
}

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
                $aindaNaoFez = $total - $doneCount;
                $naFila = (int) ($pendentesFiltro[$filtroPorEtapaKey[$key] ?? ''] ?? 0);
                // Para etapas com “fila” (já passaram da anterior): mostra quem está na etapa agora.
                $pendentesEtapaLabel = $naFila > 0 ? $naFila : $aindaNaoFez;
                $pendentesEtapaSuffix = $naFila > 0 ? 'na fila' : 'pendente' . ($aindaNaoFez === 1 ? '' : 's');
                $etapaPct = $total > 0 ? (int) round(($doneCount / $total) * 100) : 0;
                $filtroEtapa = $filtroPorEtapaKey[$key] ?? '';
                $selecionada = ($filtroEtapa !== '' && $filtro === $filtroEtapa) || ($filtroEtapa === '' && $filtro === '');
                $estado = $etapaPct >= 100 ? 'is-done' : (($etapaPct > 0 || $naFila > 0) ? 'is-active' : 'is-todo');
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
                        <small><?= $doneCount ?>/<?= $total ?><?php if ($aindaNaoFez > 0): ?> · <?= $pendentesEtapaLabel ?> <?= $pendentesEtapaSuffix ?><?php endif; ?></small>
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
    <?php if (($btnExecutar !== null && $qtdExecutar > 0) || ($filtro === 'email' && $qtdReenvioEmail > 0)): ?>
        <div class="card-body medicao-lote-exec" style="padding-top:0;display:flex;flex-wrap:wrap;gap:.6rem;align-items:center;">
            <?php if ($btnExecutar !== null && $qtdExecutar > 0): ?>
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
            </form>
            <?php endif; ?>
            <?php if ($filtro === 'email' && $qtdReenvioEmail > 0): ?>
            <form method="post" action="/rh/medicao/lote/email-reenvio"
                  id="medicaoReenvioForm"
                  data-confirm-title="Reenviar e-mails?"
                  data-confirm-base="Reenviar a folha (com link) para quem já recebeu e ainda não assinou?"
                  data-elegiveis-total="<?= $qtdReenvioEmail ?>"
                  data-reenvio-ids="<?= View::e(implode(',', $idsReenvioEmail)) ?>">
                <input type="hidden" name="data_inicio" value="<?= View::e($dataInicio) ?>">
                <input type="hidden" name="data_fim" value="<?= View::e($dataFim) ?>">
                <input type="hidden" name="filtro" value="email">
                <button class="btn btn-secondary" type="submit" id="medicaoReenvioSubmit">
                    Reenviar e-mails
                    <span class="medicao-lote-count" id="medicaoReenvioCount"><?= $qtdReenvioEmail ?></span>
                </button>
            </form>
            <?php endif; ?>
            <span class="muted text-sm" id="medicaoBatchHint">Aplica aos elegíveis desta etapa (ou apenas aos marcados na tabela).</span>
            <?php
            $mostrarRelatorioPersistente = $filtro === 'email' && $relatorioEnvios !== [];
            $qtdRelOk = count(array_filter($relatorioEnvios, static fn(array $i): bool => ($i['status'] ?? '') === 'ok'));
            $qtdRelFail = count(array_filter($relatorioEnvios, static fn(array $i): bool => ($i['status'] ?? '') === 'falha'));
            ?>
            <div class="batch-progress-card" id="medicaoBatchProgress" <?= $mostrarRelatorioPersistente ? '' : 'hidden' ?> style="margin-top:.75rem;flex:1 1 100%;">
                <div class="batch-progress-head">
                    <strong id="medicaoBatchTitle"><?= $mostrarRelatorioPersistente ? 'Relatório de envios do período' : 'Processando lote...' ?></strong>
                    <span id="medicaoBatchStats" class="muted"><?= $mostrarRelatorioPersistente ? ($qtdRelOk . ' ok · ' . $qtdRelFail . ' falha') : '0 / 0' ?></span>
                </div>
                <div class="progress" id="medicaoBatchProgressBarWrap" <?= $mostrarRelatorioPersistente ? 'hidden' : '' ?>><span id="medicaoBatchBar" style="width:0%"></span></div>
                <button type="button" class="medicao-report-toggle" id="medicaoReportToggle" <?= $mostrarRelatorioPersistente ? '' : 'hidden' ?> aria-expanded="<?= $mostrarRelatorioPersistente ? 'true' : 'false' ?>">
                    <span class="medicao-report-chevron" aria-hidden="true"></span>
                    <span id="medicaoReportToggleLabel">Relatório de envios (<?= $qtdRelOk ?> sucesso · <?= $qtdRelFail ?> falha)</span>
                </button>
                <div class="medicao-report" id="medicaoReportPanel" <?= $mostrarRelatorioPersistente ? '' : 'hidden' ?>>
                    <div class="medicao-report-filters" role="tablist" aria-label="Filtro do relatório">
                        <button type="button" class="medicao-report-filter is-active" data-filter="todos">Todos <span id="medicaoReportCountAll">0</span></button>
                        <button type="button" class="medicao-report-filter" data-filter="ok">Sucesso <span id="medicaoReportCountOk">0</span></button>
                        <button type="button" class="medicao-report-filter" data-filter="falha">Falha <span id="medicaoReportCountFail">0</span></button>
                    </div>
                    <ul class="medicao-report-list" id="medicaoReportList"></ul>
                    <p class="muted text-sm medicao-report-empty" id="medicaoReportEmpty" hidden>Nenhum item neste filtro.</p>
                    <p class="muted text-sm" id="medicaoReportHint" hidden>
                        Falhas de envio <strong>não avançam</strong> para a etapa 7 — o colaborador permanece na etapa 6.
                    </p>
                </div>
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
    <?php elseif ($filtro === 'email' && $qtdFiltro === 0 && $modoConsultaEmail): ?>
        <div class="card-body" style="padding-top:0;">
            <p class="muted text-sm" style="margin:0;">Nenhum 1º envio pendente — abaixo quem já recebeu e ainda não assinou (reenvio).</p>
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
                <?php elseif ($modoConsultaEmail): ?>
                    E-mails já enviados — <?= count($colaboradoresFiltrados) ?> aguardando assinatura (reenvio)
                    · <a href="<?= View::e($urlFiltro('')) ?>">limpar filtro</a>
                <?php elseif ($filtro === 'assinaturas'): ?>
                    Filtro: <strong>Assinaturas</strong>
                    — <?= count($colaboradoresFiltrados) ?> pendente<?= count($colaboradoresFiltrados) === 1 ? '' : 's' ?>
                    <?php if ($statusPonto !== ''): ?>
                        · status do ponto: <strong><?= View::e($statusLabel($statusPonto)) ?></strong>
                    <?php endif; ?>
                    · <a href="<?= View::e($urlFiltro('')) ?>">limpar filtro</a>
                <?php elseif ($filtro !== ''): ?>
                    Filtro: <strong><?= View::e($filtroAtivoLabel) ?></strong>
                    <?php if ($subfiltro === 'he_bloqueante'): ?>
                        · Subfiltro: <strong>HE Cliente bloqueante</strong>
                    <?php elseif ($subfiltro === 'email_falha'): ?>
                        · Subfiltro: <strong>E-mails sem entrega</strong>
                    <?php endif; ?>
                    — <?= count($colaboradoresFiltrados) ?> pendente<?= count($colaboradoresFiltrados) === 1 ? '' : 's' ?>
                    · <a href="<?= View::e($urlFiltro('')) ?>">limpar filtro</a>
                <?php else: ?>
                    Verde = missão completa (ponto + RA assinados e etapas anteriores)
                <?php endif; ?>
            </p>
        </div>
        <?php if (($colaboradoresFiltrados !== [] || $filtro === 'assinaturas') && !($modoDossie && $prontoDossie)): ?>
            <div class="medicao-colab-tools">
                <?php if ($filtro === 'assinaturas'): ?>
                    <div class="medicao-status-filters" role="group" aria-label="Filtrar por status do ponto">
                        <a class="medicao-status-filter <?= $statusPonto === '' ? 'is-active' : '' ?>"
                           href="<?= View::e($urlFiltro('assinaturas')) ?>">
                            Todos <span><?= (int) $qtdAssinaturasTodos ?></span>
                        </a>
                        <a class="medicao-status-filter <?= $statusPonto === 'enviado' ? 'is-active' : '' ?>"
                           href="<?= View::e($urlFiltro('assinaturas', 'enviado')) ?>">
                            Enviado <span><?= (int) $contagemStatusAssinaturas['enviado'] ?></span>
                        </a>
                        <a class="medicao-status-filter <?= $statusPonto === 'assinado' ? 'is-active' : '' ?>"
                           href="<?= View::e($urlFiltro('assinaturas', 'assinado')) ?>">
                            Assinado <span><?= (int) $contagemStatusAssinaturas['assinado'] ?></span>
                        </a>
                    </div>
                <?php endif; ?>
                <?php if ($colaboradoresFiltrados !== []): ?>
                    <div class="medicao-colab-search">
                        <label class="visually-hidden" for="medicaoBuscaNome">Buscar colaborador</label>
                        <input type="search" class="form-control" id="medicaoBuscaNome"
                               placeholder="Buscar por nome ou matrícula…"
                               autocomplete="off">
                    </div>
                <?php endif; ?>
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
                                <?php $renderMedicaoZap($c, $filtro, $periodoLabel); ?>
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
                        <?php if ($filtro === 'assinaturas'): ?>
                            <th>Ponto</th>
                            <th>RA</th>
                        <?php else: ?>
                            <th>Status do ponto</th>
                        <?php endif; ?>
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
                        $folhaSt = (string) ($c['folha_status'] ?? '');
                        $raDocSt = (string) ($c['ra_doc_status'] ?? '');
                        $pontoAssinado = $stEsp === 'assinado' || $folhaSt === 'assinado';
                        $raAssinado = $raDocSt === 'assinado';
                        $elegivelAtual = match ($filtro) {
                            'conferir' => empty($etapas['tratado']) && empty($c['he_pendente']),
                            'fechar' => !empty($etapas['tratado']) && empty($etapas['fechado']),
                            'pdf' => !empty($etapas['fechado']) && empty($etapas['pdf']),
                            'link' => !empty($etapas['pdf']) && empty($etapas['link']),
                            'email' => $modoConsultaEmail
                                ? (!empty($etapas['link']) && !empty($etapas['email']) && empty($etapas['assinaturas']) && !empty($c['email_corp']))
                                : (!empty($etapas['link']) && empty($etapas['email'])),
                            default => !$done,
                        };
                        $podeReenviarEmail = $filtro === 'email'
                            && !$done
                            && !empty($etapas['link'])
                            && !empty($etapas['email'])
                            && empty($etapas['assinaturas'])
                            && !empty($c['email_corp'])
                            && !empty($c['documento_id']);
                        $podeEnviarEmail = $filtro === 'email'
                            && !$done
                            && !$modoConsultaEmail
                            && !empty($etapas['link'])
                            && empty($etapas['email'])
                            && !empty($c['email_corp'])
                            && !empty($c['documento_id']);
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
                            <?php if ($filtro === 'assinaturas'): ?>
                                <td>
                                    <span class="badge <?= $pontoAssinado ? 'badge-aprovada' : 'badge-pendente' ?>">
                                        <?= $pontoAssinado ? 'Assinado' : View::e($statusLabel($stEsp)) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge <?= $raAssinado ? 'badge-aprovada' : 'badge-pendente' ?>">
                                        <?= $raAssinado ? 'Assinado' : 'Pendente' ?>
                                    </span>
                                </td>
                            <?php else: ?>
                                <td>
                                    <span class="badge <?= in_array($stEsp, ['fechado', 'enviado', 'assinado', 'conferido'], true) ? 'badge-aprovada' : 'badge-pendente' ?>">
                                        <?= View::e($statusLabel($stEsp)) ?>
                                    </span>
                                    <?php if (!empty($c['he_pendente'])): ?>
                                        <div class="muted text-sm">HE pendente</div>
                                    <?php endif; ?>
                                    <?php if ($filtro === 'email' && empty($etapas['email']) && ($c['email_ultimo_status'] ?? '') === 'falha'): ?>
                                        <div class="medicao-email-fail" title="<?= View::e((string) ($c['email_ultimo_erro'] ?? 'Falha no envio')) ?>">
                                            Falha no envio — permanece na etapa 6
                                        </div>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                            <td style="min-width:9rem;">
                                <div class="medicao-pct <?= $done ? 'is-done' : '' ?>"><?= $pctC ?>%</div>
                                <div class="progress <?= $done ? 'is-success' : ($pctC >= 50 ? 'is-info' : 'is-warning') ?>">
                                    <span style="width:<?= $pctC ?>%"></span>
                                </div>
                            </td>
                            <td>
                                <?php if ($done): ?>
                                    <span class="badge badge-aprovada">100% concluído</span>
                                <?php elseif ($filtro === 'assinaturas'): ?>
                                    <?php if ($pontoAssinado && !$raAssinado): ?>
                                        Falta assinar RA
                                    <?php elseif (!$pontoAssinado && $raAssinado): ?>
                                        Falta assinar ponto
                                    <?php else: ?>
                                        Falta assinar ponto e RA
                                    <?php endif; ?>
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
                            <td class="text-right medicao-row-actions">
                                <?php if ($mostraZap): ?>
                                    <?php $renderMedicaoZap($c, $filtro, $periodoLabel); ?>
                                <?php endif; ?>
                                <?php if ($podeReenviarEmail): ?>
                                    <button type="button"
                                            class="btn btn-secondary btn-sm js-medicao-email-one"
                                            data-espelho-id="<?= (int) $c['espelho_id'] ?>"
                                            data-mode="reenvio"
                                            title="Reenviar e-mail com link de assinatura">
                                        Reenviar e-mail
                                    </button>
                                <?php elseif ($podeEnviarEmail): ?>
                                    <button type="button"
                                            class="btn btn-secondary btn-sm js-medicao-email-one"
                                            data-espelho-id="<?= (int) $c['espelho_id'] ?>"
                                            data-mode="envio"
                                            title="Enviar e-mail com link de assinatura">
                                        Enviar e-mail
                                    </button>
                                <?php endif; ?>
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

    const b64ToUtf8 = (b64) => {
      try {
        const bin = atob(String(b64 || ''));
        const bytes = Uint8Array.from(bin, (ch) => ch.charCodeAt(0));
        return new TextDecoder('utf-8').decode(bytes);
      } catch (err) {
        return '';
      }
    };
    document.querySelectorAll('.js-medicao-wa').forEach((btn) => {
      btn.addEventListener('click', (ev) => {
        const phone = btn.getAttribute('data-wa-phone') || '';
        const msg = b64ToUtf8(btn.getAttribute('data-wa-msg') || '');
        if (!phone || !msg) return;
        ev.preventDefault();
        const url = 'https://api.whatsapp.com/send?phone='
          + encodeURIComponent(phone)
          + '&text=' + encodeURIComponent(msg);
        window.open(url, '_blank', 'noopener,noreferrer');
      });
    });

    const form = document.getElementById('medicaoBatchForm');
    const reenvioForm = document.getElementById('medicaoReenvioForm');
    const selectAll = document.getElementById('medicaoSelectAll');
    const checks = () => Array.from(document.querySelectorAll('.medicao-check:not(:disabled)'))
      .filter((c) => !c.closest('tr')?.hidden);
    const countBadge = document.getElementById('medicaoBatchCount');
    const reenvioCount = document.getElementById('medicaoReenvioCount');
    const submitBtn = document.getElementById('medicaoBatchSubmit');
    const reenvioBtn = document.getElementById('medicaoReenvioSubmit');
    const progCard = document.getElementById('medicaoBatchProgress');
    const progTitle = document.getElementById('medicaoBatchTitle');
    const progStats = document.getElementById('medicaoBatchStats');
    const progBar = document.getElementById('medicaoBatchBar');
    const progErrors = document.getElementById('medicaoBatchErrors');
    const progReload = document.getElementById('medicaoBatchReloadWrap');
    const reportToggle = document.getElementById('medicaoReportToggle');
    const reportToggleLabel = document.getElementById('medicaoReportToggleLabel');
    const reportPanel = document.getElementById('medicaoReportPanel');
    const reportList = document.getElementById('medicaoReportList');
    const reportEmpty = document.getElementById('medicaoReportEmpty');
    const reportHint = document.getElementById('medicaoReportHint');
    const reportCountAll = document.getElementById('medicaoReportCountAll');
    const reportCountOk = document.getElementById('medicaoReportCountOk');
    const reportCountFail = document.getElementById('medicaoReportCountFail');
    let reportItems = <?= json_encode(array_map(static function (array $i): array {
        return [
            'espelho_id' => (int) ($i['espelho_id'] ?? 0),
            'nome' => (string) ($i['nome'] ?? ''),
            'status' => (string) ($i['status'] ?? ''),
            'message' => (string) ($i['message'] ?? ''),
        ];
    }, $relatorioEnvios), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
    let reportFilter = 'todos';

    const statusLabelReport = (st) => {
      if (st === 'ok') return 'Sucesso';
      if (st === 'falha') return 'Falha';
      return 'Pulado';
    };

    const renderReport = () => {
      if (!reportList) return;
      const okN = reportItems.filter((it) => it.status === 'ok').length;
      const failN = reportItems.filter((it) => it.status === 'falha').length;
      if (reportCountAll) reportCountAll.textContent = String(reportItems.length);
      if (reportCountOk) reportCountOk.textContent = String(okN);
      if (reportCountFail) reportCountFail.textContent = String(failN);
      if (reportToggleLabel) {
        reportToggleLabel.textContent = `Relatório de envios (${okN} sucesso · ${failN} falha)`;
      }
      if (reportToggle) reportToggle.hidden = reportItems.length === 0;
      if (reportHint) reportHint.hidden = failN === 0;

      const filtered = reportItems.filter((it) => {
        if (reportFilter === 'ok') return it.status === 'ok';
        if (reportFilter === 'falha') return it.status === 'falha';
        return true;
      });
      reportList.innerHTML = '';
      filtered.forEach((it) => {
        const li = document.createElement('li');
        li.setAttribute('data-status', it.status || '');
        const st = document.createElement('span');
        st.className = 'medicao-report-status is-' + (it.status || 'pulado');
        st.textContent = statusLabelReport(it.status);
        const name = document.createElement('strong');
        name.textContent = it.nome || ('#' + (it.espelho_id || ''));
        const msg = document.createElement('span');
        msg.className = 'medicao-report-msg';
        msg.textContent = it.message || '';
        li.appendChild(st);
        li.appendChild(name);
        if (it.message) li.appendChild(msg);
        reportList.appendChild(li);
      });
      if (reportEmpty) reportEmpty.hidden = filtered.length > 0;
    };

    const resetReport = () => {
      reportItems = [];
      reportFilter = 'todos';
      document.querySelectorAll('.medicao-report-filter').forEach((btn) => {
        btn.classList.toggle('is-active', btn.getAttribute('data-filter') === 'todos');
      });
      if (reportToggle) {
        reportToggle.hidden = true;
        reportToggle.setAttribute('aria-expanded', 'false');
      }
      if (reportPanel) reportPanel.hidden = true;
      renderReport();
    };

    if (reportToggle) {
      reportToggle.addEventListener('click', () => {
        const open = reportToggle.getAttribute('aria-expanded') === 'true';
        reportToggle.setAttribute('aria-expanded', open ? 'false' : 'true');
        if (reportPanel) reportPanel.hidden = open;
      });
    }
    document.querySelectorAll('.medicao-report-filter').forEach((btn) => {
      btn.addEventListener('click', () => {
        reportFilter = btn.getAttribute('data-filter') || 'todos';
        document.querySelectorAll('.medicao-report-filter').forEach((b) => {
          b.classList.toggle('is-active', b === btn);
        });
        renderReport();
      });
    });
    if (reportItems.length > 0) {
      // Preferir falhas quando há bounce / domínio inválido no período.
      const hasFail = reportItems.some((it) => it.status === 'falha');
      if (hasFail) {
        reportFilter = 'falha';
        document.querySelectorAll('.medicao-report-filter').forEach((b) => {
          b.classList.toggle('is-active', b.getAttribute('data-filter') === 'falha');
        });
      }
      renderReport();
    }

    const getTargetIds = () => {
      const marked = checks().filter((c) => c.checked).map((c) => Number(c.value)).filter((n) => n > 0);
      if (marked.length > 0) return marked;
      return checks().filter((c) => c.getAttribute('data-elegivel') === '1').map((c) => Number(c.value)).filter((n) => n > 0);
    };

    const getReenvioIds = () => {
      const marked = checks().filter((c) => c.checked).map((c) => Number(c.value)).filter((n) => n > 0);
      if (marked.length > 0) return marked;
      const raw = (reenvioForm && reenvioForm.getAttribute('data-reenvio-ids')) || '';
      return raw.split(',').map((v) => Number(v.trim())).filter((n) => n > 0);
    };

    const syncBatchCount = () => {
      const marked = checks().filter((c) => c.checked).map((c) => Number(c.value)).filter((n) => n > 0);
      if (countBadge && form) {
        const totalElig = Number(form.getAttribute('data-elegiveis-total') || '0');
        countBadge.textContent = String(marked.length > 0 ? marked.length : totalElig);
      }
      if (reenvioCount && reenvioForm) {
        const totalRe = Number(reenvioForm.getAttribute('data-elegiveis-total') || '0');
        reenvioCount.textContent = String(marked.length > 0 ? marked.length : totalRe);
      }
    };

    if (selectAll) {
      selectAll.addEventListener('change', () => {
        checks().forEach((c) => { c.checked = selectAll.checked; });
        syncBatchCount();
      });
    }
    checks().forEach((c) => c.addEventListener('change', syncBatchCount));

    const runBatch = async (opts) => {
      const { url, ids, submitEl, formEl, confirmTitle, confirmBase, progressLabel } = opts;
      if (!ids.length) {
        if (window.Toast) window.Toast.show('Nenhum colaborador elegível ou selecionado.', 'warning');
        return;
      }
      if (!window.BatchRunner) {
        if (window.Toast) window.Toast.show('Recarregue a página: o enviador em lote não carregou.', 'danger');
        return;
      }
      const confirmed = window.uiConfirm
        ? await window.uiConfirm({
          title: confirmTitle || 'Executar lote?',
          message: `${confirmBase || 'Confirmar?'} (${ids.length} colaborador${ids.length === 1 ? '' : 'es'})`,
          confirmText: 'Executar',
          tone: 'brand',
        })
        : true;
      if (!confirmed) return;

      if (submitEl) submitEl.disabled = true;
      if (submitBtn && submitEl !== submitBtn) submitBtn.disabled = true;
      if (reenvioBtn && submitEl !== reenvioBtn) reenvioBtn.disabled = true;
      if (progCard) progCard.hidden = false;
      const barWrap = document.getElementById('medicaoBatchProgressBarWrap');
      if (barWrap) barWrap.hidden = false;
      if (progErrors) { progErrors.hidden = true; progErrors.innerHTML = ''; }
      if (progReload) progReload.hidden = true;
      if (progBar) progBar.style.width = '5%';
      if (progStats) progStats.textContent = `0 / ${ids.length}`;
      if (progTitle) progTitle.textContent = progressLabel || 'Processando lote...';
      resetReport();

      const sourceForm = formEl || form || reenvioForm;
      const ini = sourceForm?.querySelector('input[name="data_inicio"]')?.value || '';
      const fim = sourceForm?.querySelector('input[name="data_fim"]')?.value || '';
      const filtroVal = sourceForm?.querySelector('input[name="filtro"]')?.value || '';
      const isEmail = String(url || '').includes('/lote/email');

      try {
        const summary = await window.BatchRunner.run({
          url,
          ids,
          chunkSize: isEmail ? 1 : 5,
          params: { data_inicio: ini, data_fim: fim, filtro: filtroVal },
          onProgress(p) {
            const pct = Math.min(100, Math.round((p.done / Math.max(1, p.total)) * 100));
            if (progBar) progBar.style.width = `${pct}%`;
            if (progStats) progStats.textContent = `${p.done} / ${p.total} (${p.ok} aplicado${p.ok === 1 ? '' : 's'})`;
            if (progTitle) progTitle.textContent = progressLabel || (isEmail ? 'Enviando e-mails...' : 'Processando lote...');
            reportItems = Array.isArray(p.items) ? p.items.slice() : reportItems;
            renderReport();
            if (isEmail && reportToggle && reportItems.length > 0) {
              reportToggle.hidden = false;
            }
          },
        });

        reportItems = Array.isArray(summary.items) ? summary.items.slice() : reportItems;
        renderReport();
        if (reportToggle && reportItems.length > 0) {
          reportToggle.hidden = false;
          reportToggle.setAttribute('aria-expanded', 'true');
          if (reportPanel) reportPanel.hidden = false;
          if (summary.errors.length > 0) {
            reportFilter = 'falha';
            document.querySelectorAll('.medicao-report-filter').forEach((b) => {
              b.classList.toggle('is-active', b.getAttribute('data-filter') === 'falha');
            });
            renderReport();
          }
        }

        if (summary.errors.length === 0) {
          if (progTitle) progTitle.textContent = `Concluído: ${summary.ok} processado(s).`;
          if (window.Toast) window.Toast.show(`Lote finalizado (${summary.ok} processados).`, 'success');
          // Com relatório aberto, dá tempo de ler antes de atualizar o painel.
          window.setTimeout(() => window.location.reload(), isEmail ? 1800 : 800);
          return;
        }

        if (progTitle) progTitle.textContent = `Concluído com ${summary.errors.length} falha(s) (${summary.ok} ok) — falhas ficam na etapa 6.`;
        if (progErrors) progErrors.hidden = true;
        if (progReload) progReload.hidden = false;
        if (submitBtn) submitBtn.disabled = false;
        if (reenvioBtn) reenvioBtn.disabled = false;
        if (window.Toast) window.Toast.show(`${summary.ok} ok, ${summary.errors.length} falha(s). Quem falhou permanece na etapa 6.`, 'warning');
      } catch (err) {
        const msg = (err && err.message) ? err.message : 'Erro ao executar lote.';
        if (progTitle) progTitle.textContent = msg;
        if (progReload) progReload.hidden = false;
        if (submitBtn) submitBtn.disabled = false;
        if (reenvioBtn) reenvioBtn.disabled = false;
        if (window.Toast) window.Toast.show(msg, 'danger');
      }
    };

    if (form) {
      form.addEventListener('submit', (ev) => {
        ev.preventDefault();
        runBatch({
          url: form.action,
          ids: getTargetIds(),
          submitEl: submitBtn,
          formEl: form,
          confirmTitle: form.getAttribute('data-confirm-title') || 'Executar lote?',
          confirmBase: form.getAttribute('data-confirm-base') || 'Executar esta etapa em lote?',
          progressLabel: String(form.action || '').includes('/lote/email') ? 'Enviando e-mails...' : 'Processando lote...',
        });
      });
    }

    if (reenvioForm) {
      reenvioForm.addEventListener('submit', (ev) => {
        ev.preventDefault();
        runBatch({
          url: reenvioForm.action,
          ids: getReenvioIds(),
          submitEl: reenvioBtn,
          formEl: reenvioForm,
          confirmTitle: reenvioForm.getAttribute('data-confirm-title') || 'Reenviar e-mails?',
          confirmBase: reenvioForm.getAttribute('data-confirm-base') || 'Reenviar e-mails?',
          progressLabel: 'Reenviando e-mails...',
        });
      });
    }

    document.querySelectorAll('.js-medicao-email-one').forEach((btn) => {
      btn.addEventListener('click', () => {
        const id = Number(btn.getAttribute('data-espelho-id') || '0');
        if (!id) return;
        const mode = btn.getAttribute('data-mode') === 'reenvio' ? 'reenvio' : 'envio';
        const url = mode === 'reenvio'
          ? '/rh/medicao/lote/email-reenvio'
          : '/rh/medicao/lote/email';
        const source = mode === 'reenvio' ? (reenvioForm || form) : (form || reenvioForm);
        runBatch({
          url,
          ids: [id],
          submitEl: btn,
          formEl: source,
          confirmTitle: mode === 'reenvio' ? 'Reenviar e-mail?' : 'Enviar e-mail?',
          confirmBase: mode === 'reenvio'
            ? 'Reenviar a folha com link de assinatura para este colaborador?'
            : 'Enviar a folha com link de assinatura para este colaborador?',
          progressLabel: mode === 'reenvio' ? 'Reenviando e-mail...' : 'Enviando e-mail...',
        });
      });
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

