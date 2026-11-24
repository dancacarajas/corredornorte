<?php

use App\Helpers\Auth;
use App\Helpers\View;

$activeMenu = $activeMenu ?? '';
$app = $app ?? ['name' => 'Arcadis', 'tagline' => 'RH Operacional'];
$currentUser = $currentUser ?? ['name' => 'Administrador', 'role' => 'RH'];
$alertasBadge = (int) ($alertasBadge ?? 0);

$isActive = static fn(string $key): string => $activeMenu === $key ? ' is-active' : '';
$can = static fn(string ...$perms): bool => Auth::canAny(...$perms);

$inGroup = static function (array $keys) use ($activeMenu): bool {
    return in_array($activeMenu, $keys, true);
};

$orgKeys = ['empresas', 'contratos', 'centros', 'os', 'localidades'];
$escalasKeys = ['horarios', 'feriados'];
$pessoasKeys = ['lotacoes', 'cargos', 'funcoes', 'gestores-arcadis', 'gestores-cliente', 'supervisores'];
$medicaoKeys = ['ponto', 'medicao', 'relatorio-he', 'ra', 'justificativa-tipos', 'folha'];
$vrKeys = ['vr-config', 'vr-relatorio'];
$entregasKeys = ['entregas', 'entregas-equipamentos-cat', 'entregas-perifericos'];

$cadastroVers = \App\Services\PerfilService::codigosCadastrosVer();
$cadastroEdits = \App\Services\PerfilService::codigosCadastrosEditar();
$canCadastro = static function (string $slug, bool $editar = false) use ($can): bool {
    $meta = \App\Services\PerfilService::mapaCadastrosMenu()[$slug] ?? null;
    if ($meta === null) {
        return $can('cadastros.ver', 'cadastros.editar');
    }
    return $editar
        ? $can($meta['editar'], $meta['criar'], 'cadastros.editar')
        : $can($meta['ver'], $meta['criar'], $meta['editar'], 'cadastros.ver', 'cadastros.editar');
};

$showRh = $can(
    'efetivo.ver', 'efetivo.editar', 'cadastros.ver', 'cadastros.editar', 'ponto.ver', 'folha.ver',
    'medicao.ver', 'ra.ver', 'he_relatorio.ver', 'he_relatorio.operar',
    'vr.ver', 'vr.editar', 'entregas.ver', 'entregas.gerir', 'entregas.operar',
    ...$cadastroVers,
    ...$cadastroEdits
);
$showOrg = $can('cadastros.ver', 'cadastros.editar', 'empresas.ver', 'empresas.editar', 'contratos.ver', 'contratos.editar', 'centros_custo.ver', 'centros_custo.editar', 'ordens_servico.ver', 'ordens_servico.editar', 'localidades.ver', 'localidades.editar');
$showEscalas = $can('cadastros.ver', 'cadastros.editar', 'horarios.ver', 'horarios.editar', 'calendarios.ver', 'calendarios.editar');
$showPessoas = $can('cadastros.ver', 'cadastros.editar', 'lotacoes.ver', 'lotacoes.editar', 'cargos.ver', 'cargos.editar', 'funcoes.ver', 'funcoes.editar', 'gestores_arcadis.ver', 'gestores_arcadis.editar', 'gestores_cliente.ver', 'gestores_cliente.editar', 'supervisores.ver', 'supervisores.editar');
$showPonto = $can('ponto.ver', 'ponto.editar', 'medicao.ver', 'ra.ver', 'justificativa_tipos.ver', 'he_relatorio.ver', 'he_relatorio.operar', 'folha.ver');
$showVr = $can('vr.ver', 'vr.editar', 'vr_relatorio.ver', 'vr_relatorio.operar');
$showSistema = $can(
    'usuarios.gerenciar', 'usuarios.ver', 'atividade.ver',
    'perfis.gerenciar', 'perfis.ver',
    'email.gerenciar', 'email.ver',
    'importacoes.gerenciar', 'importacoes.ver',
    'alertas.gerenciar', 'alertas_config.ver'
);

