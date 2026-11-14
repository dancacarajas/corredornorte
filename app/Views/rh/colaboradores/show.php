<?php

use App\Helpers\View;

/** @var array<string,mixed> $colaborador */
/** @var array<string,mixed>|null $lotacaoAtual */
/** @var list<array<string,mixed>> $historico */
/** @var list<array<string,mixed>> $lotacoes */
?>

<?php if (!empty($flash)): ?>
    <div class="alert alert-success">
        <?php if ($flash === 'atualizado'): ?>
            Dados do colaborador atualizados.
        <?php elseif ($flash === 'excecao'): ?>
            Exceção individual registrada.
        <?php elseif ($flash === 'percentual_he'): ?>
            Percentual de hora extra atualizado.
        <?php else: ?>
            Transferência registrada com vigência.
        <?php endif; ?>
    </div>
<?php endif; ?>
<?php if (!empty($error)): ?>
    <div class="alert alert-danger"><?= View::e((string) $error) ?></div>
<?php endif; ?>

<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Dados cadastrais</h2>
            <p class="card-subtitle">Identificação do colaborador</p>
        </div>
        <a class="btn btn-primary btn-sm" href="/rh/colaboradores/<?= (int)$colaborador['id'] ?>/editar">Editar dados</a>
    </div>
    <div class="card-body form-grid cols-3">
        <div><div class="text-xs muted">Nome</div><div class="fw-700"><?= View::e((string)$colaborador['nome']) ?></div></div>
        <div><div class="text-xs muted">Matrícula</div><div class="fw-700"><?= View::e((string)$colaborador['matricula']) ?></div></div>
        <div><div class="text-xs muted">CPF</div><div class="fw-700"><?= View::e((string)$colaborador['cpf']) ?></div></div>
        <div><div class="text-xs muted">E-mail</div><div class="fw-700"><?= View::e((string)($colaborador['email_corp'] ?? '—')) ?></div></div>
        <div><div class="text-xs muted">Telefone</div><div class="fw-700"><?= View::e((string)($colaborador['telefone'] ?? '—')) ?></div></div>
        <div><div class="text-xs muted">Cargo / Função</div><div class="fw-700"><?= View::e(trim(($colaborador['cargo'] ?? '').' / '.($colaborador['funcao'] ?? ''), ' /')) ?></div></div>
        <div><div class="text-xs muted">HE via Gestor Cliente</div><div class="fw-700"><?= (($colaborador['he_fluxo'] ?? 'off') === 'cliente') ? 'Sim' : 'Não' ?></div></div>
        <div><div class="text-xs muted">Admissão</div><div class="fw-700"><?= View::e((string)($colaborador['data_admissao'] ?? '—')) ?></div></div>
        <div><div class="text-xs muted">Status</div><div class="fw-700"><?= View::e(ucfirst((string)$colaborador['status'])) ?></div></div>
    </div>
</section>

<section class="kpi-grid">
    <article class="card kpi-card">
        <div class="kpi-label">Status</div>
        <div class="kpi-value" style="font-size:1.35rem;"><?= View::e(ucfirst((string) $colaborador['status'])) ?></div>
        <div class="kpi-meta neutral">Mat. <?= View::e((string) $colaborador['matricula']) ?></div>
    </article>
    <article class="card kpi-card">
        <div class="kpi-label">Contrato</div>
        <div class="kpi-value" style="font-size:1.35rem;"><?= View::e((string) ($lotacaoAtual['contrato_codigo'] ?? '—')) ?></div>
        <div class="kpi-meta neutral"><?= View::e((string) ($lotacaoAtual['contrato_nome'] ?? '')) ?></div>
    </article>
    <article class="card kpi-card">
        <div class="kpi-label">CC / OS</div>
        <div class="kpi-value" style="font-size:1.35rem;">
            <?= View::e(trim(($lotacaoAtual['centro_custo_codigo'] ?? '—') . ' / ' . ($lotacaoAtual['os_codigo'] ?? '—'))) ?>
        </div>
        <div class="kpi-meta neutral"><?= View::e((string) ($lotacaoAtual['localidade_nome'] ?? '')) ?></div>
    </article>
    <article class="card kpi-card">
        <div class="kpi-label">Jornada / Escala</div>
        <div class="kpi-value" style="font-size:1.2rem;">
            <?= View::e((string) ($lotacaoAtual['jornada_codigo'] ?? '—')) ?>
        </div>
        <div class="kpi-meta neutral"><?= View::e((string) ($lotacaoAtual['escala_codigo'] ?? '')) ?></div>
    </article>
