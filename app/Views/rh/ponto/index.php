<?php

use App\Helpers\View;
use App\Helpers\Csrf;
use App\Services\CalculoPontoService;

/** @var bool $dbOk */
/** @var list<array<string,mixed>> $espelhos */
/** @var array<string,int> $resumo */
/** @var bool $mesUnico */
/** @var bool $podeGerar */
/** @var bool $podeExcluirPeriodo */
/** @var string $rotuloGerar */
/** @var string $dataInicio */
/** @var string $dataFim */
/** @var ?string $avisoPeriodo */
/** @var string $busca */
/** @var string $filtroStatus */
/** @var list<array{label:string,data_inicio:string,data_fim:string}> $atalhosPeriodo */
/** @var list<array{id:int,data_inicio:string,data_fim:string,status:string,espelhos:int,label:string}> $periodosGerados */
/** @var bool $periodoInexistente */
/** @var bool $periodoJaGerado */

$podeGerar = $podeGerar ?? $mesUnico;
$podeExcluirPeriodo = (bool) ($podeExcluirPeriodo ?? false);
$rotuloGerar = $rotuloGerar ?? 'Gerar espelhos';
$periodoInexistente = (bool) ($periodoInexistente ?? false);
$periodoJaGerado = (bool) ($periodoJaGerado ?? false);
$periodosGerados = is_array($periodosGerados ?? null) ? $periodosGerados : [];
$resumo = $resumo ?? [
    'total' => 0, 'abertos' => 0, 'tratamento' => 0, 'conferidos' => 0, 'fechados' => 0,
    'he_min' => 0, 'falta_min' => 0, 'atraso_min' => 0, 'he_autorizadas' => 0, 'he_pendentes' => 0,
];
$busca = $busca ?? '';
$filtroStatus = $filtroStatus ?? '';
$filtroEspecial = (string) ($filtroEspecial ?? '');
$faltantesSemEspelho = is_array($faltantesSemEspelho ?? null) ? $faltantesSemEspelho : [];
$atalhosPeriodo = $atalhosPeriodo ?? [];
$calc = new CalculoPontoService();
$fmt = static fn(int $min): string => $min > 0 ? $calc->fmt($min) : '—';
$qsPeriodo = http_build_query([
    'data_inicio' => $dataInicio,
    'data_fim' => $dataFim,
]);
$qsBase = http_build_query(array_filter([
    'data_inicio' => $dataInicio,
    'data_fim' => $dataFim,
    'q' => $busca !== '' ? $busca : null,
    'status' => $filtroStatus !== '' ? $filtroStatus : null,
    'filtro' => $filtroEspecial !== '' ? $filtroEspecial : null,
]));
$rotuloPeriodo = date('d/m/Y', strtotime($dataInicio)) . ' a ' . date('d/m/Y', strtotime($dataFim));
?>

<?php if (!$dbOk): ?>
    <div class="alert alert-warning">Banco indisponível.</div>
<?php endif; ?>

<?php if (!empty($_GET['ok'])): ?>
    <div class="alert alert-success">
        <?= ((string) $_GET['ok'] === '1')
            ? 'Espelhos gerados para o período.'
            : View::e((string) $_GET['ok']) ?>
    </div>
<?php endif; ?>
<?php if (!empty($_GET['ok_lote'])): ?>
    <div class="alert alert-success">
        <?= (int) $_GET['ok_lote'] ?> colaborador(es) atualizado(s) para
        <strong><?= View::e((string) ($_GET['status_lote'] ?? '')) ?></strong>.
    </div>
<?php endif; ?>
<?php if (!empty($_GET['erro'])): ?>
    <div class="alert alert-danger"><?= View::e((string) ($_GET['erro']) ) ?></div>
<?php endif; ?>
<?php if (!empty($avisoPeriodo)): ?>
    <div class="alert alert-warning"><?= View::e($avisoPeriodo) ?></div>
<?php endif; ?>

