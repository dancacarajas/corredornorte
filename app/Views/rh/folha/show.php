<?php

use App\Helpers\View;
use App\Services\FolhaPontoService;
use App\Services\PeriodoPonto;

/** @var array{espelho:array<string,mixed>,dias:list<array<string,mixed>>,periodo:array,recorte:bool,espelhos_periodo:array} $dados */
/** @var list<array<string,mixed>> $documentos */
/** @var list<array<string,mixed>> $envios */
/** @var string $periodoQuery */
$e = $dados['espelho'];
$totais = ['horas_extras_min' => 0, 'horas_falta_min' => 0, 'atraso_bruto_min' => 0, 'adn_min' => 0];
foreach ($dados['dias'] as $dia) {
    foreach ($totais as $campo => $total) {
        $totais[$campo] = $total + (int) ($dia[$campo] ?? 0);
    }
}
?>

<?php if (!empty($flash) && $flash !== 'link_assinatura'): ?><div class="alert alert-success">Operação concluída.</div><?php endif; ?>
<?php if (!empty($error)): ?><div class="alert alert-danger"><?= View::e((string) $error) ?></div><?php endif; ?>
<?php if (!empty($portalUrl)): ?>
    <div class="alert alert-success">
        Link de assinatura gerado. Compartilhe-o somente com o colaborador, pois ele dá acesso à folha.
        <div class="stack-row" style="margin-top:.5rem;">
            <a class="btn btn-primary btn-sm" href="<?= View::e($portalUrl) ?>" target="_blank" rel="noopener noreferrer">Abrir portal do colaborador</a>
            <input class="form-control" aria-label="Link de assinatura" style="max-width:480px;" readonly value="<?= View::e($portalUrl) ?>" onclick="this.select()">
        </div>
    </div>
<?php endif; ?>

<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Pré-visualização</h2>
            <p class="card-subtitle">
                <?= View::e($e['nome']) ?> · Mat. <?= View::e((string) $e['matricula']) ?> ·
                <?= View::e(($e['contrato_codigo'] ?? '') . ' / ' . ($e['cc_codigo'] ?? '') . ' / ' . ($e['os_codigo'] ?? '')) ?>
                · <?= View::e(PeriodoPonto::rotulo($dados['periodo'])) ?>
            </p>
        </div>
        <span class="pill pill-info"><?= $dados['recorte'] ? count($dados['espelhos_periodo']) . ' competência(s)' : View::e((string) $e['status']) ?></span>
    </div>
    <div class="card-body">
        <div class="text-sm muted">
            Totais do período — HE: <?= $totais['horas_extras_min'] ?> min · Faltas: <?= $totais['horas_falta_min'] ?> · Atrasos: <?= $totais['atraso_bruto_min'] ?> · ADN: <?= $totais['adn_min'] ?>
        </div>
        <div class="text-sm" style="margin-top:.75rem;">
            Dias no espelho: <strong><?= count($dados['dias']) ?></strong>
        </div>
    </div>
</section>

<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">PDFs / versões</h2>
            <p class="card-subtitle">Código de verificação único por documento</p>
        </div>
    </div>
    <?php if ($documentos === []): ?>
        <div class="empty-state"><p>Nenhum PDF gerado ainda.</p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>Documento</th>
                    <th>Status</th>
                    <th>Código</th>
                    <th>Ações</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($documentos as $doc):
                    $modeloAtual = (int) ($doc['layout_pdf'] ?? 0) === FolhaPontoService::LAYOUT_PDF_ATUAL;
                    $pendente = in_array($doc['status'], ['gerado', 'enviado'], true);
                    $seloDigital = !empty($doc['arquivo_assinado_sha256'])
                        && (!empty($doc['assinatura_texto_aceite']) || !empty($doc['assinatura_email_confirmado_em']));
                    $status = $pendente && !$modeloAtual ? 'Modelo anterior' : match ($doc['status']) {
                        'assinado' => $seloDigital ? 'Assinado e selado' : 'Registro anterior',
                        'enviado' => 'Aguardando assinatura',
                        'invalidado' => 'Invalidado',
                        default => 'PDF gerado',
                    };
                ?>
                    <tr>
                        <td>#<?= (int) $doc['id'] ?> · <?= View::e((string) $doc['created_at']) ?></td>
                        <td><span class="pill <?= $doc['status'] === 'assinado' ? 'pill-success' : 'pill-neutral' ?>"><?= View::e($status) ?></span></td>
                        <td class="text-xs"><?= View::e((string) $doc['codigo_verificacao']) ?></td>
                        <td style="display:flex;gap:.4rem;flex-wrap:wrap;align-items:center;">
                            <a class="btn btn-secondary btn-sm" href="/rh/folha/documento/<?= (int) $doc['id'] ?>"><?= $modeloAtual ? 'PDF original' : 'PDF anterior' ?></a>
                            <?php if (!empty($doc['arquivo_assinado_path'])): ?>
                                <a class="btn btn-secondary btn-sm" href="/rh/folha/documento/<?= (int) $doc['id'] ?>?versao=assinada">
                                    <?= $seloDigital ? 'PDF assinado' : 'PDF histórico' ?>
                                </a>
                            <?php endif; ?>
                            <a class="btn btn-ghost btn-sm" href="/verificar/<?= View::e((string)$doc['codigo_verificacao']) ?>">Validar</a>
                            <?php if ($pendente): ?>
                            <form method="post" action="/rh/folha/<?= (int) $doc['espelho_id'] ?>/enviar?<?= View::e($periodoQuery) ?>">
                                <input type="hidden" name="documento_id" value="<?= (int) $doc['id'] ?>">
                                <button class="btn btn-primary btn-sm" type="submit"><?= $modeloAtual ? 'Enviar PDF' : 'Atualizar e enviar PDF' ?></button>
                            </form>
                            <form method="post" action="/rh/folha/<?= (int) $doc['espelho_id'] ?>/link-assinatura?<?= View::e($periodoQuery) ?>">
                                <input type="hidden" name="documento_id" value="<?= (int) $doc['id'] ?>">
                                <button class="btn btn-secondary btn-sm" type="submit"><?= $modeloAtual ? 'Gerar link assinatura' : 'Atualizar e gerar link' ?></button>
                            </form>
                            <?php endif; ?>
                            <?php if (($doc['status'] === 'assinado' || ($pendente && $modeloAtual)) && !empty($doc['portal_token'])): ?>
                                <a class="btn btn-ghost btn-sm" href="/assinar/<?= View::e((string) $doc['portal_token']) ?>" target="_blank" rel="noopener noreferrer">Portal</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Controle de envio</h2>
            <p class="card-subtitle">Histórico de tentativas</p>
        </div>
    </div>
    <?php if ($envios === []): ?>
        <div class="empty-state"><p>Nenhum envio registrado.</p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>Destinatário</th>
                    <th>Status</th>
                    <th>Tentativa</th>
                    <th>Quando</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($envios as $env): ?>
                    <?php
                    $pill = match ((string) $env['status']) {
                        'enviado' => 'pill-success',
                        'falha' => 'pill-danger',
                        default => 'pill-warning',
                    };
                    ?>
                    <tr>
                        <td><?= View::e((string) $env['destinatario']) ?></td>
                        <td><span class="pill <?= $pill ?>"><?= View::e((string) $env['status']) ?></span></td>
                        <td><?= (int) $env['tentativa'] ?></td>
                        <td><?= View::e((string) ($env['enviado_em'] ?? $env['created_at'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
