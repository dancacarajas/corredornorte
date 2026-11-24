<?php

use App\Helpers\View;

$pageTitle = $pageTitle ?? 'Hora Extra';
$user = $user ?? null;
$content = $content ?? '';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= View::e(\App\Helpers\Csrf::token()) ?>">
    <title><?= View::e($pageTitle) ?> · Arcadis</title>
    <link rel="stylesheet" href="<?= View::asset('css/tokens.css') ?>?v=brand-20261118b">
    <link rel="stylesheet" href="<?= View::asset('css/components.css') ?>?v=brand-20261118b">
    <style>
        body {
            min-height: 100vh;
            margin: 0;
            background:
                radial-gradient(circle at top left, rgba(var(--brand-heritage-rgb), .14), transparent 42%),
                radial-gradient(circle at bottom right, rgba(var(--brand-confidence-rgb), .06), transparent 38%),
                var(--canvas);
            font-family: var(--font-sans);
            letter-spacing: var(--tracking);
            color: var(--ink);
        }
        .he-wrap { width: min(920px, 100%); margin: 0 auto; padding: 1.25rem 1rem 2.5rem; }
        .he-top {
            display: flex; align-items: center; justify-content: space-between; gap: 1rem;
            margin-bottom: 1.25rem; flex-wrap: wrap;
        }
        .he-brand { display: flex; align-items: center; gap: .75rem; }
        .he-brand img { height: 40px; width: auto; min-width: 72px; }
        .he-brand strong { display: block; font-family: var(--font-display); font-weight: 700; letter-spacing: var(--tracking); }
        .he-brand span { color: var(--ink-muted); font-size: var(--text-sm); }
        .he-nav { display: flex; gap: .5rem; flex-wrap: wrap; }
        .he-card {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: var(--radius-lg); box-shadow: var(--shadow-md);
            padding: 1.25rem;
            margin-bottom: 1rem;
        }
        .he-card h2 { margin: 0 0 .85rem; font-size: var(--text-h2); font-family: var(--font-display); font-weight: 700; letter-spacing: var(--tracking); }
        .he-grid { display: grid; gap: .85rem; grid-template-columns: repeat(2, minmax(0, 1fr)); }
        @media (max-width: 700px) { .he-grid { grid-template-columns: 1fr; } }
        .he-grid .span-2 { grid-column: 1 / -1; }
        .modalidade-row { display: flex; gap: .75rem; flex-wrap: wrap; }
        .modalidade-row label {
            display: inline-flex; align-items: center; gap: .4rem;
            border: 1px solid var(--border); border-radius: var(--radius-md);
            padding: .55rem .8rem; cursor: pointer; background: var(--canvas);
        }
        .modalidade-row input { accent-color: var(--brand-primary); }
        table.he-table { width: 100%; border-collapse: collapse; font-size: var(--text-sm); }
        table.he-table th, table.he-table td {
            text-align: left; padding: .55rem .4rem; border-bottom: 1px solid var(--border); vertical-align: top;
        }
        .badge {
            display: inline-block; padding: .15rem .5rem; border-radius: 999px;
            font-size: var(--text-xs); font-weight: 700; background: var(--canvas);
        }
        .badge-pendente { color: var(--ink); background: var(--warning-soft); }
        .badge-aprovada { color: var(--success); background: var(--success-soft); }
        .badge-recusada, .badge-cancelada { color: var(--danger); background: var(--danger-soft); }
        .muted { color: var(--ink-muted); }
        .actions { display: flex; gap: .4rem; flex-wrap: wrap; }
    </style>
</head>
<body>
<div class="he-wrap">
    <div class="he-top">
        <div class="he-brand">
            <img src="<?= View::asset('img/logo.png') ?>" alt="Arcadis">
            <div>
                <strong>Autorização de HE</strong>
                <?php if ($user): ?>
                    <span><?= View::e($user['nome']) ?> · <?= View::e($user['matricula']) ?></span>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($user): ?>
        <div class="he-nav">
            <a class="btn btn-ghost" href="/he">Solicitar</a>
            <a class="btn btn-ghost" href="/he/aprovacoes">Aprovações</a>
            <form method="post" action="/he/logout" style="display:inline;">
                <?= \App\Helpers\Csrf::field() ?>
                <button class="btn btn-ghost" type="submit">Sair</button>
            </form>
        </div>
        <?php endif; ?>
    </div>
    <?= $content ?>
</div>
<script src="<?= View::asset('js/app.js') ?>" defer></script>
</body>
</html>
