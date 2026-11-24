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
    if (window.AppClock?.formatDateTime) {
      return window.AppClock.formatDateTime(dt);
    }
    const raw = String(dt).trim().replace(' ', 'T');
    const d = new Date(/[zZ]|[+\-]\d{2}:?\d{2}$/.test(raw) ? raw : `${raw}-03:00`);
    if (Number.isNaN(d.getTime())) return '—';
    return new Intl.DateTimeFormat('pt-BR', {
      timeZone: 'America/Sao_Paulo',
      day: '2-digit', month: '2-digit', year: 'numeric',
      hour: '2-digit', minute: '2-digit',
    }).format(d);
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

  const avatarHtml = (row, idKey, nome, extraClass = '') => {
    const url = String(row?.avatar_url || '').trim()
      || (() => {
        const p = String(row?.avatar_path || '').trim();
        const uid = Number(row?.[idKey]) || 0;
        return p && uid > 0 ? `/conta/avatar?id=${uid}` : '';
      })();
    const cls = `atividade-avatar ${extraClass} ${url ? 'has-photo' : ''}`.trim();
    if (url) {
      return `<span class="${cls}" aria-hidden="true"><img src="${esc(url)}" alt=""></span>`;
    }
    return `<span class="${cls}" aria-hidden="true">${esc(inicial(nome))}</span>`;
  };

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
        ${avatarHtml(o, 'usuario_id', o.nome)}
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
              ${avatarHtml(u, 'id', u.nome, online ? 'is-online' : '')}
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
        kpiLive.textContent = window.AppClock?.nowTime(true)
          || new Intl.DateTimeFormat('pt-BR', {
            timeZone: 'America/Sao_Paulo', hour: '2-digit', minute: '2-digit', second: '2-digit',
          }).format(new Date());
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
