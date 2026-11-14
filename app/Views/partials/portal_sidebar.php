<?php

use App\Helpers\View;

$activeMenu = $activeMenu ?? '';
$user = $user ?? null;
$appName = $appName ?? 'Portal do Colaborador';

$isActive = static fn(string $key): string => $activeMenu === $key ? ' is-active' : '';
?>
<aside class="sidebar" id="sidebar">
    <div class="brand">
        <img class="brand-logo" src="<?= View::asset('img/logo.png') ?>" alt="Arcadis">
    </div>

    <nav>
        <div class="nav-section">
            <div class="nav-section-title">Portal</div>
            <ul class="nav-list">
                <li>
                    <a class="nav-link<?= $isActive('overview') ?>" href="/he">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 13h7V4H4v9zm9 7h7V11h-7v9zM4 20h7v-5H4v5zm9-16v5h7V4h-7z"/></svg>
                        Visão Geral
                    </a>
                </li>
                <li>
                    <a class="nav-link<?= $isActive('relatorios') ?>" href="/he/relatorios">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M8 13h8M8 17h6"/></svg>
                        Relatório de Atividades
                    </a>
                </li>
            </ul>
        </div>
    </nav>

    <div class="sidebar-footer">
        <div class="user-chip">
            <div class="avatar" aria-hidden="true"><?= View::e(mb_substr((string) ($user['nome'] ?? 'C'), 0, 1)) ?></div>
            <div>
                <strong><?= View::e((string) ($user['nome'] ?? 'Colaborador')) ?></strong>
                <span><?= View::e((string) ($user['matricula'] ?? $appName)) ?></span>
            </div>
        </div>
        <div style="padding:0 .45rem .35rem;">
            <a class="btn btn-ghost btn-sm" href="/he/logout" style="width:100%;">Sair</a>
        </div>
    </div>
</aside>
