<?php use App\Helpers\View; ?>

<div class="alert alert-brand">
    Design System Arcadis — tokens da logo, shell ERP e componentes base prontos para o módulo RH.
</div>

<section class="kpi-grid">
    <article class="card kpi-card">
        <div class="kpi-label">Primária</div>
        <div class="kpi-value" style="color:var(--brand-primary)">#E06008</div>
        <div class="kpi-meta up">Brand primary</div>
    </article>
    <article class="card kpi-card">
        <div class="kpi-label">Soft</div>
        <div class="kpi-value">#FFF1E6</div>
        <div class="kpi-meta neutral">Fundos ativos</div>
    </article>
    <article class="card kpi-card">
        <div class="kpi-label">Ink</div>
        <div class="kpi-value">#111111</div>
        <div class="kpi-meta neutral">Texto / wordmark</div>
    </article>
    <article class="card kpi-card">
        <div class="kpi-label">Canvas</div>
        <div class="kpi-value">#F3F4F6</div>
        <div class="kpi-meta neutral">Fundo app</div>
    </article>
</section>

<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Botões e ações</h2>
            <p class="card-subtitle">Primário laranja Arcadis como CTA padrão</p>
        </div>
    </div>
    <div class="card-body" style="display:flex;gap:.75rem;flex-wrap:wrap;">
        <button class="btn btn-primary" type="button">Primário</button>
        <button class="btn btn-secondary" type="button">Secundário</button>
        <button class="btn btn-ghost" type="button">Ghost</button>
        <button class="btn btn-danger" type="button">Perigo</button>
        <button class="btn btn-primary btn-sm" type="button">Compacto</button>
        <button class="btn btn-primary" type="button" data-modal-open="demoModal">Abrir modal</button>
    </div>
</section>

<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Formulários</h2>
            <p class="card-subtitle">Campos com foco na cor da marca</p>
        </div>
    </div>
    <div class="card-body form-grid cols-3">
        <div class="form-field">
            <label class="form-label">Matrícula</label>
            <input class="form-control" type="text" placeholder="Ex: 10234">
        </div>
        <div class="form-field">
            <label class="form-label">Centro de custo</label>
            <select class="form-control">
                <option>CC-1234</option>
                <option>CC-200</option>
            </select>
        </div>
        <div class="form-field">
            <label class="form-label">Status</label>
            <select class="form-control">
                <option>Ativo</option>
                <option>Afastado</option>
            </select>
        </div>
    </div>
</section>

<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Status e prioridades</h2>
            <p class="card-subtitle">Pills para ponto, envio e assinatura</p>
        </div>
    </div>
    <div class="card-body" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;">
        <span class="pill pill-success">Enviado</span>
        <span class="pill pill-warning">Aguardando</span>
        <span class="pill pill-danger">Falha</span>
        <span class="pill pill-info">Em tratamento</span>
        <span class="pill pill-brand">Fechado</span>
        <span class="pill pill-neutral">Em aberto</span>
        <span class="tag tag-high">Alta</span>
        <span class="tag tag-medium">Média</span>
        <span class="tag tag-low">Baixa</span>
    </div>
</section>

<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Tabela de efetivo (amostra)</h2>
            <p class="card-subtitle">Padrão visual para listagens do RH</p>
        </div>
        <button class="btn btn-secondary btn-sm" type="button">Exportar</button>
    </div>
    <div class="table-wrap">
        <table class="table">
            <thead>
            <tr>
                <th>Colaborador</th>
                <th>Contrato</th>
                <th>CC / OS</th>
                <th>Localidade</th>
                <th>Status</th>
                <th>Prioridade</th>
            </tr>
            </thead>
            <tbody>
            <tr>
                <td>
                    <div style="display:flex;align-items:center;gap:.65rem;">
                        <div class="avatar">JS</div>
                        <div>
                            <div class="fw-700">João Silva</div>
                            <div class="text-xs muted">Mat. 10021</div>
                        </div>
                    </div>
                </td>
                <td>XYZ</td>
                <td>CC-123 / OS-002</td>
                <td>Parauapebas</td>
                <td><span class="pill pill-success">Ativo</span></td>
                <td><span class="tag tag-low">Baixa</span></td>
            </tr>
            <tr>
                <td>
                    <div style="display:flex;align-items:center;gap:.65rem;">
                        <div class="avatar">MS</div>
                        <div>
                            <div class="fw-700">Maria Silva</div>
                            <div class="text-xs muted">Mat. 10045</div>
                        </div>
                    </div>
                </td>
                <td>XYZ</td>
                <td>CC-200 / OS-001</td>
                <td>Parauapebas</td>
                <td><span class="pill pill-warning">Afastado</span></td>
                <td><span class="tag tag-medium">Média</span></td>
            </tr>
            <tr>
                <td>
                    <div style="display:flex;align-items:center;gap:.65rem;">
                        <div class="avatar">CS</div>
                        <div>
                            <div class="fw-700">Carlos Souza</div>
                            <div class="text-xs muted">Mat. 10088</div>
                        </div>
                    </div>
                </td>
                <td>ABC</td>
                <td>CC-100 / OS-003</td>
                <td>Marabá</td>
                <td><span class="pill pill-danger">Pendente</span></td>
                <td><span class="tag tag-high">Alta</span></td>
            </tr>
            </tbody>
        </table>
    </div>
</section>

<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Progresso operacional</h2>
            <p class="card-subtitle">Indicadores de competência / fechamento</p>
        </div>
    </div>
    <div class="card-body section-stack">
        <div>
            <div class="stack-row" style="margin-bottom:.4rem;">
                <span class="text-sm fw-700">Folhas tratadas</span>
                <span class="text-sm muted">94%</span>
            </div>
            <div class="progress is-success"><span style="width:94%"></span></div>
        </div>
        <div>
            <div class="stack-row" style="margin-bottom:.4rem;">
                <span class="text-sm fw-700">Enviadas</span>
                <span class="text-sm muted">86%</span>
            </div>
            <div class="progress"><span style="width:86%"></span></div>
        </div>
        <div>
            <div class="stack-row" style="margin-bottom:.4rem;">
                <span class="text-sm fw-700">Com inconsistência</span>
                <span class="text-sm muted">8%</span>
            </div>
            <div class="progress is-danger"><span style="width:8%"></span></div>
        </div>
    </div>
</section>

<div class="modal-backdrop" id="demoModal">
    <div class="modal">
        <div class="modal-header">
            <h3 class="card-title">Transferir lotação</h3>
            <button class="btn btn-ghost btn-sm" type="button" data-modal-close>Fechar</button>
        </div>
        <div class="modal-body form-grid cols-2">
            <div class="form-field">
                <label class="form-label">Nova lotação</label>
                <select class="form-control">
                    <option>Parauapebas · CC-200 · OS-001 · ADM-44H</option>
                </select>
            </div>
            <div class="form-field">
                <label class="form-label">Data da transferência</label>
                <input class="form-control" type="date" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="form-field" style="grid-column:1/-1;">
                <label class="form-label">Motivo</label>
                <textarea class="form-control" placeholder="Ex: realocação contratual"></textarea>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" type="button" data-modal-close>Cancelar</button>
            <button class="btn btn-primary" type="button">Confirmar transferência</button>
        </div>
    </div>
</div>
