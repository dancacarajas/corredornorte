<?php

use App\Helpers\View;

$flash = $flash ?? null;
$error = $error ?? null;
$relatoriosAbertos = (int) ($relatoriosAbertos ?? 0);
$user = $user ?? [];
?>

<?php if ($flash): ?>
    <div class="alert alert-success"><?= View::e((string) $flash) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><?= View::e((string) $error) ?></div>
<?php endif; ?>

<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Olá, <?= View::e((string) ($user['nome'] ?? '')) ?></h2>
            <p class="card-subtitle">Preencha o Relatório de Atividades do período do seu cartão de ponto.</p>
        </div>
    </div>
    <div class="card-body">
        <div class="portal-kpi-grid">
            <article class="portal-kpi">
                <span>Relatórios em rascunho</span>
                <strong><?= $relatoriosAbertos ?></strong>
            </article>
        </div>
        <div style="display:flex;gap:.75rem;flex-wrap:wrap;margin-top:1.25rem;">
            <a class="btn btn-primary" href="/he/relatorios">Relatório de Atividades</a>
        </div>
    </div>
</section>
