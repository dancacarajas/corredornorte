<?php

use App\Helpers\Auth;
use App\Helpers\Csrf;
use App\Helpers\View;

/** @var bool $dbOk */
/** @var array $kpis */
/** @var list<array<string,mixed>> $rows */
/** @var array $filters */
/** @var array $lookups */
/** @var string $exportQuery */
$exportQuery = $exportQuery ?? '';
$exportHref = '/rh/efetivo/exportar' . ($exportQuery !== '' ? '?' . $exportQuery : '');
$podeCriar = $podeCriar ?? Auth::canAny('efetivo.criar', 'efetivo.operar');
$podeEditar = $podeEditar ?? Auth::canAny('efetivo.editar', 'efetivo.operar');
$podeExcluir = $podeExcluir ?? Auth::can('efetivo.excluir');
$podeOperar = $podeOperar ?? Auth::canAny('efetivo.operar', 'importacoes.operar', 'importacoes.gerenciar');
?>

<?php if (!$dbOk): ?>
    <div class="alert alert-warning">MySQL indisponível.</div>
<?php endif; ?>
<?php if (($flashOk ?? null) === 'excluido'): ?>
    <div class="alert alert-success">Colaborador apagado permanentemente do sistema.</div>
<?php elseif (($flashOk ?? null) === 'excluido_massa'): ?>
    <div class="alert alert-success"><?= (int) ($_GET['n'] ?? 0) ?> colaborador(es) apagado(s) permanentemente do sistema.</div>
