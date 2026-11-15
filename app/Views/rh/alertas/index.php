<?php
declare(strict_types=1);

use App\Helpers\Csrf;

/** @var list<array<string,mixed>> $itens */
/** @var int $naoLidos */
/** @var array{abertos:int,nao_lidos:int,criticos:int,altas:int,cobrancas:int,meus:int} $contadores */
/** @var array<string,mixed> $filtros */
/** @var list<array<string,mixed>> $periodosGerados */
/** @var list<array{id:int,nome:string}> $usuariosAtribuicao */
/** @var list<array<string,mixed>> $tiposCatalogo */
/** @var string $dataInicio */
/** @var string $dataFim */
/** @var string $periodoLabel */
/** @var int $usuarioId */
/** @var bool $isHistorico */
/** @var bool $podeConfigurar */
/** @var ?string $flashOk */
/** @var ?string $erro */

$csrf = Csrf::token();
$statusAtual = (string) ($filtros['status'] ?? 'abertos');
$sevAtual = (string) ($filtros['severidade'] ?? '');
$codAtual = (string) ($filtros['codigo'] ?? '');
$qAtual = (string) ($filtros['q'] ?? '');

$contadores = $contadores ?? [
    'abertos' => count($itens),
    'nao_lidos' => $naoLidos,
    'criticos' => 0,
    'altas' => 0,
    'cobrancas' => 0,
    'meus' => 0,
];

$sevLabels = [
    'critica' => 'Crítica',
    'alta' => 'Alta',
    'media' => 'Média',
    'info' => 'Informativo',
];

$temFiltroAtivo = ($statusAtual !== 'abertos')
    || ($sevAtual !== '')
    || ($codAtual !== '')
    || ($qAtual !== '');

$qsPeriodo = [];
if ($dataInicio !== '' && $dataFim !== '') {
    $qsPeriodo['data_inicio'] = $dataInicio;
    $qsPeriodo['data_fim'] = $dataFim;
}
$buildFilterUrl = static function (array $override) use ($qsPeriodo): string {
    $params = array_merge($qsPeriodo, $override);
    $clean = array_filter($params, static fn ($v): bool => $v !== null && $v !== '');
    return '/rh/alertas' . ($clean !== [] ? '?' . http_build_query($clean) : '');
};

