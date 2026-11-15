<?php
declare(strict_types=1);

use App\Helpers\Csrf;

/** @var string $aba */
/** @var list<array<string,mixed>> $tipos */
/** @var array<string,list<array{tipo:string,id:int}>> $destinos */
/** @var array{digest_ativo:bool,digest_hora:string,historico_dias:int,limite_lista:int,digest_ultimo_disparo?:string,digest_ultima_data?:string,digest_ultimo_resumo?:string} $config */
/** @var list<array<string,mixed>> $abertos */
/** @var int $abertosCount */
/** @var list<array{id:int,nome:string}> $usuarios */
/** @var list<array<string,mixed>> $perfis */
/** @var array{ok:bool,status_label:string,email_remetente:string,provider:string,ultimo_erro:string} $canalEmail */
/** @var bool $cronConfigured */
/** @var string $appBaseUrl */
/** @var ?string $flashOk */
/** @var ?string $flashErro */

$sevLabels = [
    'critica' => 'Crítica (bloqueia fechamento)',
    'alta' => 'Alta prioridade',
    'media' => 'Média prioridade',
    'info' => 'Informativo',
];

$sevShort = [
    'critica' => 'Crítica',
    'alta' => 'Alta',
    'media' => 'Média',
    'info' => 'Informativo',
];

