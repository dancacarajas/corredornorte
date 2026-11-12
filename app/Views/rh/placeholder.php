<?php use App\Helpers\View; ?>

<section class="card">
    <div class="empty-state">
        <h3><?= View::e($moduleTitle ?? 'Módulo') ?></h3>
        <p><?= View::e($moduleMessage ?? 'Em construção.') ?></p>
        <div style="margin-top:1rem;">
            <a class="btn btn-secondary" href="/ui/showcase">Abrir Design System</a>
        </div>
    </div>
</section>
