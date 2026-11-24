<?php

use App\Helpers\Csrf;
use App\Helpers\View;

$entrega = is_array($entrega ?? null) ? $entrega : null;
$itens = $itens ?? [];
$anexos = $anexos ?? [];
$colaboradores = $colaboradores ?? [];
$tipos = $tipos ?? [];
$gestoresArcadis = $gestoresArcadis ?? [];
$supervisores = $supervisores ?? [];
$flash = $flash ?? null;
$error = $error ?? null;
$dbOk = $dbOk ?? true;
$podeGerir = !empty($podeGerir);

$tiposEquip = array_values(array_filter($tipos, static fn($t) => ($t['categoria'] ?? '') === 'equipamento'));
$tiposPerif = array_values(array_filter($tipos, static fn($t) => ($t['categoria'] ?? '') === 'periferico'));

$id = $entrega ? (int) $entrega['id'] : 0;
$status = (string) ($entrega['status'] ?? 'rascunho');
$encerrada = in_array($status, ['devolvido', 'cancelado'], true);
$editavel = $podeGerir && !$encerrada;
$temPdf = !empty($entrega['arquivo_path']);
$podeAnexar = $temPdf || in_array($status, ['emitido', 'assinado_anexado', 'devolvido'], true);
$podeDevolver = in_array($status, ['emitido', 'assinado_anexado', 'devolvido'], true);

$equip = [];
$perif = [];
foreach ($itens as $it) {
    if (($it['tipo'] ?? '') === 'periferico') {
        $perif[] = $it;
    } else {
        $equip[] = $it;
    }
}
if ($equip === []) {
    $equip[] = ['quantidade' => 1, 'descricao' => '', 'tombamento' => ''];
}
if ($perif === []) {
    $perif[] = ['quantidade' => 1, 'descricao' => '', 'tombamento' => ''];
}

$labels = [
    'rascunho' => 'Rascunho',
    'emitido' => 'Emitido',
    'assinado_anexado' => 'Assinado',
    'devolvido' => 'Devolvido',
    'cancelado' => 'Cancelado',
];

$v = static function (string $key, string $default = '') use ($entrega): string {
    return $entrega ? (string) ($entrega[$key] ?? $default) : $default;
};

// Passo inicial da jornada conforme o status.
$passoInicial = 1;
if ($id > 0) {
    if (in_array($status, ['assinado_anexado', 'devolvido'], true)) {
        $passoInicial = 4;
    } elseif ($status === 'emitido' || $temPdf) {
        $passoInicial = 4;
    } elseif (trim($v('colab_nome')) !== '' && trim((string) ($equip[0]['descricao'] ?? '')) !== '') {
        $passoInicial = 3;
    } elseif (trim($v('colab_nome')) !== '') {
        $passoInicial = 2;
    }
}

$nEquip = count(array_filter($equip, static fn($r) => trim((string) ($r['descricao'] ?? '')) !== ''));
$nPerif = count(array_filter($perif, static fn($r) => trim((string) ($r['descricao'] ?? '')) !== ''));
?>

