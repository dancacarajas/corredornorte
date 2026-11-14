<?php

use App\Helpers\View;

$flash = $flash ?? null;
$error = $error ?? null;
$tarefas = is_array($tarefas ?? null) ? $tarefas : [];
$user = $user ?? [];
$qtd = count($tarefas);
?>

<?php if ($flash): ?>
    <div class="alert alert-success"><?= View::e((string) $flash) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><?= View::e((string) $error) ?></div>
<?php endif; ?>

<section class="portal-home">
    <?php if ($qtd === 0): ?>
        <div class="portal-home-empty">
            <div class="portal-home-empty-badge" aria-hidden="true">✓</div>
            <h2>Nada pendente agora</h2>
            <p>
                Quando o RH liberar um relatório para preencher ou uma folha para assinar,
                a ação aparece aqui com um botão direto.
            </p>
            <div class="portal-home-empty-actions">
                <a class="btn btn-secondary" href="/he/relatorios">Ver relatórios</a>
                <a class="btn btn-ghost" href="/he/folhas">Ver folhas</a>
            </div>
        </div>
    <?php else: ?>
        <header class="portal-home-head">
            <div>
                <p class="portal-home-kicker"><?= $qtd === 1 ? '1 pendência' : $qtd . ' pendências' ?></p>
                <h2>O que você precisa fazer</h2>
                <p class="portal-home-lead">Toque na ação — sem procurar menu.</p>
            </div>
        </header>

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
