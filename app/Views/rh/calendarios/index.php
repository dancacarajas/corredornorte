<?php

use App\Helpers\View;

/** @var array<string,mixed>|null $nacional */
/** @var list<array<string,mixed>> $cidades */
/** @var bool $dbOk */
?>
<?php if (!$dbOk): ?><div class="alert alert-warning">Banco indisponível.</div><?php endif; ?>

<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Feriados nacionais</h2>
            <p class="card-subtitle">Valem para todas as cidades</p>
        </div>
        <?php if ($nacional): ?>
            <a class="btn btn-secondary btn-sm" href="/rh/calendarios/<?= (int) $nacional['id'] ?>">Gerenciar nacionais</a>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <?php if ($nacional): ?>
            <p class="text-sm">
                <strong><?= View::e((string) $nacional['nome']) ?></strong>
                · <?= (int) ($nacional['total_feriados'] ?? 0) ?> feriado(s) cadastrado(s)
            </p>
        <?php else: ?>
            <p class="muted">Nenhum calendário nacional encontrado.</p>
        <?php endif; ?>
    </div>
</section>

<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Feriados por cidade</h2>
            <p class="card-subtitle">Estaduais (UF) + municipais alocados na localidade</p>
        </div>
        <a class="btn btn-primary btn-sm" href="/rh/localidades">Localidades</a>
    </div>
    <?php if ($cidades === []): ?>
        <div class="empty-state">
            <h3>Nenhuma cidade cadastrada</h3>
            <p>Cadastre uma localidade para vincular feriados estaduais e municipais.</p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>Cidade</th>
                    <th>Localidade</th>
                    <th>UF</th>
                    <th>Estaduais</th>
                    <th>Municipais</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($cidades as $c): ?>
                    <tr>
                        <td class="fw-700"><?= View::e((string) $c['municipio']) ?></td>
                        <td><?= View::e((string) $c['nome']) ?></td>
                        <td><span class="pill pill-brand"><?= View::e((string) $c['uf']) ?></span></td>
                        <td><?= (int) $c['feriados_estaduais'] ?></td>
                        <td><?= (int) $c['feriados_municipais'] ?></td>
                        <td>
                            <a class="btn btn-primary btn-sm" href="/rh/localidades/<?= (int) $c['id'] ?>/feriados">
                                Feriados da cidade
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
