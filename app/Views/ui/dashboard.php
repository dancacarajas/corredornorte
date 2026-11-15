<?php

use App\Helpers\View;

/** @var bool $dbOk */
/** @var ?array<string,mixed> $painel */
/** @var string $periodoLabel */
/** @var string $dataInicio */
/** @var string $dataFim */
/** @var string $consultaPeriodo */
/** @var ?string $erroPeriodo */
/** @var list<array{id:int,data_inicio:string,data_fim:string,status:string,espelhos:int,label:string}> $periodosGerados */

$periodosGerados = $periodosGerados ?? [];
$painel = is_array($painel ?? null) ? $painel : null;
$canalEmail = $painel['canal_email'] ?? [
    'estado' => 'critico',
    'label' => 'Canal de e-mail indisponível',
    'email' => null,
    'provedor_label' => null,
    'detalhe' => 'Verifique a conexão com o banco.',
    'cta_label' => 'Configurar e-mail',
    'href' => '/rh/email',
];
$cobertura = $painel['cobertura'] ?? ['esperadas' => 0, 'gerados' => 0, 'faltantes' => 0, 'href' => '/rh/ponto?' . $consultaPeriodo];
$gargalo = $painel['gargalo'] ?? null;
$kpis = $painel['kpis'] ?? [];
$filas = $painel['filas'] ?? [];
$filaPadrao = (string) ($painel['fila_padrao'] ?? 'gargalo');

$div = $kpis['divergencias'] ?? ['total' => 0, 'criticas' => 0, 'maior_idade' => null, 'outras_periodos' => 0, 'tom' => 'ok', 'href' => '/rh/ponto?' . $consultaPeriodo, 'cta_label' => 'Ver revisões'];
$he = $kpis['he'] ?? ['total' => 0, 'total_dias' => 0, 'horas_fmt' => null, 'tom' => 'ok', 'href' => '/rh/medicao?' . $consultaPeriodo, 'cta_label' => 'Conferir HE'];
$email = $kpis['email'] ?? ['total' => 0, 'falhas' => 0, 'sem_email' => 0, 'invalidos' => 0, 'tom' => 'ok', 'href' => '/rh/medicao?' . $consultaPeriodo, 'cta_label' => 'Corrigir envios'];
$ass = $kpis['assinaturas'] ?? ['total' => 0, 'em_atraso' => 0, 'fase' => 'aberto', 'du1_label' => '', 'du2_label' => '', 'folhas_pendentes' => 0, 'ras_pendentes' => 0, 'ras_rascunho' => 0, 'nivel_max' => 0, 'tom' => 'ok', 'href' => '/rh/medicao?' . $consultaPeriodo, 'cta_label' => 'Ver assinaturas'];

$assSub = match ((string) ($ass['fase'] ?? 'aberto')) {
    'du1' => 'Hoje é 1º dia útil pós-fechamento · cobrança nível 1',
    'du2' => 'Hoje é 2º dia útil (prazo final ' . ($ass['du2_label'] ?? '') . ')',
    'vencido' => 'Prazo vencido após ' . ($ass['du2_label'] ?? '') . ' · cobrança nível ' . max(1, (int) ($ass['nivel_max'] ?? 0)),
    'aguardando_du1' => '1ª cobrança automática em ' . ($ass['du1_label'] ?? ''),
    default => 'Ciclo em aberto até ' . date('d/m/Y', strtotime($dataFim)),
};
$assTotalRas = (int) ($ass['ras_pendentes'] ?? 0) + (int) ($ass['ras_rascunho'] ?? 0);
$assDetalhe = (int) ($ass['total'] ?? 0) === 0
    ? 'Nenhuma assinatura pendente no momento.'
    : sprintf(
        '%d folha(s) · %d RA(s)%s · %s',
        (int) ($ass['folhas_pendentes'] ?? 0),
        $assTotalRas,
        (int) ($ass['ras_rascunho'] ?? 0) > 0 ? ' (' . (int) $ass['ras_rascunho'] . ' rascunho)' : '',
        $assSub
    );