<?php endif; ?>
<?php if (!empty($_GET['erro'])): ?>
    <div class="alert alert-danger"><?= View::e((string) $_GET['erro']) ?></div>
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
        <?php if (Auth::canAny('efetivo.ver', 'efetivo.editar', 'efetivo.criar', 'efetivo.operar', 'importacoes.gerenciar', 'importacoes.operar')): ?>
        <a class="btn btn-primary" href="<?= View::e($exportHref) ?>">Exportar efetivo (.xlsx)</a>
        <a class="btn btn-secondary" href="/rh/importacoes/modelo">Baixar modelo matriz</a>
        <?php endif; ?>
        <?php if ($podeOperar): ?>
        <a class="btn btn-secondary" href="/rh/importacoes/nova">Importar planilha</a>
        <?php endif; ?>
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
        <a class="btn btn-secondary btn-sm" href="/rh/horarios">Horários</a>
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
                <option value="" <?= $filters['status']===''?'selected':'' ?>>Lista ativa (oculta desligados)</option>
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
        <?php if ($podeExcluir && $rows !== []): ?>
        <div class="stack-row" style="gap:.5rem;align-items:center;">
            <span class="text-sm muted" id="efetivoSelCount">0 selecionado(s)</span>
            <button class="btn btn-ghost btn-sm" type="button" id="efetivoSelAll" hidden>Selecionar todos</button>
            <button class="btn btn-ghost btn-sm" type="button" id="efetivoSelNone" hidden>Limpar</button>
            <button class="btn btn-secondary btn-sm" type="submit" form="efetivoExcluirMassa"
                    id="efetivoExcluirMassaBtn" hidden
                    style="color:#B42318;border-color:#F3C0BC;"
                    data-confirm-title="Apagar permanentemente?"
                    data-confirm="Apaga 100% os colaboradores marcados do sistema (cadastro, lotação, ponto, documentos e histórico). Não dá para desfazer."
                    data-confirm-ok="Apagar definitivamente"
                    data-confirm-tone="danger"
                    data-confirm-danger>Excluir selecionados</button>
        </div>
        <?php endif; ?>
    </div>
    <?php if ($rows === []): ?>
        <div class="empty-state"><h3>Nenhum colaborador encontrado</h3></div>
    <?php else: ?>
        <?php if ($podeExcluir): ?>
        <form method="post" action="/rh/colaboradores/excluir-massa" id="efetivoExcluirMassa" style="display:none;">
            <?= Csrf::field() ?>
        </form>
        <?php endif; ?>
        <div class="table-wrap">
            <table class="table" id="efetivoTable">
                <thead>
                <tr>
                    <?php if ($podeExcluir): ?>
                    <th style="width:36px;">
                        <input type="checkbox" id="efetivoCheckAll" aria-label="Selecionar todos elegíveis">
                    </th>
                    <?php endif; ?>
                    <th>Colaborador</th><th>Contrato</th><th>CC / OS</th><th>Localidade</th><th>Horário</th><th>Status</th><th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <?php if ($podeExcluir): ?>
                        <td>
                            <input type="checkbox" name="ids[]" value="<?= (int)$row['id'] ?>"
                                   class="efetivo-row-check" form="efetivoExcluirMassa"
                                   aria-label="Selecionar <?= View::e((string)$row['nome']) ?>">
                        </td>
                        <?php endif; ?>
                        <td>
                            <div class="fw-700"><?= View::e((string)$row['nome']) ?></div>
                            <div class="text-xs muted">Mat. <?= View::e((string)$row['matricula']) ?> · <?= View::e((string)($row['cargo'] ?? '')) ?></div>
                        </td>
                        <td><?= View::e((string)($row['contrato_codigo'] ?? '—')) ?></td>
                        <td><?= View::e(trim(($row['cc_codigo'] ?? '—').' / '.($row['os_codigo'] ?? '—'))) ?></td>
                        <td><?= !empty($row['municipio']) ? View::e($row['municipio'].'/'.$row['uf']) : '—' ?></td>
                        <td class="text-sm"><?= View::e((string) ($row['horario_codigo'] ?? '—')) ?></td>
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
                            <?php if ($podeEditar): ?>
                            <a class="btn btn-secondary btn-sm" href="/rh/colaboradores/<?= (int)$row['id'] ?>/editar">Editar</a>
                            <?php endif; ?>
                            <?php if ($podeExcluir): ?>
                            <form method="post" action="/rh/colaboradores/<?= (int)$row['id'] ?>/excluir" style="display:inline;">
                                <?= Csrf::field() ?>
                                <button class="btn btn-ghost btn-sm" type="submit" style="color:#B42318;"
                                        data-confirm-title="Apagar permanentemente?"
                                        data-confirm="Apaga 100% <?= View::e((string)$row['nome']) ?> do sistema (cadastro, ponto, documentos e histórico). Não dá para desfazer."
                                        data-confirm-ok="Apagar definitivamente"
                                        data-confirm-tone="danger"
                                        data-confirm-danger>Excluir</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($podeExcluir): ?>
        <script>
        (function () {
          const checks = () => Array.from(document.querySelectorAll('.efetivo-row-check'));
          const master = document.getElementById('efetivoCheckAll');
          const countEl = document.getElementById('efetivoSelCount');
          const btn = document.getElementById('efetivoExcluirMassaBtn');
          const selAll = document.getElementById('efetivoSelAll');
          const selNone = document.getElementById('efetivoSelNone');
          const form = document.getElementById('efetivoExcluirMassa');
          const syncIdsIntoForm = () => {
            if (!(form instanceof HTMLFormElement)) return;
            form.querySelectorAll('input[data-efetivo-massa-id]').forEach((el) => el.remove());
            checks().filter((c) => c.checked).forEach((c) => {
              const hidden = document.createElement('input');
              hidden.type = 'hidden';
              hidden.name = 'ids[]';
              hidden.value = c.value;
              hidden.setAttribute('data-efetivo-massa-id', '1');
              form.appendChild(hidden);
            });
          };
          const refresh = () => {
            const list = checks();
            const on = list.filter((c) => c.checked);
            const n = on.length;
            if (countEl) countEl.textContent = n + ' selecionado(s)';
            if (btn) btn.hidden = n === 0;
            if (selAll) selAll.hidden = list.length === 0 || n === list.length;
            if (selNone) selNone.hidden = n === 0;
            if (master) {
              master.checked = list.length > 0 && n === list.length;
              master.indeterminate = n > 0 && n < list.length;
            }
            syncIdsIntoForm();
          };
          master?.addEventListener('change', () => {
            checks().forEach((c) => { c.checked = !!master.checked; });
            refresh();
          });
          checks().forEach((c) => c.addEventListener('change', refresh));
          selAll?.addEventListener('click', () => {
            checks().forEach((c) => { c.checked = true; });
            refresh();
          });
          selNone?.addEventListener('click', () => {
            checks().forEach((c) => { c.checked = false; });
            refresh();
          });
          form?.addEventListener('submit', syncIdsIntoForm);
          btn?.addEventListener('click', syncIdsIntoForm);
          refresh();
        })();
        </script>
        <?php endif; ?>
    <?php endif; ?>
</section>
