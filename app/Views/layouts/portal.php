<?php

use App\Helpers\Csrf;
use App\Helpers\View;

$app = require dirname(__DIR__, 3) . '/config/app.php';
$pageTitle = $pageTitle ?? 'Portal';
$pageSubtitle = $pageSubtitle ?? null;
$activeMenu = $activeMenu ?? '';
$user = $user ?? null;
$currentUser = [
    'name' => (string) ($user['nome'] ?? 'Colaborador'),
    'role' => (string) ($user['matricula'] ?? 'Portal'),
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= View::e(Csrf::token()) ?>">
    <title><?= View::e($pageTitle) ?> · Portal</title>
    <link rel="stylesheet" href="<?= View::asset('css/tokens.css') ?>?v=brand-20261127a">
    <link rel="stylesheet" href="<?= View::asset('css/components.css') ?>?v=brand-20261127a">
    <link rel="stylesheet" href="<?= View::asset('css/layout.css') ?>?v=brand-20261127a">
    <link rel="stylesheet" href="<?= View::asset('css/portal.css') ?>?v=brand-20261127a">
    <style>dialog:not([open]),[hidden]{display:none!important;}</style>
    <?php foreach (($extraCss ?? []) as $cssFile): ?>
        <link rel="stylesheet" href="<?= View::asset($cssFile) ?>">
    <?php endforeach; ?>
</head>
<body>
<div class="sidebar-backdrop" id="sidebarBackdrop"></div>
<div class="app-shell">
    <?php View::partial('portal_sidebar', [
        'activeMenu' => $activeMenu,
        'user' => $user,
        'appName' => $app['name'] ?? 'Arcadis',
    ]); ?>

    <div class="main-area">
        <?php View::partial('topbar', [
            'pageTitle' => $pageTitle,
            'pageSubtitle' => $pageSubtitle,
            'topbarActions' => $topbarActions ?? null,
        ]); ?>

        <main class="content section-stack">
            <?= $content ?>
        </main>
    </div>
</div>
<script src="<?= View::asset('js/app.js') ?>?v=confirm-submit-20261116"></script>
<?php foreach (($extraJs ?? []) as $jsFile): ?>
    <script src="<?= View::asset($jsFile) ?>" defer></script>
<?php endforeach; ?>
</body>
</html>
