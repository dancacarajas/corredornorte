<?php

use App\Helpers\View;

/** @var bool $dbOk */
/** @var array $kpis */
/** @var list<array<string,mixed>> $rows */
/** @var array $filters */
/** @var array $lookups */
/** @var string $exportQuery */
$exportQuery = $exportQuery ?? '';
$exportHref = '/rh/efetivo/exportar' . ($exportQuery !== '' ? '?' . $exportQuery : '');
?>

<?php if (!$dbOk): ?>
    <div class="alert alert-warning">MySQL indisponível.</div>
<?php endif; ?>

<section class="kpi-grid">
    <article class="card kpi-card"><div class="kpi-label">Ativos</div><div class="kpi-value"><?= (int)$kpis['ativos'] ?></div><div class="kpi-meta neutral">Colaboradores</div></article>
    <article class="card kpi-card"><div class="kpi-label">Contratos</div><div class="kpi-value"><?= (int)$kpis['contratos'] ?></div><div class="kpi-meta neutral">Hierarquia</div></article>
    <article class="card kpi-card"><div class="kpi-label">Centros de custo</div><div class="kpi-value"><?= (int)$kpis['centros'] ?></div><div class="kpi-meta neutral">Em operação</div></article>
    <article class="card kpi-card"><div class="kpi-label">Localidades</div><div class="kpi-value"><?= (int)$kpis['localidades'] ?></div><div class="kpi-meta neutral">Calendários locais</div></article>
</section>

<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Importação / exportação</h2>
            <p class="card-subtitle">Mesmo modelo matriz padrão (.xlsx) para baixar em branco, exportar o efetivo ou reimportar</p>
        </div>
    </div>
    <div class="card-body" style="display:flex;gap:.6rem;flex-wrap:wrap;align-items:center;">
        <a class="btn btn-primary" href="<?= View::e($exportHref) ?>">Exportar efetivo (.xlsx)</a>
        <a class="btn btn-secondary" href="/rh/importacoes/modelo">Baixar modelo matriz</a>
        <a class="btn btn-secondary" href="/rh/importacoes/nova">Importar planilha</a>
        <span class="text-sm muted">Matriz completa do cadastro: Empresa, Matrícula, CPF, Nome, e-mails, Telefone, Admissão, Cargo, Função, Status, Gestor, Supervisor, Lotação, CC, OS, Localidade</span>
    </div>
</section>

<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Cadastros do Efetivo</h2>
            <p class="card-subtitle">Hierarquia Contrato → CC → OS + regras operacionais</p>
        </div>
    </div>
    <div class="card-body" style="display:flex;gap:.6rem;flex-wrap:wrap;">
        <a class="btn btn-secondary btn-sm" href="/rh/empresas">Empresas</a>
        <a class="btn btn-secondary btn-sm" href="/rh/contratos">Contratos</a>
        <a class="btn btn-secondary btn-sm" href="/rh/centros-custo">Centros de Custo</a>
        <a class="btn btn-secondary btn-sm" href="/rh/ordens-servico">OS</a>
        <a class="btn btn-secondary btn-sm" href="/rh/localidades">Localidades</a>
        <a class="btn btn-secondary btn-sm" href="/rh/jornadas">Jornadas</a>
        <a class="btn btn-secondary btn-sm" href="/rh/escalas">Escalas</a>
        <a class="btn btn-secondary btn-sm" href="/rh/calendarios">Feriados</a>
        <a class="btn btn-primary btn-sm" href="/rh/lotacoes">Lotações</a>
    </div>
</section>

