<?php

use App\Helpers\View;
use App\Services\CalculoPontoService;

/** @var bool $dbOk */
/** @var list<array<string,mixed>> $espelhos */
/** @var array<string,int> $resumo */
/** @var bool $mesUnico */
/** @var bool $podeGerar */
/** @var string $rotuloGerar */
/** @var string $dataInicio */
/** @var string $dataFim */
/** @var ?string $avisoPeriodo */
/** @var string $busca */
/** @var string $filtroStatus */
/** @var list<array{label:string,data_inicio:string,data_fim:string}> $atalhosPeriodo */
/** @var bool $periodoInexistente */
/** @var bool $periodoJaGerado */

$podeGerar = $podeGerar ?? $mesUnico;
$rotuloGerar = $rotuloGerar ?? 'Gerar espelhos';
$periodoInexistente = (bool) ($periodoInexistente ?? false);
$periodoJaGerado = (bool) ($periodoJaGerado ?? false);
$resumo = $resumo ?? [
    'total' => 0, 'abertos' => 0, 'tratamento' => 0, 'conferidos' => 0, 'fechados' => 0,
    'he_min' => 0, 'falta_min' => 0, 'atraso_min' => 0, 'he_autorizadas' => 0, 'he_pendentes' => 0,
];
$busca = $busca ?? '';
$filtroStatus = $filtroStatus ?? '';
$atalhosPeriodo = $atalhosPeriodo ?? [];
$calc = new CalculoPontoService();
$fmt = static fn(int $min): string => $min > 0 ? $calc->fmt($min) : '—';
$qsBase = http_build_query(array_filter([
    'data_inicio' => $dataInicio,
    'data_fim' => $dataFim,
    'q' => $busca !== '' ? $busca : null,
    'status' => $filtroStatus !== '' ? $filtroStatus : null,
]));
$rotuloPeriodo = date('d/m/Y', strtotime($dataInicio)) . ' a ' . date('d/m/Y', strtotime($dataFim));
?>

<?php if (!$dbOk): ?>
    <div class="alert alert-warning">Banco indisponível.</div>
<?php endif; ?>

<?php if (!empty($_GET['ok'])): ?>
    <div class="alert alert-success">Espelhos gerados para o período.</div>
<?php endif; ?>
<?php if (!empty($_GET['ok_lote'])): ?>
    <div class="alert alert-success">
        <?= (int) $_GET['ok_lote'] ?> colaborador(es) atualizado(s) para
        <strong><?= View::e((string) ($_GET['status_lote'] ?? '')) ?></strong>.
    </div>
<?php endif; ?>
<?php if (!empty($_GET['erro'])): ?>
    <div class="alert alert-danger"><?= View::e((string) $_GET['erro']) ?></div>
<?php endif; ?>
<?php if (!empty($avisoPeriodo)): ?>
    <div class="alert alert-warning"><?= View::e($avisoPeriodo) ?></div>
<?php endif; ?>

<section class="ponto-resumo-grid">
    <article class="ponto-kpi">
        <span>Colaboradores</span>
        <strong><?= (int) $resumo['total'] ?></strong>
    </article>
    <article class="ponto-kpi">
        <span>Em aberto</span>
        <strong><?= (int) $resumo['abertos'] ?></strong>
    </article>
    <article class="ponto-kpi">
        <span>Em tratamento</span>
        <strong><?= (int) $resumo['tratamento'] ?></strong>
    </article>
    <article class="ponto-kpi">
        <span>Conferidos</span>
        <strong><?= (int) $resumo['conferidos'] ?></strong>
    </article>
    <article class="ponto-kpi">
        <span>Fechados</span>
        <strong><?= (int) $resumo['fechados'] ?></strong>
    </article>
    <article class="ponto-kpi">
        <span>HE no período</span>
        <strong><?= View::e($fmt((int) $resumo['he_min'])) ?></strong>
    </article>
</section>

