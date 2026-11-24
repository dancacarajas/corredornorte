<?php use App\Helpers\View; ?>

<style>
  .ds-swatch-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(140px,1fr)); gap:.75rem; }
  .ds-swatch { border:1px solid var(--border); border-radius:var(--radius-md); overflow:hidden; background:var(--surface); }
  .ds-swatch-chip { height:64px; }
  .ds-swatch-meta { padding:.65rem .75rem; }
  .ds-swatch-meta strong { display:block; font-size:var(--text-sm); font-weight:700; letter-spacing:var(--tracking); }
  .ds-swatch-meta span { display:block; font-size:var(--text-xs); color:var(--ink-muted); margin-top:.15rem; }
  .ds-tint-row { display:flex; gap:.35rem; flex-wrap:wrap; margin-top:.55rem; }
  .ds-tint { width:36px; height:36px; border-radius:8px; border:1px solid var(--border); }
  .ds-type-row { display:grid; gap:.85rem; }
  .ds-type-row > div { padding:.75rem 0; border-bottom:1px solid var(--border); }
  .ds-type-row > div:last-child { border-bottom:0; }
  .ds-rules { margin:0; padding-left:1.1rem; color:var(--ink-muted); font-size:var(--text-sm); line-height:1.55; }
  .ds-rules li + li { margin-top:.35rem; }
</style>

<div class="alert alert-brand">
    Design System Arcadis 2026 — Visual Identity (§3.1–3.3) aplicada à intranet RH. Tipografia Arial; FS Elliot reservada a marketing.
</div>

<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Regras de uso</h2>
            <p class="card-subtitle">Lead with primary · Orange as accent · Arial only</p>
        </div>
    </div>
    <div class="card-body">
        <ul class="ds-rules">
            <li><strong>Dominant:</strong> Wisdom White / Confidence Black (fundo e texto).</li>
            <li><strong>Support:</strong> neutros oficiais + Integrity (estrutura, links, info).</li>
            <li><strong>Accent:</strong> Heritage Orange para CTA e foco — não precisa dominar a tela.</li>
            <li><strong>Secundárias:</strong> Collaboration / Customer Success / Sustainability / Integrity só para status e categoria.</li>
            <li><strong>Tipo:</strong> Arial Regular/Bold/Italic; sem tracking; sem Arial Black.</li>
            <li><strong>Logo:</strong> full color em fundo claro; mono branco em Heritage/escuro; clear space e mínimo 102px.</li>
        </ul>
    </div>
</section>

<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Cores primárias</h2>
            <p class="card-subtitle">Heritage · Confidence · Wisdom (+ tintas oficiais)</p>
        </div>
    </div>
    <div class="card-body ds-swatch-grid">
        <article class="ds-swatch">
            <div class="ds-swatch-chip" style="background:var(--brand-heritage)"></div>
            <div class="ds-swatch-meta">
                <strong>Heritage</strong>
                <span>#E4610F · PMS 152C</span>
                <div class="ds-tint-row">
                    <span class="ds-tint" style="background:var(--brand-heritage-t1)" title="#EB894B"></span>
                    <span class="ds-tint" style="background:var(--brand-heritage-t2)" title="#F2B087"></span>
                    <span class="ds-tint" style="background:var(--brand-heritage-t3)" title="#F8D8C3"></span>
                    <span class="ds-tint" style="background:var(--brand-heritage-t4)" title="#FFEDE6"></span>
                </div>
            </div>
        </article>
        <article class="ds-swatch">
            <div class="ds-swatch-chip" style="background:var(--brand-confidence)"></div>
            <div class="ds-swatch-meta">
                <strong>Confidence</strong>
                <span>#1D1D1D · Black C</span>
            </div>
        </article>
        <article class="ds-swatch">
            <div class="ds-swatch-chip" style="background:var(--brand-wisdom); border-bottom:1px solid var(--border)"></div>
            <div class="ds-swatch-meta">
                <strong>Wisdom</strong>
                <span>#FFFFFF · White C</span>
            </div>
        </article>
        <article class="ds-swatch">
            <div class="ds-swatch-chip" style="background:var(--canvas)"></div>
            <div class="ds-swatch-meta">
                <strong>Canvas / Neutros</strong>
                <span>#F3F2F1 · #E7E6E6 · #C2C1C1 · #868484 · #494646</span>
            </div>
        </article>
    </div>
</section>