<style>
.ent-jornada { --ent-accent: var(--brand-primary); }
.ent-top {
  display:flex; flex-wrap:wrap; gap:.75rem; align-items:center;
  justify-content:space-between; margin-bottom:1rem;
}
.ent-steps {
  display:flex; gap:.35rem; flex-wrap:wrap; margin:0 0 1.1rem;
  padding:.55rem; background:var(--canvas, #f6f5f3);
  border:1px solid var(--border, #e4e1db); border-radius:12px;
}
.ent-step {
  flex:1 1 8.5rem; min-width:7.5rem; border:0; background:transparent;
  border-radius:10px; padding:.65rem .75rem; text-align:left; cursor:pointer;
  color:var(--muted, #6b675f); transition:background .15s, color .15s, box-shadow .15s;
}
.ent-step:hover { background:rgba(255,255,255,.7); color:inherit; }
.ent-step.is-active {
  background:#fff; color:inherit;
  box-shadow:0 1px 0 rgba(0,0,0,.04), 0 6px 18px rgba(0,0,0,.06);
}
.ent-step.is-done .ent-step-num { background:var(--ent-accent); color:var(--brand-wisdom); }
.ent-step-num {
  display:inline-flex; align-items:center; justify-content:center;
  width:1.55rem; height:1.55rem; border-radius:999px;
  background:#ddd8d0; color:#444; font-size:.8rem; font-weight:700; margin-right:.45rem;
}
.ent-step.is-active .ent-step-num { background:#222; color:#fff; }
.ent-step-label { font-size:.82rem; font-weight:650; display:block; line-height:1.25; }
.ent-step-hint { font-size:.72rem; opacity:.72; display:block; margin-top:.15rem; }
.ent-pane { display:none; }
.ent-pane.is-active { display:block; }
.ent-nav {
  display:flex; flex-wrap:wrap; gap:.5rem; justify-content:space-between;
  align-items:center; margin-top:1.25rem; padding-top:1rem;
  border-top:1px solid var(--border, #e4e1db);
}
.ent-nav-right { display:flex; flex-wrap:wrap; gap:.5rem; margin-left:auto; }
.ent-summary {
  display:grid; grid-template-columns:repeat(auto-fit,minmax(140px,1fr));
  gap:.65rem; margin-bottom:1rem;
}
.ent-summary-item {
  background:var(--canvas, #f6f5f3); border:1px solid var(--border, #e4e1db);
  border-radius:10px; padding:.7rem .85rem;
}
.ent-summary-item strong { display:block; font-size:.72rem; text-transform:uppercase; letter-spacing:.04em; opacity:.65; margin-bottom:.2rem; }
#tblEquip .js-qtd, #tblPerif .js-qtd {
  min-width:4.75rem; padding:.55rem .35rem; text-align:center;
  font-weight:600; font-variant-numeric:tabular-nums;
}
.ent-action-card {
  border:1px solid var(--border, #e4e1db); border-radius:12px;
  padding:1rem; margin-bottom:.75rem; background:#fff;
}
.ent-action-card h3 { margin:0 0 .35rem; font-size:1rem; }
.ent-action-card p { margin:0 0 .75rem; color:var(--muted, #6b675f); font-size:.875rem; }
.ent-more { margin-top:.5rem; }
.ent-more > summary {
  cursor:pointer; color:var(--muted, #6b675f); font-size:.875rem; user-select:none;
}
.ent-more[open] > summary { margin-bottom:.65rem; }
@media (max-width:720px) {
  .ent-step { flex:1 1 calc(50% - .35rem); }
}
</style>

<?php if (!$dbOk): ?><div class="alert alert-warning">Banco indisponível.</div><?php endif; ?>
<?php if ($flash): ?><div class="alert alert-success"><?= View::e((string) $flash) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= View::e((string) $error) ?></div><?php endif; ?>

<div class="ent-jornada" id="entJornada" data-step="<?= (int) $passoInicial ?>">
    <div class="ent-top">
        <div style="display:flex;gap:.6rem;align-items:center;flex-wrap:wrap;">
            <a class="btn btn-secondary btn-sm" href="/rh/entregas-equipamentos">← Entregas</a>
            <?php if ($entrega): ?>
                <span class="badge"><?= View::e($labels[$status] ?? $status) ?> · <?= View::e((string) $entrega['codigo']) ?></span>
            <?php endif; ?>
        </div>
        <?php if ($temPdf): ?>
            <a class="btn btn-secondary btn-sm" href="/rh/entregas-equipamentos/<?= $id ?>/pdf" target="_blank">Ver PDF</a>
        <?php endif; ?>
    </div>

    <nav class="ent-steps" aria-label="Jornada da entrega">
        <button type="button" class="ent-step" data-goto="1">
            <span class="ent-step-num">1</span>
            <span class="ent-step-label">Colaborador</span>
            <span class="ent-step-hint">Quem recebe</span>
        </button>
        <button type="button" class="ent-step" data-goto="2">
            <span class="ent-step-num">2</span>
            <span class="ent-step-label">Itens</span>
            <span class="ent-step-hint">Equip. e periféricos</span>
        </button>
        <button type="button" class="ent-step" data-goto="3">
            <span class="ent-step-num">3</span>
            <span class="ent-step-label">Gestor</span>
            <span class="ent-step-hint">Responsável e projeto</span>
        </button>
        <button type="button" class="ent-step" data-goto="4" <?= $id > 0 ? '' : 'disabled title="Salve a entrega primeiro"' ?>>
            <span class="ent-step-num">4</span>
            <span class="ent-step-label">Termo</span>
            <span class="ent-step-hint">Emitir e concluir</span>
        </button>
    </nav>

    <form method="post"
          action="<?= $id > 0 ? '/rh/entregas-equipamentos/' . $id : '/rh/entregas-equipamentos' ?>"
          id="entregaForm">
        <?= Csrf::field() ?>
        <input type="hidden" name="voltar_passo" id="voltarPasso" value="<?= (int) $passoInicial ?>">

        <section class="card ent-pane" data-pane="1">
            <div class="card-header">
                <div>
                    <h2 class="card-title">1. Colaborador</h2>
                    <p class="card-subtitle">Selecione no cadastro — os dados vão para o termo.</p>
                </div>
            </div>
            <div class="card-body">
                <div class="form-grid cols-2">
                    <div class="form-field" style="grid-column:1/-1;">
                        <label class="form-label">Colaborador</label>
                        <select class="form-control" name="colaborador_id" id="colabSelect" <?= $editavel ? '' : 'disabled' ?>>
                            <option value="">— Digitar manualmente —</option>
                            <?php foreach ($colaboradores as $c): ?>
                                <option value="<?= (int) $c['id'] ?>"
                                    <?= (int) $v('colaborador_id') === (int) $c['id'] ? 'selected' : '' ?>>
                                    <?= View::e((string) $c['nome']) ?>
                                    <?php if (!empty($c['matricula'])): ?> (<?= View::e((string) $c['matricula']) ?>)<?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-field">
                        <label class="form-label">Nome *</label>
                        <input class="form-control" name="colab_nome" id="colab_nome" required
                               value="<?= View::e($v('colab_nome')) ?>" <?= $editavel ? '' : 'readonly' ?>>
                    </div>
                    <div class="form-field">
                        <label class="form-label">CPF</label>
                        <input class="form-control" name="colab_cpf" id="colab_cpf"
                               value="<?= View::e($v('colab_cpf')) ?>" <?= $editavel ? '' : 'readonly' ?>>
                    </div>
                    <div class="form-field">
                        <label class="form-label">Telefone</label>
                        <input class="form-control" name="colab_telefone" id="colab_telefone"
                               value="<?= View::e($v('colab_telefone')) ?>" <?= $editavel ? '' : 'readonly' ?>>
                    </div>
                    <div class="form-field">
                        <label class="form-label">E-mail</label>
                        <input class="form-control" name="colab_email" id="colab_email" type="email"
                               value="<?= View::e($v('colab_email')) ?>" <?= $editavel ? '' : 'readonly' ?>>
                    </div>
                    <div class="form-field">
                        <label class="form-label">Local do termo</label>
                        <input class="form-control" name="local_termo"
                               value="<?= View::e($v('local_termo', 'Parauapebas-PA')) ?>" <?= $editavel ? '' : 'readonly' ?>>
                    </div>
                    <div class="form-field">
                        <label class="form-label">Data do termo</label>
                        <input class="form-control" type="date" name="data_termo"
                               value="<?= View::e($v('data_termo', date('Y-m-d'))) ?>" <?= $editavel ? '' : 'readonly' ?>>
                    </div>
                </div>

                <details class="ent-more">
                    <summary>Endereço, RG e dados PJ (opcional)</summary>
                    <div class="form-grid cols-2">
                        <div class="form-field">
                            <label class="form-label">RG</label>
                            <input class="form-control" name="colab_rg" id="colab_rg"
                                   value="<?= View::e($v('colab_rg')) ?>" <?= $editavel ? '' : 'readonly' ?>>
                        </div>
                        <div class="form-field" style="grid-column:1/-1;">
                            <label class="form-label">Endereço</label>
                            <input class="form-control" name="colab_endereco" id="colab_endereco"
                                   value="<?= View::e($v('colab_endereco')) ?>" <?= $editavel ? '' : 'readonly' ?>>
                        </div>
                        <div class="form-field">
                            <label class="form-label">Bairro</label>
                            <input class="form-control" name="colab_bairro" id="colab_bairro"
                                   value="<?= View::e($v('colab_bairro')) ?>" <?= $editavel ? '' : 'readonly' ?>>
                        </div>
                        <div class="form-field">
                            <label class="form-label">Município</label>
                            <input class="form-control" name="colab_municipio" id="colab_municipio"
                                   value="<?= View::e($v('colab_municipio')) ?>" <?= $editavel ? '' : 'readonly' ?>>
                        </div>
                        <div class="form-field">
                            <label class="form-label">Razão social (PJ)</label>
                            <input class="form-control" name="colab_razao_social"
                                   value="<?= View::e($v('colab_razao_social')) ?>" <?= $editavel ? '' : 'readonly' ?>>
                        </div>
                        <div class="form-field">
                            <label class="form-label">CNPJ (PJ)</label>
                            <input class="form-control" name="colab_cnpj"
                                   value="<?= View::e($v('colab_cnpj')) ?>" <?= $editavel ? '' : 'readonly' ?>>
                        </div>
                    </div>
                </details>

                <div class="ent-nav">
                    <span class="muted text-sm">Próximo: equipamentos e periféricos</span>
                    <div class="ent-nav-right">
                        <?php if ($editavel): ?>
                            <button class="btn btn-secondary" type="submit" name="acao_salvar" value="1">Salvar</button>
                        <?php endif; ?>
                        <button class="btn btn-primary" type="button" data-next="2">Continuar</button>
                    </div>
                </div>
            </div>
        </section>

        <section class="card ent-pane" data-pane="2">
            <div class="card-header">
                <div>
                    <h2 class="card-title">2. Itens entregues</h2>
                    <p class="card-subtitle">Escolha no catálogo. Tombamento = nº patrimônio / service tag.</p>
                </div>
            </div>
            <div class="card-body">
                <h3 style="margin:0 0 .5rem;font-size:.95rem;">Equipamentos</h3>
                <?php if ($tiposEquip === []): ?>
                    <p class="form-hint">Cadastre em <a href="/rh/entregas-equipamentos/equipamentos">Equipamentos</a>.</p>
                <?php endif; ?>
                <div class="table-wrap">
                    <table class="table" id="tblEquip">
                        <thead>
                        <tr>
                            <th style="width:7rem;">Qtd</th>
                            <th>Equipamento</th>
                            <th style="width:14rem;">Nº patrimônio / Service Tag</th>
                            <?php if ($editavel): ?><th style="width:3rem;"></th><?php endif; ?>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($equip as $row):
                            $descAtual = (string) ($row['descricao'] ?? '');
                            ?>
                            <tr>
                                <td>
                                    <input type="hidden" name="item_tipo[]" value="equipamento">
                                    <input class="form-control js-qtd" name="item_qtd[]" type="number" min="1" step="1"
                                           inputmode="numeric" value="<?= max(1, (int) ($row['quantidade'] ?? 1)) ?>"
                                           <?= $editavel ? '' : 'readonly' ?>>
                                </td>
                                <td>
                                    <?php if ($editavel): ?>
                                    <select class="form-control" name="item_desc[]">
                                        <option value="">— Selecione —</option>
                                        <?php foreach ($tiposEquip as $t): ?>
                                            <option value="<?= View::e((string) $t['nome']) ?>"
                                                <?= $descAtual === (string) $t['nome'] ? 'selected' : '' ?>>
                                                <?= View::e((string) $t['nome']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                        <?php if ($descAtual !== '' && !in_array($descAtual, array_column($tiposEquip, 'nome'), true)): ?>
                                            <option value="<?= View::e($descAtual) ?>" selected><?= View::e($descAtual) ?> (legado)</option>
                                        <?php endif; ?>
                                    </select>
                                    <?php else: ?>
                                        <input class="form-control" name="item_desc[]" value="<?= View::e($descAtual) ?>" readonly>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <input class="form-control" name="item_tomb[]"
                                           placeholder="Service tag / patrimônio"
                                           value="<?= View::e((string) ($row['tombamento'] ?? '')) ?>" <?= $editavel ? '' : 'readonly' ?>>
                                </td>
                                <?php if ($editavel): ?>
                                    <td><button type="button" class="btn btn-secondary btn-sm js-rm-row">×</button></td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($editavel): ?>
                    <button type="button" class="btn btn-secondary btn-sm" data-add="tblEquip" data-tipo="equipamento">+ Equipamento</button>
                <?php endif; ?>

                <h3 style="margin:1.1rem 0 .5rem;font-size:.95rem;">Periféricos</h3>
                <?php if ($tiposPerif === []): ?>
                    <p class="form-hint">Cadastre em <a href="/rh/entregas-equipamentos/perifericos">Periféricos</a>.</p>
                <?php endif; ?>
                <div class="table-wrap">
                    <table class="table" id="tblPerif">
                        <thead>
                        <tr>
                            <th style="width:7rem;">Qtd</th>
                            <th>Periférico</th>
                            <?php if ($editavel): ?><th style="width:3rem;"></th><?php endif; ?>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($perif as $row):
                            $descAtual = (string) ($row['descricao'] ?? '');
                            ?>
                            <tr>
                                <td>
                                    <input type="hidden" name="item_tipo[]" value="periferico">
                                    <input class="form-control js-qtd" name="item_qtd[]" type="number" min="1" step="1"
                                           inputmode="numeric" value="<?= max(1, (int) ($row['quantidade'] ?? 1)) ?>"
                                           <?= $editavel ? '' : 'readonly' ?>>
                                </td>
                                <td>
                                    <?php if ($editavel): ?>
                                    <select class="form-control" name="item_desc[]">
                                        <option value="">— Selecione —</option>
                                        <?php foreach ($tiposPerif as $t): ?>
                                            <option value="<?= View::e((string) $t['nome']) ?>"
                                                <?= $descAtual === (string) $t['nome'] ? 'selected' : '' ?>>
                                                <?= View::e((string) $t['nome']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                        <?php if ($descAtual !== '' && !in_array($descAtual, array_column($tiposPerif, 'nome'), true)): ?>
                                            <option value="<?= View::e($descAtual) ?>" selected><?= View::e($descAtual) ?> (legado)</option>
                                        <?php endif; ?>
                                    </select>
                                    <?php else: ?>
                                        <input class="form-control" name="item_desc[]" value="<?= View::e($descAtual) ?>" readonly>
                                    <?php endif; ?>
                                    <input type="hidden" name="item_tomb[]" value="">
                                </td>
                                <?php if ($editavel): ?>
                                    <td><button type="button" class="btn btn-secondary btn-sm js-rm-row">×</button></td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($editavel): ?>
                    <button type="button" class="btn btn-secondary btn-sm" data-add="tblPerif" data-tipo="periferico">+ Periférico</button>
                <?php endif; ?>

                <script type="application/json" id="optEquipJson"><?= json_encode(array_column($tiposEquip, 'nome'), JSON_UNESCAPED_UNICODE) ?></script>
                <script type="application/json" id="optPerifJson"><?= json_encode(array_column($tiposPerif, 'nome'), JSON_UNESCAPED_UNICODE) ?></script>

                <div class="ent-nav">
                    <button class="btn btn-secondary" type="button" data-prev="1">Voltar</button>
                    <div class="ent-nav-right">
                        <?php if ($editavel): ?>
                            <button class="btn btn-secondary" type="submit" name="acao_salvar" value="1">Salvar</button>
                        <?php endif; ?>
                        <button class="btn btn-primary" type="button" data-next="3">Continuar</button>
                    </div>
                </div>
            </div>
        </section>

        <section class="card ent-pane" data-pane="3">
            <div class="card-header">
                <div>
                    <h2 class="card-title">3. Gestor responsável</h2>
                    <p class="card-subtitle">Do cadastro de Gestor Arcadis e Supervisor.</p>
                </div>
            </div>
            <div class="card-body">
                <div class="form-grid cols-2">
                    <div class="form-field">
                        <label class="form-label">Gestor Arcadis</label>
                        <select class="form-control" name="gestor_arcadis_id" <?= $editavel ? '' : 'disabled' ?>>
                            <option value="">— Selecione —</option>
                            <?php foreach ($gestoresArcadis as $g): ?>
                                <option value="<?= (int) $g['id'] ?>"
                                    <?= (string) $v('gestor_arcadis_id') === (string) $g['id'] ? 'selected' : '' ?>>
                                    <?= View::e(trim(($g['matricula'] ?? '') . ' — ' . ($g['nome'] ?? ''), ' —')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($gestoresArcadis === []): ?>
                            <div class="form-hint">Cadastre em <a href="/rh/gestores-arcadis">Gestor Arcadis</a>.</div>
                        <?php endif; ?>
                        <?php if (!$editavel): ?>
                            <input type="hidden" name="gestor_arcadis_id" value="<?= View::e($v('gestor_arcadis_id')) ?>">
                        <?php endif; ?>
                    </div>
                    <div class="form-field">
                        <label class="form-label">Supervisor</label>
                        <select class="form-control" name="supervisor_id" <?= $editavel ? '' : 'disabled' ?>>
                            <option value="">— Selecione —</option>
                            <?php foreach ($supervisores as $s): ?>
                                <option value="<?= (int) $s['id'] ?>"
                                    <?= (string) $v('supervisor_id') === (string) $s['id'] ? 'selected' : '' ?>>
                                    <?= View::e(trim(($s['matricula'] ?? '') . ' — ' . ($s['nome'] ?? ''), ' —')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($supervisores === []): ?>
                            <div class="form-hint">Cadastre em <a href="/rh/supervisores">Supervisor</a>.</div>
                        <?php endif; ?>
                        <?php if (!$editavel): ?>
                            <input type="hidden" name="supervisor_id" value="<?= View::e($v('supervisor_id')) ?>">
                        <?php endif; ?>
                    </div>
                    <div class="form-field">
                        <label class="form-label">CPF do gestor (opcional)</label>
                        <input class="form-control" name="gestor_cpf"
                               value="<?= View::e($v('gestor_cpf')) ?>" <?= $editavel ? '' : 'readonly' ?>>
                    </div>
                    <div class="form-field">
                        <label class="form-label">Projeto</label>
                        <input class="form-control" name="projeto_nome"
                               value="<?= View::e($v('projeto_nome')) ?>" <?= $editavel ? '' : 'readonly' ?>>
                    </div>
                    <div class="form-field">
                        <label class="form-label">Centro de custo</label>
                        <input class="form-control" name="centro_custo"
                               value="<?= View::e($v('centro_custo')) ?>" <?= $editavel ? '' : 'readonly' ?>>
                    </div>
                    <div class="form-field">
                        <label class="form-label">Observação interna</label>
                        <input class="form-control" name="observacao"
                               value="<?= View::e($v('observacao')) ?>" <?= $editavel ? '' : 'readonly' ?>>
                    </div>
                </div>

                <div class="ent-nav">
                    <button class="btn btn-secondary" type="button" data-prev="2">Voltar</button>
                    <div class="ent-nav-right">
                        <?php if ($editavel): ?>
                            <button class="btn btn-primary" type="submit" name="acao_salvar" value="1">
                                <?= $id > 0 ? 'Salvar e ir ao termo' : 'Salvar entrega' ?>
                            </button>
                        <?php elseif ($id > 0): ?>
                            <button class="btn btn-primary" type="button" data-next="4">Ir ao termo</button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </section>
    </form>

    <section class="card ent-pane" data-pane="4">
        <div class="card-header">
            <div>
                <h2 class="card-title">4. Emitir e concluir</h2>
                <p class="card-subtitle">Gere o PDF, anexe o assinado e registre a devolução quando houver.</p>
            </div>
        </div>
        <div class="card-body">
            <?php if ($id <= 0): ?>
                <div class="alert alert-warning">Salve a entrega (passos 1–3) antes de emitir o termo.</div>
            <?php else: ?>
                <div class="ent-summary">
                    <div class="ent-summary-item">
                        <strong>Colaborador</strong>
                        <?= View::e($v('colab_nome') !== '' ? $v('colab_nome') : '—') ?>
                    </div>
                    <div class="ent-summary-item">
                        <strong>Itens</strong>
                        <?= (int) $nEquip ?> equip. · <?= (int) $nPerif ?> perif.
                    </div>
                    <div class="ent-summary-item">
                        <strong>Gestor</strong>
                        <?= View::e($v('gestor_nome') !== '' ? $v('gestor_nome') : '—') ?>
                    </div>
                    <div class="ent-summary-item">
                        <strong>Status</strong>
                        <?= View::e($labels[$status] ?? $status) ?>
                    </div>
                </div>

                <?php if ($podeGerir): ?>
                <div class="ent-action-card">
                    <h3>Emitir termo PDF</h3>
                    <p>Gera o COR-JUR-FM-005 com os dados desta entrega.</p>
                    <div style="display:flex;flex-wrap:wrap;gap:.5rem;">
                        <?php if (!$encerrada): ?>
                        <form method="post" action="/rh/entregas-equipamentos/<?= $id ?>/emitir">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="download" value="1">
                            <button class="btn btn-primary" type="submit"><?= $temPdf ? 'Reemitir PDF' : 'Gerar termo PDF' ?></button>
                        </form>
                        <?php endif; ?>
                        <?php if ($temPdf): ?>
                            <a class="btn btn-secondary" href="/rh/entregas-equipamentos/<?= $id ?>/pdf" target="_blank">Abrir PDF</a>
                        <?php endif; ?>
                        <?php if ($status !== 'cancelado' && $status !== 'devolvido'): ?>
                        <form method="post" action="/rh/entregas-equipamentos/<?= $id ?>/cancelar"
                              data-confirm="Cancelar esta entrega?">
                            <?= Csrf::field() ?>
                            <button class="btn btn-secondary" type="submit">Cancelar</button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($podeAnexar): ?>
                <div class="ent-action-card">
                    <h3>Anexar termo assinado</h3>
                    <p>PDF ou imagem do termo já assinado.</p>
                    <form method="post" action="/rh/entregas-equipamentos/<?= $id ?>/anexar-assinado"
                          enctype="multipart/form-data" class="form-grid cols-2" style="align-items:end;">
                        <?= Csrf::field() ?>
                        <div class="form-field">
                            <input class="form-control" type="file" name="arquivo" accept=".pdf,image/*" required>
                        </div>
                        <div class="form-field">
                            <button class="btn btn-secondary" type="submit">Enviar anexo</button>
                        </div>
                    </form>
                </div>
                <?php endif; ?>

                <?php if ($podeDevolver): ?>
                <div class="ent-action-card">
                    <h3>Registrar devolução</h3>
                    <p>Quando os equipamentos forem devolvidos.</p>
                    <form method="post" action="/rh/entregas-equipamentos/<?= $id ?>/devolucao"
                          enctype="multipart/form-data" class="form-grid cols-2">
                        <?= Csrf::field() ?>
                        <div class="form-field">
                            <label class="form-label">Data *</label>
                            <input class="form-control" type="date" name="devolucao_data" required
                                   value="<?= View::e($v('devolucao_data', date('Y-m-d'))) ?>">
                        </div>
                        <div class="form-field">
                            <label class="form-label">Condição *</label>
                            <select class="form-control" name="devolucao_condicao" required>
                                <option value="bom_estado" <?= $v('devolucao_condicao') === 'bom_estado' ? 'selected' : '' ?>>Bom estado</option>
                                <option value="com_defeito" <?= $v('devolucao_condicao') === 'com_defeito' ? 'selected' : '' ?>>Com defeito</option>
                            </select>
                        </div>
                        <div class="form-field" style="grid-column:1/-1;">
                            <label class="form-label">Descrição do defeito</label>
                            <textarea class="form-control" name="devolucao_descricao" rows="2"><?= View::e($v('devolucao_descricao')) ?></textarea>
                        </div>
                        <div class="form-field">
                            <label class="form-label">Quem atesta</label>
                            <input class="form-control" name="devolucao_nome"
                                   value="<?= View::e($v('devolucao_nome', $v('colab_nome'))) ?>">
                        </div>
                        <div class="form-field">
                            <label class="form-label">Anexo (opcional)</label>
                            <input class="form-control" type="file" name="arquivo" accept=".pdf,image/*">
                        </div>
                        <div class="form-field" style="grid-column:1/-1;">
                            <button class="btn btn-primary" type="submit">Registrar devolução</button>
                        </div>
                    </form>
                </div>
                <?php endif; ?>
                <?php endif; ?>

                <?php if ($anexos !== []): ?>
                <div class="ent-action-card">
                    <h3>Anexos</h3>
                    <ul style="margin:0;padding-left:1.1rem;">
                        <?php foreach ($anexos as $a): ?>
                            <li>
                                <a href="/rh/entregas-equipamentos/<?= $id ?>/anexo/<?= (int) $a['id'] ?>" target="_blank">
                                    <?= View::e((string) ($a['arquivo_nome'] ?? 'arquivo')) ?>
                                </a>
                                <span class="muted text-sm">(<?= View::e((string) ($a['tipo'] ?? '')) ?>)</span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
            <?php endif; ?>

            <div class="ent-nav">
                <button class="btn btn-secondary" type="button" data-prev="3">Voltar</button>
            </div>
        </div>
    </section>
</div>

<script>
(function () {
    var root = document.getElementById('entJornada');
    if (!root) return;
    var form = document.getElementById('entregaForm');
    var passoInput = document.getElementById('voltarPasso');
    var maxStep = 4;

    function setStep(n) {
        n = Math.max(1, Math.min(maxStep, parseInt(n, 10) || 1));
        if (n === 4 && !<?= $id > 0 ? 'true' : 'false' ?>) {
            n = 3;
        }
        root.setAttribute('data-step', String(n));
        if (passoInput) passoInput.value = String(n);
        root.querySelectorAll('.ent-pane').forEach(function (p) {
            p.classList.toggle('is-active', p.getAttribute('data-pane') === String(n));
        });
        root.querySelectorAll('.ent-step').forEach(function (btn) {
            var g = parseInt(btn.getAttribute('data-goto'), 10);
            btn.classList.toggle('is-active', g === n);
            btn.classList.toggle('is-done', g < n);
        });
        try { history.replaceState(null, '', '#passo=' + n); } catch (e) {}
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    var fromHash = (location.hash.match(/passo=(\d+)/) || [])[1];
    setStep(fromHash || root.getAttribute('data-step') || 1);

    root.addEventListener('click', function (ev) {
        var t = ev.target.closest('[data-goto],[data-next],[data-prev]');
        if (!t || t.disabled) return;
        if (t.hasAttribute('data-goto')) setStep(t.getAttribute('data-goto'));
        if (t.hasAttribute('data-next')) setStep(t.getAttribute('data-next'));
        if (t.hasAttribute('data-prev')) setStep(t.getAttribute('data-prev'));
    });

    if (form) {
        form.addEventListener('submit', function () {
            if (passoInput) {
                // Após salvar no passo 3, abrir o termo.
                var active = root.querySelector('.ent-pane.is-active');
                var pane = active ? active.getAttribute('data-pane') : '1';
                passoInput.value = pane === '3' ? '4' : pane;
            }
        });
    }

    var sel = document.getElementById('colabSelect');
    if (sel) {
        sel.addEventListener('change', function () {
            var id = sel.value;
            if (!id) return;
            fetch('/rh/entregas-equipamentos/colaborador/' + id, {
                headers: { 'Accept': 'application/json' }
            }).then(function (r) { return r.json(); }).then(function (d) {
                if (!d || !d.ok) return;
                var map = {
                    colab_nome: d.nome, colab_cpf: d.cpf, colab_rg: d.rg,
                    colab_telefone: d.telefone, colab_email: d.email,
                    colab_endereco: d.endereco, colab_bairro: d.bairro, colab_municipio: d.municipio
                };
                Object.keys(map).forEach(function (k) {
                    var el = document.getElementById(k);
                    if (el && map[k]) el.value = map[k];
                });
            }).catch(function () {});
        });
    }

    function optsHtml(tipo) {
        var el = document.getElementById(tipo === 'periferico' ? 'optPerifJson' : 'optEquipJson');
        var arr = [];
        try { arr = JSON.parse(el ? el.textContent : '[]') || []; } catch (e) { arr = []; }
        var html = '<option value="">— Selecione —</option>';
        arr.forEach(function (n) {
            html += '<option value="' + String(n).replace(/"/g, '&quot;') + '">' + String(n).replace(/</g, '&lt;') + '</option>';
        });
        return html;
    }

    document.querySelectorAll('[data-add]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var tbl = document.getElementById(btn.getAttribute('data-add'));
            var tipo = btn.getAttribute('data-tipo') || 'equipamento';
            if (!tbl) return;
            var tb = tbl.querySelector('tbody');
            var tr = document.createElement('tr');
            if (tipo === 'periferico') {
                tr.innerHTML =
                    '<td><input type="hidden" name="item_tipo[]" value="periferico">' +
                    '<input class="form-control js-qtd" name="item_qtd[]" type="number" min="1" step="1" inputmode="numeric" value="1"></td>' +
                    '<td><select class="form-control" name="item_desc[]">' + optsHtml(tipo) + '</select>' +
                    '<input type="hidden" name="item_tomb[]" value=""></td>' +
                    '<td><button type="button" class="btn btn-secondary btn-sm js-rm-row">×</button></td>';
            } else {
                tr.innerHTML =
                    '<td><input type="hidden" name="item_tipo[]" value="equipamento">' +
                    '<input class="form-control js-qtd" name="item_qtd[]" type="number" min="1" step="1" inputmode="numeric" value="1"></td>' +
                    '<td><select class="form-control" name="item_desc[]">' + optsHtml(tipo) + '</select></td>' +
                    '<td><input class="form-control" name="item_tomb[]" placeholder="Service tag / patrimônio" value=""></td>' +
                    '<td><button type="button" class="btn btn-secondary btn-sm js-rm-row">×</button></td>';
            }
            tb.appendChild(tr);
        });
    });

    document.addEventListener('click', function (ev) {
        var t = ev.target;
        if (t && t.classList && t.classList.contains('js-rm-row')) {
            var tr = t.closest('tr');
            var tb = tr && tr.parentElement;
            if (tr && tb && tb.querySelectorAll('tr').length > 1) tr.remove();
        }
    });
})();
</script>