<section class="ponto-periodos-bar" aria-label="Períodos com folhas geradas">
    <div class="ponto-periodos">
        <span class="ponto-periodos-label">Períodos gerados</span>
        <?php if ($periodosGerados === []): ?>
            <span class="muted text-sm">Nenhum período com folhas ainda — use “Gerar espelhos do período”.</span>
        <?php else: ?>
            <?php foreach ($periodosGerados as $pg):
                $ativo = $pg['data_inicio'] === $dataInicio && $pg['data_fim'] === $dataFim;
                ?>
                <a class="btn <?= $ativo ? 'btn-primary' : 'btn-secondary' ?> btn-sm"
                   href="/rh/ponto?<?= View::e(http_build_query([
                       'data_inicio' => $pg['data_inicio'],
                       'data_fim' => $pg['data_fim'],
                       'q' => $busca !== '' ? $busca : null,
                       'status' => $filtroStatus !== '' ? $filtroStatus : null,
                       'filtro' => $filtroEspecial !== '' ? $filtroEspecial : null,
                   ])) ?>">
                    <?= View::e($pg['label']) ?>
                    <span class="ponto-chip-count">· <?= (int) $pg['espelhos'] ?></span>
                </a>
            <?php endforeach; ?>
        <?php endif; ?>
        <?php if ($podeExcluirPeriodo && $periodoJaGerado): ?>
            <button class="btn btn-danger-soft btn-sm" type="button" data-modal-open="excluirPeriodoModal">
                Excluir período
            </button>
        <?php endif; ?>
    </div>
</section>

<?php if ($podeExcluirPeriodo && $periodoJaGerado): ?>
<div class="modal-backdrop" id="excluirPeriodoModal" data-excluir-periodo-modal>
    <div class="modal modal-danger-confirm" role="dialog" aria-modal="true" aria-labelledby="excluirPeriodoTitulo" aria-describedby="excluirPeriodoDesc">
        <form method="post" action="/rh/ponto/periodo/excluir" id="excluirPeriodoForm" autocomplete="off">
            <?= Csrf::field() ?>
            <input type="hidden" name="data_inicio" value="<?= View::e($dataInicio) ?>">
            <input type="hidden" name="data_fim" value="<?= View::e($dataFim) ?>">

            <div class="modal-header modal-danger-confirm-header">
                <div class="modal-danger-confirm-heading">
                    <span class="modal-danger-confirm-icon" aria-hidden="true">!</span>
                    <div>
                        <p class="modal-danger-confirm-kicker">Ação irreversível</p>
                        <h3 class="card-title" id="excluirPeriodoTitulo">Excluir período</h3>
                    </div>
                </div>
                <button class="btn btn-ghost btn-sm" type="button" data-modal-close aria-label="Fechar">Fechar</button>
            </div>

            <div class="modal-body modal-danger-confirm-body">
                <p class="modal-danger-confirm-lead" id="excluirPeriodoDesc">
                    Vai apagar <strong>tudo</strong> gerado neste período. Não há como desfazer.
                </p>

                <div class="modal-danger-period" role="group" aria-label="Período selecionado">
                    <span class="modal-danger-period-label">Período</span>
                    <strong class="modal-danger-period-value"><?= View::e($rotuloPeriodo) ?></strong>
                    <?php if (!empty($resumo['total'])): ?>
                        <span class="modal-danger-period-meta"><?= (int) $resumo['total'] ?> cartão(ões) de ponto</span>
                    <?php endif; ?>
                </div>

                <div class="modal-danger-list-wrap">
                    <p class="modal-danger-list-title">O que será removido</p>
                    <ul class="modal-danger-list">
                        <li>Cartões de ponto, batidas e justificativas (com anexos)</li>
                        <li>PDFs de folha, e-mails e links de assinatura</li>
                        <li>Relatórios de atividade (RA) e arquivos em disco</li>
                    </ul>
                </div>

                <div class="form-field modal-danger-confirm-field">
                    <label class="form-label" for="confirmTextoExcluir">
                        Para confirmar, digite <kbd class="modal-danger-kbd">EXCLUIR</kbd>
                    </label>
                    <input
                        class="form-control modal-danger-confirm-input"
                        id="confirmTextoExcluir"
                        name="confirm_texto"
                        required
                        autocomplete="off"
                        spellcheck="false"
                        autocapitalize="characters"
                        placeholder="Digite EXCLUIR"
                        inputmode="text"
                        aria-describedby="confirmTextoExcluirHint"
                    >
                    <p class="form-hint" id="confirmTextoExcluirHint">O botão só libera com a palavra exata.</p>
                </div>
            </div>

            <div class="modal-footer modal-danger-confirm-footer">
                <button class="btn btn-ghost" type="button" data-modal-close>Cancelar</button>
                <button class="btn btn-danger" type="submit" id="excluirPeriodoSubmit" disabled>
                    Excluir período agora
                </button>
            </div>
        </form>
    </div>
