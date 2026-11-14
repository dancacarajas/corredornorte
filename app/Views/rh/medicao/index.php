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
$filtro = (string) ($filtro ?? '');
$qsBase = ['data_inicio' => $dataInicio, 'data_fim' => $dataFim];
$qs = http_build_query($qsBase);

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
?>

<?php if ($flash): ?><div class="alert alert-success"><?= View::e((string) $flash) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= View::e((string) $error) ?></div><?php endif; ?>
<?php if (!$dbOk): ?><div class="alert alert-warning">Banco indisponível.</div><?php endif; ?>

<section class="medicao-hero card">
    <div class="card-body medicao-hero-grid">
        <div>
            <p class="medicao-kicker">Jornada de medição</p>
            <h2 class="medicao-title">Acompanhe cada etapa até o dossiê</h2>
            <p class="medicao-lead">Fluxo: gerar → conferir → fechar → PDF → link → e-mail → assinaturas → <strong>dossiê</strong>. O dossiê só libera com todos em 100%.</p>
            <form method="get" action="/rh/medicao" class="medicao-periodo-form">
                <label class="form-label" for="periodoGerado">Período gerado</label>
                <div class="medicao-periodo-row">
                    <select class="form-control" id="periodoGerado" name="periodo_pick" onchange="
                        const o=this.options[this.selectedIndex];
                        if(!o||!o.value)return;
                        const [i,f]=o.value.split('|');
                        this.form.data_inicio.value=i;
                        this.form.data_fim.value=f;
                        this.form.submit();
                    ">
                        <option value="">Selecione…</option>
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
                    <button class="btn btn-secondary" type="submit">Atualizar</button>
                </div>
            </form>
        </div>

        <div class="medicao-score <?= $prontoDossie ? 'is-complete' : '' ?>" aria-label="Progresso geral">
            <div class="medicao-score-ring" style="--pct: <?= max(0, min(100, $pct)) ?>">
                <strong><?= $pct ?>%</strong>
                <span>do período</span>
            </div>
            <div class="medicao-score-meta">
                <p><strong><?= $concluidos ?></strong> de <strong><?= $total ?></strong> concluídos</p>
                <?php if ($periodoLabel !== ''): ?>
                    <p class="muted"><?= View::e($periodoLabel) ?></p>
                <?php endif; ?>
                <?php if ($prontoDossie): ?>
                    <a class="btn btn-primary" href="/rh/medicao/dossie?<?= View::e($qs) ?>">Baixar dossiê (ZIP)</a>
                <?php else: ?>
                    <button class="btn btn-secondary" type="button" disabled title="Disponível quando todos estiverem 100%">Baixar dossiê (ZIP)</button>
                <?php endif; ?>
            </div>
        </div>
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
        'confirm' => 'Enviar e-mail dos elegíveis com link?',
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
                  onsubmit="return confirm(<?= htmlspecialchars(json_encode($btnExecutar['confirm'] . ' (' . $qtdExecutar . ')', JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>);">
                <input type="hidden" name="data_inicio" value="<?= View::e($dataInicio) ?>">
                <input type="hidden" name="data_fim" value="<?= View::e($dataFim) ?>">
                <input type="hidden" name="filtro" value="<?= View::e($filtro) ?>">
                <button class="btn btn-primary" type="submit">
                    Executar lote: <?= View::e((string) $btnExecutar['label']) ?>
                    <span class="medicao-lote-count"><?= $qtdExecutar ?></span>
                </button>
                <span class="muted text-sm">Aplica só aos elegíveis desta etapa.</span>
            </form>
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
    <div class="card-header">
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
            <div class="table-responsive">
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
                        ?>
                        <tr>
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
                  try {
                    await navigator.clipboard.writeText(link);
                    var old = btn.textContent;
                    btn.textContent = 'Copiado';
                    setTimeout(function () { btn.textContent = old; }, 1200);
                  } catch (e) {
                    window.prompt('Copie o link:', link);
                  }
                });
              });
            })();
            </script>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table medicao-table">
                    <thead>
                    <tr>
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
                        ?>
                        <tr class="<?= $done ? 'medicao-row-done' : '' ?>">
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
        <?php endif; ?>
    </div>
</section>
<?php elseif ($dbOk && $periodosGerados === []): ?>
<section class="card">
    <div class="card-body">
        <p class="muted">Ainda não há períodos com cartões gerados. Comece em <a href="/rh/ponto">Gestão de Ponto</a> (etapa 01).</p>
    </div>
</section>
<?php endif; ?>