$chevron = <<<'SVG'
<svg class="nav-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
SVG;
?>
<aside class="sidebar" id="sidebar">
    <div class="brand">
        <img class="brand-logo" src="<?= View::asset('img/logo.png') ?>" alt="Arcadis">
    </div>

    <div class="sidebar-search search-field">
        <span class="search-icon" aria-hidden="true">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
        </span>
        <input class="form-control" type="search" placeholder="Buscar..." aria-label="Buscar">
    </div>

    <nav>
        <div class="nav-section">
            <div class="nav-section-title">Principal</div>
            <ul class="nav-list">
                <?php if ($can('dashboard.ver')): ?>
                <li>
                    <a class="nav-link<?= $isActive('dashboard') ?>" href="/">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 13h7V4H4v9zm9 7h7V11h-7v9zM4 20h7v-5H4v5zm9-16v5h7V4h-7z"/></svg>
                        Visão geral
                    </a>
                </li>
                <?php endif; ?>
                <?php if ($can('alertas.ver') || $can('dashboard.ver')): ?>
                <li>
                    <a class="nav-link<?= $isActive('alertas') ?>" href="/rh/alertas">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 7 3 9H3c0-2 3-2 3-9"/><path d="M10 21a2 2 0 0 0 4 0"/></svg>
                        Central de Alertas
                        <?php if ($alertasBadge > 0): ?>
                            <span class="nav-badge"><?= $alertasBadge > 99 ? '99+' : $alertasBadge ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <?php endif; ?>
                <?php if ($can('mapa.ver', 'dashboard.ver')): ?>
                <li>
                    <a class="nav-link<?= $isActive('mapa-sistema') ?>" href="/ui/mapa-sistema">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="6" cy="6" r="2.2"/><circle cx="18" cy="6" r="2.2"/><circle cx="6" cy="18" r="2.2"/><circle cx="18" cy="18" r="2.2"/><path d="M8.2 6h7.6M6 8.2v7.6M18 8.2v7.6M8.2 18h7.6"/></svg>
                        Mapa do sistema
                    </a>
                </li>
                <?php endif; ?>
            </ul>
        </div>

        <?php if ($showRh): ?>
        <div class="nav-section">
            <div class="nav-section-title">RH</div>
            <ul class="nav-list">
                <?php if ($can('efetivo.ver')): ?>
                <li>
                    <a class="nav-link<?= $isActive('efetivo') ?>" href="/rh/efetivo">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                        Efetivo
                    </a>
                </li>
                <?php endif; ?>

                <?php if ($showOrg): ?>
                <?php $orgOpen = $inGroup($orgKeys); ?>
                <li class="nav-group<?= $orgOpen ? ' is-open' : '' ?>" data-nav-group>
                    <button type="button" class="nav-group-toggle" aria-expanded="<?= $orgOpen ? 'true' : 'false' ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 21h18"/><path d="M5 21V7l7-4 7 4v14"/><path d="M9 21v-6h6v6"/></svg>
                        <span>Organização</span>
                        <?= $chevron ?>
                    </button>
                    <ul class="nav-sublist"<?= $orgOpen ? '' : ' hidden' ?>>
                        <?php if ($canCadastro('empresas')): ?><li><a class="nav-sublink<?= $isActive('empresas') ?>" href="/rh/empresas">Empresas</a></li><?php endif; ?>
                        <?php if ($canCadastro('contratos')): ?><li><a class="nav-sublink<?= $isActive('contratos') ?>" href="/rh/contratos">Contratos</a></li><?php endif; ?>
                        <?php if ($canCadastro('centros-custo')): ?><li><a class="nav-sublink<?= $isActive('centros') ?>" href="/rh/centros-custo">Centros de Custo</a></li><?php endif; ?>
                        <?php if ($canCadastro('ordens-servico')): ?><li><a class="nav-sublink<?= $isActive('os') ?>" href="/rh/ordens-servico">Ordens de Serviço</a></li><?php endif; ?>
                        <?php if ($canCadastro('localidades')): ?><li><a class="nav-sublink<?= $isActive('localidades') ?>" href="/rh/localidades">Localidades</a></li><?php endif; ?>
                    </ul>
                </li>
                <?php endif; ?>

                <?php if ($showEscalas): ?>
                <?php $escOpen = $inGroup($escalasKeys); ?>
                <li class="nav-group<?= $escOpen ? ' is-open' : '' ?>" data-nav-group>
                    <button type="button" class="nav-group-toggle" aria-expanded="<?= $escOpen ? 'true' : 'false' ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l4 2"/></svg>
                        <span>Horários e calendário</span>
                        <?= $chevron ?>
                    </button>
                    <ul class="nav-sublist"<?= $escOpen ? '' : ' hidden' ?>>
                        <?php if ($canCadastro('horarios')): ?><li><a class="nav-sublink<?= $isActive('horarios') ?>" href="/rh/horarios">Horários</a></li><?php endif; ?>
                        <?php if ($canCadastro('calendarios')): ?><li><a class="nav-sublink<?= $isActive('feriados') ?>" href="/rh/calendarios">Feriados</a></li><?php endif; ?>
                    </ul>
                </li>
                <?php endif; ?>

                <?php if ($showPessoas): ?>
                <?php $pesOpen = $inGroup($pessoasKeys); ?>
                <li class="nav-group<?= $pesOpen ? ' is-open' : '' ?>" data-nav-group>
                    <button type="button" class="nav-group-toggle" aria-expanded="<?= $pesOpen ? 'true' : 'false' ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 11h-6M19 8v6"/></svg>
                        <span>Pessoas e papéis</span>
                        <?= $chevron ?>
                    </button>
                    <ul class="nav-sublist"<?= $pesOpen ? '' : ' hidden' ?>>
                        <?php if ($canCadastro('lotacoes')): ?><li><a class="nav-sublink<?= $isActive('lotacoes') ?>" href="/rh/lotacoes">Lotações</a></li><?php endif; ?>
                        <?php if ($canCadastro('cargos')): ?><li><a class="nav-sublink<?= $isActive('cargos') ?>" href="/rh/cargos">Cargo</a></li><?php endif; ?>
                        <?php if ($canCadastro('funcoes')): ?><li><a class="nav-sublink<?= $isActive('funcoes') ?>" href="/rh/funcoes">Função</a></li><?php endif; ?>
                        <?php if ($canCadastro('gestores-arcadis')): ?><li><a class="nav-sublink<?= $isActive('gestores-arcadis') ?>" href="/rh/gestores-arcadis">Gestor Arcadis</a></li><?php endif; ?>
                        <?php if ($canCadastro('gestores-cliente')): ?><li><a class="nav-sublink<?= $isActive('gestores-cliente') ?>" href="/rh/gestores-cliente">Gestor Cliente</a></li><?php endif; ?>
                        <?php if ($canCadastro('supervisores')): ?><li><a class="nav-sublink<?= $isActive('supervisores') ?>" href="/rh/supervisores">Supervisor</a></li><?php endif; ?>
                    </ul>
                </li>
                <?php endif; ?>

                <?php if ($showPonto): ?>
                <?php $medOpen = $inGroup($medicaoKeys); ?>
                <li class="nav-group<?= $medOpen ? ' is-open' : '' ?>" data-nav-group>
                    <button type="button" class="nav-group-toggle" aria-expanded="<?= $medOpen ? 'true' : 'false' ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 19h16"/><path d="M7 16V9"/><path d="M12 16V5"/><path d="M17 16v-4"/></svg>
                        <span>Medição e ponto</span>
                        <?= $chevron ?>
                    </button>
                    <ul class="nav-sublist"<?= $medOpen ? '' : ' hidden' ?>>
                        <?php if ($can('ponto.ver', 'ponto.editar', 'ponto.operar')): ?>
                        <li><a class="nav-sublink<?= $isActive('ponto') ?>" href="/rh/ponto">Gestão de Ponto</a></li>
                        <?php endif; ?>
                        <?php if ($can('justificativa_tipos.ver', 'ponto.ver', 'ponto.editar')): ?>
                        <li><a class="nav-sublink<?= $isActive('justificativa-tipos') ?>" href="/rh/ponto/justificativa-tipos">Tipos de justificativa</a></li>
                        <?php endif; ?>
                        <?php if ($can('medicao.ver', 'medicao.operar', 'medicao.editar', 'ponto.ver')): ?>
                        <li><a class="nav-sublink<?= $isActive('medicao') ?>" href="/rh/medicao">Medição</a></li>
                        <?php endif; ?>
                        <?php if ($can('he_relatorio.ver', 'he_relatorio.operar', 'medicao.ver', 'medicao.operar', 'ponto.ver')): ?>
                        <li><a class="nav-sublink<?= $isActive('relatorio-he') ?>" href="/rh/relatorio-he">Relatório de HE</a></li>
                        <?php endif; ?>
                        <?php if ($can('ra.ver', 'ra.editar', 'ra.operar', 'ponto.ver')): ?>
                        <li><a class="nav-sublink<?= $isActive('ra') ?>" href="/rh/relatorios-atividades">Relatórios de Atividades</a></li>
                        <?php endif; ?>
                        <?php if ($can('folha.ver', 'folha.operar', 'folha.enviar')): ?>
                        <li><a class="nav-sublink<?= $isActive('folha') ?>" href="/rh/folha">Folha de ponto</a></li>
                        <?php endif; ?>
                    </ul>
                </li>
                <?php endif; ?>

                <?php if ($showVr): ?>
                <?php $vrOpen = $inGroup($vrKeys); ?>
                <li class="nav-group<?= $vrOpen ? ' is-open' : '' ?>" data-nav-group>
                    <button type="button" class="nav-group-toggle" aria-expanded="<?= $vrOpen ? 'true' : 'false' ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 7h16v10H4z"/><path d="M8 7V5a4 4 0 0 1 8 0v2"/><path d="M12 11v2"/></svg>
                        <span>Vale Refeição</span>
                        <?= $chevron ?>
                    </button>
                    <ul class="nav-sublist"<?= $vrOpen ? '' : ' hidden' ?>>
                        <li><a class="nav-sublink<?= $isActive('vr-config') ?>" href="/rh/vale-refeicao">Configuração</a></li>
                        <li><a class="nav-sublink<?= $isActive('vr-relatorio') ?>" href="/rh/vale-refeicao/relatorio">Gerar relatório</a></li>
                    </ul>
                </li>
                <?php endif; ?>

                <?php if ($can('entregas.ver', 'entregas.gerir', 'entregas.operar')): ?>
                <?php $entOpen = $inGroup($entregasKeys); ?>
                <li class="nav-group<?= $entOpen ? ' is-open' : '' ?>" data-nav-group>
                    <button type="button" class="nav-group-toggle" aria-expanded="<?= $entOpen ? 'true' : 'false' ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="4" width="18" height="14" rx="2"/><path d="M7 8h10M7 12h6"/><path d="M8 20h8"/></svg>
                        <span>Entrega de Equipamentos</span>
                        <?= $chevron ?>
                    </button>
                    <ul class="nav-sublist"<?= $entOpen ? '' : ' hidden' ?>>
                        <li><a class="nav-sublink<?= $isActive('entregas') ?>" href="/rh/entregas-equipamentos">Entregas</a></li>
                        <li><a class="nav-sublink<?= $isActive('entregas-equipamentos-cat') ?>" href="/rh/entregas-equipamentos/equipamentos">Equipamentos</a></li>
                        <li><a class="nav-sublink<?= $isActive('entregas-perifericos') ?>" href="/rh/entregas-equipamentos/perifericos">Periféricos</a></li>
                    </ul>
                </li>
                <?php endif; ?>
            </ul>
        </div>
        <?php endif; ?>

        <?php if ($showSistema): ?>
        <div class="nav-section">
            <div class="nav-section-title">Sistema</div>
            <ul class="nav-list">
                <?php if ($can('usuarios.gerenciar', 'usuarios.ver')): ?>
                <li>
                    <a class="nav-link<?= $isActive('usuarios') ?>" href="/rh/usuarios">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                        Usuários
                    </a>
                </li>
                <?php endif; ?>
                <?php if ($can('atividade.ver')): ?>
                <li>
                    <a class="nav-link<?= $isActive('atividade') ?>" href="/rh/atividade">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/><path d="M5 12h2M17 12h2M12 5V3"/></svg>
                        Atividade
                        <span class="nav-badge" id="atividadeOnlineBadge" hidden>0</span>
                    </a>
                </li>
                <?php endif; ?>
                <?php if ($can('perfis.gerenciar', 'perfis.ver')): ?>
                <li>
                    <a class="nav-link<?= $isActive('perfis') ?>" href="/rh/perfis">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 3l8 4v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V7l8-4z"/></svg>
                        Perfis
                    </a>
                </li>
                <?php endif; ?>
                <?php if ($can('email.gerenciar', 'email.ver')): ?>
                <li>
                    <a class="nav-link<?= $isActive('email') ?>" href="/rh/email">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 6h16v12H4z"/><path d="M4 7l8 6 8-6"/></svg>
                        Conta de e-mail
                    </a>
                </li>
                <?php endif; ?>
                <?php if ($can('alertas.gerenciar', 'alertas_config.ver')): ?>
                <li>
                    <a class="nav-link<?= $isActive('alertas-config') ?>" href="/rh/alertas/config">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 9v4"/><path d="M12 17h.01"/><path d="M10.3 3.9L2.8 17a2 2 0 0 0 1.7 3h15a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/></svg>
                        Configurar alertas
                    </a>
                </li>
                <?php endif; ?>
                <?php if ($can('importacoes.gerenciar', 'importacoes.ver')): ?>
                <li>
                    <a class="nav-link<?= $isActive('importacoes') ?>" href="/rh/importacoes">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/></svg>
                        Importações
                    </a>
                </li>
                <?php endif; ?>
            </ul>
        </div>
        <?php endif; ?>
    </nav>

    <div class="sidebar-footer">
        <div class="user-chip">
            <a class="user-chip-link" href="/conta" title="Meu perfil">
                <?php
                $avatarUrl = $currentUser['avatar_url'] ?? \App\Helpers\Auth::avatarUrl();
                $inicial = mb_strtoupper(mb_substr((string) ($currentUser['name'] ?? 'U'), 0, 1));
                ?>
                <div class="avatar <?= $avatarUrl ? 'has-photo' : '' ?>" aria-hidden="true">
                    <?php if ($avatarUrl): ?>
                        <img src="<?= View::e((string) $avatarUrl) ?>" alt="">
                    <?php else: ?>
                        <?= View::e($inicial) ?>
                    <?php endif; ?>
                </div>
                <div>
                    <strong><?= View::e((string) ($currentUser['name'] ?? 'Usuário')) ?></strong>
                    <span><?= View::e((string) ($currentUser['role'] ?? 'RH')) ?></span>
                </div>
            </a>
        </div>
        <div style="padding:0 .45rem .35rem;display:grid;gap:.35rem;">
            <a class="btn btn-secondary btn-sm" href="/conta" style="width:100%;">Meu perfil</a>
            <form method="post" action="/logout">
                <?= \App\Helpers\Csrf::field() ?>
                <button class="btn btn-ghost btn-sm" type="submit" style="width:100%;">Sair</button>
            </form>
        </div>
    </div>
</aside>