</section>

<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Lotação vigente</h2>
            <p class="card-subtitle">Herdada do pacote operacional — altere via transferência</p>
        </div>
        <span class="pill pill-brand">Somente leitura</span>
    </div>
    <div class="card-body">
        <?php if ($lotacaoAtual === null): ?>
            <div class="empty-state">
                <h3>Sem lotação vigente</h3>
                <p>Use Transferir lotação para vincular o colaborador a um pacote.</p>
            </div>
        <?php else: ?>
            <div class="form-grid cols-3">
                <div>
                    <div class="text-xs muted">Lotação</div>
                    <div class="fw-700"><?= View::e(($lotacaoAtual['codigo'] ?? '') . ' — ' . ($lotacaoAtual['nome'] ?? '')) ?></div>
                </div>
                <div>
                    <div class="text-xs muted">Horário previsto</div>
                    <div class="fw-700">
                        <?= View::e(substr((string) ($lotacaoAtual['entrada'] ?? ''), 0, 5)) ?>
                        ·
                        <?= View::e(substr((string) ($lotacaoAtual['intervalo_inicio'] ?? ''), 0, 5)) ?>-<?= View::e(substr((string) ($lotacaoAtual['intervalo_fim'] ?? ''), 0, 5)) ?>
                        ·
                        <?= View::e(substr((string) ($lotacaoAtual['saida'] ?? ''), 0, 5)) ?>
                    </div>
                </div>
                <div>
                    <div class="text-xs muted">Vigência</div>
                    <div class="fw-700">
                        desde <?= View::e((string) ($lotacaoAtual['vigencia_inicio'] ?? '')) ?>
                    </div>
                </div>
                <div>
                    <div class="text-xs muted">% Hora extra (efetivo)</div>
                    <div class="fw-700"><?= (int) ($lotacaoAtual['percentual_he'] ?? 50) ?>%</div>
                </div>
                <div style="grid-column:1/-1;">
                    <form method="post" action="/rh/colaboradores/<?= (int) $colaborador['id'] ?>/percentual-he" class="form-grid cols-3" style="align-items:end;">
                        <div class="form-field">
                            <label class="form-label">% HE deste colaborador</label>
                            <input class="form-control" type="number" name="percentual_he" min="1" max="200" step="1"
                                   value="<?= View::e((string) ($lotacaoAtual['colaborador_percentual_he'] ?? $lotacaoAtual['percentual_he'] ?? '50')) ?>"
                                   placeholder="<?= (int) ($lotacaoAtual['lotacao_percentual_he'] ?? 50) ?>">
                            <div class="form-hint">Padrão da lotação: <?= (int) ($lotacaoAtual['lotacao_percentual_he'] ?? 50) ?>%. Deixe em branco e salve para herdar.</div>
                        </div>
                        <div class="form-field">
                            <button class="btn btn-primary" type="submit">Salvar % HE</button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>
</section>

