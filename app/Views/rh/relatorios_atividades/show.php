<?php

use App\Helpers\View;

$dados = $dados ?? ['relatorio' => [], 'dias' => [], 'contadores' => []];
$rel = $dados['relatorio'];
$dias = $dados['dias'];
$cont = $dados['contadores'];
$flash = $flash ?? null;
$error = $error ?? null;
$status = (string) ($rel['status'] ?? '');
$heCliente = (($rel['he_fluxo'] ?? 'off') === 'cliente');
$ini = (string) ($rel['data_inicio'] ?? '');
$fim = (string) ($rel['data_fim'] ?? '');
$periodoLabel = ($ini && $fim)
    ? date('d/m/Y', strtotime($ini)) . ' a ' . date('d/m/Y', strtotime($fim))
    : '—';

$vazio = static fn(?string $v): string => ($v !== null && trim($v) !== '') ? $v : '—';
$junta = static function (?string $codigo, ?string $nome) use ($vazio): string {
    $codigo = trim((string) $codigo);
    $nome = trim((string) $nome);
    if ($codigo !== '' && $nome !== '') {
        return $codigo . ' — ' . $nome;
    }
    return $vazio($codigo !== '' ? $codigo : ($nome !== '' ? $nome : null));
};
$ambLabel = static fn(?string $a): string => match ((string) $a) {
    'remoto' => 'remoto',
    'site' => 'site',
    'viagem' => 'viagem',
    default => '—',
};
?>

<?php if ($flash): ?><div class="alert alert-success"><?= View::e((string) $flash) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= View::e((string) $error) ?></div><?php endif; ?>

