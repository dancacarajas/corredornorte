<?php

use App\Helpers\Csrf;
use App\Helpers\View;

/** @var array{relatorio:array,dias:list,contadores:array} $dados */
$dados = $dados ?? ['relatorio' => [], 'dias' => [], 'contadores' => []];
$rel = $dados['relatorio'];
$dias = $dados['dias'];
$cont = $dados['contadores'];
$somenteLeitura = (bool) ($somenteLeitura ?? false);
$contratos = $contratos ?? [];
$ordens = $ordens ?? [];
$flash = $flash ?? null;
$error = $error ?? null;
$documentoAssinatura = $documentoAssinatura ?? null;
$linkAssinatura = $linkAssinatura ?? null;
$textoAceite = $textoAceite ?? \App\Services\RelatorioAssinaturaService::TEXTO_ACEITE;
$nomeAssinaturaInformado = $nomeAssinaturaInformado ?? '';

$ini = (string) ($rel['data_inicio'] ?? '');
$fim = (string) ($rel['data_fim'] ?? '');
$rotulo = ($ini && $fim) ? date('d/m/Y', strtotime($ini)) . ' a ' . date('d/m/Y', strtotime($fim)) : '—';
$contratoId = (int) ($rel['contrato_id'] ?? 0);
$osId = (int) ($rel['ordem_servico_id'] ?? 0);
$status = (string) ($rel['status'] ?? 'rascunho');
$heCliente = (($rel['he_fluxo'] ?? 'off') === 'cliente');
$docStatus = is_array($documentoAssinatura) ? (string) ($documentoAssinatura['status'] ?? '') : '';
$assinado = $docStatus === 'assinado';

if ($linkAssinatura === null && is_array($documentoAssinatura) && !empty($documentoAssinatura['portal_token'])) {
    $config = require dirname(__DIR__, 3) . '/config/app.php';
    $base = rtrim((string) ($config['url'] ?? ''), '/');
    if (str_ends_with(strtolower($base), '/public')) {
        $base = substr($base, 0, -7);
    }
    $linkAssinatura = $base . '/assinar-relatorio/' . rawurlencode((string) $documentoAssinatura['portal_token']);
}

$temLink = is_string($linkAssinatura) && $linkAssinatura !== '';
$modo = match (true) {
    $assinado => 'concluido',
    in_array($status, ['enviado', 'aprovado'], true) => 'assinar',
    default => 'preencher',
};

$statusLabel = match ($status) {
    'rascunho' => 'Rascunho',
    'enviado' => 'Enviado',
    'aprovado' => 'Aprovado',
    default => $status,
};

$diasSemanaPt = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
$diasTrabalho = 0;
$diasFolga = 0;
$diasDispensados = 0;
$camposOk = 0;
$camposTotal = 0;
$faltamDescricao = 0;
$faltamProjeto = 0;
$faltamAmbiente = 0;
$diasIncompletos = 0;
foreach ($dias as $dia) {
    $tipo = (string) ($dia['tipo_dia'] ?? 'trabalho');
    if ($tipo !== 'trabalho') {
        $diasFolga++;
        continue;
    }
    if (!empty($dia['dispensado_preenchimento'])) {
        $diasDispensados++;
        continue;
    }
    $diasTrabalho++;
    $camposTotal += 3;
    $temDesc = trim((string) ($dia['descricao'] ?? '')) !== '';
    $temProj = trim((string) ($dia['projeto_obra'] ?? '')) !== '';
    $temAmb = trim((string) ($dia['ambiente'] ?? '')) !== '';
    if ($temDesc) {
        $camposOk++;
    } else {
        $faltamDescricao++;
    }
    if ($temProj) {
        $camposOk++;
    } else {
        $faltamProjeto++;
    }
    if ($temAmb) {
        $camposOk++;
    } else {
        $faltamAmbiente++;
    }
    if (!$temDesc || !$temProj || !$temAmb) {
        $diasIncompletos++;
    }
}
$progressoPct = $camposTotal > 0 ? (int) round(($camposOk / $camposTotal) * 100) : ($diasDispensados > 0 ? 100 : 0);
$prontoEnviar = ($diasTrabalho > 0 || $diasDispensados > 0) && $diasIncompletos === 0;
$primeiroPendenteAberto = false;
?>

<?php if ($flash): ?>
    <div class="alert alert-success" role="status"><?= View::e((string) $flash) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger" role="alert"><?= View::e((string) $error) ?></div>
<?php endif; ?>