<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Consulta do efetivo</h2>
            <p class="card-subtitle">Combine Contrato + CC + OS + Localidade</p>
        </div>
    </div>
    <form method="get" action="/rh/efetivo" class="card-body form-grid cols-3">
        <div class="form-field"><label class="form-label">Busca</label><input class="form-control" name="q" value="<?= View::e($filters['q']) ?>" placeholder="Nome, matrícula, CPF"></div>
        <div class="form-field"><label class="form-label">Contrato</label>
            <select class="form-control" name="contrato_id"><option value="0">Todos</option>
                <?php foreach ($lookups['contratos'] as $o): ?>
                    <option value="<?= (int)$o['id'] ?>" <?= $filters['contrato_id']===(int)$o['id']?'selected':'' ?>><?= View::e($o['codigo'].' — '.$o['nome']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-field"><label class="form-label">Centro de custo</label>
            <select class="form-control" name="centro_custo_id"><option value="0">Todos</option>
                <?php foreach ($lookups['centros'] as $o): ?>
                    <option value="<?= (int)$o['id'] ?>" <?= $filters['centro_custo_id']===(int)$o['id']?'selected':'' ?>><?= View::e($o['codigo'].' — '.$o['nome']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-field"><label class="form-label">OS</label>
            <select class="form-control" name="ordem_servico_id"><option value="0">Todas</option>
                <?php foreach ($lookups['oss'] as $o): ?>
                    <option value="<?= (int)$o['id'] ?>" <?= $filters['ordem_servico_id']===(int)$o['id']?'selected':'' ?>><?= View::e($o['codigo'].' — '.$o['nome']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-field"><label class="form-label">Localidade</label>
            <select class="form-control" name="localidade_id"><option value="0">Todas</option>
                <?php foreach ($lookups['localidades'] as $o): ?>
                    <option value="<?= (int)$o['id'] ?>" <?= $filters['localidade_id']===(int)$o['id']?'selected':'' ?>><?= View::e($o['municipio'].'/'.$o['uf']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-field"><label class="form-label">Status</label>
            <select class="form-control" name="status">
                <option value="">Todos</option>
                <?php foreach (['ativo','afastado','inativo','desligado'] as $st): ?>
                    <option value="<?= $st ?>" <?= $filters['status']===$st?'selected':'' ?>><?= ucfirst($st) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="stack-row" style="grid-column:1/-1;">
            <button class="btn btn-primary" type="submit">Filtrar</button>
            <a class="btn btn-secondary" href="/rh/efetivo">Limpar</a>
            <span class="text-sm muted"><?= count($rows) ?> resultado(s)</span>
        </div>
    </form>
</section>

<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Colaboradores</h2>
            <p class="card-subtitle">Lotação vigente herdada — transferência altera vigência</p>
        </div>
    </div>
    <?php if ($rows === []): ?>
        <div class="empty-state"><h3>Nenhum colaborador encontrado</h3></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>Colaborador</th><th>Contrato</th><th>CC / OS</th><th>Localidade</th><th>Jornada/Escala</th><th>Status</th><th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td>
                            <div class="fw-700"><?= View::e((string)$row['nome']) ?></div>
                            <div class="text-xs muted">Mat. <?= View::e((string)$row['matricula']) ?> · <?= View::e((string)($row['cargo'] ?? '')) ?></div>
                        </td>
                        <td><?= View::e((string)($row['contrato_codigo'] ?? '—')) ?></td>
                        <td><?= View::e(trim(($row['cc_codigo'] ?? '—').' / '.($row['os_codigo'] ?? '—'))) ?></td>
                        <td><?= !empty($row['municipio']) ? View::e($row['municipio'].'/'.$row['uf']) : '—' ?></td>
                        <td class="text-sm"><?= View::e(trim(($row['jornada_codigo'] ?? '—').' · '.($row['escala_codigo'] ?? ''))) ?></td>
                        <td>
                            <?php
                            $status = (string)($row['status'] ?? 'ativo');
                            $pill = match ($status) {
                                'ativo' => 'pill-success',
                                'afastado' => 'pill-warning',
                                default => 'pill-neutral',
                            };
                            ?>
                            <span class="pill <?= $pill ?>"><?= View::e(ucfirst($status)) ?></span>
                        </td>
                        <td style="white-space:nowrap;">
                            <a class="btn btn-ghost btn-sm" href="/rh/colaboradores/<?= (int)$row['id'] ?>">Abrir</a>
                            <a class="btn btn-secondary btn-sm" href="/rh/colaboradores/<?= (int)$row['id'] ?>/editar">Editar</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
