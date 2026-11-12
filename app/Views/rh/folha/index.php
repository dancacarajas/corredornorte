<?php

use App\Helpers\View;
use App\Services\FolhaPontoService;
use App\Services\PeriodoPonto;

/** @var bool $dbOk */
/** @var list<array<string,mixed>> $docs */
?>

<?php if (!$dbOk): ?>
    <div class="alert alert-warning">Banco indisponível.</div>
<?php endif; ?>

<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Documentos gerados</h2>
            <p class="card-subtitle">Controle de PDF, envio e assinatura</p>
        </div>
        <a class="btn btn-secondary btn-sm" href="/rh/ponto">Ir para espelhos</a>
    </div>
    <?php if ($docs === []): ?>
        <div class="empty-state">
            <h3>Nenhuma folha gerada</h3>
            <p>Feche um espelho de ponto e gere o PDF pela ficha da folha.</p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>Colaborador</th>
                    <th>Período</th>
                    <th>Status doc</th>
                    <th>Verificação</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($docs as $d):
                    $modeloAnterior = (int) ($d['layout_pdf'] ?? 0) !== FolhaPontoService::LAYOUT_PDF_ATUAL;
                    $pendente = in_array($d['status'], ['gerado', 'enviado'], true);
                    $periodo = PeriodoPonto::selecionar($d['periodo_inicio'], $d['periodo_fim'], (int) $d['ano'], (int) $d['mes']);
                ?>
                    <tr>
                        <td>
                            <div class="fw-700"><?= View::e((string) $d['nome']) ?></div>
                            <div class="text-xs muted">Mat. <?= View::e((string) $d['matricula']) ?></div>
                        </td>
                        <td><?= View::e(PeriodoPonto::rotulo($periodo)) ?></td>
                        <td><span class="pill pill-brand"><?= $pendente && $modeloAnterior ? 'Modelo anterior' : View::e((string) $d['status']) ?></span></td>
                        <td class="text-xs"><?= View::e(substr((string) $d['codigo_verificacao'], 0, 12)) ?>…</td>
                        <td>
                            <a class="btn btn-ghost btn-sm" href="/rh/folha/<?= (int) $d['espelho_id'] ?>?<?= View::e(PeriodoPonto::consulta($periodo)) ?>">Abrir</a>
                            <a class="btn btn-secondary btn-sm" href="/rh/folha/documento/<?= (int) $d['id'] ?>"><?= $modeloAnterior ? 'PDF anterior' : 'PDF' ?></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