<section class="card ra-page" data-modo="<?= View::e($modo) ?>" data-ra-id="<?= (int) $rel['id'] ?>" data-has-flash="<?= $flash ? '1' : '0' ?>">
    <div class="card-header ra-page-header">
        <div>
            <h2 class="card-title">
                <?php if ($modo === 'preencher'): ?>
                    Preencher relatório
                <?php elseif ($modo === 'assinar'): ?>
                    Assinar relatório
                <?php else: ?>
                    Relatório concluído
                <?php endif; ?>
            </h2>
            <p class="card-subtitle">
                <?= View::e($rotulo) ?>
                · <span class="ra-status-pill ra-status-<?= View::e($status) ?>"><?= View::e($statusLabel) ?></span>
                <?php if ($assinado): ?>
                    · <span class="ra-status-pill ra-status-assinado">Assinado</span>
                <?php endif; ?>
            </p>
        </div>
        <div class="ra-header-actions">
            <details class="ra-more">
                <summary class="btn btn-ghost btn-sm">Mais</summary>
                <div class="ra-more-menu" role="menu">
                    <a href="/he/relatorios/<?= (int) $rel['id'] ?>/pdf" target="_blank" rel="noopener">Exportar PDF</a>
                    <a href="/he/relatorios/<?= (int) $rel['id'] ?>/excel">Exportar Excel</a>
                </div>
            </details>
            <a class="btn btn-ghost btn-sm" href="/he/relatorios">Voltar</a>
        </div>
    </div>

    <div class="card-body section-stack">
        <?php
        $step1Current = $modo === 'preencher' && !$prontoEnviar;
        $step2Current = $modo === 'preencher' && $prontoEnviar;
        $step1Done = $modo !== 'preencher' || $prontoEnviar;
        $step2Done = $modo !== 'preencher';
        $etapas = [
            ['n' => '1', 'label' => 'Preencher', 'done' => $step1Done, 'current' => $step1Current],
            ['n' => '2', 'label' => 'Enviar', 'done' => $step2Done, 'current' => $step2Current],
            ['n' => '3', 'label' => 'Assinar', 'done' => $modo === 'concluido', 'current' => $modo === 'assinar'],
            ['n' => '4', 'label' => 'Concluído', 'done' => $modo === 'concluido', 'current' => false],
        ];
        ?>
        <nav class="ra-steps" aria-label="Etapas do relatório">
            <?php foreach ($etapas as $etapa): ?>
                <?php
                $feito = (bool) $etapa['done'];
                $atual = (bool) $etapa['current'] && !$feito;
                ?>
                <div class="ra-step <?= $feito ? 'is-done' : '' ?> <?= $atual ? 'is-current' : '' ?>">
                    <span class="ra-step-num" aria-hidden="true"><?= $feito ? '✓' : View::e($etapa['n']) ?></span>
                    <span class="ra-step-label"><?= View::e($etapa['label']) ?></span>
                </div>
            <?php endforeach; ?>
        </nav>

        <?php if ($modo === 'preencher'): ?>
            <div class="ra-progress" aria-label="Progresso do preenchimento">
                <div class="ra-progress-top">
                    <strong>Preenchimento do R06</strong>
                    <span data-ra-progress-label><?= (int) $progressoPct ?>% · <?= (int) $camposOk ?>/<?= (int) $camposTotal ?> campos</span>
                </div>
                <div class="ra-progress-track" role="progressbar"
                     aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int) $progressoPct ?>"
                     data-ra-progress-bar>
                    <span class="ra-progress-fill" style="width: <?= (int) $progressoPct ?>%"></span>
                </div>
                <p class="ra-progress-hint text-sm muted">
                    Obrigatórios por dia útil: descrição, projeto/obra e ambiente. Em folgas/feriados você pode lançar horas extras quando autorizado.
                </p>
            </div>
            <div class="ra-next" role="status">
                <div>
                    <strong>Próximo passo:</strong>
                    <?php if ($prontoEnviar): ?>
                        revise e envie o relatório para assinatura.
                    <?php else: ?>
                        complete
                        <strong data-ra-faltam-dias><?= (int) $diasIncompletos ?></strong>
                        dia<?= $diasIncompletos === 1 ? '' : 's' ?> de trabalho com os campos obrigatórios.
                    <?php endif; ?>
                </div>
                <div class="ra-checklist text-sm">
                    <span class="<?= $diasTrabalho > 0 ? 'is-ok' : '' ?>">Dias úteis: <?= (int) $diasTrabalho ?></span>
                    <span class="<?= $faltamDescricao === 0 ? 'is-ok' : 'is-warn' ?>" data-ra-check-desc>
                        Descrição: <?= $faltamDescricao === 0 ? 'ok' : ($faltamDescricao . ' pendente' . ($faltamDescricao === 1 ? '' : 's')) ?>
                    </span>
                    <span class="<?= $faltamProjeto === 0 ? 'is-ok' : 'is-warn' ?>" data-ra-check-proj>
                        Projeto/obra: <?= $faltamProjeto === 0 ? 'ok' : ($faltamProjeto . ' pendente' . ($faltamProjeto === 1 ? '' : 's')) ?>
                    </span>
                    <span class="<?= $faltamAmbiente === 0 ? 'is-ok' : 'is-warn' ?>" data-ra-check-ambiente>
                        Ambiente: <?= $faltamAmbiente === 0 ? 'ok' : ($faltamAmbiente . ' pendente' . ($faltamAmbiente === 1 ? '' : 's')) ?>
                    </span>
                </div>
            </div>
        <?php elseif ($modo === 'assinar'): ?>
            <div class="ra-next ra-next-sign" role="status">
                <div>
                    <strong>Etapa final:</strong> confira o resumo e assine eletronicamente aqui mesmo neste portal.
                </div>
                <div class="ra-sign-row">
                    <a class="btn btn-secondary btn-sm" href="/he/relatorios/<?= (int) $rel['id'] ?>/pdf" target="_blank" rel="noopener">Visualizar PDF</a>
                </div>
            </div>
            <div class="ra-sign-box">
                <form method="post" action="/he/relatorios/<?= (int) $rel['id'] ?>/assinar" class="ra-embed-sign" id="raEmbedSignForm">
                    <?= Csrf::field() ?>
                    <div>
                        <strong style="font-size:1.05rem;">Assinar Relatório de Atividades agora</strong>
                        <p class="text-sm muted" style="margin:.25rem 0 0;">Você não precisa sair desta tela: sua assinatura gera e sela o PDF instantaneamente.</p>
                    </div>
                    <label class="ra-embed-sign-check">
                        <input type="checkbox" name="aceite" value="1" required>
                        <span><?= View::e($textoAceite) ?></span>
                    </label>
                    <div class="form-grid cols-2">
                        <div class="form-field">
                            <label class="form-label" for="raNomeAssinar">Nome completo (conforme cadastro)</label>
                            <input class="form-control" id="raNomeAssinar" name="nome" type="text" required maxlength="160"
                                   autocomplete="name"
                                   value="<?= View::e($nomeAssinaturaInformado !== '' ? $nomeAssinaturaInformado : (string) ($rel['nome'] ?? '')) ?>"
                                   placeholder="Digite seu nome completo conforme o cadastro">
                        </div>
                        <div class="form-field" style="align-self:end;">
                            <button class="btn btn-primary" type="submit" id="raEmbedSignBtn" style="width:100%;min-height:46px;">
                                Assinar Relatório Agora
                            </button>
                        </div>
                    </div>
                </form>

                <details>
                    <summary class="text-sm muted" style="cursor:pointer;font-weight:600;">Ou gerar link externo para compartilhar/assinar em outro dispositivo</summary>
                    <div style="margin-top:.75rem;">
                        <?php if (!$temLink): ?>
                            <form method="post" action="/he/relatorios/<?= (int) $rel['id'] ?>/link-assinatura" class="ra-sign-generate">
                                <?= Csrf::field() ?>
                                <button class="btn btn-secondary btn-sm" type="submit">Gerar link público de assinatura</button>
                            </form>
                        <?php else: ?>
                            <div class="ra-sign-ready">
                                <div class="ra-sign-row">
                                    <input class="form-control" id="raLinkAssinatura" value="<?= View::e($linkAssinatura) ?>" readonly>
                                    <button class="btn btn-secondary btn-sm" type="button" id="raCopiarLink">Copiar</button>
                                    <a class="btn btn-ghost btn-sm" href="<?= View::e($linkAssinatura) ?>" target="_blank" rel="noopener">Abrir</a>
                                </div>
                                <p class="form-hint" id="raCopiarHint" hidden>Link copiado.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </details>
            </div>
        <?php else: ?>
            <div class="ra-next ra-next-done" role="status">
                <div>
                    <strong>Concluído.</strong> Relatório assinado eletronicamente.
                </div>
                <div class="ra-sign-row">
                    <a class="btn btn-primary btn-sm" href="/he/relatorios/<?= (int) $rel['id'] ?>/pdf" target="_blank" rel="noopener">Abrir PDF assinado</a>
                    <?php if ($temLink): ?>
                        <a class="btn btn-secondary btn-sm" href="<?= View::e($linkAssinatura) ?>" target="_blank" rel="noopener">Ver comprovante</a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <form method="post" action="/he/relatorios/<?= (int) $rel['id'] ?>" id="raForm" class="ra-form">
            <?= Csrf::field() ?>
            <details class="ra-meta" <?= $modo === 'preencher' ? 'open' : '' ?>>
                <summary>Dados do cabeçalho e contrato</summary>
                <div class="form-grid cols-2 ra-meta-grid">
                    <div class="form-field">
                        <label class="form-label">Colaborador</label>
                        <input class="form-control" value="<?= View::e((string) ($rel['nome'] ?? '')) ?>" readonly>
                    </div>
                    <div class="form-field">
                        <label class="form-label">Função</label>
                        <input class="form-control" value="<?= View::e((string) ($rel['funcao'] ?? $rel['cargo'] ?? '')) ?>" readonly>
                    </div>
                    <div class="form-field">
                        <label class="form-label">Contrato</label>
                        <select class="form-control" name="contrato_id" id="contratoId" <?= $somenteLeitura ? 'disabled' : '' ?>>
                            <option value="">Selecione…</option>
                            <?php foreach ($contratos as $c): ?>
                                <option value="<?= (int) $c['id'] ?>" <?= $contratoId === (int) $c['id'] ? 'selected' : '' ?>>
                                    <?= View::e($c['codigo'] . ' — ' . $c['nome']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-field">
                        <label class="form-label">OS / Frente</label>
                        <select class="form-control" name="ordem_servico_id" id="ordemServicoId" <?= $somenteLeitura ? 'disabled' : '' ?>>
                            <option value="">Selecione…</option>
                            <?php foreach ($ordens as $o): ?>
                                <option value="<?= (int) $o['id'] ?>" <?= $osId === (int) $o['id'] ? 'selected' : '' ?>>
                                    <?= View::e($o['codigo'] . ' — ' . $o['nome']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-field">
                        <label class="form-label">Gestor</label>
                        <input class="form-control" value="<?= View::e((string) ($rel['gestor_nome'] ?? '')) ?>" readonly>
                    </div>
                    <div class="form-field">
                        <label class="form-label">Resp. / Fiscal Vale</label>
                        <input class="form-control" name="fiscal_vale" value="<?= View::e((string) ($rel['fiscal_vale'] ?? '')) ?>" <?= $somenteLeitura ? 'readonly' : '' ?>>
                    </div>
                </div>
            </details>

            <?php if (!$somenteLeitura): ?>
                <div class="ra-batch-bar" aria-label="Aplicar padrão em lote">
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;">
                        <strong style="font-size:.9rem;">Aplicar padrão nos dias pendentes</strong>
                        <span class="text-sm muted" id="raAutosaveHint">Rascunho automático local ativo</span>
                    </div>
                    <div class="ra-batch-grid">
                        <div class="ra-field-box">
                            <label for="raBatchProj">Projeto / Obra padrão</label>
                            <input type="text" id="raBatchProj" placeholder="Ex.: Projeto Serra Norte">
                        </div>
                        <div class="ra-field-box">
                            <label for="raBatchMun">Município</label>
                            <input type="text" id="raBatchMun" placeholder="Ex.: Parauapebas">
                        </div>
                        <div class="ra-field-box">
                            <label for="raBatchCc">Centro de custo</label>
                            <input type="text" id="raBatchCc" placeholder="Ex.: CC-102">
                        </div>
                        <div class="ra-field-box">
                            <label for="raBatchAmb">Ambiente padrão</label>
                            <select id="raBatchAmb" style="min-height:42px;border:1px solid #CBD5E1;border-radius:8px;padding:0 .5rem;background:#fff;">
                                <option value="">Manter atual</option>
                                <option value="remoto">Remoto (1)</option>
                                <option value="site">Site (2)</option>
                                <option value="viagem">Viagem (3)</option>
                            </select>
                        </div>
                        <div>
                            <button type="button" class="btn btn-secondary" id="raApplyBatchBtn" style="width:100%;min-height:42px;">
                                Aplicar nos pendentes
                            </button>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="ra-counters">
                <span class="pill" data-count="remoto">Remoto (1): <?= (int) ($cont['remoto'] ?? 0) ?></span>
                <span class="pill" data-count="site">Site (2): <?= (int) ($cont['site'] ?? 0) ?></span>
                <span class="pill" data-count="viagem">Viagem (3): <?= (int) ($cont['viagem'] ?? 0) ?></span>
                <span class="pill">Área classificada: <?= (int) ($cont['classificada'] ?? 0) ?></span>
            </div>

            <?php if ($diasFolga > 0): ?>
                <div class="ra-folga-toggle">
                    <label>
                        <input type="checkbox" id="raMostrarFolgas">
                        Mostrar <?= (int) $diasFolga ?> dia<?= $diasFolga === 1 ? '' : 's' ?> de folga/feriado<?= $heCliente ? ' (permite lançar HE)' : '' ?>
                    </label>
                </div>
            <?php endif; ?>

            <div class="ra-days-list">
                <?php foreach ($dias as $i => $dia):
                    $tipo = (string) ($dia['tipo_dia'] ?? 'trabalho');
                    $data = (string) $dia['data'];
                    $ts = strtotime($data);
                    $dow = $ts !== false ? ($diasSemanaPt[(int) date('w', $ts)] ?? '') : '';
                    $amb = (string) ($dia['ambiente'] ?? '');
                    $heStatus = (string) ($dia['he_status'] ?? 'nenhuma');
                    $heLock = $somenteLeitura || $heStatus === 'aplicada';
                    $heIni = substr((string) ($dia['he_hora_inicio'] ?? ''), 0, 5);
                    $heFim = substr((string) ($dia['he_hora_fim'] ?? ''), 0, 5);
                    $pontoBatidas = trim((string) ($dia['ponto_batidas_label'] ?? ''));
                    $pontoUltima = substr((string) ($dia['ponto_ultima_batida'] ?? ''), 0, 5);
                    $ehFolga = $tipo !== 'trabalho';
                    $dispensado = !$ehFolga && !empty($dia['dispensado_preenchimento']);
                    $motivoDispensa = trim((string) ($dia['motivo_dispensa_label'] ?? 'Ausência / Justificativa no ponto'));
                    $exigeObrigatorios = !$ehFolga && !$dispensado;
                    $temHeOuTextoFolga = $ehFolga && ($heIni !== '' || $heFim !== '' || trim((string) ($dia['descricao'] ?? '')) !== '');
                    $temDesc = trim((string) ($dia['descricao'] ?? '')) !== '';
                    $temProj = trim((string) ($dia['projeto_obra'] ?? '')) !== '';
                    $pendenteObrigatorio = $exigeObrigatorios && (!$temDesc || !$temProj || $amb === '') && !$somenteLeitura;
                    $completo = $dispensado || ($exigeObrigatorios && $temDesc && $temProj && $amb !== '');
                    $abrirInicial = ($pendenteObrigatorio && !$primeiroPendenteAberto) || $temHeOuTextoFolga;
                    if ($pendenteObrigatorio && !$primeiroPendenteAberto) {
                        $primeiroPendenteAberto = true;
                    }
                    $tipoLabel = match ($tipo) {
                        'trabalho' => $dispensado ? ('Trabalho · ' . $motivoDispensa) : 'Trabalho',
                        'folga', 'folga_escala' => 'Folga',
                        'feriado' => 'Feriado',
                        default => ucfirst(str_replace('_', ' ', $tipo)),
                    };
                    $cardClasses = ['ra-day-card'];
                    if ($ehFolga) {
                        $cardClasses[] = 'ra-day-folga';
                    }
                    if ($pendenteObrigatorio) {
                        $cardClasses[] = 'ra-day-pendente';
                    } elseif ($completo) {
                        $cardClasses[] = 'is-complete';
                    }
                    if ($abrirInicial) {
                        $cardClasses[] = 'is-open';
                    }
                    $cardDataAttrs = $ehFolga
                        ? ('data-folga="1"' . ($temHeOuTextoFolga ? '' : ' hidden'))
                        : ($dispensado ? 'data-dispensado="1"' : 'data-ra-dia-trabalho="1"');
                    ?>
                    <article class="<?= implode(' ', $cardClasses) ?>"
                             data-day-index="<?= (int) $i ?>"
                             <?= $cardDataAttrs ?>
                             data-tipo="<?= View::e($tipo) ?>">
                        <input type="hidden" name="dias[<?= $i ?>][data]" value="<?= View::e($data) ?>">
                        <header class="ra-day-card-head">
                            <button type="button" class="ra-day-toggle" data-day-toggle aria-expanded="<?= $abrirInicial ? 'true' : 'false' ?>">
                                <span class="ra-caret" aria-hidden="true">▸</span>
                                <strong><?= View::e(date('d/m', $ts ?: time())) ?> · <?= View::e($dow) ?></strong>
                                <span class="muted text-sm">(<?= View::e($tipoLabel) ?>)</span>
                            </button>
                            <div class="ra-day-head-meta">
                                <?php if ($dispensado): ?>
                                    <span class="ra-status-pill ra-status-enviado" data-day-badge title="Dia dispensado de preenchimento obrigatório pelo cartão de ponto">
                                        <?= View::e($motivoDispensa) ?> · Dispensado
                                    </span>
                                <?php elseif (!$ehFolga): ?>
                                    <span class="ra-status-pill <?= $completo ? 'ra-status-aprovado' : 'ra-status-rascunho' ?>" data-day-badge>
                                        <?= $completo ? 'Completo' : 'Pendente' ?>
                                    </span>
                                <?php elseif ($heCliente && ($heIni !== '' || $heFim !== '')): ?>
                                    <span class="ra-status-pill ra-status-enviado">HE <?= View::e($heIni ?: '—') ?>–<?= View::e($heFim ?: '—') ?></span>
                                <?php endif; ?>
                                <?php if (!$somenteLeitura && $i > 0 && !$dispensado): ?>
                                    <button type="button" class="btn btn-ghost btn-sm" data-copy-prev="<?= (int) $i ?>"
                                            title="Copiar dados preenchidos do dia anterior">
                                        Copiar dia anterior
                                    </button>
                                <?php endif; ?>
                            </div>
                        </header>

                        <div class="ra-day-body">
                            <?php if ($dispensado): ?>
                                <div class="muted text-sm" style="margin-bottom:.65rem;padding:.5rem .75rem;border-radius:6px;background:rgba(37,99,235,.08);color:var(--c-text,#1e293b);">
                                    <strong>Dia abonado/justificado no ponto (<?= View::e($motivoDispensa) ?>):</strong> o preenchimento de atividades, projeto/obra e ambiente é opcional neste dia.
                                </div>
                            <?php endif; ?>
                            <div class="ra-day-fields-grid">
                                <div class="ra-field-box span-full">
                                    <label>Descrição das atividades <?= !$exigeObrigatorios ? '(opcional)' : '*' ?></label>
                                    <textarea name="dias[<?= $i ?>][descricao]"
                                              <?= $exigeObrigatorios ? 'data-ra-obrigatorio="descricao"' : '' ?>
                                              <?= $somenteLeitura ? 'readonly' : ($exigeObrigatorios ? 'required' : '') ?>
                                              placeholder="<?= $dispensado ? ('Opcional — ' . View::e($motivoDispensa)) : ($ehFolga ? 'Descreva se houve atividade/HE neste dia' : 'Descreva as atividades executadas') ?>"><?= View::e((string) ($dia['descricao'] ?? '')) ?></textarea>
                                </div>

                                <div class="ra-field-box">
                                    <label>Projeto / Obra <?= !$exigeObrigatorios ? '' : '*' ?></label>
                                    <input type="text" name="dias[<?= $i ?>][projeto_obra]"
                                           <?= $exigeObrigatorios ? 'data-ra-obrigatorio="projeto"' : '' ?>
                                           data-field="projeto_obra"
                                           value="<?= View::e((string) ($dia['projeto_obra'] ?? '')) ?>"
                                           <?= $somenteLeitura ? 'readonly' : ($exigeObrigatorios ? 'required' : '') ?>
                                           placeholder="<?= !$exigeObrigatorios ? 'Opcional' : 'Obrigatório' ?>">
                                </div>

                                <div class="ra-field-box">
                                    <label>Área de atendimento</label>
                                    <input type="text" name="dias[<?= $i ?>][area_atendimento]" data-field="area_atendimento"
                                           value="<?= View::e((string) ($dia['area_atendimento'] ?? '')) ?>"
                                           <?= $somenteLeitura ? 'readonly' : '' ?>>
                                </div>

                                <div class="ra-field-box">
                                    <label>Município</label>
                                    <input type="text" name="dias[<?= $i ?>][municipio]" data-field="municipio"
                                           value="<?= View::e((string) ($dia['municipio'] ?? '')) ?>"
                                           <?= $somenteLeitura ? 'readonly' : '' ?>>
                                </div>

                                <div class="ra-field-box">
                                    <label>Centro de custo</label>
                                    <input type="text" name="dias[<?= $i ?>][centro_custo]" data-field="centro_custo"
                                           value="<?= View::e((string) ($dia['centro_custo'] ?? '')) ?>"
                                           <?= $somenteLeitura ? 'readonly' : '' ?>>
                                </div>

                                <div class="ra-field-box span-full">
                                    <label>Ambiente <?= !$exigeObrigatorios ? '' : '*' ?></label>
                                    <div style="display:flex;flex-wrap:wrap;gap:.5rem;align-items:center;">
                                        <div class="ra-amb-group" data-ambiente-group <?= $exigeObrigatorios ? 'data-trabalho="1"' : '' ?>>
                                            <?php foreach (['remoto' => 'Remoto', 'site' => 'Site', 'viagem' => 'Viagem'] as $val => $lab): ?>
                                                <label class="ra-amb-opt">
                                                    <input type="radio" name="dias[<?= $i ?>][ambiente]" value="<?= $val ?>"
                                                           <?= $amb === $val ? 'checked' : '' ?>
                                                           <?= $somenteLeitura ? 'disabled' : '' ?>
                                                           data-ambiente>
                                                    <span><?= View::e($lab) ?></span>
                                                </label>
                                            <?php endforeach; ?>
                                            <?php if (!$somenteLeitura): ?>
                                                <label class="ra-amb-opt" title="Limpar ambiente">
                                                    <input type="radio" name="dias[<?= $i ?>][ambiente]" value="" <?= $amb === '' ? 'checked' : '' ?> data-ambiente>
                                                    <span>—</span>
                                                </label>
                                            <?php endif; ?>
                                        </div>

                                        <label class="ra-switch">
                                            <input type="checkbox" name="dias[<?= $i ?>][area_classificada]" value="1"
                                                   data-field="area_classificada"
                                                   <?= !empty($dia['area_classificada']) ? 'checked' : '' ?>
                                                   <?= $somenteLeitura ? 'disabled' : '' ?>>
                                            <span>Área classificada</span>
                                        </label>
                                    </div>
                                </div>

                                <div class="ra-field-box span-full">
                                    <label>Observações</label>
                                    <input type="text" name="dias[<?= $i ?>][observacoes]" data-field="observacoes"
                                           value="<?= View::e((string) ($dia['observacoes'] ?? '')) ?>"
                                           <?= $somenteLeitura ? 'readonly' : '' ?>>
                                </div>
                            </div>

                            <?php if ($heCliente): ?>
                                <div class="ra-he-block"
                                     data-ra-he-day
                                     data-ultima-batida="<?= View::e($pontoUltima) ?>"
                                     data-day-label="<?= View::e(date('d/m', $ts ?: time())) ?>">
                                    <div class="ra-ponto-batidas">
                                        <div class="ra-ponto-batidas-title">Marcação do ponto</div>
                                        <?php if ($pontoBatidas !== ''): ?>
                                            <div class="ra-ponto-batidas-times"><?= View::e($pontoBatidas) ?></div>
                                            <?php if ($pontoUltima !== ''): ?>
                                                <p class="ra-ponto-batidas-hint">
                                                    HE só a partir de <strong><?= View::e($pontoUltima) ?></strong>
                                                    (última batida do dia).
                                                </p>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <div class="ra-ponto-batidas-times is-empty">Sem batidas registradas neste dia</div>
                                            <p class="ra-ponto-batidas-hint">
                                                <?= $ehFolga
                                                    ? 'Em folga/feriado você pode lançar HE autorizada sem batida prévia.'
                                                    : 'Sem batida final no ponto, a HE ainda pode ser informada — o RH conferirá na aplicação.' ?>
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                    <div class="ra-he-row">
                                        <div class="ra-field-box">
                                            <label>HE Início<?= $ehFolga ? ' (Folga/Feriado)' : '' ?></label>
                                            <input type="time"
                                                   name="dias[<?= $i ?>][he_hora_inicio]"
                                                   value="<?= View::e($heIni) ?>"
                                                   data-he-inicio
                                                   <?= $pontoUltima !== '' ? 'min="' . View::e($pontoUltima) . '"' : '' ?>
                                                   <?= $heLock ? 'readonly' : '' ?>>
                                        </div>
                                        <div class="ra-field-box">
                                            <label>HE Fim</label>
                                            <input type="time"
                                                   name="dias[<?= $i ?>][he_hora_fim]"
                                                   value="<?= View::e($heFim) ?>"
                                                   data-he-fim
                                                   <?= $heLock ? 'readonly' : '' ?>>
                                        </div>
                                        <div class="ra-field-box">
                                            <label>Situação HE</label>
                                            <div style="min-height:42px;display:flex;align-items:center;font-weight:700;font-size:.88rem;" data-he-situacao>
                                                <?php
                                                echo match ($heStatus) {
                                                    'declarada' => 'Declarada',
                                                    'aplicada' => 'Aplicada no ponto',
                                                    'divergente' => 'Divergente',
                                                    default => 'Sem HE',
                                                };
                                                ?>
                                            </div>
                                        </div>
                                    </div>
                                    <p class="ra-he-error" data-he-error hidden></p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <?php if ($heCliente && !$somenteLeitura): ?>
                <p class="muted text-sm" style="margin-top:.75rem;">
                    HE Cliente: o início da hora extra precisa ser igual ou posterior à última batida do ponto daquele dia
                    (ex.: batida final 17:00 → HE 17:00–19:00). Em folgas/feriados sem batida, o lançamento autorizado permanece liberado.
                    <?php if (!empty($rel['gestor_cliente_nome'])): ?>
                        Gestor Cliente: <strong><?= View::e((string) $rel['gestor_cliente_nome']) ?></strong>.
                    <?php endif; ?>
                </p>
            <?php endif; ?>

            <?php if (!$somenteLeitura): ?>
                <div class="ra-actions-spacer" aria-hidden="true"></div>
                <div class="ra-actions-bar">
                    <div class="ra-actions-hint text-sm muted" data-ra-bar-hint>
                        <?php if ($prontoEnviar): ?>
                            100% preenchido — pronto para enviar.
                        <?php else: ?>
                            Progresso <strong data-ra-progress-mini><?= (int) $progressoPct ?>%</strong>
                            · faltam <strong data-ra-faltam-dias><?= (int) $diasIncompletos ?></strong> dia(s).
                        <?php endif; ?>
                    </div>
                    <div class="ra-actions-btns">
                        <button class="btn btn-secondary" type="submit" name="enviar" value="0" formnovalidate>Salvar rascunho</button>
                        <button class="btn btn-primary" type="submit" name="enviar" value="1" id="raEnviarBtn"
                                <?= $prontoEnviar ? '' : 'disabled' ?>
                                data-confirm-title="Enviar relatório?"
                                data-confirm="Todos os dias de trabalho precisam ter descrição, projeto/obra e ambiente. Depois do envio, só o RH poderá reabrir."
                                data-confirm-ok="Enviar"
                                data-confirm-tone="brand">
                            Enviar relatório
                        </button>
                    </div>
                </div>
            <?php endif; ?>
        </form>
    </div>
</section>

<script>
(() => {
  const pageEl = document.querySelector('.ra-page');
  const raId = pageEl?.dataset.raId || '0';
  const draftKey = 'arcadis_ra_draft_' + raId;
  const form = document.getElementById('raForm');
  const autosaveHint = document.getElementById('raAutosaveHint');
  let dirty = false;

  if (pageEl?.dataset.hasFlash === '1') {
    try { localStorage.removeItem(draftKey); } catch (_) {}
  }

  // Cascata Contrato -> OS
  const contrato = document.getElementById('contratoId');
  const os = document.getElementById('ordemServicoId');
  const selectedOs = <?= (int) $osId ?>;
  if (contrato && os && !contrato.disabled) {
    contrato.addEventListener('change', async () => {
      os.innerHTML = '<option value="">Carregando…</option>';
      try {
        const res = await fetch('/he/ordens?contrato_id=' + encodeURIComponent(contrato.value));
        const data = await res.json();
        os.innerHTML = '<option value="">Selecione…</option>';
        (data || []).forEach((item) => {
          const opt = document.createElement('option');
          opt.value = item.id;
          opt.textContent = item.codigo + ' — ' + item.nome;
          if (Number(item.id) === selectedOs) opt.selected = true;
          os.appendChild(opt);
        });
      } catch (e) {
        os.innerHTML = '<option value="">Falha ao carregar</option>';
      }
    });
  }

  // Accordion dos cards mobile
  document.querySelectorAll('[data-day-toggle]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const card = btn.closest('.ra-day-card');
      if (!card) return;
      const open = card.classList.toggle('is-open');
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  });

  // Mostrar/ocultar folgas
  const folgasToggle = document.getElementById('raMostrarFolgas');
  if (folgasToggle) {
    folgasToggle.addEventListener('change', () => {
      document.querySelectorAll('.ra-day-card[data-folga="1"]').forEach((card) => {
        card.hidden = !folgasToggle.checked;
        if (folgasToggle.checked) card.classList.add('is-open');
      });
    });
  }

  // Copiar link de assinatura externo
  const copiar = document.getElementById('raCopiarLink');
  const linkInput = document.getElementById('raLinkAssinatura');
  const hint = document.getElementById('raCopiarHint');
  if (copiar && linkInput) {
    copiar.addEventListener('click', async () => {
      const valor = linkInput.value;
      try {
        await navigator.clipboard.writeText(valor);
      } catch (_) {
        linkInput.select();
        document.execCommand('copy');
      }
      if (hint) {
        hint.hidden = false;
        setTimeout(() => { hint.hidden = true; }, 2000);
      }
      copiar.textContent = 'Copiado';
      setTimeout(() => { copiar.textContent = 'Copiar'; }, 1600);
    });
  }

  // Spinner assinatura embutida
  const embedForm = document.getElementById('raEmbedSignForm');
  const embedBtn = document.getElementById('raEmbedSignBtn');
  if (embedForm && embedBtn) {
    embedForm.addEventListener('submit', () => {
      if (!embedForm.checkValidity()) return;
      embedBtn.disabled = true;
      embedBtn.textContent = 'Assinando PDF…';
    });
  }

  // Recontagem de progresso e status
  const enviarBtn = document.getElementById('raEnviarBtn');
  const setCheck = (sel, faltam, labelOk, labelPend) => {
    const el = document.querySelector(sel);
    if (!el) return;
    el.classList.toggle('is-ok', faltam === 0);
    el.classList.toggle('is-warn', faltam > 0);
    el.textContent = faltam === 0 ? labelOk : labelPend.replace('%d', String(faltam));
  };

  const recount = () => {
    const totals = { remoto: 0, site: 0, viagem: 0 };
    let faltamDesc = 0;
    let faltamProj = 0;
    let faltamAmb = 0;
    let diasIncompletos = 0;
    let camposOk = 0;
    let camposTotal = 0;

    document.querySelectorAll('.ra-day-card[data-ra-dia-trabalho="1"]').forEach((card) => {
      camposTotal += 3;
      const desc = (card.querySelector('[data-ra-obrigatorio="descricao"]')?.value || '').trim();
      const proj = (card.querySelector('[data-ra-obrigatorio="projeto"]')?.value || '').trim();
      const checked = card.querySelector('[data-ambiente-group] input[data-ambiente]:checked');
      const amb = checked ? checked.value : '';
      if (desc) camposOk += 1; else faltamDesc += 1;
      if (proj) camposOk += 1; else faltamProj += 1;
      if (amb) {
        camposOk += 1;
        if (totals[amb] !== undefined) totals[amb] += 1;
      } else {
        faltamAmb += 1;
      }
      const incompleto = !desc || !proj || !amb;
      if (incompleto) diasIncompletos += 1;
      card.classList.toggle('ra-day-pendente', incompleto);
      card.classList.toggle('is-complete', !incompleto);
      const badge = card.querySelector('[data-day-badge]');
      if (badge) {
        badge.textContent = incompleto ? 'Pendente' : 'Completo';
        badge.classList.toggle('ra-status-rascunho', incompleto);
        badge.classList.toggle('ra-status-aprovado', !incompleto);
      }
    });

    const hasDispensados = document.querySelectorAll('.ra-day-card[data-dispensado="1"]').length > 0;
    const pct = camposTotal > 0 ? Math.round((camposOk / camposTotal) * 100) : (hasDispensados ? 100 : 0);
    const fill = document.querySelector('.ra-progress-fill');
    const track = document.querySelector('[data-ra-progress-bar]');
    const label = document.querySelector('[data-ra-progress-label]');
    if (fill) fill.style.width = pct + '%';
    if (track) track.setAttribute('aria-valuenow', String(pct));
    if (label) label.textContent = pct + '% · ' + camposOk + '/' + camposTotal + ' campos';

    document.querySelectorAll('[data-count]').forEach((el) => {
      const k = el.getAttribute('data-count');
      if (!k) return;
      const labels = { remoto: 'Remoto (1)', site: 'Site (2)', viagem: 'Viagem (3)' };
      el.textContent = (labels[k] || k) + ': ' + (totals[k] || 0);
    });
    document.querySelectorAll('[data-ra-faltam-dias]').forEach((el) => { el.textContent = String(diasIncompletos); });
    document.querySelectorAll('[data-ra-progress-mini]').forEach((el) => { el.textContent = pct + '%'; });
    setCheck('[data-ra-check-desc]', faltamDesc, 'Descrição: ok', 'Descrição: %d pendente' + (faltamDesc === 1 ? '' : 's'));
    setCheck('[data-ra-check-proj]', faltamProj, 'Projeto/obra: ok', 'Projeto/obra: %d pendente' + (faltamProj === 1 ? '' : 's'));
    setCheck('[data-ra-check-ambiente]', faltamAmb, 'Ambiente: ok', 'Ambiente: %d pendente' + (faltamAmb === 1 ? '' : 's'));

    const barHint = document.querySelector('[data-ra-bar-hint]');
    if (barHint) {
      barHint.innerHTML = diasIncompletos === 0
        ? '100% preenchido — pronto para enviar.'
        : ('Progresso <strong>' + pct + '%</strong> · faltam <strong>' + diasIncompletos + '</strong> dia(s).');
    }
    if (enviarBtn) enviarBtn.disabled = diasIncompletos > 0 || (camposTotal === 0 && !hasDispensados);
  };

  // Copiar do dia anterior
  const cards = Array.from(document.querySelectorAll('.ra-day-card'));
  document.querySelectorAll('[data-copy-prev]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const idx = Number(btn.getAttribute('data-copy-prev'));
      const dest = cards[idx];
      if (!dest) return;
      // Busca o dia anterior (preferindo o dia útil mais próximo preenchido se o anterior imediato for folga vazia)
      let src = null;
      for (let j = idx - 1; j >= 0; j--) {
        const c = cards[j];
        const hasAny = (c.querySelector('textarea')?.value || '').trim() !== ''
          || (c.querySelector('[data-field="projeto_obra"]')?.value || '').trim() !== '';
        if (hasAny) { src = c; break; }
      }
      if (!src) return;
      ['textarea', '[data-field="projeto_obra"]', '[data-field="area_atendimento"]', '[data-field="municipio"]', '[data-field="centro_custo"]', '[data-field="observacoes"]'].forEach((sel) => {
        const from = src.querySelector(sel);
        const to = dest.querySelector(sel);
        if (from && to && !to.readOnly) to.value = from.value;
      });
      const srcCheck = src.querySelector('[data-field="area_classificada"]');
      const destCheck = dest.querySelector('[data-field="area_classificada"]');
      if (srcCheck && destCheck && !destCheck.disabled) destCheck.checked = srcCheck.checked;
      const srcAmb = src.querySelector('input[data-ambiente]:checked')?.value || '';
      if (srcAmb) {
        const destRadio = dest.querySelector('input[data-ambiente][value="' + srcAmb + '"]');
        if (destRadio && !destRadio.disabled) destRadio.checked = true;
      }
      dest.classList.add('is-open');
      dirty = true;
      saveDraftLocal();
      recount();
    });
  });

  // Aplicar padrão nos dias pendentes
  document.getElementById('raApplyBatchBtn')?.addEventListener('click', () => {
    const proj = (document.getElementById('raBatchProj')?.value || '').trim();
    const mun = (document.getElementById('raBatchMun')?.value || '').trim();
    const cc = (document.getElementById('raBatchCc')?.value || '').trim();
    const amb = document.getElementById('raBatchAmb')?.value || '';
    cards.forEach((card) => {
      if (card.dataset.raDiaTrabalho !== '1') return;
      const setIfEmpty = (sel, val) => {
        if (!val) return;
        const el = card.querySelector(sel);
        if (el && !el.readOnly && el.value.trim() === '') el.value = val;
      };
      setIfEmpty('[data-field="projeto_obra"]', proj);
      setIfEmpty('[data-field="municipio"]', mun);
      setIfEmpty('[data-field="centro_custo"]', cc);
      if (amb) {
        const cur = card.querySelector('input[data-ambiente]:checked')?.value || '';
        if (cur === '') {
          const target = card.querySelector('input[data-ambiente][value="' + amb + '"]');
          if (target && !target.disabled) target.checked = true;
        }
      }
    });
    dirty = true;
    saveDraftLocal();
    recount();
  });

  // HE Cliente: início >= última batida do ponto
  const toMinutes = (hhmm) => {
    if (!hhmm || !/^\d{2}:\d{2}/.test(hhmm)) return null;
    const [h, m] = hhmm.slice(0, 5).split(':').map(Number);
    return (h * 60) + m;
  };
  const validateHeBlock = (block) => {
    const err = block.querySelector('[data-he-error]');
    const iniEl = block.querySelector('[data-he-inicio]');
    const fimEl = block.querySelector('[data-he-fim]');
    if (!iniEl || !fimEl) return true;
    const ultima = (block.dataset.ultimaBatida || '').slice(0, 5);
    const ini = (iniEl.value || '').slice(0, 5);
    const fim = (fimEl.value || '').slice(0, 5);
    const label = block.dataset.dayLabel || '';
    let msg = '';
    if ((ini && !fim) || (!ini && fim)) {
      msg = (label ? label + ': ' : '') + 'Informe início e fim da HE.';
    } else if (ini && fim) {
      const iniMin = toMinutes(ini);
      const fimMin = toMinutes(fim);
      const ultimaMin = toMinutes(ultima);
      if (iniMin !== null && fimMin !== null && fimMin <= iniMin) {
        msg = (label ? label + ': ' : '') + 'HE fim deve ser após o início.';
      } else if (ultimaMin !== null && iniMin !== null && iniMin < ultimaMin) {
        msg = (label ? label + ': ' : '') + 'HE início deve ser a partir da última batida (' + ultima + ').';
        if (!iniEl.readOnly) iniEl.value = ultima;
      }
    }
    if (err) {
      err.hidden = !msg;
      err.textContent = msg;
    }
    if (msg && iniEl && !iniEl.readOnly) {
      iniEl.setCustomValidity(msg);
    } else if (iniEl) {
      iniEl.setCustomValidity('');
    }
    return !msg;
  };
  document.querySelectorAll('[data-ra-he-day]').forEach((block) => {
    ['change', 'input', 'blur'].forEach((evt) => {
      block.addEventListener(evt, (e) => {
        if (!(e.target instanceof HTMLInputElement)) return;
        if (!e.target.matches('[data-he-inicio], [data-he-fim]')) return;
        validateHeBlock(block);
      }, true);
    });
  });

  // Autosave em localStorage + proteção beforeunload
  const saveDraftLocal = () => {
    if (!form) return;
    const payload = {};
    form.querySelectorAll('input[name], textarea[name], select[name]').forEach((el) => {
      if (el.type === 'radio') {
        if (el.checked) payload[el.name] = el.value;
      } else if (el.type === 'checkbox') {
        payload[el.name] = el.checked ? '1' : '0';
      } else {
        payload[el.name] = el.value;
      }
    });
    try {
      localStorage.setItem(draftKey, JSON.stringify({ ts: Date.now(), data: payload }));
      if (autosaveHint) autosaveHint.textContent = 'Salvo localmente às ' + new Date().toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
    } catch (_) {}
  };

  if (form && pageEl?.dataset.modo === 'preencher') {
    try {
      const saved = localStorage.getItem(draftKey);
      if (saved) {
        const parsed = JSON.parse(saved);
        if (parsed && parsed.data) {
          Object.entries(parsed.data).forEach(([name, val]) => {
            const els = form.querySelectorAll('[name="' + CSS.escape(name) + '"]');
            els.forEach((el) => {
              if (el.readOnly || el.disabled) return;
              if (el.type === 'radio') {
                el.checked = (el.value === String(val));
              } else if (el.type === 'checkbox') {
                el.checked = (String(val) === '1');
              } else if (el.value === '' && String(val) !== '') {
                el.value = String(val);
              }
            });
          });
        }
      }
    } catch (_) {}

    form.addEventListener('input', () => { dirty = true; saveDraftLocal(); recount(); });
    form.addEventListener('change', () => { dirty = true; saveDraftLocal(); recount(); });
    form.addEventListener('submit', (ev) => {
      let heOk = true;
      document.querySelectorAll('[data-ra-he-day]').forEach((block) => {
        if (!validateHeBlock(block)) heOk = false;
      });
      if (!heOk) {
        ev.preventDefault();
        const firstErr = document.querySelector('[data-he-error]:not([hidden])');
        firstErr?.closest('.ra-day-card')?.classList.add('is-open');
        firstErr?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        return;
      }
      dirty = false;
      try { localStorage.removeItem(draftKey); } catch (_) {}
    });
    window.addEventListener('beforeunload', (e) => {
      if (!dirty) return;
      e.preventDefault();
      e.returnValue = '';
    });
  }

  recount();
})();
</script>
