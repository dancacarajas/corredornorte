<?php

use App\Helpers\View;

$pageTitle = $pageTitle ?? '';
$pageSubtitle = $pageSubtitle ?? null;
?>
<header class="topbar">
    <div style="display:flex;align-items:center;gap:.75rem;">
        <button class="mobile-toggle" id="sidebarToggle" type="button" aria-label="Abrir menu">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
        </button>
        <div class="topbar-title">
            <h1><?= View::e($pageTitle) ?></h1>
            <?php if ($pageSubtitle): ?>
                <p><?= View::e($pageSubtitle) ?></p>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($topbarActions)): ?>
        <div class="topbar-actions">
            <?= $topbarActions ?>
        </div>
    <?php endif; ?>
</header>