$cardAtivoAbertos = !$isHistorico && $statusAtual === 'abertos' && $sevAtual === '' && $codAtual === '';
$cardAtivoNaoLidos = !$isHistorico && $statusAtual === 'nao_lidos';
$cardAtivoCriticos = !$isHistorico && $sevAtual === 'critica';
$cardAtivoAltas = !$isHistorico && $sevAtual === 'alta' && $codAtual === '';
$cardAtivoCobrancas = !$isHistorico && in_array($codAtual, ['cobranca', 'cobranca.falha', 'assinatura.atraso'], true);
?>
<div class="alertas-page" data-csrf="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">

    <?php if (!empty($flashOk)): ?>
        <div class="alert alert--success mb-4" role="status">
            <div>
                <div class="alert__title">Atualização concluída</div>
                <div><?= htmlspecialchars($flashOk, ENT_QUOTES, 'UTF-8') ?></div>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($erro)): ?>
        <div class="alert alert--danger mb-4" role="alert">
            <div>
                <div class="alert__title">Falha ao carregar alertas</div>
                <div><?= htmlspecialchars($erro, ENT_QUOTES, 'UTF-8') ?></div>
            </div>
        </div>
    <?php endif; ?>

    <header class="alertas-head">
        <div class="alertas-head-main">
            <div class="alertas-kicker-row">
                <span class="alertas-kicker">
                    <?= $isHistorico ? 'Auditoria · Últimos 30 dias' : 'Monitoramento do Fechamento' ?>
                </span>
                <?php if ($periodoLabel !== ''): ?>
                    <span class="alertas-periodo-pill" title="Período de apuração ativo">
                        Período: <?= htmlspecialchars($periodoLabel, ENT_QUOTES, 'UTF-8') ?>
                    </span>
                <?php endif; ?>
            </div>
            <h2 class="alertas-title">
                <?= $isHistorico
                    ? 'Histórico de alertas concluídos e dispensados'
                    : 'Pendências e alertas operacionais' ?>
            </h2>
            <p class="alertas-subtitle">
                <?= $isHistorico
                    ? 'Consulte ocorrências que já foram resolvidas automaticamente na operação ou arquivadas por você.'
                    : 'Acompanhe em tempo real o que trava o fechamento do ponto, envio de folhas, horas extras e assinaturas.' ?>
            </p>
        </div>

        <div class="alertas-head-actions">
            <div class="alertas-mode-switch" role="tablist" aria-label="Alternar modo de visualização">
                <a class="alertas-mode-btn <?= !$isHistorico ? 'is-active' : '' ?>"
                   href="<?= htmlspecialchars($buildFilterUrl(['status' => 'abertos']), ENT_QUOTES, 'UTF-8') ?>">
                    Fila ativa
                    <span class="alertas-mode-badge" data-stat-counter="abertos_ pill"><?= (int) $contadores['abertos'] ?></span>
                </a>
                <a class="alertas-mode-btn <?= $isHistorico ? 'is-active' : '' ?>"
                   href="<?= htmlspecialchars($buildFilterUrl(['status' => 'resolvidos']), ENT_QUOTES, 'UTF-8') ?>">
                    Histórico (30d)
                </a>
            </div>

            <form method="post" action="/rh/alertas/sincronizar" class="inline-form">
                <?= Csrf::field() ?>
                <input type="hidden" name="data_inicio" value="<?= htmlspecialchars($dataInicio, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="data_fim" value="<?= htmlspecialchars($dataFim, ENT_QUOTES, 'UTF-8') ?>">
                <button type="submit" class="btn btn--secondary btn--sm" title="Recalcular alertas com base no estado atual do Ponto, Medição e E-mail">
                    ↻ Atualizar agora
                </button>
            </form>

            <?php if (!$isHistorico && $naoLidos > 0): ?>
                <form method="post" action="/rh/alertas/marcar-todos-lidos" data-alertas-lidos-todos class="inline-form">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="redirect" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? '/rh/alertas', ENT_QUOTES, 'UTF-8') ?>">
                    <button type="submit" class="btn btn--ghost btn--sm">
                        ✓ Marcar todos como lidos (<span data-stat-counter="nao_lidos_btn"><?= (int) $naoLidos ?></span>)
                    </button>
                </form>
            <?php endif; ?>

            <?php if (!empty($podeConfigurar)): ?>
                <a class="btn btn--secondary btn--sm alertas-cfg-btn" href="/rh/alertas/config">
                    ⚙ Configurar regras e envios
                </a>
            <?php endif; ?>
        </div>
    </header>

    <?php if (!$isHistorico): ?>
        <section class="alertas-stats" aria-label="Filtros rápidos por indicador">
            <a class="alertas-stat <?= $cardAtivoAbertos ? 'is-active' : '' ?>"
               href="<?= htmlspecialchars($buildFilterUrl(['status' => 'abertos']), ENT_QUOTES, 'UTF-8') ?>">
                <div class="alertas-stat-top">
                    <span class="alertas-stat-label">Abertos na fila</span>
                    <span class="alertas-stat-hint">Ver todos</span>
                </div>
                <strong class="alertas-stat-num" data-stat-counter="abertos"><?= (int) $contadores['abertos'] ?></strong>
                <span class="alertas-stat-foot">Pendências ativas no período</span>
            </a>

            <a class="alertas-stat <?= $contadores['nao_lidos'] > 0 ? 'alertas-stat--unread' : '' ?> <?= $cardAtivoNaoLidos ? 'is-active' : '' ?>"
               href="<?= htmlspecialchars($buildFilterUrl(['status' => 'nao_lidos']), ENT_QUOTES, 'UTF-8') ?>">
                <div class="alertas-stat-top">
                    <span class="alertas-stat-label">Não lidos</span>
                    <span class="alertas-stat-hint">Filtrar novos</span>
                </div>
                <strong class="alertas-stat-num" data-stat-counter="nao_lidos"><?= (int) $contadores['nao_lidos'] ?></strong>
                <span class="alertas-stat-foot">Aguardando sua visualização</span>
            </a>

            <a class="alertas-stat alertas-stat--danger <?= $cardAtivoCriticos ? 'is-active' : '' ?>"
               href="<?= htmlspecialchars($buildFilterUrl(['status' => 'abertos', 'severidade' => 'critica']), ENT_QUOTES, 'UTF-8') ?>">
                <div class="alertas-stat-top">
                    <span class="alertas-stat-label">Críticos</span>
                    <span class="alertas-stat-hint">Ação imediata</span>
                </div>
                <strong class="alertas-stat-num" data-stat-counter="criticos"><?= (int) $contadores['criticos'] ?></strong>
                <span class="alertas-stat-foot">Canal de e-mail, SLA ou atraso D+2</span>
            </a>

            <a class="alertas-stat alertas-stat--warning <?= $cardAtivoAltas ? 'is-active' : '' ?>"
               href="<?= htmlspecialchars($buildFilterUrl(['status' => 'abertos', 'severidade' => 'alta']), ENT_QUOTES, 'UTF-8') ?>">
                <div class="alertas-stat-top">
                    <span class="alertas-stat-label">Alta prioridade</span>
                    <span class="alertas-stat-hint">Destravar etapa</span>
                </div>
                <strong class="alertas-stat-num" data-stat-counter="altas"><?= (int) $contadores['altas'] ?></strong>
                <span class="alertas-stat-foot">Divergências, HE, espelhos e envios</span>
            </a>

            <a class="alertas-stat <?= $contadores['cobrancas'] > 0 ? 'alertas-stat--cobranca' : '' ?> <?= $cardAtivoCobrancas ? 'is-active' : '' ?>"
               href="<?= htmlspecialchars($buildFilterUrl(['status' => 'abertos', 'codigo' => 'cobranca']), ENT_QUOTES, 'UTF-8') ?>">
                <div class="alertas-stat-top">
                    <span class="alertas-stat-label">Cobrança e assinaturas</span>
                    <span class="alertas-stat-hint">Filtrar régua</span>
                </div>
                <strong class="alertas-stat-num" data-stat-counter="cobrancas"><?= (int) $contadores['cobrancas'] ?></strong>
                <span class="alertas-stat-foot">Falhas de disparo ou atraso na régua</span>
            </a>
        </section>
    <?php endif; ?>

    <form method="get" action="/rh/alertas" class="alertas-toolbar" role="search" aria-label="Filtrar alertas">
        <?php if ($periodosGerados !== []): ?>
            <label class="alertas-filter">
                <span>Período de apuração</span>
                <select name="periodo_sel" onchange="
                    var p = this.value.split('|');
                    this.form.data_inicio.value = p[0] || '';
                    this.form.data_fim.value = p[1] || '';
                    this.form.submit();
                ">
                    <option value="|" <?= ($dataInicio === '' && $dataFim === '') ? 'selected' : '' ?>>Todos os períodos</option>
                    <?php foreach ($periodosGerados as $p):
                        $pi = (string) $p['data_inicio'];
                        $pf = (string) $p['data_fim'];
                        $sel = ($pi === $dataInicio && $pf === $dataFim) ? 'selected' : '';
                        ?>
                        <option value="<?= htmlspecialchars($pi . '|' . $pf, ENT_QUOTES, 'UTF-8') ?>" <?= $sel ?>>
                            <?= htmlspecialchars((string) ($p['rotulo'] ?? ($pi . ' a ' . $pf)), ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="hidden" name="data_inicio" value="<?= htmlspecialchars($dataInicio, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="data_fim" value="<?= htmlspecialchars($dataFim, ENT_QUOTES, 'UTF-8') ?>">
            </label>
        <?php endif; ?>

        <label class="alertas-filter">
            <span>Situação</span>
            <select name="status" onchange="this.form.submit()">
                <optgroup label="Fila de trabalho">
                    <option value="abertos" <?= $statusAtual === 'abertos' ? 'selected' : '' ?>>Abertos na fila (<?= (int) $contadores['abertos'] ?>)</option>
                    <option value="nao_lidos" <?= $statusAtual === 'nao_lidos' ? 'selected' : '' ?>>Apenas não lidos (<?= (int) $contadores['nao_lidos'] ?>)</option>
                    <option value="meus" <?= $statusAtual === 'meus' ? 'selected' : '' ?>>Atribuídos a mim (<?= (int) $contadores['meus'] ?>)</option>
                </optgroup>
                <optgroup label="Histórico (últimos 30 dias)">
                    <option value="resolvidos" <?= $statusAtual === 'resolvidos' ? 'selected' : '' ?>>Resolvidos na operação</option>
                    <option value="dispensados" <?= $statusAtual === 'dispensados' ? 'selected' : '' ?>>Dispensados por mim</option>
                </optgroup>
            </select>
        </label>

        <label class="alertas-filter">
            <span>Prioridade</span>
            <select name="severidade" onchange="this.form.submit()">
                <option value="">Todas as prioridades</option>
                <?php foreach ($sevLabels as $k => $lbl): ?>
                    <option value="<?= $k ?>" <?= $sevAtual === $k ? 'selected' : '' ?>><?= $lbl ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <label class="alertas-filter">
            <span>Categoria</span>
            <select name="codigo" onchange="this.form.submit()">
                <option value="">Todas as categorias</option>
                <optgroup label="Agrupamentos rápidos">
                    <option value="cobranca" <?= $codAtual === 'cobranca' ? 'selected' : '' ?>>Cobrança e atraso de assinatura</option>
                    <option value="divergencia" <?= $codAtual === 'divergencia' ? 'selected' : '' ?>>Divergências de ponto (todas)</option>
                    <option value="gargalo" <?= $codAtual === 'gargalo' ? 'selected' : '' ?>>Gargalo do período (todas as etapas)</option>
                </optgroup>
                <optgroup label="Regras específicas">
                    <?php foreach ($tiposCatalogo as $tc):
                        $c = (string) ($tc['codigo'] ?? '');
                        if ($c === '' || $c === 'gargalo') {
                            continue;
                        }
                        ?>
                        <option value="<?= htmlspecialchars($c, ENT_QUOTES, 'UTF-8') ?>" <?= $codAtual === $c ? 'selected' : '' ?>>
                            <?= htmlspecialchars((string) ($tc['grupo'] ?? 'Geral') . ' · ' . (string) ($tc['nome'] ?? $c), ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </optgroup>
            </select>
        </label>

        <label class="alertas-filter alertas-filter--grow">
            <span>Buscar</span>
            <input type="search" name="q" value="<?= htmlspecialchars($qAtual, ENT_QUOTES, 'UTF-8') ?>"
                   placeholder="Colaborador, motivo, e-mail ou descrição…">
        </label>

        <div class="alertas-toolbar-btns">
            <button type="submit" class="btn btn--secondary btn--sm">Filtrar</button>
            <?php if ($temFiltroAtivo): ?>
                <a class="btn btn--ghost btn--sm" href="<?= htmlspecialchars($buildFilterUrl(['status' => 'abertos']), ENT_QUOTES, 'UTF-8') ?>">
                    Limpar filtros
                </a>
            <?php endif; ?>
        </div>
    </form>

    <?php if ($itens === []): ?>
        <div class="empty-state" data-alertas-empty>
            <div class="empty-state__title">
                <?= $isHistorico
                    ? 'Nenhum alerta registrado neste recorte do histórico'
                    : ($temFiltroAtivo ? 'Nenhum alerta corresponde aos filtros selecionados' : 'Operação em dia — nenhuma pendência aberta') ?>
            </div>
            <p class="empty-state__description">
                <?= $isHistorico
                    ? 'Os alertas resolvidos automaticamente ou arquivados nos últimos 30 dias aparecerão aqui.'
                    : ($temFiltroAtivo
                        ? 'Experimente limpar os filtros acima para visualizar todas as pendências ativas do período.'
                        : 'Canal de e-mail conectado, cartões de ponto conferidos e nenhuma divergência ou falha de envio aberta no momento.') ?>
            </p>
            <div class="empty-state__actions">
                <?php if ($temFiltroAtivo): ?>
                    <a class="btn btn--secondary btn--sm" href="<?= htmlspecialchars($buildFilterUrl(['status' => 'abertos']), ENT_QUOTES, 'UTF-8') ?>">
                        Ver todos os alertas abertos
                    </a>
                <?php endif; ?>
                <a class="btn btn--primary btn--sm" href="/">Ir para a Visão Geral</a>
                <a class="btn btn--ghost btn--sm" href="/rh/medicao">Abrir Medição</a>
            </div>
        </div>
    <?php else: ?>
        <div class="alertas-list" role="list">
            <?php foreach ($itens as $item):
                $id = (int) $item['id'];
                $sev = (string) $item['severidade'];
                $lido = !empty($item['lido']);
                $codigo = (string) $item['codigo'];
                $tipoNome = (string) ($item['tipo_nome'] ?? $codigo);
                $grupoNome = (string) ($item['grupo'] ?? 'Operação');
                $periodoRotuloItem = (string) ($item['periodo_rotulo'] ?? '');
                $ctaLabel = (string) ($item['cta_label'] ?? 'Verificar pendência');
                $isCobranca = in_array($codigo, ['cobranca.falha', 'assinatura.atraso'], true);
                $isCobrancaFalha = $codigo === 'cobranca.falha';
                $atribuidoId = $item['atribuido_usuario_id'] ?? null;
                $atribuidoNome = (string) ($item['atribuido_nome'] ?? '');
                ?>
                <article class="alerta-card alerta-card--<?= htmlspecialchars($sev, ENT_QUOTES, 'UTF-8') ?> <?= $lido ? 'is-read' : 'is-unread' ?> <?= !empty($item['destaque']) ? 'is-featured' : '' ?>"
                         role="listitem"
                         data-alerta-id="<?= $id ?>"
                         data-lido="<?= $lido ? '1' : '0' ?>"
                         data-severidade="<?= htmlspecialchars($sev, ENT_QUOTES, 'UTF-8') ?>"
                         data-cobranca="<?= $isCobranca ? '1' : '0' ?>">
                    <div class="alerta-card-head">
                        <div class="alerta-badges">
                            <span class="alerta-sev alerta-sev--<?= htmlspecialchars($sev, ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($sevLabels[$sev] ?? $sev, ENT_QUOTES, 'UTF-8') ?>
                            </span>
                            <?php if ($isCobrancaFalha): ?>
                                <span class="alerta-pill alerta-pill--cobranca">Falha no disparo de cobrança</span>
                            <?php endif; ?>
                            <span class="alerta-pill alerta-pill--cat" title="Regra: <?= htmlspecialchars($codigo, ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($grupoNome . ' · ' . $tipoNome, ENT_QUOTES, 'UTF-8') ?>
                            </span>
                            <?php if (!$lido && !$isHistorico): ?>
                                <span class="alerta-unread-dot" data-alerta-unread-indicator title="Ainda não lido por você">Novo</span>
                            <?php endif; ?>
                        </div>
                        <span class="alerta-age">
                            <?php if ($statusAtual === 'resolvidos'): ?>
                                Resolvido na operação
                            <?php elseif ($statusAtual === 'dispensados'): ?>
                                Arquivado por você
                            <?php elseif ($item['idade'] !== ''): ?>
                                Aberto há <?= htmlspecialchars((string) $item['idade'], ENT_QUOTES, 'UTF-8') ?>
                            <?php endif; ?>
                        </span>
                    </div>

                    <div class="alerta-card-body">
                        <h3 class="alerta-card-title">
                            <?= htmlspecialchars((string) $item['titulo'], ENT_QUOTES, 'UTF-8') ?>
                        </h3>

                        <div class="alerta-context-row">
                            <?php if ($item['colaborador_nome'] !== ''): ?>
                                <span class="alerta-chip">
                                    <strong>Colaborador:</strong>
                                    <?= htmlspecialchars((string) $item['colaborador_nome'], ENT_QUOTES, 'UTF-8') ?>
                                    <?php if ($item['colaborador_matricula'] !== ''): ?>
                                        (Mat. <?= htmlspecialchars((string) $item['colaborador_matricula'], ENT_QUOTES, 'UTF-8') ?>)
                                    <?php endif; ?>
                                </span>
                            <?php endif; ?>

                            <?php if ($periodoRotuloItem !== ''): ?>
                                <span class="alerta-chip">
                                    <strong>Período:</strong> <?= htmlspecialchars($periodoRotuloItem, ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            <?php endif; ?>

                            <span class="alerta-chip <?= $atribuidoNome !== '' ? 'alerta-chip--owner' : '' ?>" data-alerta-owner-chip>
                                <strong>Responsável:</strong>
                                <span data-alerta-owner-label>
                                    <?= $atribuidoNome !== ''
                                        ? htmlspecialchars($atribuidoNome, ENT_QUOTES, 'UTF-8')
                                        : 'Sem responsável definido' ?>
                                </span>
                            </span>
                        </div>

                        <?php if ($item['detalhe'] !== ''): ?>
                            <p class="alerta-card-detail"><?= htmlspecialchars((string) $item['detalhe'], ENT_QUOTES, 'UTF-8') ?></p>
                        <?php endif; ?>
                    </div>

                    <div class="alerta-card-foot">
                        <div class="alerta-card-primary-action">
                            <?php if ($item['href'] !== ''): ?>
                                <a class="btn btn--primary btn--sm"
                                   href="<?= htmlspecialchars((string) $item['href'], ENT_QUOTES, 'UTF-8') ?>"
                                   data-alerta-abrir="<?= $id ?>">
                                    <?= htmlspecialchars($ctaLabel, ENT_QUOTES, 'UTF-8') ?> →
                                </a>
                            <?php endif; ?>
                        </div>

                        <?php if (!$isHistorico): ?>
                            <div class="alerta-card-actions">
                                <?php if ($usuariosAtribuicao !== []): ?>
                                    <form method="post" action="/rh/alertas/<?= $id ?>/atribuir" class="alerta-assign-form" data-alerta-atribuir="<?= $id ?>">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="redirect" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? '/rh/alertas', ENT_QUOTES, 'UTF-8') ?>">
                                        <select name="usuario_id" class="alerta-assign-select" aria-label="Definir responsável pelo alerta">
                                            <option value="0">Responsável: Sem dono</option>
                                            <?php foreach ($usuariosAtribuicao as $u): ?>
                                                <option value="<?= (int) $u['id'] ?>" <?= (int) $atribuidoId === (int) $u['id'] ? 'selected' : '' ?>>
                                                    Responsável: <?= htmlspecialchars((string) $u['nome'], ENT_QUOTES, 'UTF-8') ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </form>
                                <?php endif; ?>

                                <?php if (!$lido): ?>
                                    <form method="post" action="/rh/alertas/<?= $id ?>/lido" data-alerta-lido="<?= $id ?>" class="inline-form">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="redirect" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? '/rh/alertas', ENT_QUOTES, 'UTF-8') ?>">
                                        <button type="submit" class="btn btn--secondary btn--sm">Marcar como lido</button>
                                    </form>
                                <?php endif; ?>

                                <form method="post" action="/rh/alertas/<?= $id ?>/dispensar" data-alerta-dispensar="<?= $id ?>" class="inline-form">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="redirect" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? '/rh/alertas', ENT_QUOTES, 'UTF-8') ?>">
                                    <button type="submit" class="btn btn--ghost btn--sm" title="Ocultar este alerta da sua fila individual">
                                        Arquivar da minha fila
                                    </button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