<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Espelhos · <?= date('d/m/Y', strtotime($dataInicio)) ?> a <?= date('d/m/Y', strtotime($dataFim)) ?></h2>
            <p class="card-subtitle">Filtre, gere, confira e feche o ponto do período selecionado.</p>
        </div>
        <div class="ponto-atalhos">
            <?php foreach ($atalhosPeriodo as $atalho): ?>
                <a class="btn btn-ghost btn-sm"
                   href="/rh/ponto?<?= View::e(http_build_query([
                       'data_inicio' => $atalho['data_inicio'],
                       'data_fim' => $atalho['data_fim'],
                       'q' => $busca !== '' ? $busca : null,
                       'status' => $filtroStatus !== '' ? $filtroStatus : null,
                   ])) ?>"><?= View::e($atalho['label']) ?></a>
            <?php endforeach; ?>
        </div>
    </div>

    <form method="get" action="/rh/ponto" class="card-body ponto-filtros" style="padding-top:0;">
        <div class="form-field">
            <label class="form-label">Período inicial</label>
            <input class="form-control" type="date" name="data_inicio" required value="<?= View::e($dataInicio) ?>">
        </div>
        <div class="form-field">
            <label class="form-label">Período final</label>
            <input class="form-control" type="date" name="data_fim" required value="<?= View::e($dataFim) ?>">
        </div>
        <div class="form-field">
            <label class="form-label">Buscar</label>
            <input class="form-control" type="search" name="q" value="<?= View::e($busca) ?>" placeholder="Nome, matrícula ou cargo">
        </div>
        <div class="form-field">
            <label class="form-label">Status</label>
            <select class="form-control" name="status">
                <option value="">Todos</option>
                <?php foreach ([
                    'em_aberto' => 'Em aberto',
                    'em_tratamento' => 'Em tratamento',
                    'conferido' => 'Conferido',
                    'fechado' => 'Fechado',
                ] as $val => $lab): ?>
                    <option value="<?= $val ?>" <?= $filtroStatus === $val ? 'selected' : '' ?>><?= View::e($lab) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-field ponto-filtros-actions">
            <button class="btn btn-secondary" type="submit">Filtrar</button>
            <?php if ($podeGerar): ?>
                <button class="btn btn-primary" type="submit" formaction="/rh/ponto/gerar" formmethod="post" data-ponto-gerar><?= View::e($rotuloGerar) ?></button>
            <?php endif; ?>
        </div>
    </form>

    <?php if ($espelhos === []): ?>
        <div class="empty-state">
            <h3><?= $periodoInexistente ? 'Nenhum cartão gerado para este período' : 'Nenhum espelho no período' ?></h3>
            <p><?= $periodoInexistente
                ? 'Não existe cartão de ponto gerado para ' . View::e($rotuloPeriodo) . '. Gere os espelhos deste período para começar.'
                : ($podeGerar
                    ? 'Clique em “' . View::e($rotuloGerar) . '” para criar os espelhos deste período.'
                    : 'Ajuste o filtro ou selecione um período válido para gerar os espelhos.') ?></p>
        </div>
    <?php else: ?>
        <form method="post" action="/rh/ponto/lote-status" id="pontoLoteForm">
            <input type="hidden" name="data_inicio" value="<?= View::e($dataInicio) ?>">
            <input type="hidden" name="data_fim" value="<?= View::e($dataFim) ?>">
            <input type="hidden" name="q" value="<?= View::e($busca) ?>">
            <input type="hidden" name="filtro_status" value="<?= View::e($filtroStatus) ?>">

            <div class="ponto-periodo-banner">
                <div>
                    <span class="ponto-periodo-kicker">Período do cartão de ponto</span>
                    <strong><?= View::e($rotuloPeriodo) ?></strong>
                </div>
                <span class="pill pill-brand"><?= count($espelhos) ?> colaborador(es)</span>
            </div>

            <div class="ponto-lote-bar">
                <label class="text-sm">
                    <input type="checkbox" id="pontoSelectAll"> Selecionar todos
                </label>
                <div class="ponto-lote-actions">
                    <button class="btn btn-secondary btn-sm" type="submit" data-lote-action="conferido" data-status-value="conferido">Conferir selecionados</button>
                    <button class="btn btn-primary btn-sm" type="submit" data-lote-action="fechado" data-status-value="fechado">Fechar selecionados</button>
                    <button class="btn btn-ghost btn-sm" type="submit" data-lote-action="reabrir" data-status-value="em_tratamento">Reabrir tratamento</button>
                </div>
                <input type="hidden" name="status" id="pontoLoteStatus" value="conferido">
                <input type="hidden" name="justificativa" id="pontoLoteJustificativa" value="">
            </div>

            <div class="table-wrap">
                <table class="table">
                    <thead>
                    <tr>
                        <th></th>
                        <th>Colaborador</th>
                        <th>Período</th>
                        <th>Status</th>
                        <th>Pendências</th>
                        <th>HE</th>
                        <th>Faltas</th>
                        <th>Atrasos</th>
                        <th>HE auth.</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($espelhos as $e): ?>
                        <?php
                        $pill = match ((string) $e['status']) {
                            'fechado', 'enviado', 'assinado' => 'pill-success',
                            'em_tratamento', 'conferido' => 'pill-warning',
                            default => 'pill-neutral',
                        };
                        $qsEspelho = View::e(http_build_query([
                            'data_inicio' => $dataInicio,
                            'data_fim' => $dataFim,
                        ]));
                        $bloqueado = in_array((string) $e['status'], ['fechado', 'enviado', 'assinado'], true);
                        ?>
                        <tr>
                            <td>
                                <input type="checkbox" name="espelho_ids[]" value="<?= (int) $e['id'] ?>" class="ponto-check">
                            </td>
                            <td>
                                <div class="fw-700"><?= View::e((string) $e['nome']) ?></div>
                                <div class="text-xs muted">
                                    Mat. <?= View::e((string) $e['matricula']) ?>
                                    <?php if (!empty($e['cargo']) || !empty($e['funcao'])): ?>
                                        · <?= View::e((string) ($e['cargo'] ?: $e['funcao'])) ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <span class="ponto-periodo-cell"><?= View::e($rotuloPeriodo) ?></span>
                            </td>
                            <td><span class="pill <?= $pill ?>"><?= View::e((string) $e['status']) ?></span></td>
                            <td>
                                <?php if ((int) ($e['dias_pendentes'] ?? 0) > 0): ?>
                                    <span class="pill pill-danger"><?= (int) $e['dias_pendentes'] ?> dia(s)</span>
                                <?php else: ?>
                                    <span class="muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td><?= View::e($fmt((int) $e['periodo_he_min'])) ?></td>
                            <td><?= View::e($fmt((int) $e['periodo_falta_min'])) ?></td>
                            <td><?= View::e($fmt((int) $e['periodo_atraso_min'])) ?></td>
                            <td>
                                <?php if ((int) $e['he_aprovadas'] > 0): ?>
                                    <span class="pill pill-brand" title="HE no período"><?= (int) $e['he_aprovadas'] ?></span>
                                <?php else: ?>
                                    <span class="muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="ponto-row-actions">
                                    <a class="btn btn-ghost btn-sm" href="/rh/ponto/<?= (int) $e['id'] ?>?<?= $qsEspelho ?>">Abrir</a>
                                    <a class="btn btn-ghost btn-sm" href="/rh/folha/<?= (int) $e['id'] ?>?<?= $qsEspelho ?>">Folha</a>
                                    <?php if (!$bloqueado): ?>
                                        <button class="btn btn-ghost btn-sm" type="button"
                                                data-conferir-um="<?= (int) $e['id'] ?>">Conferir</button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </form>
    <?php endif; ?>
