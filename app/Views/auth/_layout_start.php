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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@500;600;700;800&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= View::asset('css/tokens.css') ?>">
    <link rel="stylesheet" href="<?= View::asset('css/components.css') ?>">
    <link rel="stylesheet" href="<?= View::asset('css/auth.css') ?>?v=auth-20261114">
</head>
<body class="auth-body">
<div class="login-card">
    <div class="login-brand">
        <img src="<?= View::asset('img/logo.png') ?>" alt="Arcadis">
    </div>
