<?php

use App\Helpers\Csrf;
use App\Helpers\View;
use App\Services\HeRelatorioService;

$periodos = $periodos ?? [];
$dataInicio = $dataInicio ?? '';
$dataFim = $dataFim ?? '';
$labelPeriodo = $labelPeriodo ?? '';
$gerou = !empty($gerou);
$relatorio = $relatorio ?? null;
$pendentes = $pendentes ?? [];
$colaboradores = $colaboradores ?? [];
$erroCalc = $erroCalc ?? null;
$qs = $qs ?? '';
$podeOperar = !empty($podeOperar);
$flash = $flash ?? null;
$error = $error ?? null;

$consolidado = is_array($relatorio) ? ($relatorio['consolidado'] ?? []) : [];
$lancamentos = is_array($relatorio) ? ($relatorio['lancamentos'] ?? []) : [];
$efetivo = is_array($relatorio) ? ($relatorio['efetivo'] ?? []) : [];
$totais = is_array($relatorio) ? ($relatorio['totais'] ?? []) : [];
$temResultado = $gerou && is_array($relatorio) && $erroCalc === null;
$periodoKey = $dataInicio . '|' . $dataFim;
$qtdPendentes = 0;
foreach ($pendentes as $lista) {
    $qtdPendentes += count($lista);
}

$fmt = static fn (int $min): string => HeRelatorioService::fmtMin($min);
$fmtH = static function (float $h): string {
    $min = (int) round($h * 60);
    return HeRelatorioService::fmtMin($min);
};

$aba = (string) ($_GET['aba'] ?? 'resumo');
if (!in_array($aba, ['resumo', 'dias', 'equipe', 'saldo'], true)) {
    // Compat com links antigos
    $aba = match ($aba) {
        'consolidado' => 'resumo',
        'lancamentos' => 'dias',
        'efetivo' => 'equipe',
        'pendentes' => 'saldo',
        default => 'resumo',
    };
}
$qsAba = static function (string $nome) use ($qs): string {
    return $qs === '' ? 'aba=' . $nome : $qs . '&aba=' . $nome;
};
?>

