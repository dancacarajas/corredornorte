<?php

use App\Helpers\View;

/** @var array<string,mixed> $localidade */
/** @var array<string,mixed> $nacional */
/** @var array<string,mixed> $estadual */
/** @var array<string,mixed> $municipal */
/** @var list<array<string,mixed>> $feriadosNacional */
/** @var list<array<string,mixed>> $feriadosEstadual */
/** @var list<array<string,mixed>> $feriadosMunicipal */

$locId = (int) $localidade['id'];
$sections = [
    [
        'escopo' => 'nacional',
        'titulo' => 'Feriados nacionais',
        'subtitulo' => 'Válidos em todo o Brasil · compartilhados',
        'calendario' => $nacional,
        'feriados' => $feriadosNacional,
        'pill' => 'pill-info',
    ],
    [
        'escopo' => 'estadual',
        'titulo' => 'Feriados estaduais · ' . strtoupper((string) $localidade['uf']),
        'subtitulo' => 'Alocados ao estado da cidade',
        'calendario' => $estadual,
        'feriados' => $feriadosEstadual,
        'pill' => 'pill-brand',
    ],
    [
        'escopo' => 'municipal',
        'titulo' => 'Feriados municipais · ' . $localidade['municipio'],
        'subtitulo' => 'Alocados à localidade ' . $localidade['nome'],
        'calendario' => $municipal,
        'feriados' => $feriadosMunicipal,
        'pill' => 'pill-neutral',
    ],
];
?>

<?php if (!empty($flash)): ?><div class="alert alert-success">Feriado atualizado.</div><?php endif; ?>
<?php if (!empty($error)): ?><div class="alert alert-danger"><?= View::e((string) $error) ?></div><?php endif; ?>

<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title"><?= View::e((string) $localidade['municipio']) ?>/<?= View::e((string) $localidade['uf']) ?></h2>
            <p class="card-subtitle"><?= View::e((string) $localidade['nome']) ?> — composição usada no ponto</p>
        </div>
        <div style="display:flex;gap:.5rem;">
            <a class="btn btn-secondary btn-sm" href="/rh/localidades/<?= $locId ?>/editar">Editar localidade</a>
            <a class="btn btn-secondary btn-sm" href="/rh/calendarios">Voltar</a>
        </div>
    </div>
</section>

<?php foreach ($sections as $sec): ?>
    <section class="card">
        <div class="card-header">
            <div>
                <h2 class="card-title">
                    <?= View::e($sec['titulo']) ?>
                    <span class="pill <?= View::e($sec['pill']) ?>" style="margin-left:.4rem;"><?= View::e($sec['escopo']) ?></span>
                </h2>
                <p class="card-subtitle"><?= View::e($sec['subtitulo']) ?></p>
            </div>
        </div>

        <form method="post" action="/rh/localidades/<?= $locId ?>/feriados" class="card-body form-grid cols-4">
            <input type="hidden" name="escopo" value="<?= View::e($sec['escopo']) ?>">
            <div class="form-field">
                <label class="form-label">Data</label>
                <input class="form-control" type="date" name="data" required>
            </div>
            <div class="form-field">
                <label class="form-label">Nome</label>
                <input class="form-control" name="nome" required placeholder="Nome do feriado">
            </div>
            <div class="form-field">
                <label class="form-label">Tipo</label>
                <select class="form-control" name="tipo">
                    <option value="feriado">Feriado</option>
                    <option value="ponto_facultativo">Ponto facultativo</option>
                    <option value="dia_especial">Dia especial</option>
                </select>
            </div>
            <div class="form-field">
                <label class="form-label">&nbsp;</label>
                <label class="text-sm" style="display:block;margin-bottom:.35rem;">
                    <input type="checkbox" name="recorrente_ano" value="1" checked> Recorrente no ano
                </label>
                <button class="btn btn-primary btn-sm" type="submit">Adicionar <?= View::e($sec['escopo']) ?></button>
            </div>
        </form>

        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>Data</th>
                    <th>Nome</th>
                    <th>Tipo</th>
                    <th>Recorrente</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php if ($sec['feriados'] === []): ?>
                    <tr><td colspan="5" class="muted">Nenhum feriado <?= View::e($sec['escopo']) ?> cadastrado.</td></tr>
                <?php else: ?>
                    <?php foreach ($sec['feriados'] as $f): ?>
                        <tr>
                            <td><?= View::e((string) $f['data']) ?></td>
                            <td class="fw-700"><?= View::e((string) $f['nome']) ?></td>
                            <td><span class="pill pill-info"><?= View::e((string) $f['tipo']) ?></span></td>
                            <td><?= !empty($f['recorrente_ano']) ? 'Sim' : 'Não' ?></td>
                            <td>
                                <form method="post"
                                      action="/rh/localidades/<?= $locId ?>/feriados/<?= (int) $f['id'] ?>/excluir"
                                      data-confirm-title="Remover feriado?"
                                      data-confirm="Confirma a remoção deste feriado?"
                                      data-confirm-ok="Remover"
                                      data-confirm-danger>
                                    <button class="btn btn-ghost btn-sm" type="submit">Excluir</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endforeach; ?>