<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title"><?= View::e((string) ($rel['nome'] ?? '')) ?></h2>
            <p class="card-subtitle">
                <?= View::e((string) ($rel['matricula'] ?? '')) ?>
                · <?= View::e((string) ($rel['funcao'] ?? $rel['cargo'] ?? '—')) ?>
                · Status: <?= View::e($status !== '' ? $status : '—') ?>
                · Período: <?= View::e($periodoLabel) ?>
            </p>
        </div>
        <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
            <a class="btn btn-ghost btn-sm" href="/rh/relatorios-atividades">Voltar</a>
            <a class="btn btn-secondary btn-sm" href="/rh/relatorios-atividades/<?= (int) $rel['id'] ?>/pdf" target="_blank" rel="noopener">Exportar PDF</a>
            <a class="btn btn-secondary btn-sm" href="/rh/relatorios-atividades/<?= (int) $rel['id'] ?>/excel">Exportar Excel</a>
            <?php if ($status === 'enviado'): ?>
                <form method="post" action="/rh/relatorios-atividades/<?= (int) $rel['id'] ?>/aprovar">
                    <button class="btn btn-primary btn-sm" type="submit">Aprovar</button>
                </form>
            <?php endif; ?>
            <?php if (in_array($status, ['enviado', 'aprovado'], true)): ?>
                <form method="post" action="/rh/relatorios-atividades/<?= (int) $rel['id'] ?>/reabrir">
                    <button class="btn btn-secondary btn-sm" type="submit">Reabrir</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="card-body section-stack">
        <div class="form-grid cols-2 ra-meta-grid">
            <div class="form-field">
                <label class="form-label">Contrato</label>
                <input class="form-control" readonly value="<?= View::e($junta($rel['contrato_codigo'] ?? null, $rel['contrato_nome'] ?? null)) ?>">
            </div>
            <div class="form-field">
                <label class="form-label">OS / Frente</label>
                <input class="form-control" readonly value="<?= View::e($junta($rel['os_codigo'] ?? null, $rel['os_nome'] ?? null)) ?>">
            </div>
            <div class="form-field">
                <label class="form-label">Gestor Arcadis</label>
                <input class="form-control" readonly value="<?= View::e($vazio($rel['gestor_nome'] ?? null)) ?>">
            </div>
            <div class="form-field">
                <label class="form-label">Resp. / Fiscal Vale</label>
                <input class="form-control" readonly value="<?= View::e($vazio($rel['fiscal_vale'] ?? null)) ?>">
            </div>
            <?php if ($heCliente && !empty($rel['gestor_cliente_nome'])): ?>
                <div class="form-field">
                    <label class="form-label">Gestor Cliente</label>
                    <input class="form-control" readonly value="<?= View::e((string) $rel['gestor_cliente_nome']) ?>">
                </div>
            <?php endif; ?>
        </div>

        <div class="ra-counters">
            <span class="pill">Remoto (1): <?= (int) ($cont['remoto'] ?? 0) ?></span>
            <span class="pill">Site (2): <?= (int) ($cont['site'] ?? 0) ?></span>
            <span class="pill">Viagem (3): <?= (int) ($cont['viagem'] ?? 0) ?></span>
            <span class="pill">Área classificada: <?= (int) ($cont['classificada'] ?? 0) ?></span>
        </div>
        <p class="text-sm muted" style="margin:0;">
            (1) Remoto/home/sem refeitório Vale — VR contratada ·
            (2) Site Vale com refeitório ·
            (3) Viagem autorizada Vale
        </p>

        <div class="ra-table-wrap">
            <table class="ra-table">
                <thead>
                <tr>
                    <th>Data</th>
                    <th>Descrição</th>
                    <th>Área</th>
                    <th>Município</th>
                    <th>Projeto</th>
                    <th>CC</th>
                    <th>Classif.</th>
                    <th>Ambiente</th>
                    <th>Obs.</th>
                    <?php if ($heCliente): ?>
                        <th>HE início</th>
                        <th>HE fim</th>
                        <th>HE</th>
                    <?php endif; ?>
                </tr>
                </thead>
                <tbody>
                <?php if ($dias === []): ?>
                    <tr>
                        <td colspan="<?= $heCliente ? 12 : 9 ?>" class="muted">Nenhum dia neste relatório.</td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($dias as $dia):
                    $tipo = (string) ($dia['tipo_dia'] ?? 'trabalho');
                    $data = (string) ($dia['data'] ?? '');
                    $heStatus = (string) ($dia['he_status'] ?? 'nenhuma');
                    $heIni = substr((string) ($dia['he_hora_inicio'] ?? ''), 0, 5);
                    $heFim = substr((string) ($dia['he_hora_fim'] ?? ''), 0, 5);
                    $rowClass = $tipo !== 'trabalho' && $tipo !== '' ? 'ra-day-folga' : '';
                    ?>
                    <tr class="<?= $rowClass ?>">
                        <td>
                            <strong><?= View::e($data !== '' ? date('d/m/Y', strtotime($data)) : '—') ?></strong>
                            <?php if ($tipo !== '' && $tipo !== 'trabalho'): ?>
                                <div class="muted text-sm"><?= View::e($tipo) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?= View::e($vazio($dia['descricao'] ?? null)) ?></td>
                        <td><?= View::e($vazio($dia['area_atendimento'] ?? null)) ?></td>
                        <td><?= View::e($vazio($dia['municipio'] ?? null)) ?></td>
                        <td><?= View::e($vazio($dia['projeto_obra'] ?? null)) ?></td>
                        <td><?= View::e($vazio($dia['centro_custo'] ?? null)) ?></td>
                        <td><?= !empty($dia['area_classificada']) ? 'X' : '—' ?></td>
                        <td><?= View::e($ambLabel($dia['ambiente'] ?? null)) ?></td>
                        <td><?= View::e($vazio($dia['observacoes'] ?? null)) ?></td>
                        <?php if ($heCliente): ?>
                            <td><?= View::e($heIni !== '' ? $heIni : '—') ?></td>
                            <td><?= View::e($heFim !== '' ? $heFim : '—') ?></td>
                            <td class="muted text-sm">
                                <?= View::e(match ($heStatus) {
                                    'declarada' => 'Declarada',
                                    'aplicada' => 'No ponto',
                                    'divergente' => 'Divergente',
                                    default => '—',
                                }) ?>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
