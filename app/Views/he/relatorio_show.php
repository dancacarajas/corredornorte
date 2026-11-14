<?php

use App\Helpers\View;

/** @var array{relatorio:array,dias:list,contadores:array} $dados */
$dados = $dados ?? ['relatorio' => [], 'dias' => [], 'contadores' => []];
$rel = $dados['relatorio'];
$dias = $dados['dias'];
$cont = $dados['contadores'];
$somenteLeitura = (bool) ($somenteLeitura ?? false);
$contratos = $contratos ?? [];
$ordens = $ordens ?? [];
$flash = $flash ?? null;
$error = $error ?? null;
$documentoAssinatura = $documentoAssinatura ?? null;
$linkAssinatura = $linkAssinatura ?? null;

$ini = (string) ($rel['data_inicio'] ?? '');
$fim = (string) ($rel['data_fim'] ?? '');
$rotulo = ($ini && $fim) ? date('d/m/Y', strtotime($ini)) . ' a ' . date('d/m/Y', strtotime($fim)) : '—';
$contratoId = (int) ($rel['contrato_id'] ?? 0);
$osId = (int) ($rel['ordem_servico_id'] ?? 0);
$status = (string) ($rel['status'] ?? 'rascunho');
$heCliente = (($rel['he_fluxo'] ?? 'off') === 'cliente');
if ($linkAssinatura === null && is_array($documentoAssinatura) && !empty($documentoAssinatura['portal_token'])) {
    $config = require dirname(__DIR__, 3) . '/config/app.php';
    $base = rtrim((string) ($config['url'] ?? ''), '/');
    if (str_ends_with(strtolower($base), '/public')) {
        $base = substr($base, 0, -7);
    }
    $linkAssinatura = $base . '/assinar-relatorio/' . rawurlencode((string) $documentoAssinatura['portal_token']);
}
?>

<?php if ($flash): ?>
    <div class="alert alert-success"><?= View::e((string) $flash) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><?= View::e((string) $error) ?></div>
<?php endif; ?>

