<?php

use App\Helpers\View;

/** @var string $permissao */
?>
<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Acesso negado</h2>
            <p class="card-subtitle">Seu perfil não permite acessar este recurso</p>
        </div>
    </div>
    <div class="card-body">
        <div class="alert alert-danger">
            Permissão necessária: <code><?= View::e($permissao ?? '') ?></code>
        </div>
        <a class="btn btn-secondary" href="/">Voltar ao início</a>
    </div>
</section>
