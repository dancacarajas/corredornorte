<?php

use App\Helpers\View;

/** @var array<string,mixed> $import */
/** @var list<array<string,mixed>> $linhas */
?>

<section class="kpi-grid">
    <article class="card kpi-card">
        <div class="kpi-label">Registros</div>
        <div class="kpi-value"><?= (int) $import['total_registros'] ?></div>
        <div class="kpi-meta neutral">Lidos na planilha</div>
    </article>
    <article class="card kpi-card">
        <div class="kpi-label">Sem alteração</div>
        <div class="kpi-value"><?= (int) $import['total_iguais'] ?></div>
        <div class="kpi-meta up">Iguais ao banco</div>
    </article>
    <article class="card kpi-card">
        <div class="kpi-label">Atualizar / Novos</div>
        <div class="kpi-value"><?= (int) $import['total_atualizar'] ?> / <?= (int) $import['total_criar'] ?></div>
        <div class="kpi-meta neutral">Pendentes de confirmação</div>
    </article>
    <article class="card kpi-card">
        <div class="kpi-label">Inconsistências</div>
        <div class="kpi-value"><?= (int) $import['total_erros'] ?></div>
        <div class="kpi-meta down">Revisar antes de confirmar</div>
    </article>
</section>

<?php if (!empty($_GET['ok'])): ?>
    <div class="alert alert-success">Importação confirmada. <?= (int) $_GET['ok'] ?> alteração(ões) aplicadas.</div>
<?php endif; ?>
<?php if (!empty($_GET['erro'])): ?>
    <div class="alert alert-danger"><?= View::e((string) $_GET['erro']) ?></div>
<?php endif; ?>

<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Prévia de diferenças</h2>
            <p class="card-subtitle">Revise e confirme para gravar no efetivo</p>
        </div>
        <div style="display:flex;gap:.5rem;align-items:center;">
            <span class="pill pill-warning"><?= View::e((string) $import['status']) ?></span>
            <?php if (($import['status'] ?? '') !== 'confirmado'): ?>
                <form method="post" action="/rh/importacoes/<?= (int) $import['id'] ?>/confirmar">
                    <button class="btn btn-primary btn-sm" type="submit">Confirmar atualização</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($linhas === []): ?>
        <div class="empty-state">
            <h3>Nenhuma diferença relevante</h3>
            <p>Todos os registros estão iguais ou a planilha não trouxe alterações.</p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>Linha</th>
                    <th>Chave</th>
                    <th>Ação</th>
                    <th>Diff</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($linhas as $linha): ?>
                    <?php
                    $diff = json_decode((string) ($linha['diff_json'] ?? '{}'), true) ?: [];
                    $acao = (string) $linha['acao'];
                    $pill = match ($acao) {
                        'criar' => 'pill-success',
                        'atualizar' => 'pill-info',
                        'erro' => 'pill-danger',
                        default => 'pill-neutral',
                    };
                    ?>
                    <tr>
                        <td><?= (int) $linha['linha'] ?></td>
                        <td class="fw-700"><?= View::e((string) $linha['chave']) ?></td>
                        <td><span class="pill <?= $pill ?>"><?= View::e($acao) ?></span></td>
                        <td class="text-sm">
                            <?php if ($diff === []): ?>
                                —
                            <?php else: ?>
                                <?php foreach ($diff as $campo => $valores): ?>
                                    <div>
                                        <strong><?= View::e((string) $campo) ?></strong>:
                                        <?php if (is_array($valores) && isset($valores['anterior'], $valores['novo'])): ?>
                                            <?= View::e((string) $valores['anterior']) ?> → <?= View::e((string) $valores['novo']) ?>
                                        <?php elseif (is_array($valores) && isset($valores['novo'])): ?>
                                            <?= View::e((string) $valores['novo']) ?>
                                        <?php else: ?>
                                            <?= View::e(json_encode($valores, JSON_UNESCAPED_UNICODE) ?: '') ?>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