</div>
<script>
(() => {
  const modal = document.getElementById('excluirPeriodoModal');
  const input = document.getElementById('confirmTextoExcluir');
  const submit = document.getElementById('excluirPeriodoSubmit');
  const form = document.getElementById('excluirPeriodoForm');
  if (!modal || !input || !submit || !form) return;

  const sync = () => {
    const ok = input.value.trim().toUpperCase() === 'EXCLUIR';
    submit.disabled = !ok;
    input.classList.toggle('is-valid', ok);
    input.classList.toggle('is-invalid', input.value.trim() !== '' && !ok);
  };

  input.addEventListener('input', sync);
  input.addEventListener('keydown', (ev) => {
    if (ev.key === 'Enter' && submit.disabled) ev.preventDefault();
  });

  form.addEventListener('submit', (ev) => {
    if (submit.disabled || input.value.trim().toUpperCase() !== 'EXCLUIR') {
      ev.preventDefault();
      input.focus();
      sync();
      return;
    }
    submit.disabled = true;
    submit.textContent = 'Excluindo…';
  });

  const openBtn = document.querySelector('[data-modal-open="excluirPeriodoModal"]');
  openBtn?.addEventListener('click', () => {
    input.value = '';
    submit.textContent = 'Excluir período agora';
    sync();
    window.setTimeout(() => input.focus(), 50);
  });

  sync();
})();
</script>
<?php endif; ?>

<?php if ($filtroEspecial === 'divergencia'): ?>
    <div class="alert alert-warning" style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:14px;">
        <span>
            <strong>Filtro ativo:</strong> exibindo apenas espelhos com revisão/divergência aberta em
            <?= View::e($rotuloPeriodo) ?> (<?= (int) $resumo['total'] ?> encontrado<?= $resumo['total'] === 1 ? '' : 's' ?>).
        </span>
        <a class="btn btn-secondary btn-sm" href="/rh/ponto?<?= View::e($qsPeriodo) ?>">Limpar filtro</a>
    </div>