<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Configuração operacional herdada</h2>
            <p class="card-subtitle">Somente leitura — vem da lotação / exceção vigente</p>
        </div>
    </div>
    <div class="card-body form-grid cols-3">
        <?php
        $heranca = [
            'Jornada' => $lotacaoAtual['jornada_codigo'] ?? '—',
            'Escala' => $lotacaoAtual['escala_codigo'] ?? '—',
            'Entrada' => isset($lotacaoAtual['entrada']) ? substr((string)$lotacaoAtual['entrada'],0,5) : '—',
            'Intervalo' => trim(substr((string)($lotacaoAtual['intervalo_inicio']??''),0,5).'-'.substr((string)($lotacaoAtual['intervalo_fim']??''),0,5),'-') ?: '—',
            'Saída' => isset($lotacaoAtual['saida']) ? substr((string)$lotacaoAtual['saida'],0,5) : '—',
            'Carga diária' => isset($lotacaoAtual['carga_diaria_min']) ? sprintf('%02d:%02d', intdiv((int)$lotacaoAtual['carga_diaria_min'], 60), ((int)$lotacaoAtual['carga_diaria_min']) % 60) : '—',
            'Carga semanal' => isset($lotacaoAtual['carga_semanal_min']) ? sprintf('%02d:%02d', intdiv((int)$lotacaoAtual['carga_semanal_min'], 60), ((int)$lotacaoAtual['carga_semanal_min']) % 60) : '—',
            'Sábado' => !empty($lotacaoAtual['trabalha_sabado']) ? 'Sim' : 'Não',
            'Domingo' => !empty($lotacaoAtual['trabalha_domingo']) ? 'Sim' : 'Não',
            'Feriado' => !empty($lotacaoAtual['trabalha_feriado']) ? 'Sim' : 'Não',
            'Adicional noturno' => !empty($lotacaoAtual['adicional_noturno']) ? 'Sim' : 'Não',
            '% Hora extra' => ((int) ($lotacaoAtual['percentual_he'] ?? 50)) . '%',
            'Exceção ativa' => !empty($lotacaoAtual['excecao']) ? 'Sim' : 'Não',
        ];
        foreach ($heranca as $label => $val):
        ?>
            <div><div class="text-xs muted"><?= View::e($label) ?></div><div class="fw-700"><?= View::e((string)$val) ?></div></div>
        <?php endforeach; ?>
    </div>
</section>