$abertosCount = $abertosCount ?? count($abertos);
$canalEmail = $canalEmail ?? [
    'ok' => false,
    'status_label' => 'Não verificado',
    'email_remetente' => '',
    'provider' => '',
    'ultimo_erro' => '',
];
$appBaseUrl = $appBaseUrl ?? 'http://127.0.0.1:8090';
$horaServidor = date('H:i');
?>
<div class="alertas-cfg-page">

    <?php if (!empty($flashOk)): ?>
        <div class="alert alert--success mb-4" role="status">
            <div>
                <div class="alert__title">Alteração aplicada</div>
                <div><?= htmlspecialchars((string) $flashOk, ENT_QUOTES, 'UTF-8') ?></div>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($flashErro)): ?>
        <div class="alert alert--danger mb-4" role="alert">
            <div>
                <div class="alert__title">Atenção</div>
                <div><?= htmlspecialchars((string) $flashErro, ENT_QUOTES, 'UTF-8') ?></div>
            </div>
        </div>
    <?php endif; ?>

    <header class="alertas-cfg-head">
        <div class="alertas-cfg-head-main">
            <span class="alertas-cfg-kicker">Governança Operacional · Central de Alertas</span>
            <h2 class="alertas-cfg-title">Configurações de Regras, Destinatários e Automação</h2>
            <p class="alertas-cfg-sub">
                Defina quais ocorrências geram alertas, quem deve vê-las na fila, o horário do resumo diário por e-mail e execute ações em lote.
            </p>
        </div>
        <div class="alertas-cfg-head-actions">
            <a class="btn btn--secondary btn--sm" href="/rh/email">
                ✉ Conta e Modelos de E-mail
            </a>
            <a class="btn btn--primary btn--sm" href="/rh/alertas">
                ← Voltar para a Central de Alertas
            </a>
        </div>
    </header>

    <nav class="alertas-cfg-tabs" aria-label="Seções de configuração da Central de Alertas">
        <a class="alertas-cfg-tab <?= $aba === 'tipos' ? 'is-active' : '' ?>" href="/rh/alertas/config?aba=tipos">
            <span class="alertas-cfg-tab-step">1</span>
            <span>Regras e Prazos (SLA)</span>
        </a>
        <a class="alertas-cfg-tab <?= $aba === 'destinos' ? 'is-active' : '' ?>" href="/rh/alertas/config?aba=destinos">
            <span class="alertas-cfg-tab-step">2</span>
            <span>Destinatários por Alerta</span>
        </a>
        <a class="alertas-cfg-tab <?= $aba === 'geral' ? 'is-active' : '' ?>" href="/rh/alertas/config?aba=geral">
            <span class="alertas-cfg-tab-step">3</span>
            <span>Resumo Diário e Automação</span>
        </a>
        <a class="alertas-cfg-tab <?= $aba === 'operacao' ? 'is-active' : '' ?>" href="/rh/alertas/config?aba=operacao">
            <span class="alertas-cfg-tab-step">4</span>
            <span>Manutenção em Lote</span>
            <span class="alertas-cfg-tab-badge <?= $abertosCount > 0 ? 'is-nonzero' : '' ?>"><?= (int) $abertosCount ?></span>
        </a>
    </nav>

    <?php if ($aba === 'tipos'): ?>
        <div class="alertas-cfg-callout">
            <div class="alertas-cfg-callout-title">Como funcionam as regras e o Prazo de Atenção (SLA)?</div>
            <p class="alertas-cfg-callout-text">
                Cada regra abaixo monitora automaticamente uma etapa do fechamento (Ponto, Horas Extras, E-mail, Assinaturas e Medição).
                Ao desativar uma regra, os alertas abertos daquele tipo são encerrados automaticamente.
                O <strong>Prazo de Atenção (SLA em horas)</strong> define após quantas horas uma divergência aberta sobe para prioridade <strong>Crítica</strong>.
            </p>
        </div>

        <form method="post" action="/rh/alertas/config/tipos" class="alertas-cfg-card">
            <?= Csrf::field() ?>

            <div class="alertas-cfg-rules-list">
                <?php foreach ($tipos as $i => $t):
                    $cod = (string) $t['codigo'];
                    $ativo = !empty($t['ativo']);
                    $sev = (string) ($t['severidade_padrao'] ?? 'media');
                    $sla = $t['sla_horas'] ?? null;
                    $grupo = (string) ($t['grupo'] ?? 'Operação');
                    $usaSla = str_starts_with($cod, 'divergencia.');
                    ?>
                    <div class="alertas-cfg-rule-item <?= $ativo ? 'is-enabled' : 'is-disabled' ?>" data-rule-item>
                        <input type="hidden" name="tipos[<?= $i ?>][codigo]" value="<?= htmlspecialchars($cod, ENT_QUOTES, 'UTF-8') ?>">

                        <div class="alertas-cfg-rule-main">
                            <div class="alertas-cfg-rule-toggle">
                                <label class="switch-label" title="Ativar ou desativar o monitoramento desta regra">
                                    <input type="checkbox"
                                           name="tipos[<?= $i ?>][ativo]"
                                           value="1"
                                           data-rule-toggle
                                           <?= $ativo ? 'checked' : '' ?>>
                                    <span class="switch-text"><?= $ativo ? 'Ativa' : 'Inativa' ?></span>
                                </label>
                            </div>

                            <div class="alertas-cfg-rule-info">
                                <div class="alertas-cfg-rule-title-row">
                                    <span class="alertas-cfg-group-pill"><?= htmlspecialchars($grupo, ENT_QUOTES, 'UTF-8') ?></span>
                                    <strong class="alertas-cfg-rule-name"><?= htmlspecialchars((string) $t['nome'], ENT_QUOTES, 'UTF-8') ?></strong>
                                    <code class="alertas-cfg-code" title="Identificador técnico da regra"><?= htmlspecialchars($cod, ENT_QUOTES, 'UTF-8') ?></code>
                                </div>
                                <p class="alertas-cfg-rule-desc"><?= htmlspecialchars((string) ($t['descricao'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
                            </div>

                            <div class="alertas-cfg-rule-controls">
                                <label class="alertas-cfg-control">
                                    <span>Prioridade padrão</span>
                                    <select name="tipos[<?= $i ?>][severidade_padrao]" class="alertas-cfg-sev-select">
                                        <?php foreach ($sevLabels as $k => $lbl): ?>
                                            <option value="<?= $k ?>" <?= $sev === $k ? 'selected' : '' ?>><?= $lbl ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>

                                <div class="alertas-cfg-control">
                                    <span>Prazo para escalar (SLA)</span>
                                    <?php if ($usaSla): ?>
                                        <div class="alertas-cfg-sla-box">
                                            <input type="number"
                                                   min="1"
                                                   max="720"
                                                   name="tipos[<?= $i ?>][sla_horas]"
                                                   value="<?= $sla !== null ? (int) $sla : '' ?>"
                                                   placeholder="48"
                                                   class="alertas-cfg-sla-input"
                                                   data-sla-input>
                                            <span class="alertas-cfg-sla-unit">horas</span>
                                            <div class="alertas-cfg-sla-presets" aria-label="Sugestões de prazo">
                                                <button type="button" class="sla-preset-btn" data-sla-set="24">24h</button>
                                                <button type="button" class="sla-preset-btn" data-sla-set="48">48h</button>
                                                <button type="button" class="sla-preset-btn" data-sla-set="72">72h</button>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <input type="hidden" name="tipos[<?= $i ?>][sla_horas]" value="<?= $sla !== null ? (int) $sla : '' ?>">
                                        <span class="alertas-cfg-sla-na" title="Esta regra dispara imediatamente conforme o estado da operação ou calendário da régua">
                                            Imediato (por evento/estado)
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <details class="alerta-cfg-drawer">
                            <summary>Personalizar título padrão e rota de destino</summary>
                            <div class="alerta-cfg-drawer-grid">
                                <label class="alertas-cfg-field">
                                    <span>Título base exibido no alerta</span>
                                    <input type="text"
                                           maxlength="200"
                                           name="tipos[<?= $i ?>][titulo_template]"
                                           value="<?= htmlspecialchars((string) ($t['titulo_template'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                           placeholder="Ex.: Divergência aberta — {colaborador}">
                                    <small class="alertas-cfg-help">
                                        O nome do colaborador é adicionado automaticamente ao final (ou onde você incluir <code>{colaborador}</code>).
                                    </small>
                                </label>

                                <label class="alertas-cfg-field">
                                    <span>Rota de destino do botão principal</span>
                                    <input type="text"
                                           maxlength="500"
                                           name="tipos[<?= $i ?>][href_padrao]"
                                           value="<?= htmlspecialchars((string) ($t['href_padrao'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                           placeholder="/rh/medicao">
                                    <small class="alertas-cfg-help">
                                        Caminho interno para onde o botão de ação leva (ex.: <code>/rh/ponto</code>, <code>/rh/medicao</code>, <code>/rh/email</code>).
                                    </small>
                                </label>
                            </div>
                        </details>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="alertas-cfg-foot">
                <span class="alertas-cfg-foot-hint">As alterações entram em vigor imediatamente na próxima atualização da Central de Alertas.</span>
                <button type="submit" class="btn btn--primary">Salvar regras e prazos</button>
            </div>
        </form>

    <?php elseif ($aba === 'destinos'): ?>
        <div class="alertas-cfg-callout">
            <div class="alertas-cfg-callout-title">Quem recebe cada categoria de alerta?</div>
            <p class="alertas-cfg-callout-text">
                Por padrão, quando <strong>nenhum perfil ou usuário está marcado</strong> em uma regra, o alerta fica
                <strong>visível para toda a equipe</strong> com acesso à Central de Alertas.
                Marque perfis ou pessoas específicas apenas quando desejar restringir um tipo de alerta a um grupo responsável.
            </p>
        </div>

        <form method="post" action="/rh/alertas/config/destinos" class="alertas-cfg-card">
            <?= Csrf::field() ?>

            <div class="alertas-cfg-destinos">
                <?php foreach ($tipos as $t):
                    $cod = (string) $t['codigo'];
                    $atuais = $destinos[$cod] ?? [];
                    $set = [];
                    foreach ($atuais as $a) {
                        $set[$a['tipo'] . ':' . $a['id']] = true;
                    }
                    $qtdSel = count($set);
                    ?>
                    <div class="alertas-cfg-destino-row" data-destino-card>
                        <div class="alertas-cfg-destino-top">
                            <div>
                                <div class="alertas-cfg-rule-title-row">
                                    <span class="alertas-cfg-group-pill"><?= htmlspecialchars((string) ($t['grupo'] ?? 'Operação'), ENT_QUOTES, 'UTF-8') ?></span>
                                    <strong><?= htmlspecialchars((string) $t['nome'], ENT_QUOTES, 'UTF-8') ?></strong>
                                </div>
                                <p class="alertas-cfg-rule-desc"><?= htmlspecialchars((string) ($t['descricao'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
                            </div>

                            <div class="alertas-cfg-destino-status-wrap">
                                <span class="alertas-cfg-scope-badge <?= $qtdSel > 0 ? 'is-restricted' : 'is-all' ?>" data-destino-badge>
                                    <?= $qtdSel > 0
                                        ? ('Restrito a ' . $qtdSel . ' selecionado(s)')
                                        : 'Visível para toda a equipe RH' ?>
                                </span>
                                <button type="button"
                                        class="btn btn--ghost btn--sm"
                                        data-destino-clear
                                        <?= $qtdSel === 0 ? 'hidden' : '' ?>>
                                    Limpar seleção (Todos veem)
                                </button>
                            </div>
                        </div>

                        <div class="alertas-cfg-chips-grid">
                            <div class="alertas-cfg-chips-group">
                                <span class="alertas-cfg-chips-title">Perfis de acesso</span>
                                <div class="alertas-cfg-chips">
                                    <?php foreach ($perfis as $p):
                                        $val = 'perfil:' . (int) $p['id'];
                                        $checked = isset($set[$val]);
                                        ?>
                                        <label class="chip-check <?= $checked ? 'is-checked' : '' ?>">
                                            <input type="checkbox"
                                                   name="destinos[<?= htmlspecialchars($cod, ENT_QUOTES, 'UTF-8') ?>][]"
                                                   value="<?= htmlspecialchars($val, ENT_QUOTES, 'UTF-8') ?>"
                                                   data-destino-check
                                                   <?= $checked ? 'checked' : '' ?>>
                                            <span><?= htmlspecialchars((string) $p['nome'], ENT_QUOTES, 'UTF-8') ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <div class="alertas-cfg-chips-group">
                                <span class="alertas-cfg-chips-title">Usuários específicos</span>
                                <div class="alertas-cfg-chips">
                                    <?php foreach ($usuarios as $u):
                                        $val = 'usuario:' . (int) $u['id'];
                                        $checked = isset($set[$val]);
                                        ?>
                                        <label class="chip-check <?= $checked ? 'is-checked' : '' ?>">
                                            <input type="checkbox"
                                                   name="destinos[<?= htmlspecialchars($cod, ENT_QUOTES, 'UTF-8') ?>][]"
                                                   value="<?= htmlspecialchars($val, ENT_QUOTES, 'UTF-8') ?>"
                                                   data-destino-check
                                                   <?= $checked ? 'checked' : '' ?>>
                                            <span><?= htmlspecialchars((string) $u['nome'], ENT_QUOTES, 'UTF-8') ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="alertas-cfg-foot">
                <span class="alertas-cfg-foot-hint">Regras sem nenhuma marcação permanecem visíveis para todos os usuários com permissão de ver alertas.</span>
                <button type="submit" class="btn btn--primary">Salvar destinatários</button>
            </div>
        </form>

    <?php elseif ($aba === 'geral'): ?>
        <div class="alertas-cfg-geral-grid">
            <div class="alertas-cfg-col">
                <section class="alertas-cfg-card">
                    <div class="alertas-cfg-section-head">
                        <div>
                            <span class="alertas-cfg-section-kicker">Infraestrutura de Envio</span>
                            <h3 class="alertas-cfg-section-title">Saúde do Canal de E-mail</h3>
                        </div>
                        <span class="alertas-cfg-status-pill <?= !empty($canalEmail['ok']) ? 'is-ok' : 'is-danger' ?>">
                            <?= !empty($canalEmail['ok']) ? '● Conectado' : '▲ Requer atenção' ?>
                        </span>
                    </div>

                    <p class="alertas-cfg-section-desc">
                        O resumo diário de alertas e a régua automática de cobrança de assinaturas utilizam a conta de e-mail institucional configurada no módulo de E-mail.
                    </p>

                    <div class="alertas-cfg-email-box <?= !empty($canalEmail['ok']) ? 'is-ok' : 'is-danger' ?>">
                        <div class="alertas-cfg-email-row">
                            <strong>Status atual:</strong>
                            <span><?= htmlspecialchars((string) $canalEmail['status_label'], ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                        <?php if (!empty($canalEmail['email_remetente'])): ?>
                            <div class="alertas-cfg-email-row">
                                <strong>Conta remetente:</strong>
                                <span>
                                    <?= htmlspecialchars((string) $canalEmail['email_remetente'], ENT_QUOTES, 'UTF-8') ?>
                                    <?php if (!empty($canalEmail['provider'])): ?>
                                        (<?= htmlspecialchars((string) $canalEmail['provider'], ENT_QUOTES, 'UTF-8') ?>)
                                    <?php endif; ?>
                                </span>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($canalEmail['ultimo_erro'])): ?>
                            <div class="alertas-cfg-email-row is-error">
                                <strong>Última falha registrada:</strong>
                                <span><?= htmlspecialchars((string) $canalEmail['ultimo_erro'], ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="alertas-cfg-inline-actions">
                        <a class="btn btn--secondary btn--sm" href="/rh/email">
                            Gerenciar conexão OAuth / SMTP →
                        </a>
                    </div>
                </section>

                <form method="post" action="/rh/alertas/config/geral" class="alertas-cfg-card">
                    <?= Csrf::field() ?>
                    <div class="alertas-cfg-section-head">
                        <div>
                            <span class="alertas-cfg-section-kicker">Preferências Gerais</span>
                            <h3 class="alertas-cfg-section-title">Resumo Diário e Retenção de Histórico</h3>
                        </div>
                    </div>

                    <div class="alertas-cfg-form">
                        <label class="alertas-cfg-check-card">
                            <input type="checkbox" name="digest_ativo" value="1" <?= !empty($config['digest_ativo']) ? 'checked' : '' ?>>
                            <div>
                                <strong>Enviar resumo diário por e-mail com pendências não lidas</strong>
                                <p>Dispara 1 e-mail por dia apenas para os integrantes da equipe que possuírem alertas abertos e ainda não lidos.</p>
                            </div>
                        </label>

                        <div class="alertas-cfg-form-row">
                            <label class="alertas-cfg-field">
                                <span>Horário programado do resumo (HH:MM)</span>
                                <input type="time" name="digest_hora" value="<?= htmlspecialchars((string) $config['digest_hora'], ENT_QUOTES, 'UTF-8') ?>">
                                <small class="alertas-cfg-help">Horário atual do servidor: <strong><?= htmlspecialchars($horaServidor, ENT_QUOTES, 'UTF-8') ?></strong></small>
                            </label>

                            <label class="alertas-cfg-field">
                                <span>Retenção do histórico (dias)</span>
                                <input type="number" min="1" max="365" name="historico_dias" value="<?= (int) $config['historico_dias'] ?>">
                                <small class="alertas-cfg-help">Período exibido na aba Histórico (resolvidos e arquivados).</small>
                            </label>
                        </div>

                        <label class="alertas-cfg-field">
                            <span>Limite máximo de alertas listados por página</span>
                            <input type="number" min="20" max="500" name="limite_lista" value="<?= (int) $config['limite_lista'] ?>">
                            <small class="alertas-cfg-help">Recomendado: 200 itens para manter carregamento rápido.</small>
                        </label>

                        <?php if (!empty($config['digest_ultimo_disparo'])): ?>
                            <div class="alertas-cfg-last-run">
                                <strong>Última execução do resumo:</strong>
                                <span><?= htmlspecialchars((string) $config['digest_ultimo_disparo'], ENT_QUOTES, 'UTF-8') ?></span>
                                <?php if (!empty($config['digest_ultimo_resumo'])): ?>
                                    <span class="alertas-cfg-muted"> — <?= htmlspecialchars((string) $config['digest_ultimo_resumo'], ENT_QUOTES, 'UTF-8') ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="alertas-cfg-foot">
                        <button type="submit" class="btn btn--primary">Salvar preferências gerais</button>
                    </div>
                </form>
            </div>

            <div class="alertas-cfg-col">
                <section class="alertas-cfg-card">
                    <div class="alertas-cfg-section-head">
                        <div>
                            <span class="alertas-cfg-section-kicker">Execução Manual e Testes</span>
                            <h3 class="alertas-cfg-section-title">Disparar Agora</h3>
                        </div>
                    </div>
                    <p class="alertas-cfg-section-desc">
                        Teste o envio do resumo diário imediatamente ou acione a verificação da régua de cobrança de assinaturas (DU+1 / DU+2) sem aguardar o agendador.
                    </p>

                    <div class="alertas-cfg-manual-blocks">
                        <form method="post" action="/rh/alertas/config/testar-digest" class="alertas-cfg-manual-box">
                            <?= Csrf::field() ?>
                            <div class="alertas-cfg-manual-info">
                                <strong>Resumo Diário da Central de Alertas</strong>
                                <p>Sincroniza as pendências atuais e envia o e-mail de resumo com link direto para a Central.</p>
                            </div>
                            <div class="alertas-cfg-manual-controls">
                                <select name="escopo" aria-label="Destinatário do disparo manual">
                                    <option value="meu_usuario">Enviar teste apenas para o meu e-mail</option>
                                    <option value="todos">Enviar para todos os usuários elegíveis</option>
                                </select>
                                <button type="submit" class="btn btn--secondary btn--sm">
                                    ✉ Disparar resumo agora
                                </button>
                            </div>
                        </form>

                        <form method="post" action="/rh/alertas/config/executar-cobranca" class="alertas-cfg-manual-box">
                            <?= Csrf::field() ?>
                            <div class="alertas-cfg-manual-info">
                                <strong>Régua de Cobrança de Assinaturas (Folha e RA)</strong>
                                <p>Verifica documentos pendentes em DU+1 / DU+2 no calendário útil e envia os lembretes pendentes.</p>
                            </div>
                            <div class="alertas-cfg-manual-controls">
                                <button type="submit" class="btn btn--secondary btn--sm">
                                    ⚡ Executar régua de cobrança agora
                                </button>
                            </div>
                        </form>
                    </div>
                </section>

                <section class="alertas-cfg-card">
                    <div class="alertas-cfg-section-head">
                        <div>
                            <span class="alertas-cfg-section-kicker">Comunicação e Copy</span>
                            <h3 class="alertas-cfg-section-title">Modelos de E-mail Relacionados</h3>
                        </div>
                        <a class="btn btn--ghost btn--sm" href="/rh/email">Editar modelos →</a>
                    </div>
                    <p class="alertas-cfg-section-desc">
                        Visualize como os e-mails automáticos chegam para a equipe RH e para os colaboradores/gestores.
                    </p>

                    <div class="alertas-cfg-tpl-list">
                        <div class="alertas-cfg-tpl-item">
                            <div>
                                <strong>Resumo Diário de Alertas (Equipe RH)</strong>
                                <span class="alertas-cfg-tpl-meta">Variáveis: <code>{{nome}}</code>, <code>{{quantidade}}</code>, <code>{{portal_url}}</code></span>
                            </div>
                            <a class="btn btn--ghost btn--sm" target="_blank" rel="noopener" href="/rh/email/template/preview?chave=sistema.alertas_digest">
                                Visualizar modelo ↗
                            </a>
                        </div>
                        <div class="alertas-cfg-tpl-item">
                            <div>
                                <strong>Cobrança de Folha de Ponto · Níveis 1, 2 e 3</strong>
                                <span class="alertas-cfg-tpl-meta">Lembrete amigável (N1), alerta de prazo (N2) e aviso urgente (N3)</span>
                            </div>
                            <div class="alertas-cfg-tpl-links">
                                <a class="btn btn--ghost btn--sm" target="_blank" rel="noopener" href="/rh/email/template/preview?chave=folha.cobranca_1">Nível 1 ↗</a>
                                <a class="btn btn--ghost btn--sm" target="_blank" rel="noopener" href="/rh/email/template/preview?chave=folha.cobranca_2">Nível 2 ↗</a>
                                <a class="btn btn--ghost btn--sm" target="_blank" rel="noopener" href="/rh/email/template/preview?chave=folha.cobranca_3">Nível 3 ↗</a>
                            </div>
                        </div>
                        <div class="alertas-cfg-tpl-item">
                            <div>
                                <strong>Cobrança de Relatório de Atividades (RA)</strong>
                                <span class="alertas-cfg-tpl-meta">Notificação de pendência de assinatura do RA no ciclo</span>
                            </div>
                            <a class="btn btn--ghost btn--sm" target="_blank" rel="noopener" href="/rh/email/template/preview?chave=ra.cobranca_1">
                                Visualizar modelo ↗
                            </a>
                        </div>
                    </div>
                </section>

                <details class="alertas-cfg-card alertas-cfg-cron-details">
                    <summary class="alertas-cfg-cron-summary">
                        <div>
                            <span class="alertas-cfg-section-kicker">Diagnóstico Técnico (TI / Infraestrutura)</span>
                            <h3 class="alertas-cfg-section-title">Agendador Automático (Cron HTTP / CLI)</h3>
                        </div>
                        <span class="alertas-cfg-status-pill <?= !empty($cronConfigured) ? 'is-ok' : 'is-warn' ?>">
                            <?= !empty($cronConfigured) ? 'Chave Cron ativa' : 'Requer CRON_SECRET' ?>
                        </span>
                    </summary>

                    <div class="alertas-cfg-cron-body">
                        <p class="alertas-cfg-help">
                            Para execução automática no servidor, configure as chamadas abaixo no agendador (Hostinger / Linux Cron) autenticadas com o cabeçalho <code>X-Cron-Key</code>:
                        </p>

                        <div class="alertas-cfg-cron-cmd">
                            <div class="alertas-cfg-cron-cmd-head">
                                <strong>1. Resumo Diário de Alertas (a cada minuto ou no horário programado)</strong>
                                <button type="button" class="btn btn--ghost btn--sm" data-copy-text="curl -s -H &quot;X-Cron-Key: SEU_CRON_SECRET&quot; &quot;<?= htmlspecialchars($appBaseUrl, ENT_QUOTES, 'UTF-8') ?>/cron/alertas-digest&quot;">
                                    Copiar comando
                                </button>
                            </div>
                            <code>curl -s -H "X-Cron-Key: SEU_CRON_SECRET" "<?= htmlspecialchars($appBaseUrl, ENT_QUOTES, 'UTF-8') ?>/cron/alertas-digest"</code>
                        </div>

                        <div class="alertas-cfg-cron-cmd">
                            <div class="alertas-cfg-cron-cmd-head">
                                <strong>2. Régua de Cobrança de Assinaturas (1x ao dia em dias úteis)</strong>
                                <button type="button" class="btn btn--ghost btn--sm" data-copy-text="curl -s -H &quot;X-Cron-Key: SEU_CRON_SECRET&quot; &quot;<?= htmlspecialchars($appBaseUrl, ENT_QUOTES, 'UTF-8') ?>/cron/cobranca-assinatura&quot;">
                                    Copiar comando
                                </button>
                            </div>
                            <code>curl -s -H "X-Cron-Key: SEU_CRON_SECRET" "<?= htmlspecialchars($appBaseUrl, ENT_QUOTES, 'UTF-8') ?>/cron/cobranca-assinatura"</code>
                        </div>
                    </div>
                </details>
            </div>
        </div>

    <?php elseif ($aba === 'operacao'): ?>
        <div class="alertas-cfg-card">
            <?php if ($abertos === []): ?>
                <div class="empty-state">
                    <div class="empty-state__title">Nenhum alerta aberto no momento</div>
                    <p class="empty-state__description">
                        Não há pendências ativas para reatribuir, arquivar ou resolver em lote.
                    </p>
                    <div class="empty-state__actions">
                        <a class="btn btn--primary btn--sm" href="/rh/alertas">Voltar para a Central de Alertas</a>
                    </div>
                </div>
            <?php else: ?>
                <form method="post" id="alertas-admin-form" action="/rh/alertas/admin/resolver">
                    <?= Csrf::field() ?>

                    <div class="alertas-cfg-batch-top">
                        <div class="alertas-cfg-batch-filters">
                            <input type="search"
                                   class="alertas-cfg-search"
                                   data-alertas-batch-search
                                   placeholder="Filtrar por colaborador, título ou categoria…">
                            <select class="alertas-cfg-sev-filter" data-alertas-batch-sev aria-label="Filtrar por prioridade">
                                <option value="">Todas as prioridades</option>
                                <option value="critica">Apenas Crítica</option>
                                <option value="alta">Apenas Alta</option>
                                <option value="media">Apenas Média</option>
                                <option value="info">Apenas Informativo</option>
                            </select>
                        </div>

                        <div class="alertas-cfg-sel-counter" data-alertas-sel-counter>
                            0 selecionado(s) de <?= count($abertos) ?>
                        </div>
                    </div>

                    <div class="alertas-cfg-batch-bar">
                        <div class="alertas-cfg-batch-group">
                            <label class="alertas-cfg-batch-label">
                                <span>1. Atribuir responsável em lote</span>
                                <div class="alertas-cfg-batch-inline">
                                    <select name="usuario_id">
                                        <option value="0">— Remover responsável (Sem dono) —</option>
                                        <?php foreach ($usuarios as $u): ?>
                                            <option value="<?= (int) $u['id'] ?>"><?= htmlspecialchars((string) $u['nome'], ENT_QUOTES, 'UTF-8') ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit"
                                            class="btn btn--secondary btn--sm"
                                            data-batch-btn
                                            disabled
                                            formaction="/rh/alertas/admin/reatribuir">
                                        Aplicar responsável
                                    </button>
                                </div>
                            </label>
                        </div>

                        <div class="alertas-cfg-batch-group alertas-cfg-batch-group--end">
                            <label class="alertas-cfg-batch-label">
                                <span>2. Encerramento ou arquivamento em lote</span>
                                <div class="alertas-cfg-batch-inline">
                                    <input type="text"
                                           name="motivo"
                                           placeholder="Motivo do arquivamento (opcional)"
                                           maxlength="255"
                                           class="alertas-cfg-motivo">
                                    <button type="submit"
                                            class="btn btn--secondary btn--sm"
                                            data-batch-btn
                                            disabled
                                            formaction="/rh/alertas/admin/dispensar"
                                            onclick="return confirm('Arquivar os alertas selecionados da fila de toda a equipe?');">
                                        Arquivar para todos
                                    </button>
                                    <button type="submit"
                                            class="btn btn--danger btn--sm"
                                            data-batch-btn
                                            disabled
                                            formaction="/rh/alertas/admin/resolver"
                                            onclick="return confirm('Forçar a resolução imediata dos alertas selecionados?');">
                                        Forçar resolução
                                    </button>
                                </div>
                            </label>
                        </div>
                    </div>

                    <div class="alertas-cfg-table-wrap">
                        <table class="alertas-cfg-table">
                            <thead>
                                <tr>
                                    <th style="width:2.5rem"><input type="checkbox" data-alertas-check-all aria-label="Selecionar todos os alertas visíveis"></th>
                                    <th style="width:9rem">Prioridade</th>
                                    <th style="width:14rem">Categoria</th>
                                    <th>Alerta e diagnóstico</th>
                                    <th style="width:12rem">Colaborador / Período</th>
                                    <th style="width:10rem">Responsável</th>
                                    <th style="width:5.5rem">Idade</th>
                                    <th style="width:6.5rem">Ação</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($abertos as $a):
                                    $sevA = (string) ($a['severidade'] ?? 'media');
                                    $searchText = mb_strtolower(
                                        (string) ($a['titulo'] ?? '') . ' '
                                        . (string) ($a['detalhe'] ?? '') . ' '
                                        . (string) ($a['colaborador_nome'] ?? '') . ' '
                                        . (string) ($a['tipo_nome'] ?? '') . ' '
                                        . (string) ($a['codigo'] ?? '')
                                    );
                                    ?>
                                    <tr data-batch-row
                                        data-row-sev="<?= htmlspecialchars($sevA, ENT_QUOTES, 'UTF-8') ?>"
                                        data-row-search="<?= htmlspecialchars($searchText, ENT_QUOTES, 'UTF-8') ?>">
                                        <td>
                                            <input type="checkbox" name="ids[]" value="<?= (int) $a['id'] ?>" data-alerta-check>
                                        </td>
                                        <td>
                                            <span class="alerta-sev-pill alerta-sev-pill--<?= htmlspecialchars($sevA, ENT_QUOTES, 'UTF-8') ?>">
                                                <?= htmlspecialchars($sevShort[$sevA] ?? $sevA, ENT_QUOTES, 'UTF-8') ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="alertas-cfg-cat-cell">
                                                <strong><?= htmlspecialchars((string) ($a['tipo_nome'] ?? $a['codigo']), ENT_QUOTES, 'UTF-8') ?></strong>
                                                <span><?= htmlspecialchars((string) ($a['grupo'] ?? 'Operação'), ENT_QUOTES, 'UTF-8') ?></span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="alertas-cfg-title-cell">
                                                <strong><?= htmlspecialchars((string) $a['titulo'], ENT_QUOTES, 'UTF-8') ?></strong>
                                                <?php if ($a['detalhe'] !== ''): ?>
                                                    <div class="alertas-cfg-sub"><?= htmlspecialchars((string) $a['detalhe'], ENT_QUOTES, 'UTF-8') ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="alertas-cfg-colab-cell">
                                                <?php if ($a['colaborador_nome'] !== ''): ?>
                                                    <strong><?= htmlspecialchars((string) $a['colaborador_nome'], ENT_QUOTES, 'UTF-8') ?></strong>
                                                <?php else: ?>
                                                    <span class="alertas-cfg-muted">Geral / Período</span>
                                                <?php endif; ?>
                                                <?php if (!empty($a['periodo_rotulo'])): ?>
                                                    <span><?= htmlspecialchars((string) $a['periodo_rotulo'], ENT_QUOTES, 'UTF-8') ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?= $a['atribuido_nome'] !== ''
                                                ? htmlspecialchars((string) $a['atribuido_nome'], ENT_QUOTES, 'UTF-8')
                                                : '<span class="alertas-cfg-muted">Sem dono</span>' ?>
                                        </td>
                                        <td><?= htmlspecialchars((string) $a['idade'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td>
                                            <?php if (!empty($a['href'])): ?>
                                                <a class="btn btn--ghost btn--sm" href="<?= htmlspecialchars((string) $a['href'], ENT_QUOTES, 'UTF-8') ?>">
                                                    Abrir →
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
