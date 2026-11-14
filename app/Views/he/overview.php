<?php

use App\Helpers\View;

$flash = $flash ?? null;
$error = $error ?? null;
$tarefas = is_array($tarefas ?? null) ? $tarefas : [];
$concluidas = is_array($concluidas ?? null) ? $concluidas : [];
$user = $user ?? [];
$qtd = count($tarefas);
$qtdOk = count($concluidas);

$fmtQuando = static function (string $quando): string {
    if ($quando === '') {
        return '';
    }
    $ts = strtotime($quando);
    return $ts ? date('d/m/Y H:i', $ts) : '';
};
?>

<?php if ($flash): ?>
    <div class="alert alert-success"><?= View::e((string) $flash) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><?= View::e((string) $error) ?></div>
<?php endif; ?>

<section class="portal-home">
    <div class="portal-home-summary" aria-label="Resumo">
        <div class="portal-home-summary-item <?= $qtd > 0 ? 'is-warn' : 'is-ok' ?>">
            <span>Pendências</span>
            <strong><?= $qtd ?></strong>
        </div>
        <div class="portal-home-summary-item is-ok">
            <span>Concluídas</span>
            <strong><?= $qtdOk ?></strong>
        </div>
    </div>

    <section class="portal-home-section" aria-labelledby="portalPendTitle">
        <header class="portal-home-head">
            <div>
                <p class="portal-home-kicker" id="portalPendTitle">Para fazer agora</p>
                <h2>O que você precisa fazer</h2>
                <p class="portal-home-lead">
                    <?= $qtd > 0
                        ? 'Toque na ação — sem procurar menu.'
                        : 'Nenhuma ação pendente no momento.' ?>
                </p>
            </div>
        </header>

        <?php if ($qtd === 0): ?>
            <div class="portal-home-empty portal-home-empty-compact">
                <div class="portal-home-empty-badge" aria-hidden="true">✓</div>
                <h3>Fila limpa</h3>
                <p>Quando o RH liberar relatório ou folha, a pendência aparece aqui.</p>
            </div>
        <?php else: ?>
            <div class="portal-task-list" role="list">
                <?php foreach ($tarefas as $tarefa):
                    $tom = (string) ($tarefa['tom'] ?? 'acao');
                    $tipo = (string) ($tarefa['tipo'] ?? '');
                    $tipoLabel = match ($tipo) {
                        'folha' => 'Folha de ponto',
                        'ra_assinar', 'ra_preencher' => 'Relatório de atividades',
                        default => 'Pendência',
                    };
                    ?>
                    <article class="portal-task is-<?= View::e($tom) ?>" role="listitem">
                        <div class="portal-task-body">
                            <div class="portal-task-meta">
                                <span class="portal-task-type"><?= View::e($tipoLabel) ?></span>
                                <span class="portal-task-period"><?= View::e((string) ($tarefa['periodo'] ?? '')) ?></span>
                            </div>
                            <h3 class="portal-task-title"><?= View::e((string) ($tarefa['titulo'] ?? '')) ?></h3>
                            <p class="portal-task-desc"><?= View::e((string) ($tarefa['descricao'] ?? '')) ?></p>
                        </div>
                        <a class="btn <?= $tom === 'urgente' ? 'btn-primary' : 'btn-secondary' ?> portal-task-cta"
                           href="<?= View::e((string) ($tarefa['href'] ?? '#')) ?>">
                            <?= View::e((string) ($tarefa['cta'] ?? 'Abrir')) ?>
                        </a>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="portal-home-section" aria-labelledby="portalDoneTitle">
        <header class="portal-home-head">
            <div>
                <p class="portal-home-kicker is-muted" id="portalDoneTitle">Histórico</p>
                <h2>Já realizado</h2>
                <p class="portal-home-lead">Assinaturas e entregas concluídas neste portal.</p>
            </div>
        </header>

        <?php if ($qtdOk === 0): ?>
            <div class="portal-done-empty">
                Ainda não há itens concluídos. Depois de assinar, eles ficam registrados aqui.
            </div>
        <?php else: ?>
            <div class="portal-done-list" role="list">
                <?php foreach ($concluidas as $item):
                    $tipo = (string) ($item['tipo'] ?? '');
                    $tipoLabel = $tipo === 'folha' ? 'Folha de ponto' : 'Relatório de atividades';
                    $quandoTxt = $fmtQuando((string) ($item['quando'] ?? ''));
                    ?>
                    <article class="portal-done-item" role="listitem">
                        <div class="portal-done-mark" aria-hidden="true">✓</div>
                        <div class="portal-done-body">
                            <div class="portal-task-meta">
                                <span class="portal-task-type is-done"><?= View::e($tipoLabel) ?></span>
                                <span class="portal-task-period"><?= View::e((string) ($item['periodo'] ?? '')) ?></span>
                                <?php if ($quandoTxt !== ''): ?>
                                    <span class="portal-done-when">em <?= View::e($quandoTxt) ?></span>
                                <?php endif; ?>
                            </div>
                            <h3 class="portal-task-title"><?= View::e((string) ($item['titulo'] ?? '')) ?></h3>
                        </div>
                        <a class="btn btn-ghost btn-sm portal-task-cta"
                           href="<?= View::e((string) ($item['href'] ?? '#')) ?>">
                            <?= View::e((string) ($item['cta'] ?? 'Abrir')) ?>
                        </a>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</section>