$assBadge = (int) ($ass['total'] ?? 0) === 0
    ? ['label' => 'Em dia', 'tom' => 'ok']
    : match ((string) ($ass['fase'] ?? 'aberto')) {
        'vencido', 'du2' => ['label' => 'Em atraso', 'tom' => 'danger'],
        'du1' => ['label' => 'DU+1 ativo', 'tom' => 'warning'],
        default => ['label' => 'No prazo', 'tom' => 'neutral'],
    };
?>

<?php if (empty($dbOk)): ?>
    <div class="alert alert-warning" role="alert">MySQL indisponível. Rode <code>php scripts/install_db.php</code>.</div>
<?php endif; ?>
<?php if (!empty($erroPeriodo)): ?>
    <div class="alert alert-danger" role="alert"><?= View::e($erroPeriodo) ?></div>
<?php endif; ?>

<section class="dash-context-bar" aria-label="Período e saúde do canal de envio">
    <div class="dash-periodos">
        <span class="dash-periodos-label">Período</span>
        <?php if ($periodosGerados === []): ?>
            <span class="muted text-sm">Nenhum período gerado — <a href="/rh/ponto" class="dash-kpi-link">ir para Ponto</a></span>
        <?php else: ?>
            <?php foreach (array_slice($periodosGerados, 0, 8) as $pg):
                $ativo = $pg['data_inicio'] === $dataInicio && $pg['data_fim'] === $dataFim;
                ?>
                <a class="btn <?= $ativo ? 'btn-primary' : 'btn-secondary' ?> btn-sm"
                   href="/?<?= View::e(http_build_query([
                       'data_inicio' => $pg['data_inicio'],
                       'data_fim' => $pg['data_fim'],
                   ])) ?>">
                    <?= View::e($pg['label']) ?>
                    <span class="dash-chip-count">· <?= (int) $pg['espelhos'] ?></span>
                </a>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <a class="dash-channel-pill <?= ($canalEmail['estado'] ?? '') === 'ok' ? 'is-ok' : 'is-critico' ?>"
       href="<?= View::e((string) ($canalEmail['href'] ?? '/rh/email')) ?>"
       title="<?= View::e((string) ($canalEmail['detalhe'] ?? '')) ?>">
        <span class="dash-channel-dot" aria-hidden="true"></span>
        <?php if (($canalEmail['estado'] ?? '') === 'ok'): ?>
            <span>E-mail ativo · <?= View::e((string) ($canalEmail['email'] ?? '')) ?></span>
            <?php if (!empty($canalEmail['provedor_label'])): ?>
                <span class="muted">(<?= View::e((string) $canalEmail['provedor_label']) ?>)</span>
            <?php endif; ?>
        <?php else: ?>
            <span><?= View::e((string) ($canalEmail['label'] ?? 'Canal com falha')) ?></span>
        <?php endif; ?>
    </a>
</section>

<?php if (($canalEmail['estado'] ?? '') !== 'ok'): ?>
    <section class="dash-banner-alerta" role="alert" aria-label="Alerta crítico no canal de e-mail">
        <div>
            <div class="dash-banner-title">Canal de envio bloqueado — <?= View::e((string) $canalEmail['label']) ?></div>
            <div class="dash-banner-desc">
                <?= View::e((string) ($canalEmail['detalhe'] ?? '')) ?> Sem conta ativa, envios e cobranças automáticas não funcionam.
            </div>
        </div>
        <a class="btn btn-danger btn-sm" href="<?= View::e((string) ($canalEmail['href'] ?? '/rh/email')) ?>">
            <?= View::e((string) ($canalEmail['cta_label'] ?? 'Reconectar')) ?>
        </a>
    </section>
<?php endif; ?>