<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Exceções individuais</h2>
            <p class="card-subtitle">Prioridade 1 no motor — vigência temporária</p>
        </div>
    </div>
    <?php if (empty($excecoes)): ?>
        <div class="empty-state"><p>Nenhuma exceção cadastrada.</p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Período</th><th>Jornada</th><th>Escala</th><th>Obs.</th></tr></thead>
                <tbody>
                <?php foreach ($excecoes as $x): ?>
                    <tr>
                        <td><?= View::e($x['vigencia_inicio'].' → '.$x['vigencia_fim']) ?></td>
                        <td><?= View::e((string)($x['jornada_codigo'] ?? '—')) ?></td>
                        <td><?= View::e((string)($x['escala_codigo'] ?? '—')) ?></td>
                        <td><?= View::e((string)($x['observacao'] ?? '')) ?></td>
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
            <h2 class="card-title">Histórico de lotações</h2>
            <p class="card-subtitle">Folhas passadas usam a lotação vigente na data</p>
        </div>
    </div>
    <?php if ($historico === []): ?>
        <div class="empty-state"><p>Sem histórico.</p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>Período</th>
                    <th>Lotação</th>
                    <th>% HE</th>
                    <th>Contrato</th>
                    <th>CC / OS</th>
                    <th>Localidade</th>
                    <th>Motivo</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($historico as $h): ?>
                    <tr>
                        <td>
                            <?= View::e((string) $h['vigencia_inicio']) ?>
                            →
                            <?= View::e((string) ($h['vigencia_fim'] ?? 'atual')) ?>
                        </td>
                        <td><?= View::e((string) $h['lotacao_codigo']) ?></td>
                        <td><?= (int) ($h['percentual_he_efetivo'] ?? $h['lotacao_percentual_he'] ?? 50) ?>%</td>
                        <td><?= View::e((string) $h['contrato_codigo']) ?></td>
                        <td><?= View::e($h['cc_codigo'] . ' / ' . $h['os_codigo']) ?></td>
                        <td><?= View::e($h['municipio'] . '/' . $h['uf']) ?></td>
                        <td><span class="pill pill-neutral"><?= View::e((string) $h['motivo']) ?></span></td>
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
            <h2 class="card-title">Auditoria de alterações</h2>
            <p class="card-subtitle">Histórico técnico de mudanças do colaborador</p>
        </div>
    </div>
    <?php if (empty($auditoria)): ?>
        <div class="empty-state"><p>Sem eventos de auditoria.</p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Quando</th><th>Ação</th><th>Detalhe</th></tr></thead>
                <tbody>
                <?php foreach ($auditoria as $a): ?>
                    <tr>
                        <td><?= View::e((string)$a['created_at']) ?></td>
                        <td><span class="pill pill-neutral"><?= View::e((string)$a['acao']) ?></span></td>
                        <td class="text-xs"><?= View::e((string)($a['depois_json'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<div class="modal-backdrop" id="transferModal">
    <div class="modal">
        <form method="post" action="/rh/colaboradores/<?= (int) $colaborador['id'] ?>/transferir">
            <div class="modal-header">
                <h3 class="card-title">Transferir lotação</h3>
                <button class="btn btn-ghost btn-sm" type="button" data-modal-close>Fechar</button>
            </div>
            <div class="modal-body form-grid cols-2">
                <div class="form-field" style="grid-column:1/-1;">
                    <label class="form-label">Nova lotação</label>
                    <select class="form-control" name="lotacao_id" required>
                        <option value="">Selecione</option>
                        <?php foreach ($lotacoes as $lot): ?>
                            <option value="<?= (int) $lot['id'] ?>"><?= View::e($lot['codigo'] . ' — ' . $lot['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-field">
                    <label class="form-label">Data da transferência</label>
                    <input class="form-control" type="date" name="data_transferencia" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="form-field">
                    <label class="form-label">% HE (opcional)</label>
                    <input class="form-control" type="number" name="percentual_he" min="1" max="200" step="1" placeholder="Herdar da lotação">
                    <div class="form-hint">Ex.: 50, 60 ou 70. Vazio = usa o % padrão da lotação.</div>
                </div>
                <div class="form-field" style="grid-column:1/-1;">
                    <label class="form-label">Observação</label>
                    <textarea class="form-control" name="observacao" placeholder="Motivo da realocação"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" type="button" data-modal-close>Cancelar</button>
                <button class="btn btn-primary" type="submit">Confirmar transferência</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-backdrop" id="excecaoModal">
    <div class="modal modal-lg">
        <form method="post" action="/rh/colaboradores/<?= (int)$colaborador['id'] ?>/excecoes">
            <div class="modal-header"><h3 class="card-title">Exceção individual</h3><button class="btn btn-ghost btn-sm" type="button" data-modal-close>Fechar</button></div>
            <div class="modal-body form-grid cols-2">
                <div class="form-field"><label class="form-label">Início</label><input class="form-control" type="date" name="vigencia_inicio" required value="<?= date('Y-m-d') ?>"></div>
                <div class="form-field"><label class="form-label">Fim</label><input class="form-control" type="date" name="vigencia_fim" required></div>
                <div class="form-field"><label class="form-label">Jornada especial</label>
                    <select class="form-control" name="jornada_id"><option value="">Manter lotação</option>
                        <?php foreach (($jornadas ?? []) as $j): ?>
                            <option value="<?= (int)$j['id'] ?>"><?= View::e($j['codigo'].' — '.$j['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-field"><label class="form-label">Escala especial</label>
                    <select class="form-control" name="escala_id"><option value="">Manter lotação</option>
                        <?php foreach (($escalas ?? []) as $es): ?>
                            <option value="<?= (int)$es['id'] ?>"><?= View::e($es['codigo'].' — '.$es['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-field"><label class="form-label">Calendário</label>
                    <select class="form-control" name="calendario_id"><option value="">Manter lotação</option>
                        <?php foreach (($calendarios ?? []) as $cal): ?>
                            <option value="<?= (int)$cal['id'] ?>"><?= View::e($cal['codigo'].' — '.$cal['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-field" style="grid-column:1/-1;"><label class="form-label">Observação</label><textarea class="form-control" name="observacao"></textarea></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" type="button" data-modal-close>Cancelar</button>
                <button class="btn btn-primary" type="submit">Salvar exceção</button>
            </div>
        </form>
    </div>
</div>
