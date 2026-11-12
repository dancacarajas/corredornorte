<?php use App\Helpers\View; /** @var array $calendario */ /** @var list<array> $feriados */ ?>
<?php if (!empty($flash)): ?><div class="alert alert-success">Atualizado.</div><?php endif; ?>
<?php if (!empty($error)): ?><div class="alert alert-danger"><?= View::e((string)$error) ?></div><?php endif; ?>

<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title"><?= View::e((string)$calendario['nome']) ?></h2>
            <p class="card-subtitle">
                <?= View::e((string)$calendario['codigo']) ?> · <?= View::e((string)$calendario['escopo']) ?>
                <?php if (!empty($calendario['uf'])): ?> · UF <?= View::e((string)$calendario['uf']) ?><?php endif; ?>
                <?php if (!empty($calendario['municipio'])): ?>
                    · <?= View::e((string)$calendario['municipio']) ?>/<?= View::e((string)($calendario['localidade_uf'] ?? $calendario['uf'] ?? '')) ?>
                <?php endif; ?>
            </p>
        </div>
        <a class="btn btn-secondary btn-sm" href="/rh/calendarios">Voltar</a>
    </div>
</section>

<section class="card">
    <div class="card-header"><h2 class="card-title">Feriados / dias especiais</h2></div>
    <form method="post" action="/rh/calendarios/<?= (int)$calendario['id'] ?>/feriados" class="card-body form-grid cols-4">
        <div class="form-field"><label class="form-label">Data</label><input class="form-control" type="date" name="data" required></div>
        <div class="form-field"><label class="form-label">Nome</label><input class="form-control" name="nome" required></div>
        <div class="form-field"><label class="form-label">Tipo</label>
            <select class="form-control" name="tipo">
                <option value="feriado">Feriado</option>
                <option value="ponto_facultativo">Ponto facultativo</option>
                <option value="dia_especial">Dia especial</option>
            </select>
        </div>
        <div class="form-field"><label class="form-label">&nbsp;</label>
            <label class="text-sm"><input type="checkbox" name="recorrente_ano" value="1"> Recorrente no ano</label>
            <button class="btn btn-primary btn-sm" type="submit" style="margin-left:.5rem;">Adicionar</button>
        </div>
    </form>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Data</th><th>Nome</th><th>Tipo</th><th>Recorrente</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($feriados as $f): ?>
                <tr>
                    <td><?= View::e((string)$f['data']) ?></td>
                    <td class="fw-700"><?= View::e((string)$f['nome']) ?></td>
                    <td><span class="pill pill-info"><?= View::e((string)$f['tipo']) ?></span></td>
                    <td><?= !empty($f['recorrente_ano']) ? 'Sim' : 'Não' ?></td>
                    <td>
                        <form method="post" action="/rh/calendarios/<?= (int)$calendario['id'] ?>/feriados/<?= (int)$f['id'] ?>/excluir" onsubmit="return confirm('Remover?')">
                            <button class="btn btn-ghost btn-sm" type="submit">Excluir</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
