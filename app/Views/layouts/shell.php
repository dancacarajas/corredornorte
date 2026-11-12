<?php

use App\Helpers\View;

$app = require dirname(__DIR__, 3) . '/config/app.php';
$pageTitle = $pageTitle ?? $app['name'];
$pageSubtitle = $pageSubtitle ?? null;
$activeMenu = $activeMenu ?? '';
$authUser = \App\Helpers\Auth::user();
$currentUser = $currentUser ?? ($authUser ? [
    'name' => (string) $authUser['name'],
    'role' => (string) ($authUser['role'] ?? 'RH Operacional'),
] : ['name' => 'Administrador', 'role' => 'RH Operacional']);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= View::e($pageTitle) ?> · <?= View::e($app['name']) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@500;600;700;800&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= View::asset('css/tokens.css') ?>">
    <link rel="stylesheet" href="<?= View::asset('css/components.css') ?>">
    <link rel="stylesheet" href="<?= View::asset('css/layout.css') ?>">
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
<script src="<?= View::asset('js/app.js') ?>"></script>
</body>
</html>
