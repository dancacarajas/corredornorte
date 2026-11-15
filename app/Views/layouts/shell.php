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
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= View::e(Csrf::token()) ?>">
    <title><?= View::e($pageTitle) ?> · <?= View::e($app['name']) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@500;600;700;800&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= View::asset('css/tokens.css') ?>">
    <link rel="stylesheet" href="<?= View::asset('css/components.css') ?>?v=avatar-20261114">
    <link rel="stylesheet" href="<?= View::asset('css/layout.css') ?>?v=nav-perfis-20261114">
    <link rel="stylesheet" href="<?= View::asset('css/conta.css') ?>?v=conta-20261114">
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
<script src="<?= View::asset('js/app.js') ?>?v=nav-vr-20261114"></script>
<script src="<?= View::asset('js/batch-runner.js') ?>?v=email-report-20261114"></script>
<?php foreach (($extraJs ?? []) as $jsFile): ?>
<script src="<?= View::asset($jsFile) ?>"></script>
<?php endforeach; ?>
</body>
</html>
