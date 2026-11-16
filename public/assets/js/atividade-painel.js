(() => {
  const root = document.getElementById('atividadeKpis');
  if (!root) return;

  const kpiOnline = document.getElementById('kpiOnline');
  const kpiHoje = document.getElementById('kpiHoje');
  const kpiAtivos = document.getElementById('kpiAtivos');
  const kpiLive = document.getElementById('kpiLive');
  const onlineGrid = document.getElementById('atividadeOnlineGrid');
  const onlineEmpty = document.getElementById('atividadeOnlineEmpty');
  const usersBody = document.getElementById('atividadeUsersBody');
  const buscaInput = document.getElementById('atividadeBusca');
  const badge = document.getElementById('atividadeOnlineBadge');

  const fmtQuando = (dt) => {
    if (!dt) return '—';
    const d = new Date(String(dt).replace(' ', 'T'));
    if (Number.isNaN(d.getTime())) return '—';
    const pad = (n) => String(n).padStart(2, '0');
    return `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
  };

  const inicial = (nome) => {
    const n = String(nome || '').trim();
    return n ? n.charAt(0).toUpperCase() : '?';
  };

  const esc = (s) => String(s ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');

  const renderOnline = (lista) => {
    if (!onlineGrid || !onlineEmpty) return;
    const items = Array.isArray(lista) ? lista : [];
    if (items.length === 0) {
      onlineEmpty.hidden = false;
      onlineGrid.hidden = true;
      onlineGrid.innerHTML = '';
      return;
    }
    onlineEmpty.hidden = true;
    onlineGrid.hidden = false;
    onlineGrid.innerHTML = items.map((o) => `
      <a class="atividade-online-card" href="/rh/atividade/${Number(o.usuario_id) || 0}">
        <span class="atividade-avatar" aria-hidden="true">${esc(inicial(o.nome))}</span>
        <span>
          <strong>${esc(o.nome)}</strong>
          <small>desde ${esc(fmtQuando(o.logged_in_at))}</small>
        </span>
        <span class="pill pill-success">Online</span>
      </a>
    `).join('');
  };

  const renderUsers = (lista) => {
    if (!usersBody) return;
    const items = Array.isArray(lista) ? lista : [];
    usersBody.innerHTML = items.map((u) => {
      const online = !!u.online_at;
      return `
        <tr data-user-id="${Number(u.id) || 0}">
          <td>
            <div class="atividade-user-cell">
              <span class="atividade-avatar ${online ? 'is-online' : ''}">${esc(inicial(u.nome))}</span>
              <span>
                <strong>${esc(u.nome)}</strong>
                <small class="muted">${esc(u.email)}</small>
              </span>
            </div>
          </td>
          <td>${online ? '<span class="pill pill-success">Online</span>' : '<span class="pill">Offline</span>'}</td>
          <td>${esc(fmtQuando(u.ultimo_login))}</td>
          <td>${online ? '—' : esc(fmtQuando(u.ultimo_logout))}</td>
          <td>${Number(u.logins_30d) || 0}</td>
          <td class="text-right">
            <a class="btn btn-primary btn-sm" href="/rh/atividade/${Number(u.id) || 0}">Analisar</a>
          </td>
        </tr>
      `;
    }).join('');
  };

  const atualizarBadge = (n) => {
    if (!badge) return;
    const qtd = Number(n) || 0;
    if (qtd > 0) {
      badge.hidden = false;
      badge.textContent = String(qtd);
    } else {
      badge.hidden = true;
    }
  };

  let timer = null;
  let falhas = 0;

  const tick = async () => {
    try {
      const q = buscaInput?.value ? `?q=${encodeURIComponent(buscaInput.value)}` : '';
      const resp = await fetch(`/rh/atividade/live.json${q}`, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
        cache: 'no-store',
      });
      if (!resp.ok) throw new Error('Falha no feed');
      const data = await resp.json();
      if (!data?.ok) throw new Error(data?.message || 'Feed inválido');

      if (kpiOnline) kpiOnline.textContent = String(data.resumo?.online ?? 0);
      if (kpiHoje) kpiHoje.textContent = String(data.resumo?.sessoes_hoje ?? 0);
      if (kpiAtivos) kpiAtivos.textContent = String(data.resumo?.usuarios_ativos_7d ?? 0);
      if (kpiLive) {
        const agora = new Date();
        const pad = (n) => String(n).padStart(2, '0');
        kpiLive.textContent = `${pad(agora.getHours())}:${pad(agora.getMinutes())}:${pad(agora.getSeconds())}`;
      }
      renderOnline(data.online);
      renderUsers(data.usuarios);
      atualizarBadge(data.resumo?.online ?? 0);
      falhas = 0;
    } catch (err) {
      falhas += 1;
      if (kpiLive) kpiLive.textContent = falhas > 2 ? 'reconectando…' : 'ao vivo';
    }
  };

  tick();
  timer = window.setInterval(tick, 5000);

  document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
      if (timer) window.clearInterval(timer);
      timer = null;
    } else if (!timer) {
      tick();
      timer = window.setInterval(tick, 5000);
    }
  });
})();