<?php elseif ($filtroEspecial === 'sem_espelho'): ?>
    <section class="card" style="margin-bottom:14px;">
        <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
            <div>
                <h2 class="card-title">Colaboradores ativos sem espelho no período</h2>
                <p class="card-subtitle">
                    <?= count($faltantesSemEspelho) ?> colaborador<?= count($faltantesSemEspelho) === 1 ? '' : 'es' ?> ativo<?= count($faltantesSemEspelho) === 1 ? '' : 's' ?> ainda sem cartão de ponto em <?= View::e($rotuloPeriodo) ?>.
                </p>
            </div>
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                <?php if ($faltantesSemEspelho !== [] && $podeGerar): ?>
                    <form method="post" action="/rh/ponto/gerar" style="display:inline;">
                        <input type="hidden" name="data_inicio" value="<?= View::e($dataInicio) ?>">
                        <input type="hidden" name="data_fim" value="<?= View::e($dataFim) ?>">
                        <button class="btn btn-primary btn-sm" type="submit" data-ponto-gerar>Gerar espelhos faltantes</button>
                    </form>
                <?php endif; ?>
                <a class="btn btn-secondary btn-sm" href="/rh/ponto?<?= View::e($qsPeriodo) ?>">Limpar filtro</a>
            </div>
        </div>
        <?php if ($faltantesSemEspelho === []): ?>
            <div class="card-body">
                <div class="alert alert-success" style="margin:0;">Todos os colaboradores ativos já possuem espelho gerado neste período.</div>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Matrícula</th>
                            <th>Colaborador</th>
                            <th>Lotação</th>
                            <th>Localidade</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($faltantesSemEspelho as $falt): ?>
                            <tr>
                                <td><code><?= View::e((string) ($falt['matricula'] ?? '')) ?></code></td>
                                <td><a href="/rh/colaboradores/<?= (int) $falt['id'] ?>"><strong><?= View::e((string) ($falt['nome'] ?? '')) ?></strong></a></td>
                                <td><?= View::e((string) (($falt['lotacao_codigo'] ?? '') !== '' ? $falt['lotacao_codigo'] : '—')) ?></td>
                                <td>
                                    <?php
                                    $loc = trim((string) ($falt['municipio'] ?? '') . ($falt['uf'] ? '/' . $falt['uf'] : ''));
                                    ?>
                                    <?= View::e($loc !== '' ? $loc : '—') ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
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
            <p><?php if ($periodoInexistente && $periodosGerados !== []): ?>
                Não há folhas em <?= View::e($rotuloPeriodo) ?>. Selecione um período gerado acima ou gere os espelhos deste intervalo.
            <?php elseif ($periodoInexistente): ?>
                Não existe cartão de ponto gerado para <?= View::e($rotuloPeriodo) ?>. Gere os espelhos deste período para começar.
            <?php elseif ($podeGerar): ?>
                Clique em “<?= View::e($rotuloGerar) ?>” para criar os espelhos deste período.
            <?php else: ?>
                Ajuste o filtro ou selecione um período válido para gerar os espelhos.
            <?php endif; ?></p>
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
                    <button class="btn btn-secondary btn-sm" type="submit" name="acao" value="preencher_previstos" data-lote-action="preencher_previstos" data-status-value="preencher_previstos">Preencher previstos</button>
                    <button class="btn btn-secondary btn-sm" type="submit" data-lote-action="conferido" data-status-value="conferido">Conferir selecionados</button>
                    <button class="btn btn-primary btn-sm" type="submit" data-lote-action="fechado" data-status-value="fechado">Fechar selecionados</button>
                    <button class="btn btn-ghost btn-sm" type="submit" data-lote-action="reabrir" data-status-value="em_tratamento">Reabrir tratamento</button>
                </div>
                <input type="hidden" name="status" id="pontoLoteStatus" value="conferido">
                <input type="hidden" name="acao" id="pontoLoteAcao" value="">
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
<script src="<?= View::asset('js/ponto-progresso.js') ?>?v=ponto-gerar-20261119b" defer></script>

<div class="modal-backdrop" id="pontoReabrirModal">
    <div class="modal">
        <div class="modal-header">
            <h3 class="card-title">Reabrir tratamento</h3>
            <button class="btn btn-ghost btn-sm" type="button" data-modal-close>Fechar</button>
        </div>
        <div class="modal-body section-stack">
            <div class="alert alert-warning">Reabrir devolve os cartões selecionados para tratamento e invalida PDFs anteriores.</div>
            <div class="form-field">
                <label class="form-label" for="pontoReabrirMotivo">Justificativa da reabertura</label>
                <textarea class="form-control" id="pontoReabrirMotivo" rows="3" placeholder="Descreva o motivo da reabertura..." required></textarea>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" type="button" data-modal-close>Cancelar</button>
            <button class="btn btn-primary" type="button" id="pontoReabrirConfirmar">Confirmar reabertura</button>
        </div>
    </div>
