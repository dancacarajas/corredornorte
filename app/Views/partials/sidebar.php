<?php

use App\Helpers\Auth;
use App\Helpers\View;

$activeMenu = $activeMenu ?? '';
$app = $app ?? ['name' => 'Arcadis', 'tagline' => 'RH Operacional'];
$currentUser = $currentUser ?? ['name' => 'Administrador', 'role' => 'RH'];

$isActive = static fn(string $key): string => $activeMenu === $key ? ' is-active' : '';
$can = static fn(string ...$perms): bool => Auth::canAny(...$perms);
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
            </ul>
        </div>

        <?php if ($can('efetivo.ver', 'cadastros.ver')): ?>
        <div class="nav-section">
            <div class="nav-section-title">RH · Efetivo</div>
            <ul class="nav-list">
                <?php if ($can('efetivo.ver')): ?>
                <li>
                    <a class="nav-link<?= $isActive('efetivo') ?>" href="/rh/efetivo">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                        Efetivo
                    </a>
                </li>
                <?php endif; ?>
                <?php if ($can('cadastros.ver')): ?>
                <li>
                    <a class="nav-link<?= $isActive('empresas') ?>" href="/rh/empresas">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 21h18"/><path d="M5 21V7l7-4 7 4v14"/><path d="M9 21v-6h6v6"/></svg>
                        Empresas
                    </a>
                </li>
                <li>
                    <a class="nav-link<?= $isActive('contratos') ?>" href="/rh/contratos">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M8 13h8M8 17h5"/></svg>
                        Contratos
                    </a>
                </li>
                <li>
                    <a class="nav-link<?= $isActive('centros') ?>" href="/rh/centros-custo">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z"/></svg>
                        Centros de Custo
                    </a>
                </li>
                <li>
                    <a class="nav-link<?= $isActive('os') ?>" href="/rh/ordens-servico">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M9 5h11M9 12h11M9 19h11"/><path d="M4 5h.01M4 12h.01M4 19h.01"/></svg>
                        Ordens de Serviço
                    </a>
                </li>
                <li>
                    <a class="nav-link<?= $isActive('jornadas') ?>" href="/rh/jornadas">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l4 2"/></svg>
                        Jornadas
                    </a>
                </li>
                <li>
                    <a class="nav-link<?= $isActive('escalas') ?>" href="/rh/escalas">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 6h16M4 12h10M4 18h14"/></svg>
                        Escalas
                    </a>
                </li>
                <li>
                    <a class="nav-link<?= $isActive('localidades') ?>" href="/rh/localidades">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 21s7-4.5 7-11a7 7 0 1 0-14 0c0 6.5 7 11 7 11z"/><circle cx="12" cy="10" r="2.5"/></svg>
                        Localidades
                    </a>
                </li>
                <li>
                    <a class="nav-link<?= $isActive('feriados') ?>" href="/rh/calendarios">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                        Feriados
                    </a>
                </li>
                <li>
                    <a class="nav-link<?= $isActive('lotacoes') ?>" href="/rh/lotacoes">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 7h18M3 12h18M3 17h18"/></svg>
                        Lotações
                    </a>
                </li>
                <li>
                    <a class="nav-link<?= $isActive('cargos') ?>" href="/rh/cargos">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 11h-6M19 8v6"/></svg>
                        Cargo
                    </a>
                </li>
                <li>
                    <a class="nav-link<?= $isActive('funcoes') ?>" href="/rh/funcoes">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 7h16M4 12h10M4 17h14"/><path d="M16 10l4 2-4 2"/></svg>
                        Função
                    </a>
                </li>
                <li>
                    <a class="nav-link<?= $isActive('gestores-arcadis') ?>" href="/rh/gestores-arcadis">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 3l8 4v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V7l8-4z"/></svg>
                        Gestor Arcadis
                    </a>
                </li>
                <li>
                    <a class="nav-link<?= $isActive('gestores-cliente') ?>" href="/rh/gestores-cliente">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                        Gestor Cliente
                    </a>
                </li>
                <li>
                    <a class="nav-link<?= $isActive('supervisores') ?>" href="/rh/supervisores">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="8" r="4"/><path d="M4 21v-1a6 6 0 0 1 12 0v1"/><path d="M19 11v6M16 14h6"/></svg>
                        Supervisor
                    </a>
                </li>
                <?php endif; ?>
            </ul>
        </div>
        <?php endif; ?>

        <?php if ($can('ponto.ver', 'folha.ver')): ?>
        <div class="nav-section">
            <div class="nav-section-title">RH · Ponto</div>
            <ul class="nav-list">
                <?php if ($can('ponto.ver')): ?>
                <li>
                    <a class="nav-link<?= $isActive('ponto') ?>" href="/rh/ponto">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                        Gestão de Ponto
                    </a>
                </li>
                <?php endif; ?>
                <?php if ($can('folha.ver')): ?>
                <li>
                    <a class="nav-link<?= $isActive('folha') ?>" href="/rh/folha">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M8 13h8M8 17h6"/></svg>
                        Folha de Ponto
                    </a>
                </li>
                <?php endif; ?>
                <?php if ($can('ponto.ver')): ?>
                <li>
                    <a class="nav-link<?= $isActive('medicao') ?>" href="/rh/medicao">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 19h16"/><path d="M7 16V9"/><path d="M12 16V5"/><path d="M17 16v-4"/><circle cx="7" cy="7" r="1.5"/><circle cx="12" cy="3" r="1.5"/><circle cx="17" cy="10" r="1.5"/></svg>
                        Medição
                    </a>
                </li>
                <li>
                    <a class="nav-link<?= $isActive('ra') ?>" href="/rh/relatorios-atividades">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M8 13h8M8 17h6"/></svg>
                        Relatórios de Atividades
                    </a>
                </li>
                <li>
                    <a class="nav-link<?= $isActive('vr') ?>" href="/rh/vale-refeicao">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 7h16v10H4z"/><path d="M8 7V5a4 4 0 0 1 8 0v2"/><path d="M12 11v2"/></svg>
                        Vale Refeição
                    </a>
                </li>
                <?php endif; ?>
            </ul>
        </div>
        <?php endif; ?>

        <div class="nav-section">
            <div class="nav-section-title">Sistema</div>
            <ul class="nav-list">
                <?php if ($can('usuarios.gerenciar')): ?>
                <li>
                    <a class="nav-link<?= $isActive('usuarios') ?>" href="/rh/usuarios">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                        Usuários
                    </a>
                </li>
                <?php endif; ?>
                <?php if ($can('perfis.gerenciar')): ?>
                <li>
                    <a class="nav-link<?= $isActive('perfis') ?>" href="/rh/perfis">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 3l8 4v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V7l8-4z"/></svg>
                        Perfis
                    </a>
                </li>
                <?php endif; ?>
                <?php if ($can('email.gerenciar')): ?>
                <li>
                    <a class="nav-link<?= $isActive('email') ?>" href="/rh/email">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 6h16v12H4z"/><path d="M4 7l8 6 8-6"/></svg>
                        Conta de e-mail
                    </a>
                </li>
                <?php endif; ?>
                <?php if ($can('importacoes.gerenciar')): ?>
                <li>
                    <a class="nav-link<?= $isActive('importacoes') ?>" href="/rh/importacoes">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/></svg>
                        Importações
                    </a>
                </li>
                <?php endif; ?>
            </ul>
        </div>
    </nav>

    <div class="sidebar-footer">
        <div class="user-chip">
            <div class="avatar" aria-hidden="true"><?= View::e(mb_substr((string) ($currentUser['name'] ?? 'U'), 0, 1)) ?></div>
            <div>
                <strong><?= View::e((string) ($currentUser['name'] ?? 'Usuário')) ?></strong>
                <span><?= View::e((string) ($currentUser['role'] ?? 'RH')) ?></span>
            </div>
        </div>
        <div style="padding:0 .45rem .35rem;">
            <a class="btn btn-ghost btn-sm" href="/logout" style="width:100%;">Sair</a>
        </div>
    </div>
</aside>