<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Relatório de Atividades</h2>
            <p class="card-subtitle">Período do cartão: <?= View::e($rotulo) ?> · Status: <?= View::e($status) ?></p>
        </div>
        <a class="btn btn-ghost btn-sm" href="/he/relatorios">Voltar</a>
    </div>
    <div class="card-body section-stack">
        <div style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:center;">
            <a class="btn btn-secondary btn-sm" href="/he/relatorios/<?= (int) $rel['id'] ?>/pdf" target="_blank" rel="noopener">Exportar PDF</a>
            <a class="btn btn-secondary btn-sm" href="/he/relatorios/<?= (int) $rel['id'] ?>/excel">Exportar Excel</a>
            <?php if (in_array($status, ['enviado', 'aprovado'], true)
                && (string) ($documentoAssinatura['status'] ?? '') !== 'assinado'): ?>
                <form method="post" action="/he/relatorios/<?= (int) $rel['id'] ?>/link-assinatura" style="margin:0;">
                    <button class="btn btn-primary btn-sm" type="submit">Gerar link de assinatura</button>
                </form>
            <?php endif; ?>
        </div>
        <?php if (is_string($linkAssinatura) && $linkAssinatura !== ''): ?>
            <div class="form-field">
                <label class="form-label" for="raLinkAssinatura">Link de assinatura eletrônica</label>
                <input class="form-control" id="raLinkAssinatura" value="<?= View::e($linkAssinatura) ?>" readonly onclick="this.select()">
                <?php if ((string) ($documentoAssinatura['status'] ?? '') === 'assinado'): ?>
                    <p class="form-hint">Relatório assinado eletronicamente.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <form method="post" action="/he/relatorios/<?= (int) $rel['id'] ?>" id="raForm">
            <div class="form-grid cols-2">
                <div class="form-field">
                    <label class="form-label">Colaborador</label>
                    <input class="form-control" value="<?= View::e((string) ($rel['nome'] ?? '')) ?>" readonly>
                </div>
                <div class="form-field">
                    <label class="form-label">Função</label>
                    <input class="form-control" value="<?= View::e((string) ($rel['funcao'] ?? $rel['cargo'] ?? '')) ?>" readonly>
                </div>
                <div class="form-field">
                    <label class="form-label">Contrato</label>
                    <select class="form-control" name="contrato_id" id="contratoId" <?= $somenteLeitura ? 'disabled' : '' ?>>
                        <option value="">Selecione…</option>
                        <?php foreach ($contratos as $c): ?>
                            <option value="<?= (int) $c['id'] ?>" <?= $contratoId === (int) $c['id'] ? 'selected' : '' ?>>
                                <?= View::e($c['codigo'] . ' — ' . $c['nome']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-field">
                    <label class="form-label">OS / Frente</label>
                    <select class="form-control" name="ordem_servico_id" id="ordemServicoId" <?= $somenteLeitura ? 'disabled' : '' ?>>
                        <option value="">Selecione…</option>
                        <?php foreach ($ordens as $o): ?>
                            <option value="<?= (int) $o['id'] ?>" <?= $osId === (int) $o['id'] ? 'selected' : '' ?>>
                                <?= View::e($o['codigo'] . ' — ' . $o['nome']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-field">
                    <label class="form-label">Gestor</label>
                    <input class="form-control" value="<?= View::e((string) ($rel['gestor_nome'] ?? '')) ?>" readonly>
                </div>
                <div class="form-field">
                    <label class="form-label">Resp. / Fiscal Vale</label>
                    <input class="form-control" name="fiscal_vale" value="<?= View::e((string) ($rel['fiscal_vale'] ?? '')) ?>" <?= $somenteLeitura ? 'readonly' : '' ?>>
                </div>
            </div>

            <div class="ra-counters">
                <span class="pill" data-count="remoto">Remoto (1): <?= (int) ($cont['remoto'] ?? 0) ?></span>
                <span class="pill" data-count="site">Site (2): <?= (int) ($cont['site'] ?? 0) ?></span>
                <span class="pill" data-count="viagem">Viagem (3): <?= (int) ($cont['viagem'] ?? 0) ?></span>
                <span class="pill">Área classificada: <?= (int) ($cont['classificada'] ?? 0) ?></span>
            </div>

            <p class="text-sm muted">
                (1) Remoto/home/sem refeitório Vale — VR contratada ·
                (2) Site Vale com refeitório ·
                (3) Viagem autorizada Vale
            </p>

            <div class="ra-table-wrap">
                <table class="ra-table">
                    <thead>
                    <tr>
                        <th>Data</th>
                        <th>Descrição das atividades</th>
                        <th>Área atendimento</th>
                        <th>Município</th>
                        <th>Projeto / Obra</th>
                        <th>Centro de custo</th>
                        <th>Área classif.</th>
                        <th>Ambiente</th>
                        <th>Observações</th>
                        <?php if ($heCliente): ?>
                            <th>HE início</th>
                            <th>HE fim</th>
                            <th>HE</th>
                        <?php endif; ?>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($dias as $i => $dia):
                        $tipo = (string) ($dia['tipo_dia'] ?? 'trabalho');
                        $data = (string) $dia['data'];
                        $amb = (string) ($dia['ambiente'] ?? '');
                        $heStatus = (string) ($dia['he_status'] ?? 'nenhuma');
                        $heLock = $somenteLeitura || $heStatus === 'aplicada';
                        $heIni = substr((string) ($dia['he_hora_inicio'] ?? ''), 0, 5);
                        $heFim = substr((string) ($dia['he_hora_fim'] ?? ''), 0, 5);
                        $rowClass = $tipo !== 'trabalho' ? 'ra-day-folga' : '';
                        ?>
                        <tr class="<?= $rowClass ?>">
                            <td>
                                <strong><?= View::e(date('d/m', strtotime($data))) ?></strong>
                                <div class="muted"><?= View::e($tipo) ?></div>
                                <input type="hidden" name="dias[<?= $i ?>][data]" value="<?= View::e($data) ?>">
                            </td>
                            <td>
                                <textarea name="dias[<?= $i ?>][descricao]" <?= $somenteLeitura ? 'readonly' : '' ?>><?= View::e((string) ($dia['descricao'] ?? '')) ?></textarea>
                            </td>
                            <td>
                                <input type="text" name="dias[<?= $i ?>][area_atendimento]" value="<?= View::e((string) ($dia['area_atendimento'] ?? '')) ?>" <?= $somenteLeitura ? 'readonly' : '' ?>>
                            </td>
                            <td>
                                <input type="text" name="dias[<?= $i ?>][municipio]" value="<?= View::e((string) ($dia['municipio'] ?? '')) ?>" <?= $somenteLeitura ? 'readonly' : '' ?>>
                            </td>
                            <td>
                                <input type="text" name="dias[<?= $i ?>][projeto_obra]" value="<?= View::e((string) ($dia['projeto_obra'] ?? '')) ?>" <?= $somenteLeitura ? 'readonly' : '' ?>>
                            </td>
                            <td>
                                <input type="text" name="dias[<?= $i ?>][centro_custo]" value="<?= View::e((string) ($dia['centro_custo'] ?? '')) ?>" <?= $somenteLeitura ? 'readonly' : '' ?>>
                            </td>
                            <td>
                                <label>
                                    <input type="checkbox" name="dias[<?= $i ?>][area_classificada]" value="1"
                                        <?= !empty($dia['area_classificada']) ? 'checked' : '' ?>
                                        <?= $somenteLeitura ? 'disabled' : '' ?>>
                                </label>
                            </td>
                            <td>
                                <div class="ra-ambiente" data-ambiente-group>
                                    <?php foreach (['remoto' => 'Remoto', 'site' => 'Site', 'viagem' => 'Viagem'] as $val => $lab): ?>
                                        <label>
                                            <input type="radio" name="dias[<?= $i ?>][ambiente]" value="<?= $val ?>"
                                                <?= $amb === $val ? 'checked' : '' ?>
                                                <?= $somenteLeitura ? 'disabled' : '' ?>
                                                data-ambiente>
                                            <?= View::e($lab) ?>
                                        </label>
                                    <?php endforeach; ?>
                                    <?php if (!$somenteLeitura): ?>
                                        <label>
                                            <input type="radio" name="dias[<?= $i ?>][ambiente]" value="" <?= $amb === '' ? 'checked' : '' ?> data-ambiente>
                                            —
                                        </label>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <input type="text" name="dias[<?= $i ?>][observacoes]" value="<?= View::e((string) ($dia['observacoes'] ?? '')) ?>" <?= $somenteLeitura ? 'readonly' : '' ?>>
                            </td>
                            <?php if ($heCliente): ?>
                                <td>
                                    <input type="time" name="dias[<?= $i ?>][he_hora_inicio]" value="<?= View::e($heIni) ?>" <?= $heLock ? 'readonly' : '' ?>>
                                </td>
                                <td>
                                    <input type="time" name="dias[<?= $i ?>][he_hora_fim]" value="<?= View::e($heFim) ?>" <?= $heLock ? 'readonly' : '' ?>>
                                </td>
                                <td class="muted text-sm">
                                    <?php
                                    echo match ($heStatus) {
                                        'declarada' => 'Declarada',
                                        'aplicada' => 'No ponto',
                                        'divergente' => 'Divergente',
                                        default => '—',
                                    };
                                    ?>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($heCliente && !$somenteLeitura): ?>
                <p class="muted text-sm" style="margin-top:.75rem;">
                    HE: informe início e fim nos dias em que fez hora extra. A autorização formal segue por e-mail ao gestor Vale;
                    o RH aplica no cartão após conferir o de acordo no SharePoint.
                    <?php if (!empty($rel['gestor_cliente_nome'])): ?>
                        Gestor Cliente: <strong><?= View::e((string) $rel['gestor_cliente_nome']) ?></strong>.
                    <?php endif; ?>
                </p>
            <?php endif; ?>

            <?php if (!$somenteLeitura): ?>
                <div style="display:flex;gap:.75rem;flex-wrap:wrap;margin-top:1rem;">
                    <button class="btn btn-secondary" type="submit" name="enviar" value="0">Salvar rascunho</button>
                    <button class="btn btn-primary" type="submit" name="enviar" value="1"
                            onclick="return confirm('Enviar o relatório? Dias de trabalho precisam ter ambiente informado.');">
                        Enviar relatório
                    </button>
                </div>
            <?php endif; ?>
        </form>
    </div>
</section>

<script>
(() => {
  const contrato = document.getElementById('contratoId');
  const os = document.getElementById('ordemServicoId');
  const selectedOs = <?= (int) $osId ?>;
  if (contrato && os && !contrato.disabled) {
    contrato.addEventListener('change', async () => {
      os.innerHTML = '<option value="">Carregando…</option>';
      try {
        const res = await fetch('/he/ordens?contrato_id=' + encodeURIComponent(contrato.value));
        const data = await res.json();
        os.innerHTML = '<option value="">Selecione…</option>';
        (data || []).forEach((item) => {
          const opt = document.createElement('option');
          opt.value = item.id;
          opt.textContent = item.codigo + ' — ' + item.nome;
          if (Number(item.id) === selectedOs) opt.selected = true;
          os.appendChild(opt);
        });
      } catch (e) {
        os.innerHTML = '<option value="">Falha ao carregar</option>';
      }
    });
  }

  const recount = () => {
    const totals = { remoto: 0, site: 0, viagem: 0 };
    document.querySelectorAll('input[data-ambiente]:checked').forEach((el) => {
      const v = el.value;
      if (totals[v] !== undefined) totals[v] += 1;
    });
    document.querySelectorAll('[data-count]').forEach((el) => {
      const k = el.getAttribute('data-count');
      if (!k) return;
      const labels = { remoto: 'Remoto (1)', site: 'Site (2)', viagem: 'Viagem (3)' };
      el.textContent = (labels[k] || k) + ': ' + (totals[k] || 0);
    });
  };
  document.querySelectorAll('input[data-ambiente]').forEach((el) => el.addEventListener('change', recount));
})();
</script>
