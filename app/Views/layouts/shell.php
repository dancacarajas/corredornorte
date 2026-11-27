<?php

use App\Helpers\Csrf;
use App\Helpers\View;

$app = require dirname(__DIR__, 3) . '/config/app.php';
$pageTitle = $pageTitle ?? $app['name'];
$pageSubtitle = $pageSubtitle ?? null;
$activeMenu = $activeMenu ?? '';
$authUser = \App\Helpers\Auth::user();
$currentUser = $currentUser ?? ($authUser ? [
    'id' => (int) ($authUser['id'] ?? 0),
    'name' => (string) $authUser['name'],
    'email' => (string) ($authUser['email'] ?? ''),
    'role' => (string) ($authUser['role'] ?? 'RH Operacional'),
    'avatar' => (string) ($authUser['avatar'] ?? ''),
    'avatar_url' => \App\Helpers\Auth::avatarUrl($authUser),
] : ['id' => 0, 'name' => 'Administrador', 'email' => '', 'role' => 'RH Operacional', 'avatar' => '', 'avatar_url' => null]);

// Badge = não lidos do período padrão (mais recente), igual à Central de Alertas.
// Se a página já calculou ($alertasBadge > 0 ou explícito via array_key_exists no extract),
// respeita o valor; senão recalcula no período vigente.
if (!isset($alertasBadge) && \App\Helpers\Auth::check() && \App\Helpers\Auth::canAny('alertas.ver', 'dashboard.ver')) {
    try {
        $pdoBadge = \App\Helpers\Db::tryConnection();
        if ($pdoBadge instanceof PDO) {
            $alertasBadge = (new \App\Services\AlertasService($pdoBadge))->contarNaoLidos((int) (\App\Helpers\Auth::id() ?? 0));
        }
    } catch (Throwable) {
        $alertasBadge = 0;
    }
}
$alertasBadge = (int) ($alertasBadge ?? 0);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= View::e(Csrf::token()) ?>">
    <title><?= View::e($pageTitle) ?> · <?= View::e($app['name']) ?></title>
    <link rel="stylesheet" href="<?= View::asset('css/tokens.css') ?>?v=brand-20261127a">
    <link rel="stylesheet" href="<?= View::asset('css/components.css') ?>?v=brand-20261127a">
    <link rel="stylesheet" href="<?= View::asset('css/layout.css') ?>?v=brand-20261127a">
    <link rel="stylesheet" href="<?= View::asset('css/conta.css') ?>?v=conta-20261114">
    <style>dialog:not([open]),[hidden]{display:none!important;}</style>
    <?php foreach (($extraCss ?? []) as $cssFile): ?>
        <link rel="stylesheet" href="<?= View::asset($cssFile) ?>">
    <?php endforeach; ?>
</head>
<body>
<div class="sidebar-backdrop" id="sidebarBackdrop"></div>
<div class="app-shell">
    <?php View::partial('sidebar', [
        'app' => $app,
        'activeMenu' => $activeMenu,
        'currentUser' => $currentUser,
        'alertasBadge' => $alertasBadge,
    ]); ?>

    <div class="main-area">
        <?php
        $topbarActionsFinal = $topbarActions ?? null;
        if (\App\Helpers\Auth::canAny('alertas.ver', 'dashboard.ver') && ($activeMenu ?? '') !== 'alertas') {
            ob_start();
            ?>
            <a class="topbar-alerta" href="/rh/alertas" title="Central de Alertas">
                Alertas
                <?php if ($alertasBadge > 0): ?>
                    <span class="nav-badge"><?= $alertasBadge > 99 ? '99+' : (int) $alertasBadge ?></span>
                <?php endif; ?>
            </a>
            <?php
            $badgeHtml = ob_get_clean() ?: '';
            $topbarActionsFinal = $badgeHtml . ($topbarActionsFinal ?? '');
        }
        View::partial('topbar', [
            'pageTitle' => $pageTitle,
            'pageSubtitle' => $pageSubtitle,
            'topbarActions' => $topbarActionsFinal,
        ]); ?>

        <main class="content section-stack">
            <?= $content ?>
        </main>
    </div>
</div>
<script src="<?= View::asset('js/app.js') ?>?v=confirm-submit-20261116"></script>
<script src="<?= View::asset('js/batch-runner.js') ?>?v=email-report-20261114"></script>
<?php if (\App\Helpers\Auth::check()): ?>
<script src="<?= View::asset('js/atividade-heartbeat.js') ?>?v=atividade-20261116"></script>
<?php endif; ?>
<?php foreach (($extraJs ?? []) as $jsFile): ?>
<script src="<?= View::asset($jsFile) ?>"></script>
<?php endforeach; ?>
</body>
</html>
