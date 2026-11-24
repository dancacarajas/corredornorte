<?php

use App\Helpers\View;

/** @var string $pageTitle */
$pageTitle = $pageTitle ?? 'Arcadis';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="referrer" content="no-referrer">
    <title><?= View::e($pageTitle) ?> · Arcadis</title>
    <link rel="stylesheet" href="<?= View::asset('css/tokens.css') ?>?v=brand-20261118b">
    <link rel="stylesheet" href="<?= View::asset('css/components.css') ?>?v=brand-20261118b">
    <link rel="stylesheet" href="<?= View::asset('css/auth.css') ?>?v=brand-20261118b">
</head>
<body class="auth-body">
<div class="login-card">
    <div class="login-brand">
        <img src="<?= View::asset('img/logo.png') ?>" alt="Arcadis">
    </div>