<?php if ((int) ($cobertura['faltantes'] ?? 0) > 0): ?>
    <section class="dash-banner-alerta is-warning" role="status" aria-label="Cobertura de espelhos incompleta">
        <div>
            <div class="dash-banner-title">
                Cobertura de cartão: <?= (int) $cobertura['faltantes'] ?> colaborador(es) ativo(s) sem espelho em <?= View::e($periodoLabel) ?>
            </div>
            <div class="dash-banner-desc">
                <?= (int) $cobertura['gerados'] ?> de <?= (int) $cobertura['esperadas'] ?> ativos possuem cartão gerado — os faltantes não entram na Medição nem recebem assinatura.
            </div>
        </div>
        <a class="btn btn-secondary btn-sm" href="<?= View::e((string) $cobertura['href']) ?>">
            Ver faltantes no Ponto →
        </a>
    </section>
<?php endif; ?>

<?php if ($gargalo !== null): ?>
    <section class="dash-f1-grid" aria-label="Gargalo principal e travas do período">
        <!-- HERO — Gargalo Dominante -->
        <article class="dash-hero is-<?= View::e((string) ($gargalo['tom'] ?? 'brand')) ?>">
            <div>
                <span class="dash-hero-kicker"><?= View::e((string) $gargalo['kicker']) ?></span>
                <h2 class="dash-hero-title"><?= View::e((string) $gargalo['titulo']) ?></h2>
                <p class="dash-hero-sub"><?= View::e((string) $gargalo['subtitulo']) ?></p>

                <div class="dash-hero-metric">
                    <span class="dash-hero-num"><?= (int) ($gargalo['quantidade'] ?? 0) ?></span>
                    <span class="dash-hero-unit">
                        <?php if (!empty($gargalo['metric_label'])): ?>
                            <?= View::e((string) $gargalo['metric_label']) ?>
                        <?php elseif (!empty($gargalo['pronto_dossie'])): ?>
                            de <?= (int) $gargalo['total'] ?> prontos (100%)
                        <?php else: ?>
                            espelhos a gerar
                        <?php endif; ?>
                    </span>
                </div>

                <?php if (!empty($gargalo['progresso_label'])): ?>
                    <p class="dash-hero-progress"><?= View::e((string) $gargalo['progresso_label']) ?></p>
                <?php endif; ?>

                <?php
                $splits = is_array($gargalo['splits'] ?? null) ? $gargalo['splits'] : [];
                $splits = array_values(array_filter(
                    $splits,
                    static fn(array $s): bool => (int) ($s['valor'] ?? 0) > 0 || ($s['tom'] ?? '') === 'danger'
                ));
                ?>
                <?php if ($splits !== []): ?>
                    <div class="dash-hero-splits" aria-label="Prontos versus bloqueados">
                        <?php foreach ($splits as $sp): ?>
                            <div class="dash-hero-split is-<?= View::e((string) ($sp['tom'] ?? 'muted')) ?>">
                                <span class="dash-hero-split-num"><?= (int) ($sp['valor'] ?? 0) ?></span>
                                <span class="dash-hero-split-label"><?= View::e((string) ($sp['label'] ?? '')) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($gargalo['barra']) && (int) ($gargalo['total'] ?? 0) > 0): ?>
                <div class="dash-pipe-wrap">
                    <div class="dash-pipe-legend">Feitos / total por etapa</div>
                    <div class="dash-pipe" aria-label="Progresso por etapa da Medição (feitos de <?= (int) $gargalo['total'] ?>)">
                        <?php foreach ($gargalo['barra'] as $st): ?>
                            <div class="dash-pipe-step <?= (int) $st['pct'] >= 100 ? 'is-full' : '' ?> <?= !empty($st['atual']) ? 'is-atual' : '' ?>"
                                 title="<?= View::e($st['num'] . '. ' . $st['label'] . ': ' . ($st['rotulo'] ?? ($st['feitos'] . '/' . $gargalo['total'])) . ' feitos') ?>">
                                <div class="dash-pipe-track">
                                    <span class="dash-pipe-fill" style="width: <?= max(0, min(100, (int) $st['pct'])) ?>%"></span>
                                </div>
                                <span class="dash-pipe-num">E<?= (int) $st['num'] ?> · <?= View::e((string) ($st['rotulo'] ?? $st['feitos'])) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="dash-hero-foot">
                <?php if (!empty($gargalo['bloqueio_nota'])): ?>
                    <div class="dash-hero-blocker">
                        <?php if (!empty($gargalo['bloqueio_href'])): ?>
                            <a href="<?= View::e((string) $gargalo['bloqueio_href']) ?>"><?= View::e((string) $gargalo['bloqueio_nota']) ?></a>
                        <?php else: ?>
                            <span><?= View::e((string) $gargalo['bloqueio_nota']) ?></span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <div class="dash-hero-actions">
                    <a class="btn btn-primary" href="<?= View::e((string) $gargalo['cta_href']) ?>">
                        <?= View::e((string) $gargalo['cta_label']) ?>
                    </a>
                    <?php if (!empty($gargalo['cta_secundario_href']) && !empty($gargalo['cta_secundario_label'])): ?>
                        <a class="btn btn-secondary btn-sm" href="<?= View::e((string) $gargalo['cta_secundario_href']) ?>">
                            <?= View::e((string) $gargalo['cta_secundario_label']) ?>
                        </a>
                    <?php endif; ?>
                    <?php if (!empty($gargalo['secondary_href'])): ?>
                        <a class="btn btn-ghost btn-sm" href="<?= View::e((string) $gargalo['secondary_href']) ?>">
                            Abrir Medição completa
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </article>

        <!-- MATRIZ 2x2 — As 4 Travas Críticas P0 -->
        <div class="dash-kpi-grid">
            <!-- 1. Divergências abertas + SLA -->
            <article class="dash-kpi-card is-<?= View::e((string) $div['tom']) ?>">
                <div class="dash-kpi-top">
                    <span class="dash-kpi-label">Revisões de ponto abertas</span>
                    <?php if ((int) $div['total'] > 0): ?>
                        <span class="dash-kpi-badge is-<?= !empty($div['criticas']) ? 'danger' : 'warning' ?>">
                            Mais antiga: <?= View::e((string) ($div['maior_idade'] ?? 'Hoje')) ?>
                        </span>
                    <?php else: ?>
                        <span class="dash-kpi-badge is-ok">Em dia</span>
                    <?php endif; ?>
                </div>
                <div>
                    <div class="dash-kpi-num-row">
                        <span class="dash-kpi-num"><?= (int) $div['total'] ?></span>
                        <span class="dash-kpi-unit">neste período</span>
                    </div>
                    <p class="dash-kpi-desc">
                        <?php if ((int) $div['total'] > 0): ?>
                            Colaboradores pausaram a assinatura pedindo ajuste.
                            <?= (int) $div['criticas'] > 0 ? $div['criticas'] . ' com +48h aguardando.' : '' ?>
                        <?php elseif ((int) ($div['outras_periodos'] ?? 0) > 0): ?>
                            Nenhuma neste período (há <?= (int) $div['outras_periodos'] ?> aberta(s) em outro período).
                        <?php else: ?>
                            Nenhuma divergência aguardando retorno do RH.
                        <?php endif; ?>
                    </p>
                </div>
                <a class="dash-kpi-link" href="<?= View::e((string) $div['href']) ?>">
                    <?= (int) $div['total'] > 0 ? View::e((string) $div['cta_label']) . ' →' : 'Ir para Ponto →' ?>
                </a>
            </article>

            <!-- 2. HE bloqueando conferência -->
            <article class="dash-kpi-card is-<?= View::e((string) $he['tom']) ?>">
                <div class="dash-kpi-top">
                    <span class="dash-kpi-label">HE aguardando conferência</span>
                    <?php if ((int) $he['total'] > 0): ?>
                        <span class="dash-kpi-badge is-warning">Trava Etapa 2</span>
                    <?php else: ?>
                        <span class="dash-kpi-badge is-ok">Liberado</span>
                    <?php endif; ?>
                </div>
                <div>
                    <div class="dash-kpi-num-row">
                        <span class="dash-kpi-num"><?= (int) $he['total'] ?></span>
                        <span class="dash-kpi-unit">
                            <?= (int) $he['total'] === 1 ? 'cartão travado' : 'cartões travados' ?>
                        </span>
                    </div>
                    <p class="dash-kpi-desc">
                        <?php if ((int) $he['total'] > 0): ?>
                            <?= (int) ($he['total_dias'] ?? 0) ?> dia(s) com HE declarada no RA<?= !empty($he['horas_fmt']) ? ' (' . View::e((string) $he['horas_fmt']) . ')' : '' ?> impedindo conferir.
                        <?php else: ?>
                            Nenhum cartão retido por HE declarada no RA.
                        <?php endif; ?>
                    </p>
                </div>
                <a class="dash-kpi-link" href="<?= View::e((string) $he['href']) ?>">
                    <?= (int) $he['total'] > 0 ? 'Conferir HE bloqueante →' : 'Ver conferência →' ?>
                </a>
            </article>

            <!-- 3. E-mail que não entrega -->
            <article class="dash-kpi-card is-<?= View::e((string) $email['tom']) ?>">
                <div class="dash-kpi-top">
                    <span class="dash-kpi-label">E-mails sem entrega</span>
                    <?php if ((int) $email['total'] > 0): ?>
                        <span class="dash-kpi-badge is-danger">Etapa 6 parada</span>
                    <?php else: ?>
                        <span class="dash-kpi-badge is-ok">Entregáveis</span>
                    <?php endif; ?>
                </div>
                <div>
                    <div class="dash-kpi-num-row">
                        <span class="dash-kpi-num"><?= (int) $email['total'] ?></span>
                        <span class="dash-kpi-unit">
                            <?= (int) $email['total'] === 1 ? 'cadastro/envio' : 'cadastros/envios' ?>
                        </span>
                    </div>
                    <p class="dash-kpi-desc">
                        <?php if ((int) $email['total'] > 0): ?>
                            <?= (int) $email['falhas'] ?> falha(s) SMTP · <?= (int) $email['sem_email'] ?> sem e-mail · <?= (int) $email['invalidos'] ?> domínio inválido.
                        <?php else: ?>
                            Sem erros de disparo ou e-mails inválidos nos pendentes.
                        <?php endif; ?>
                    </p>
                </div>
                <a class="dash-kpi-link" href="<?= View::e((string) $email['href']) ?>">
                    <?= (int) $email['total'] > 0 ? 'Corrigir envios →' : 'Relatório de envios →' ?>
                </a>
            </article>

            <!-- 4. Assinaturas pendentes / régua -->
            <article class="dash-kpi-card is-<?= (int) $ass['total'] > 0 && $assBadge['tom'] === 'danger' ? 'danger' : ((int) $ass['total'] > 0 && $assBadge['tom'] === 'warning' ? 'warning' : ((int) $ass['total'] === 0 ? 'ok' : '')) ?>">
                <div class="dash-kpi-top">
                    <span class="dash-kpi-label">Assinaturas pendentes</span>
                    <span class="dash-kpi-badge is-<?= View::e($assBadge['tom']) ?>"><?= View::e($assBadge['label']) ?></span>
                </div>
                <div>
                    <div class="dash-kpi-num-row">
                        <span class="dash-kpi-num"><?= (int) $ass['total'] ?></span>
                        <span class="dash-kpi-unit">aguardando colaborador</span>
                    </div>
                    <p class="dash-kpi-desc"><?= View::e($assDetalhe) ?></p>
                </div>
                <a class="dash-kpi-link" href="<?= View::e((string) $ass['href']) ?>">
                    <?= (int) $ass['total'] > 0 ? 'Acompanhar e cobrar →' : 'Ver assinaturas →' ?>
                </a>
            </article>
        </div>
    </section>