</section>

<div class="modal-backdrop" id="tiposJustModalIndex">
    <div class="modal">
        <form method="post" action="/rh/ponto/justificativa-tipos">
            <input type="hidden" name="data_inicio" value="<?= View::e($dataInicio) ?>">
            <input type="hidden" name="data_fim" value="<?= View::e($dataFim) ?>">
            <div class="modal-header">
                <h3 class="card-title">Tipos de justificativa</h3>
                <button class="btn btn-ghost btn-sm" type="button" data-modal-close>Fechar</button>
            </div>
            <div class="modal-body section-stack">
                <p class="muted text-sm">Cadastre tipos usados nas justificativas do cartão de ponto.</p>
                <div class="form-grid cols-2">
                    <div class="form-field"><label class="form-label">Código</label><input class="form-control" name="codigo" required></div>
                    <div class="form-field"><label class="form-label">Nome</label><input class="form-control" name="nome" required></div>
                    <div class="form-field"><label class="text-sm"><input type="checkbox" name="abona_falta" value="1"> Abona falta</label></div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" type="button" data-modal-close>Cancelar</button>
                <button class="btn btn-primary" type="submit">Adicionar tipo</button>
            </div>
        </form>
    </div>
</div>

<dialog class="modal" id="ponto-periodo-inexistente"
        data-auto-open="<?= $periodoInexistente ? '1' : '0' ?>"
        aria-labelledby="ponto-periodo-inexistente-titulo">
    <div class="modal-header">
        <h2 class="card-title" id="ponto-periodo-inexistente-titulo">Período sem cartão</h2>
    </div>
    <div class="modal-body section-stack">
        <p>Não existe cartão de ponto gerado para este período.</p>
        <p class="text-sm muted"><strong><?= View::e($rotuloPeriodo) ?></strong></p>
        <?php if ($podeGerar): ?>
            <p class="text-sm">Use “<?= View::e($rotuloGerar) ?>” para criar os cartões deste intervalo.</p>
        <?php endif; ?>
    </div>
    <form method="dialog" class="modal-footer">
        <button class="btn btn-primary btn-sm" type="submit">Entendi</button>
    </form>