<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Cores secundárias</h2>
            <p class="card-subtitle">Suporte e status — nunca como primária da tela</p>
        </div>
    </div>
    <div class="card-body ds-swatch-grid">
        <article class="ds-swatch">
            <div class="ds-swatch-chip" style="background:var(--brand-collaboration)"></div>
            <div class="ds-swatch-meta">
                <strong>Collaboration</strong>
                <span>#9C266E · PMS 7648C</span>
                <div class="ds-tint-row">
                    <span class="ds-tint" style="background:var(--brand-collaboration-t1)"></span>
                    <span class="ds-tint" style="background:var(--brand-collaboration-t2)"></span>
                    <span class="ds-tint" style="background:var(--brand-collaboration-t3)"></span>
                    <span class="ds-tint" style="background:var(--brand-collaboration-t4)"></span>
                </div>
            </div>
        </article>
        <article class="ds-swatch">
            <div class="ds-swatch-chip" style="background:var(--brand-customer-success)"></div>
            <div class="ds-swatch-meta">
                <strong>Customer Success</strong>
                <span>#F1B434 · PMS 143C</span>
                <div class="ds-tint-row">
                    <span class="ds-tint" style="background:var(--brand-customer-success-t1)"></span>
                    <span class="ds-tint" style="background:var(--brand-customer-success-t2)"></span>
                    <span class="ds-tint" style="background:var(--brand-customer-success-t3)"></span>
                    <span class="ds-tint" style="background:var(--brand-customer-success-t4)"></span>
                </div>
            </div>
        </article>
        <article class="ds-swatch">
            <div class="ds-swatch-chip" style="background:var(--brand-sustainability)"></div>
            <div class="ds-swatch-meta">
                <strong>Sustainability</strong>
                <span>#007377 · PMS 322C</span>
                <div class="ds-tint-row">
                    <span class="ds-tint" style="background:var(--brand-sustainability-t1)"></span>
                    <span class="ds-tint" style="background:var(--brand-sustainability-t2)"></span>
                    <span class="ds-tint" style="background:var(--brand-sustainability-t3)"></span>
                    <span class="ds-tint" style="background:var(--brand-sustainability-t4)"></span>
                </div>
            </div>
        </article>
        <article class="ds-swatch">
            <div class="ds-swatch-chip" style="background:var(--brand-integrity)"></div>
            <div class="ds-swatch-meta">
                <strong>Integrity</strong>
                <span>#326295 · PMS 653C</span>
                <div class="ds-tint-row">
                    <span class="ds-tint" style="background:var(--brand-integrity-t1)"></span>
                    <span class="ds-tint" style="background:var(--brand-integrity-t2)"></span>
                    <span class="ds-tint" style="background:var(--brand-integrity-t3)"></span>
                    <span class="ds-tint" style="background:var(--brand-integrity-t4)"></span>
                </div>
            </div>
        </article>
    </div>
</section>

<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Tipografia Arial</h2>
            <p class="card-subtitle">Hierarquia do Brand Guidelines §3.3 (intranet)</p>
        </div>
    </div>
    <div class="card-body ds-type-row">
        <div>
            <div class="text-h1">Heading 1 — 19px Bold</div>
            <div class="text-xs muted">.text-h1 · var(--text-h1)</div>
        </div>
        <div>
            <div class="text-h2">Heading 2 — 16px Bold</div>
            <div class="text-xs muted">.text-h2 · var(--text-h2)</div>
        </div>
        <div>
            <div class="text-h3">Heading 3 — 14px Bold</div>
            <div class="text-xs muted">.text-h3 · var(--text-h3)</div>
        </div>
        <div>
            <div class="text-h4">Heading 4 — 12px Bold</div>
            <div class="text-xs muted">.text-h4 · var(--text-h4)</div>
        </div>
        <div>
            <div class="text-body">Body copy — texto de interface em Confidence Black, alinhado à esquerda, sem tracking.</div>
            <div class="text-xs muted">.text-body · var(--text-body)</div>
        </div>
    </div>
</section>

<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Botões e ações</h2>
            <p class="card-subtitle">Heritage como accent de CTA</p>
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
            <p class="card-subtitle">Foco com ring Heritage oficial</p>
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
            <h2 class="card-title">Status semânticos</h2>
            <p class="card-subtitle">Mapeados às secundárias Arcadis</p>
        </div>
    </div>
    <div class="card-body" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;">
        <span class="pill pill-success">Sustainability / sucesso</span>
        <span class="pill pill-warning">Customer Success / alerta</span>
        <span class="pill pill-info">Integrity / info</span>
        <span class="pill pill-collaboration">Collaboration</span>
        <span class="pill pill-brand">Heritage</span>
        <span class="pill pill-danger">Perigo</span>
        <span class="pill pill-neutral">Neutro</span>
        <span class="tag tag-high">Alta</span>
        <span class="tag tag-medium">Média</span>
        <span class="tag tag-low">Baixa</span>
    </div>
</section>

<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Alertas</h2>
            <p class="card-subtitle">Fundos soft oficiais</p>
        </div>
    </div>
    <div class="card-body section-stack">
        <div class="alert alert-brand">Brand — ação ou destaque Heritage</div>
        <div class="alert alert-success">Sucesso — Sustainability</div>
        <div class="alert alert-warning">Atenção — Customer Success</div>
        <div class="alert alert-info">Informação — Integrity</div>
        <div class="alert alert-danger">Erro / bloqueio</div>
    </div>
</section>

<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Tabela (amostra)</h2>
            <p class="card-subtitle">Listagens RH com pills semânticas</p>
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