<style>
.he-help {
    background: #FFF7F0;
    border: 1px solid #F0D2BF;
    border-radius: 10px;
    padding: .85rem 1rem;
    color: #5C3A28;
    font-size: .92rem;
    line-height: 1.45;
}
.he-help strong { color: #3D2418; }
.he-tabs {
    display: flex;
    flex-wrap: wrap;
    gap: .4rem;
    padding: .75rem 1rem;
}
.he-tab {
    display: inline-flex;
    flex-direction: column;
    align-items: flex-start;
    gap: .1rem;
    padding: .55rem .85rem;
    border-radius: 10px;
    text-decoration: none;
    border: 1px solid var(--border, #e5e1db);
    background: #fff;
    color: inherit;
    min-width: 9.5rem;
}
.he-tab.is-active {
    border-color: #C45C26;
    background: #FFF4EC;
    box-shadow: inset 0 0 0 1px #C45C26;
}
.he-tab-title { font-weight: 700; font-size: .92rem; }
.he-tab-desc { font-size: .75rem; color: var(--muted, #6b645c); }
.he-kpi-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: .75rem;
    margin-bottom: 1rem;
}
.he-kpi {
    border: 1px solid var(--border, #e5e1db);
    border-radius: 10px;
    padding: .75rem .9rem;
    background: #fff;
}
.he-kpi .lbl { font-size: .72rem; color: var(--muted, #6b645c); text-transform: uppercase; letter-spacing: .03em; }
.he-kpi .val { font-size: 1.25rem; font-weight: 700; margin-top: .15rem; }

.he-lanc-card.is-fullscreen {
    position: fixed; inset: 0; z-index: 12000; margin: 0; border-radius: 0;
    display: flex; flex-direction: column; background: var(--surface, #fff); box-shadow: none;
}
.he-lanc-card.is-fullscreen .card-header { flex: 0 0 auto; border-radius: 0; }
.he-lanc-card.is-fullscreen .he-lanc-body { flex: 1 1 auto; min-height: 0; overflow: auto; }
.he-lanc-card .he-lanc-table-wrap { overflow-x: auto; }
.he-lanc-card .he-lanc-table { min-width: 2200px; }
.he-lanc-card.is-fullscreen .he-lanc-table-wrap { overflow-x: hidden; height: 100%; }
.he-lanc-card.is-fullscreen .he-lanc-table {
    min-width: 0 !important; width: 100%; table-layout: fixed; font-size: 11px;
}
.he-lanc-card.is-fullscreen .he-lanc-table th,
.he-lanc-card.is-fullscreen .he-lanc-table td {
    padding: .35rem .3rem; white-space: normal; word-break: break-word; vertical-align: top; line-height: 1.25;
}
.he-lanc-card.is-fullscreen .he-lanc-table th {
    position: sticky; top: 0; z-index: 2; background: var(--surface-2, #f7f5f2);
}
body.he-lanc-fs-open { overflow: hidden; }
.he-lanc-fs-btn .he-lanc-fs-label-exit { display: none; }
.he-lanc-card.is-fullscreen .he-lanc-fs-btn .he-lanc-fs-label-open { display: none; }
.he-lanc-card.is-fullscreen .he-lanc-fs-btn .he-lanc-fs-label-exit { display: inline; }
</style>

<?php if ($flash): ?><div class="alert alert-success"><?= View::e((string) $flash) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= View::e((string) $error) ?></div><?php endif; ?>
<?php if ($erroCalc): ?><div class="alert alert-warning"><?= View::e((string) $erroCalc) ?></div><?php endif; ?>

<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Relatório de horas extras e adicional noturno</h2>
            <p class="card-subtitle">Consolida quem teve HE ou Adicional Noturno (CLT 52m30s) no período, a partir do ponto conferido na Medição</p>
        </div>
        <div class="stack-row" style="gap:.5rem;">
            <a class="btn btn-ghost btn-sm" href="/rh/medicao">Abrir Medição</a>
            <?php if ($temResultado && $podeOperar): ?>
                <a class="btn btn-secondary btn-sm" href="/rh/relatorio-he/excel?<?= View::e($qs) ?>">Baixar Excel (HE + ADN)</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="card-body section-stack">
        <div class="he-help">
            <strong>Como usar:</strong>
            escolha o período da Medição → veja o <strong>total do mês (HE e Adicional Noturno)</strong> → para o detalhe dia a dia, abra <strong>Dias com HE / Ad. Noturno</strong>.
            O bloco <strong>Saldo de meses anteriores</strong> só serve para HE/ADN antigos ainda não pagos — não altera o ponto.
        </div>

        <form method="get" action="/rh/relatorio-he" id="heRelatorioForm">
            <input type="hidden" name="aba" value="<?= View::e($aba) ?>">
            <div class="form-grid cols-4" style="align-items:end;">
                <div class="form-field">
                    <label class="form-label" for="hePeriodo">Período da Medição</label>
                    <select class="form-control" id="hePeriodo" name="periodo_sel">
                        <option value="">Personalizado…</option>
                        <?php foreach ($periodos as $p):
                            $val = $p['data_inicio'] . '|' . $p['data_fim'];
                            ?>
                            <option value="<?= View::e($val) ?>"
                                    data-inicio="<?= View::e($p['data_inicio']) ?>"
                                    data-fim="<?= View::e($p['data_fim']) ?>"
                                <?= $val === $periodoKey ? 'selected' : '' ?>>
                                <?= View::e($p['label']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-field">
                    <label class="form-label" for="heIni">Início</label>
                    <input class="form-control" id="heIni" type="date" name="data_inicio"
                           value="<?= View::e($dataInicio) ?>" required>
                </div>
                <div class="form-field">
                    <label class="form-label" for="heFim">Fim</label>
                    <input class="form-control" id="heFim" type="date" name="data_fim"
                           value="<?= View::e($dataFim) ?>" required>
                </div>
                <div class="form-field">
                    <button class="btn btn-primary" type="submit">Atualizar</button>
                </div>
            </div>
        </form>
    </div>
</section>

<?php if (!$temResultado): ?>
<section class="card">
    <div class="empty-state">
        <h3>Nenhum período para mostrar</h3>
        <p>Gere ou confira espelhos na Medição e volte aqui. O relatório só usa ponto já conferido.</p>
        <a class="btn btn-primary" href="/rh/medicao">Ir para Medição</a>
    </div>
</section>
<?php else: ?>

<nav class="card he-tabs" aria-label="Seções do relatório">
    <a class="he-tab<?= $aba === 'resumo' ? ' is-active' : '' ?>" href="/rh/relatorio-he?<?= View::e($qsAba('resumo')) ?>">
        <span class="he-tab-title">Total do mês</span>
        <span class="he-tab-desc"><?= count($consolidado) ?> pessoa(s) com HE / ADN</span>
    </a>
    <a class="he-tab<?= $aba === 'dias' ? ' is-active' : '' ?>" href="/rh/relatorio-he?<?= View::e($qsAba('dias')) ?>">
        <span class="he-tab-title">Dias com HE / Ad. Noturno</span>
        <span class="he-tab-desc"><?= count($lancamentos) ?> dia(s) lançado(s)</span>
    </a>
    <a class="he-tab<?= $aba === 'equipe' ? ' is-active' : '' ?>" href="/rh/relatorio-he?<?= View::e($qsAba('equipe')) ?>">
        <span class="he-tab-title">Equipe do período</span>
        <span class="he-tab-desc"><?= count($efetivo) ?> no cadastro</span>
    </a>
    <a class="he-tab<?= $aba === 'saldo' ? ' is-active' : '' ?>" href="/rh/relatorio-he?<?= View::e($qsAba('saldo')) ?>">
        <span class="he-tab-title">Saldo meses anteriores</span>
        <span class="he-tab-desc"><?= $qtdPendentes ?> registro(s)</span>
    </a>
</nav>

<?php if ($aba === 'resumo'): ?>
<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Total do mês · <?= View::e($labelPeriodo) ?></h2>
            <p class="card-subtitle">Soma por pessoa — colaboradores com Hora Extra e/ou Adicional Noturno (CLT 52m30s) neste período</p>
        </div>
    </div>
    <div class="card-body">
        <div class="he-kpi-row">
            <div class="he-kpi"><div class="lbl">Pessoas</div><div class="val"><?= (int) ($totais['colaboradores'] ?? 0) ?></div></div>
            <div class="he-kpi"><div class="lbl">Dias lançados</div><div class="val"><?= (int) ($totais['lancamentos'] ?? 0) ?></div></div>
            <div class="he-kpi"><div class="lbl">Horas trab.</div><div class="val"><?= View::e($fmt((int) ($totais['trab_min'] ?? 0))) ?></div></div>
            <div class="he-kpi" style="border-color:#F0D2BF;background:#FFF7F0;"><div class="lbl" style="color:#9A3412;">Adicional Noturno</div><div class="val" style="color:#C2410C;"><?= View::e($fmt((int) ($totais['adn_min'] ?? 0))) ?></div></div>
            <div class="he-kpi"><div class="lbl">HE 100%</div><div class="val"><?= View::e($fmt((int) (($totais['he_d100'] ?? 0) + ($totais['he_n100'] ?? 0)))) ?></div></div>
            <div class="he-kpi"><div class="lbl">HE 50/60%</div><div class="val"><?= View::e($fmt((int) (($totais['he_d50'] ?? 0) + ($totais['he_d60'] ?? 0) + ($totais['he_n50'] ?? 0) + ($totais['he_n60'] ?? 0)))) ?></div></div>
        </div>

        <?php if ($consolidado === []): ?>
            <div class="empty-state"><p>Ninguém teve HE ou Adicional Noturno neste período (no ponto conferido).</p></div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                    <tr>
                        <th>Colaborador</th>
                        <th>Chapa</th>
                        <th>Dias</th>
                        <th>Trab.</th>
                        <th>Ad. Noturno</th>
                        <th>D 50%</th>
                        <th>D 60%</th>
                        <th>D 100%</th>
                        <th>N 50%</th>
                        <th>N 60%</th>
                        <th>N 100%</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($consolidado as $row): ?>
                        <tr>
                            <td><?= View::e((string) $row['nome']) ?></td>
                            <td><?= View::e((string) $row['matricula']) ?></td>
                            <td><?= (int) $row['dias'] ?></td>
                            <td><?= View::e($fmt((int) $row['trab_min'])) ?></td>
                            <td><?= View::e($fmt((int) $row['adn_min'])) ?></td>
                            <td><?= View::e($fmt((int) $row['he_d50'])) ?></td>
                            <td><?= View::e($fmt((int) $row['he_d60'])) ?></td>
                            <td><?= View::e($fmt((int) $row['he_d100'])) ?></td>
                            <td><?= View::e($fmt((int) $row['he_n50'])) ?></td>
                            <td><?= View::e($fmt((int) $row['he_n60'])) ?></td>
                            <td><?= View::e($fmt((int) $row['he_n100'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<?php if ($aba === 'dias'): ?>
<section class="card he-lanc-card" id="heLancCard">
    <div class="card-header">
        <div>
            <h2 class="card-title">Dias com HE / Adicional Noturno</h2>
            <p class="card-subtitle">Dias em que houve hora extra e/ou adicional noturno — uma linha por dia/pessoa</p>
        </div>
        <?php if ($lancamentos !== []): ?>
        <button type="button" class="btn btn-secondary btn-sm he-lanc-fs-btn" id="heLancFsBtn" aria-pressed="false">
            <span class="he-lanc-fs-label-open">Tela cheia</span>
            <span class="he-lanc-fs-label-exit">Sair da tela cheia</span>
        </button>
        <?php endif; ?>
    </div>
    <?php if ($lancamentos === []): ?>
        <div class="empty-state"><p>Nenhum dia com HE no período.</p></div>
    <?php else: ?>
        <div class="he-lanc-body">
            <div class="table-wrap he-lanc-table-wrap">
                <table class="table text-sm he-lanc-table">
                    <thead>
                    <tr>
                        <th>Data</th><th>Dia</th><th>Colaborador</th><th>Chapa</th><th>Função</th>
                        <th>Cod Horário</th><th>Horário cadastrado</th>
                        <th>Jornada prevista</th><th>Intervalo previsto</th>
                        <th>Entrada</th><th>Saída</th><th>Horas trabalhadas</th>
                        <th>Adicional noturno</th>
                        <th>HE noturno 50%</th><th>HE noturno 60%</th><th>HE noturno 100%</th>
                        <th>HE diurno 50%</th><th>HE diurno 60%</th><th>HE diurno 100%</th>
                        <th>Feriado?</th><th>Ordem</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($lancamentos as $row): ?>
                        <tr>
                            <td><?= View::e(date('d/m/Y', strtotime((string) $row['data']) ?: time())) ?></td>
                            <td><?= View::e((string) $row['dia_semana']) ?></td>
                            <td><?= View::e((string) $row['nome']) ?></td>
                            <td><?= View::e((string) $row['matricula']) ?></td>
                            <td><?= View::e((string) ($row['funcao_label'] ?? '')) ?></td>
                            <td><?= View::e((string) ($row['horario_codigo'] ?? '')) ?></td>
                            <td><?= View::e((string) ($row['horario_texto'] ?? '')) ?></td>
                            <td><?= View::e($fmtH((float) $row['jornada_prevista_h'])) ?></td>
                            <td><?= View::e($fmtH((float) $row['intervalo_previsto_h'])) ?></td>
                            <td><?= View::e((string) $row['entrada']) ?></td>
                            <td><?= View::e((string) $row['saida']) ?></td>
                            <td><?= View::e($fmt((int) $row['trab_min'])) ?></td>
                            <td><?= View::e($fmt((int) $row['adn_min'])) ?></td>
                            <td><?= View::e($fmt((int) $row['he_n50'])) ?></td>
                            <td><?= View::e($fmt((int) $row['he_n60'])) ?></td>
                            <td><?= View::e($fmt((int) $row['he_n100'])) ?></td>
                            <td><?= View::e($fmt((int) $row['he_d50'])) ?></td>
                            <td><?= View::e($fmt((int) $row['he_d60'])) ?></td>
                            <td><?= View::e($fmt((int) $row['he_d100'])) ?></td>
                            <td><?= View::e((string) $row['feriado_sn']) ?></td>
                            <td><?= View::e((string) ($row['ordem_unica'] ?? '')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</section>
<script>
(() => {
  const card = document.getElementById('heLancCard');
  const btn = document.getElementById('heLancFsBtn');
  if (!card || !btn) return;
  const setFs = (on) => {
    card.classList.toggle('is-fullscreen', on);
    document.body.classList.toggle('he-lanc-fs-open', on);
    btn.setAttribute('aria-pressed', on ? 'true' : 'false');
    if (on) {
      const req = card.requestFullscreen || card.webkitRequestFullscreen;
      if (typeof req === 'function') { try { req.call(card); } catch (_) {} }
    } else if (document.fullscreenElement || document.webkitFullscreenElement) {
      const exit = document.exitFullscreen || document.webkitExitFullscreen;
      if (typeof exit === 'function') { try { exit.call(document); } catch (_) {} }
    }
  };
  btn.addEventListener('click', () => setFs(!card.classList.contains('is-fullscreen')));
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && card.classList.contains('is-fullscreen')) setFs(false);
  });
})();
</script>
<?php endif; ?>

<?php if ($aba === 'equipe'): ?>
<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Equipe do período</h2>
            <p class="card-subtitle">Quem aparece nos dias com HE · horário e % HE do cadastro</p>
        </div>
    </div>
    <?php if ($efetivo === []): ?>
        <div class="empty-state"><p>Ninguém no período.</p></div>
    <?php else: ?>
        <div class="table-wrap" style="overflow-x:auto;">
            <table class="table text-sm" style="min-width:1400px;">
                <thead>
                <tr>
                    <th>Chapa</th><th>Colaborador</th><th>Situação</th><th>Função</th>
                    <th>Cidade</th><th>UF</th><th>Cod Horário</th><th>Horário cadastrado</th>
                    <th>Jornada mensal</th><th>Seg-Qui (h)</th><th>Sexta (h)</th><th>Intervalo (h)</th>
                    <th>Seção</th><th>% HE</th><th>Admissão</th><th>Turno</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($efetivo as $row): ?>
                    <tr>
                        <td><?= View::e((string) $row['matricula']) ?></td>
                        <td><?= View::e((string) $row['nome']) ?></td>
                        <td><?= View::e((string) $row['situacao']) ?></td>
                        <td><?= View::e((string) $row['funcao']) ?></td>
                        <td><?= View::e((string) $row['cidade']) ?></td>
                        <td><?= View::e((string) $row['uf']) ?></td>
                        <td><?= View::e((string) $row['horario_codigo']) ?></td>
                        <td style="max-width:260px;white-space:normal;"><?= View::e((string) $row['horario_texto']) ?></td>
                        <td><?= (int) $row['jornada_mensal'] ?></td>
                        <td><?= View::e(number_format((float) $row['horas_seg_qui'], 2, ',', '')) ?></td>
                        <td><?= View::e(number_format((float) $row['horas_sexta'], 2, ',', '')) ?></td>
                        <td><?= View::e(number_format((float) $row['intervalo_h'], 2, ',', '')) ?></td>
                        <td><?= View::e((string) $row['secao']) ?></td>
                        <td><?= (int) $row['percentual_he'] ?>%</td>
                        <td><?= View::e($row['admissao'] !== '' ? date('d/m/Y', strtotime((string) $row['admissao']) ?: time()) : '') ?></td>
                        <td><?= View::e((string) $row['turno']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($aba === 'saldo'): ?>
<section class="card" id="pendentes">
    <div class="card-header">
        <div>
            <h2 class="card-title">Saldo de meses anteriores</h2>
            <p class="card-subtitle">HE antiga ainda não paga — lançamento manual</p>
        </div>
    </div>
    <div class="card-body section-stack">
        <div class="he-help">
            <strong>O que é isso?</strong><br>
            Quando alguém fez HE em um mês passado e esse valor <strong>ainda não foi pago</strong>, você registra o saldo aqui
            (ex.: no fechamento de outubro, informar HE de setembro em aberto).<br><br>
            <strong>O que isso NÃO faz:</strong> não muda o cartão de ponto, não recalcula o mês atual e não cria HE nova.
            É só um controle de “ainda falta pagar”, que também sai no Excel.
        </div>

        <?php if ($podeOperar): ?>
            <form method="post" action="/rh/relatorio-he/pendentes" class="form-grid cols-4">
                <?= Csrf::field() ?>
                <input type="hidden" name="data_inicio" value="<?= View::e($dataInicio) ?>">
                <input type="hidden" name="data_fim" value="<?= View::e($dataFim) ?>">
                <div class="form-field" style="grid-column:span 2;">
                    <label class="form-label">Colaborador</label>
                    <select class="form-control" name="colaborador_id" required>
                        <option value="">Selecione</option>
                        <?php foreach ($colaboradores as $c): ?>
                            <option value="<?= (int) $c['id'] ?>">
                                <?= View::e($c['matricula'] . ' — ' . $c['nome']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-field">
                    <label class="form-label">Mês do saldo (não pago)</label>
                    <input class="form-control" type="month" name="competencia" required
                           value="<?= View::e(date('Y-m', strtotime('-1 month') ?: time())) ?>">
                </div>
                <div class="form-field">
                    <label class="form-label">ADN</label>
                    <input class="form-control" name="adn" placeholder="0 ou 01:30">
                </div>
                <div class="form-field"><label class="form-label">Diurno 50%</label><input class="form-control" name="he_diurno_50" placeholder="0"></div>
                <div class="form-field"><label class="form-label">Diurno 60%</label><input class="form-control" name="he_diurno_60" placeholder="0"></div>
                <div class="form-field"><label class="form-label">Diurno 100%</label><input class="form-control" name="he_diurno_100" placeholder="0"></div>
                <div class="form-field"><label class="form-label">Noturno 50%</label><input class="form-control" name="he_noturno_50" placeholder="0"></div>
                <div class="form-field"><label class="form-label">Noturno 60%</label><input class="form-control" name="he_noturno_60" placeholder="0"></div>
                <div class="form-field"><label class="form-label">Noturno 100%</label><input class="form-control" name="he_noturno_100" placeholder="0"></div>
                <div class="form-field" style="grid-column:1/-1;">
                    <label class="form-label">Observação (opcional)</label>
                    <input class="form-control" name="observacao" maxlength="255" placeholder="Ex.: saldo setembro aguardando pagamento">
                </div>
                <div class="form-field">
                    <button class="btn btn-primary" type="submit">Registrar saldo</button>
                </div>
            </form>
        <?php endif; ?>

        <?php if ($pendentes === []): ?>
            <div class="empty-state">
                <h3>Nenhum saldo registrado</h3>
                <p>Se não há HE antiga em aberto, deixe esta aba vazia. O relatório do mês atual continua normal.</p>
            </div>
        <?php else: ?>
            <?php foreach ($pendentes as $comp => $rows): ?>
                <div>
                    <h3 class="card-title" style="font-size:1rem;margin-bottom:.5rem;">Saldo de <?= View::e((string) $comp) ?></h3>
                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                            <tr>
                                <th>Colaborador</th><th>Chapa</th><th>ADN</th>
                                <th>D50</th><th>D60</th><th>D100</th>
                                <th>N50</th><th>N60</th><th>N100</th>
                                <th>Obs.</th><?php if ($podeOperar): ?><th></th><?php endif; ?>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($rows as $row): ?>
                                <tr>
                                    <td><?= View::e((string) $row['nome']) ?></td>
                                    <td><?= View::e((string) $row['matricula']) ?></td>
                                    <td><?= View::e($fmt((int) $row['adn_min'])) ?></td>
                                    <td><?= View::e($fmt((int) $row['he_diurno_50_min'])) ?></td>
                                    <td><?= View::e($fmt((int) $row['he_diurno_60_min'])) ?></td>
                                    <td><?= View::e($fmt((int) $row['he_diurno_100_min'])) ?></td>
                                    <td><?= View::e($fmt((int) $row['he_noturno_50_min'])) ?></td>
                                    <td><?= View::e($fmt((int) $row['he_noturno_60_min'])) ?></td>
                                    <td><?= View::e($fmt((int) $row['he_noturno_100_min'])) ?></td>
                                    <td><?= View::e((string) ($row['observacao'] ?? '')) ?></td>
                                    <?php if ($podeOperar): ?>
                                        <td>
                                            <form method="post" action="/rh/relatorio-he/pendentes/<?= (int) $row['id'] ?>/excluir" style="display:inline;"
                                                  data-confirm-title="Remover saldo?"
                                                  data-confirm="Remove este saldo de mês anterior."
                                                  data-confirm-ok="Remover"
                                                  data-confirm-tone="danger">
                                                <?= Csrf::field() ?>
                                                <input type="hidden" name="data_inicio" value="<?= View::e($dataInicio) ?>">
                                                <input type="hidden" name="data_fim" value="<?= View::e($dataFim) ?>">
                                                <button class="btn btn-ghost btn-sm" type="submit" style="color:#B42318;">Excluir</button>
                                            </form>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<?php endif; ?>

<script>
(() => {
  const sel = document.getElementById('hePeriodo');
  const ini = document.getElementById('heIni');
  const fim = document.getElementById('heFim');
  if (!sel || !ini || !fim) return;
  sel.addEventListener('change', () => {
    const opt = sel.options[sel.selectedIndex];
    if (!opt || !opt.value) return;
    ini.value = opt.dataset.inicio || '';
    fim.value = opt.dataset.fim || '';
  });
})();
</script>
