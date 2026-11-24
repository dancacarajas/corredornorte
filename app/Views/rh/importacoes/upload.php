<?php

use App\Helpers\View;

/** @var bool $dbOk */
/** @var ?string $error */
?>

<?php if (!$dbOk): ?>
    <div class="alert alert-warning">Configure o MySQL antes de importar.</div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><?= View::e($error) ?></div>
<?php endif; ?>

<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Upload da planilha mestre</h2>
            <p class="card-subtitle">O sistema só grava após você confirmar a prévia</p>
        </div>
    </div>
    <form class="card-body section-stack" method="post" action="/rh/importacoes" enctype="multipart/form-data">
        <div class="alert alert-brand">
            Use o <strong>modelo matriz padrão</strong> para evitar erro de coluna.
            <a class="btn btn-secondary btn-sm" href="/rh/importacoes/modelo" style="margin-left:.5rem;">Baixar modelo matriz</a>
        </div>
        <div class="form-field">
            <label class="form-label">Arquivo Excel</label>
            <input class="form-control" type="file" name="arquivo" accept=".xlsx,.xls,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel" required <?= $dbOk ? '' : 'disabled' ?>>
            <div class="form-hint">Use o modelo matriz completo (todos os campos do cadastro de colaborador).</div>
        </div>
        <div class="stack-row">
            <a class="btn btn-secondary" href="/rh/importacoes">Cancelar</a>
            <a class="btn btn-ghost" href="/rh/importacoes/modelo">Baixar modelo</a>
            <button class="btn btn-primary" type="submit" <?= $dbOk ? '' : 'disabled' ?>>Ler e validar</button>
        </div>
    </form>
</section>