</div>

<script>
(() => {
  const form = document.getElementById('pontoLoteForm');
  const all = document.getElementById('pontoSelectAll');
  const checks = () => Array.from(document.querySelectorAll('.ponto-check'));
  const statusInput = document.getElementById('pontoLoteStatus');
  const acaoInput = document.getElementById('pontoLoteAcao');
  const justInput = document.getElementById('pontoLoteJustificativa');
  const reabrirModal = document.getElementById('pontoReabrirModal');
  const reabrirMotivo = document.getElementById('pontoReabrirMotivo');
  const reabrirConfirmar = document.getElementById('pontoReabrirConfirmar');
  if (!form) return;

  const notifyWarn = (msg) => {
    if (window.Toast && typeof window.Toast.show === 'function') {
      window.Toast.show(msg, 'warning');
    }
  };

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
        notifyWarn('Selecione ao menos um colaborador.');
        return;
      }
      if (statusInput) statusInput.value = statusValue;
      if (acaoInput) acaoInput.value = action === 'preencher_previstos' ? 'preencher_previstos' : '';
      if (action === 'fechado') {
        ev.preventDefault();
        const targetForm = btn.closest('form');
        const run = window.uiConfirm
          ? window.uiConfirm({
              title: 'Fechar período?',
              message: 'Fechar o período dos colaboradores selecionados?',
              confirmText: 'Fechar',
              tone: 'warning',
            })
          : Promise.resolve(true);
        run.then((ok) => {
          if (!ok || !targetForm) return;
          if (statusInput) statusInput.value = statusValue;
          if (typeof targetForm.requestSubmit === 'function') targetForm.requestSubmit();
          else targetForm.submit();
        });
        return;
      }
      if (action === 'reabrir') {
        ev.preventDefault();
        if (reabrirModal && reabrirMotivo) {
          reabrirMotivo.value = '';
          reabrirModal.classList.add('is-open');
          window.setTimeout(() => reabrirMotivo.focus(), 40);
        }
        return;
      }
      if (justInput) {
        justInput.value = '';
      }
    });
  });

  if (reabrirConfirmar && reabrirModal && reabrirMotivo) {
    reabrirConfirmar.addEventListener('click', () => {
      const motivo = (reabrirMotivo.value || '').trim();
      if (!motivo) {
        notifyWarn('Reabertura exige justificativa.');
        reabrirMotivo.focus();
        return;
      }
      if (statusInput) statusInput.value = 'em_tratamento';
      if (justInput) justInput.value = motivo;
      reabrirModal.classList.remove('is-open');
      if (typeof form.requestSubmit === 'function') form.requestSubmit();
      else form.submit();
    });
  }

  document.querySelectorAll('[data-conferir-um]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const id = btn.getAttribute('data-conferir-um') || '';
      if (!id) return;
      const singleForm = document.createElement('form');
      singleForm.method = 'post';
      singleForm.action = form.action || '/rh/ponto/lote-status';
      singleForm.style.display = 'none';

      const appendHidden = (name, value) => {
        const inp = document.createElement('input');
        inp.type = 'hidden';
        inp.name = name;
        inp.value = value;
        singleForm.appendChild(inp);
      };

      const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
      if (csrf) appendHidden('_csrf', csrf);
      const ini = form.querySelector('input[name="data_inicio"]')?.value || '';
      const fim = form.querySelector('input[name="data_fim"]')?.value || '';
      if (ini) appendHidden('data_inicio', ini);
      if (fim) appendHidden('data_fim', fim);
      appendHidden('status', 'conferido');
      appendHidden('justificativa', '');
      appendHidden('espelho_ids[]', id);

      document.body.appendChild(singleForm);
      singleForm.submit();
    });
  });
})();
</script>