</dialog>
<script>
(() => {
  const d = document.getElementById('ponto-periodo-inexistente');
  if (d?.dataset.autoOpen === '1' && typeof d.showModal === 'function') d.showModal();
})();
</script>

<dialog class="modal" id="ponto-periodo-existe" aria-labelledby="ponto-periodo-existe-titulo">
    <div class="modal-header">
        <h2 class="card-title" id="ponto-periodo-existe-titulo">Cartão já gerado</h2>
    </div>
    <div class="modal-body section-stack">
        <p>Já existe cartão gerado para este período.</p>
        <p class="text-sm muted"><strong><?= View::e($rotuloPeriodo) ?></strong></p>
        <p class="text-sm" id="ponto-periodo-existe-detalhe"></p>
        <p class="text-sm">Sobrescrever regenera apenas cartões abertos ou em tratamento. Fechados e assinados são mantidos.</p>
    </div>
    <div class="modal-footer" style="display:flex;gap:.5rem;justify-content:flex-end;flex-wrap:wrap;">
        <button class="btn btn-secondary btn-sm" type="button" id="ponto-periodo-existe-cancelar">Cancelar</button>
        <button class="btn btn-primary btn-sm" type="button" id="ponto-periodo-existe-sobrescrever">Sobrescrever</button>
    </div>
</dialog>

<?php if ($podeGerar): ?>
    <dialog class="modal ponto-progresso" id="ponto-progresso" aria-labelledby="ponto-progresso-titulo">
        <div class="modal-header">
            <h2 class="card-title" id="ponto-progresso-titulo" tabindex="-1" autofocus>Geração de espelhos</h2>
        </div>
        <div class="modal-body section-stack">
            <div class="ponto-progresso-resumo text-sm">
                <span id="ponto-progresso-status" role="status"></span>
                <strong id="ponto-progresso-porcentagem" aria-hidden="true">0%</strong>
            </div>
            <div class="progress" role="progressbar" aria-labelledby="ponto-progresso-titulo" aria-valuenow="0">
                <span></span>
            </div>
        </div>
        <form method="dialog" class="modal-footer" hidden>
            <button class="btn btn-primary btn-sm" type="submit">Fechar</button>
        </form>
    </dialog>
    <script src="<?= View::asset('js/ponto-progresso.js') ?>?v=ponto-periodo-20261113" defer></script>
<?php endif; ?>

<script>
(() => {
  const form = document.getElementById('pontoLoteForm');
  const all = document.getElementById('pontoSelectAll');
  const checks = () => Array.from(document.querySelectorAll('.ponto-check'));
  const statusInput = document.getElementById('pontoLoteStatus');
  const justInput = document.getElementById('pontoLoteJustificativa');
  if (!form) return;

  if (all) {
    all.addEventListener('change', () => {
      checks().forEach((c) => { c.checked = all.checked; });
    });
  }

  const selectedCount = () => checks().filter((c) => c.checked).length;

  form.querySelectorAll('[data-lote-action]').forEach((btn) => {
    btn.addEventListener('click', (ev) => {
      const action = btn.getAttribute('data-lote-action');
      const statusValue = btn.getAttribute('data-status-value') || 'conferido';
      if (selectedCount() === 0) {
        ev.preventDefault();
        alert('Selecione ao menos um colaborador.');
        return;
      }
      if (statusInput) statusInput.value = statusValue;
      if (action === 'fechado' && !confirm('Fechar o período dos colaboradores selecionados?')) {
        ev.preventDefault();
        return;
      }
      if (action === 'reabrir') {
        const motivo = prompt('Informe a justificativa da reabertura:');
        if (!motivo || !motivo.trim()) {
          ev.preventDefault();
          alert('Reabertura exige justificativa.');
          return;
        }
        if (justInput) justInput.value = motivo.trim();
      } else if (justInput) {
        justInput.value = '';
      }
    });
  });

  document.querySelectorAll('[data-conferir-um]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const id = btn.getAttribute('data-conferir-um');
      checks().forEach((c) => { c.checked = c.value === id; });
      if (statusInput) statusInput.value = 'conferido';
      if (justInput) justInput.value = '';
      form.requestSubmit();
    });
  });
})();
</script>