<?php endif; ?>

<!-- FAIXA 2 — Fila Acionável Imediata (Abas) -->
<section class="card" aria-label="Fila de destravamento do período">
    <div class="dash-queue-head">
        <div>
            <h2 class="card-title">Destravar agora</h2>
            <p class="card-subtitle">Top <?= 10 ?> por prioridade em <?= View::e($periodoLabel) ?> — clique para resolver direto na origem.</p>
        </div>
        <div class="dash-tabs" role="tablist" aria-label="Filas de exceção">
            <?php foreach ($filas as $tabKey => $fila):
                $isActive = $tabKey === $filaPadrao;
                ?>
                <button type="button"
                        class="dash-tab <?= $isActive ? 'is-active' : '' ?>"
                        role="tab"
                        id="dashTab-<?= View::e($tabKey) ?>"
                        aria-selected="<?= $isActive ? 'true' : 'false' ?>"
                        aria-controls="dashPanel-<?= View::e($tabKey) ?>"
                        data-dash-tab="<?= View::e($tabKey) ?>">
                    <span><?= View::e((string) $fila['label']) ?></span>
                    <span class="dash-tab-count"><?= (int) $fila['total'] ?></span>
                </button>
            <?php endforeach; ?>
        </div>
    </div>

    <?php foreach ($filas as $tabKey => $fila):
        $isActive = $tabKey === $filaPadrao;
        $itens = $fila['itens'] ?? [];
        $totalFila = (int) ($fila['total'] ?? 0);
        ?>
        <div class="dash-queue-panel"
             role="tabpanel"
             id="dashPanel-<?= View::e($tabKey) ?>"
             aria-labelledby="dashTab-<?= View::e($tabKey) ?>"
             data-dash-panel="<?= View::e($tabKey) ?>"
             <?= $isActive ? '' : 'hidden' ?>>
            <?php if ($itens === []): ?>
                <div class="empty-state" style="padding:2rem 1.25rem;">
                    <h3>Fila zerada</h3>
                    <p>Nenhum item travado em <?= View::e(mb_strtolower((string) $fila['label'], 'UTF-8')) ?> neste período.</p>
                </div>
            <?php else: ?>
                <div class="dash-queue-list">
                    <?php foreach ($itens as $item): ?>
                        <div class="dash-queue-row">
                            <div class="dash-queue-main">
                                <div class="dash-queue-name">
                                    <?= View::e((string) $item['nome']) ?>
                                    <span class="dash-queue-mat">· Mat. <?= View::e((string) ($item['matricula'] ?? '—')) ?></span>
                                </div>
                                <div class="dash-queue-det"><?= View::e((string) $item['detalhe']) ?></div>
                            </div>
                            <div class="dash-queue-actions">
                                <?php if (!empty($item['badge'])): ?>
                                    <span class="dash-kpi-badge is-<?= View::e((string) ($item['badge_tom'] ?? 'neutral')) ?>">
                                        <?= View::e((string) $item['badge']) ?>
                                    </span>
                                <?php endif; ?>
                                <a class="btn btn-secondary btn-sm"
                                   href="<?= View::e((string) $item['cta_href']) ?>"
                                   <?= !empty($item['external']) ? 'target="_blank" rel="noopener noreferrer"' : '' ?>>
                                    <?= View::e((string) $item['cta_label']) ?>
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="dash-queue-foot">
                    <span>Mostrando <?= count($itens) ?> de <?= $totalFila ?> item(ns)</span>
                    <a class="dash-kpi-link" href="<?= View::e((string) $fila['ver_todos_href']) ?>">
                        Ver fila completa →
                    </a>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</section>

<script>
(function () {
  var tabs = document.querySelectorAll('[data-dash-tab]');
  var panels = document.querySelectorAll('[data-dash-panel]');
  if (!tabs.length) return;
  tabs.forEach(function (tab) {
    tab.addEventListener('click', function () {
      var target = tab.getAttribute('data-dash-tab');
      tabs.forEach(function (t) {
        var on = t === tab;
        t.classList.toggle('is-active', on);
        t.setAttribute('aria-selected', on ? 'true' : 'false');
      });
      panels.forEach(function (p) {
        p.hidden = p.getAttribute('data-dash-panel') !== target;
      });
    });
  });
})();
</script>
