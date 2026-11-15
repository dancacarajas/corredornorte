(() => {
  const root = document.getElementById('mapaSistema');
  if (!root) return;

  const raw = document.getElementById('mapaSistemaData');
  const DATA = raw ? JSON.parse(raw.textContent || '{}') : { journeys: {}, defaultRole: 'rh', canRh: false };

  const els = {
    rail: root.querySelector('[data-rail]'),
    fill: root.querySelector('[data-progress-fill]'),
    progressLabel: root.querySelector('[data-progress-label]'),
    progressRole: root.querySelector('[data-progress-role]'),
    kicker: root.querySelector('[data-panel-kicker]'),
    title: root.querySelector('[data-panel-title]'),
    who: root.querySelector('[data-panel-who]'),
    lead: root.querySelector('[data-panel-lead]'),
    does: root.querySelector('[data-panel-does]'),
    chain: root.querySelector('[data-panel-chain]'),
    forkWrap: root.querySelector('[data-fork-wrap]'),
    fork: root.querySelector('[data-panel-fork]'),
    unlocks: root.querySelector('[data-panel-unlocks]'),
    ctas: root.querySelector('[data-panel-ctas]'),
    meta: root.querySelector('[data-controls-meta]'),
    prev: root.querySelector('[data-prev]'),
    next: root.querySelector('[data-next]'),
    play: root.querySelector('[data-play]'),
    footerPrimary: root.querySelector('[data-footer-primary]'),
    roles: Array.from(root.querySelectorAll('[data-role]')),
  };

  let role = DATA.defaultRole || 'rh';
  let index = 0;
  let forkId = null;
  let playTimer = null;
  let chainTimer = null;

  const params = new URLSearchParams(window.location.search);
  if (params.get('papel') && DATA.journeys[params.get('papel')]) {
    role = params.get('papel');
  }
  if (params.get('passo')) {
    const n = Number(params.get('passo')) - 1;
    if (Number.isFinite(n) && n >= 0) index = n;
  }
  if (params.get('ramo')) {
    forkId = params.get('ramo');
  }

  function journey() {
    return DATA.journeys[role] || DATA.journeys.rh;
  }

  function steps() {
    return journey().steps || [];
  }

  function baseStep() {
    return steps()[index] || null;
  }

  /** Conteúdo efetivo do passo, já mesclado com o ramo escolhido. */
  function resolvedStep() {
    const s = baseStep();
    if (!s) return null;

    let content = { ...s };

    if (s.forks && typeof s.forks === 'object') {
      const keys = Object.keys(s.forks);
      if (!forkId || !s.forks[forkId]) {
        forkId = s.default_fork && s.forks[s.default_fork] ? s.default_fork : keys[0];
      }
      const branch = s.forks[forkId] || {};
      content = {
        ...content,
        ...branch,
        forks: s.forks,
        default_fork: s.default_fork,
        id: s.id,
      };
    } else if (s.fork_content && forkId && s.fork_content[forkId]) {
      content = {
        ...content,
        ...s.fork_content[forkId],
        forks: null,
        id: s.id,
      };
    }

    return content;
  }

  function setUrl() {
    const url = new URL(window.location.href);
    url.searchParams.set('papel', role);
    url.searchParams.set('passo', String(index + 1));
    if (forkId) url.searchParams.set('ramo', forkId);
    else url.searchParams.delete('ramo');
    window.history.replaceState({}, '', url);
  }

  function stopPlay() {
    if (playTimer) {
      window.clearInterval(playTimer);
      playTimer = null;
    }
    if (els.play) els.play.textContent = 'Reproduzir guia';
  }

  function guideLabel(i, total) {
    return `Passo ${i + 1} de ${total} do guia`;
  }

  function updateFooter() {
    const j = journey();
    const fp = j.footer_primary;
    if (!els.footerPrimary || !fp) return;
    els.footerPrimary.href = fp.href;
    els.footerPrimary.textContent = fp.label;
  }

  function renderRail() {
    if (!els.rail) return;
    els.rail.innerHTML = '';
    steps().forEach((s, i) => {
      const title = s.forks && forkId && s.forks[forkId]
        ? (s.forks[forkId].title || s.title)
        : s.title;
      const li = document.createElement('li');
      li.className = 'mapa-step';
      if (i < index) li.classList.add('is-done');
      if (i === index) li.classList.add('is-active');
      li.innerHTML = `
        <span class="mapa-step-num" aria-hidden="true">${i + 1}</span>
        <button type="button" class="mapa-step-btn" data-goto="${i}">
          <span class="mapa-step-actor">${escapeHtml(s.actor || '')}</span>
          <span class="mapa-step-title">${escapeHtml(i === index ? (resolvedStep()?.title || title) : s.title)}</span>
          <span class="mapa-step-sub">${escapeHtml(s.sub || '')}</span>
        </button>
      `;
      els.rail.appendChild(li);
    });
    els.rail.querySelectorAll('[data-goto]').forEach((btn) => {
      btn.addEventListener('click', () => {
        stopPlay();
        goTo(Number(btn.getAttribute('data-goto')));
      });
    });
  }

  function animateChain(items) {
    if (!els.chain) return;
    els.chain.innerHTML = '';
    if (chainTimer) window.clearTimeout(chainTimer);

    (items || []).forEach((label, i) => {
      if (i > 0) {
        const arrow = document.createElement('span');
        arrow.className = 'mapa-chain-arrow';
        arrow.textContent = '→';
        arrow.setAttribute('aria-hidden', 'true');
        els.chain.appendChild(arrow);
      }
      const pill = document.createElement('span');
      pill.className = 'mapa-chain-pill';
      pill.textContent = label;
      els.chain.appendChild(pill);
    });

    const pills = Array.from(els.chain.querySelectorAll('.mapa-chain-pill'));
    let lit = 0;
    const tick = () => {
      pills.forEach((p, i) => {
        p.classList.toggle('is-lit', i <= lit);
        p.classList.toggle('is-now', i === lit);
      });
      lit += 1;
      if (lit < pills.length) chainTimer = window.setTimeout(tick, 380);
      else pills.forEach((p) => p.classList.remove('is-now'));
    };
    if (pills.length) chainTimer = window.setTimeout(tick, 120);
  }

  function setForkVisible(show) {
    if (!els.forkWrap) return;
    els.forkWrap.hidden = !show;
    els.forkWrap.classList.toggle('is-hidden', !show);
    if (!show && els.fork) els.fork.innerHTML = '';
  }

  function renderFork(base) {
    if (!els.forkWrap || !els.fork) return;
    const forks = base?.forks;
    if (!forks || typeof forks !== 'object' || !Object.keys(forks).length) {
      setForkVisible(false);
      return;
    }

    setForkVisible(true);
    const keys = Object.keys(forks);
    if (!forkId || !forks[forkId]) {
      forkId = base.default_fork && forks[base.default_fork] ? base.default_fork : keys[0];
    }

    els.fork.innerHTML = '';
    keys.forEach((id) => {
      const f = forks[id];
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'mapa-fork-card' + (id === forkId ? ' is-active' : '');
      btn.innerHTML = `<strong>${escapeHtml(f.choice_title || f.title)}</strong><span>${escapeHtml(f.choice_text || '')}</span>`;
      btn.addEventListener('click', () => {
        forkId = id;
        renderPanel();
        renderRail();
        setUrl();
        if (window.Toast) {
          window.Toast.show('Situação: ' + (f.choice_title || f.title), 'info');
        }
      });
      els.fork.appendChild(btn);
    });
  }

  function visibleLinks(links) {
    return (links || []).filter((link) => {
      if (!link.admin) return true;
      return !!DATA.canRh;
    });
  }

  function renderCtas(s) {
    if (!els.ctas) return;
    els.ctas.innerHTML = '';
    visibleLinks(s.links).forEach((link) => {
      const a = document.createElement('a');
      a.href = link.href;
      a.className = 'btn btn-sm ' + (link.primary ? 'btn-primary' : 'btn-secondary');
      a.textContent = link.label;
      els.ctas.appendChild(a);
    });
  }

  function renderPanel() {
    const base = baseStep();
    const s = resolvedStep();
    const list = steps();
    if (!base || !s) return;

    const label = guideLabel(index, list.length);
    if (els.kicker) els.kicker.textContent = label;
    if (els.title) els.title.textContent = s.title;
    if (els.who) els.who.textContent = 'Quem age: ' + (s.actor || '—');
    if (els.lead) els.lead.textContent = s.lead || '';
    if (els.unlocks) els.unlocks.textContent = s.unlocks || '';
    if (els.does) {
      els.does.innerHTML = (s.does || []).map((d) => `<li>${escapeHtml(d)}</li>`).join('');
    }
    if (els.progressLabel) els.progressLabel.textContent = label;
    if (els.progressRole) els.progressRole.textContent = journey().label || '';
    if (els.fill) {
      const pct = list.length <= 1 ? 100 : (index / (list.length - 1)) * 100;
      els.fill.style.width = pct + '%';
    }
    if (els.meta) els.meta.textContent = s.sub || '';
    if (els.prev) els.prev.disabled = index <= 0;
    if (els.next) els.next.textContent = index >= list.length - 1 ? 'Fim do guia' : 'Próximo';

    renderFork(base);
    renderCtas(s);
    animateChain(s.chain || []);
    updateFooter();
  }

  function goTo(i) {
    const list = steps();
    if (!list.length) return;
    index = Math.max(0, Math.min(i, list.length - 1));
    const s = baseStep();
    if (s?.forks) {
      if (!forkId || !s.forks[forkId]) {
        forkId = s.default_fork && s.forks[s.default_fork] ? s.default_fork : Object.keys(s.forks)[0];
      }
    }
    // mantém ramo entre passos que usam fork_content
    renderRail();
    renderPanel();
    setUrl();
  }

  function next(delta) {
    const list = steps();
    const target = index + delta;
    if (target < 0) return;
    if (target >= list.length) {
      stopPlay();
      if (window.Toast) {
        window.Toast.show('Você chegou ao fim do guia. Para executar ou acompanhar o processo, abra a Medição.', 'success');
      }
      return;
    }
    goTo(target);
  }

  function setRole(nextRole) {
    if (!DATA.journeys[nextRole]) return;
    stopPlay();
    role = nextRole;
    index = 0;
    forkId = null;
    els.roles.forEach((btn) => {
      const on = btn.getAttribute('data-role') === role;
      btn.classList.toggle('is-active', on);
      btn.setAttribute('aria-selected', on ? 'true' : 'false');
    });
    goTo(0);
  }

  function startPlay() {
    stopPlay();
    goTo(0);
    if (els.play) els.play.textContent = 'Parar';
    playTimer = window.setInterval(() => {
      if (index >= steps().length - 1) {
        stopPlay();
        if (window.Toast) {
          window.Toast.show('Fim do guia. Para executar ou acompanhar o processo, abra a Medição.', 'success');
        }
        return;
      }
      next(1);
    }, 3200);
  }

  function escapeHtml(str) {
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  els.roles.forEach((btn) => {
    btn.addEventListener('click', () => setRole(btn.getAttribute('data-role') || 'rh'));
  });
  els.prev?.addEventListener('click', () => {
    stopPlay();
    next(-1);
  });
  els.next?.addEventListener('click', () => {
    stopPlay();
    next(1);
  });
  els.play?.addEventListener('click', () => {
    if (playTimer) stopPlay();
    else startPlay();
  });

  document.addEventListener('keydown', (ev) => {
    if (ev.target && ['INPUT', 'TEXTAREA', 'SELECT'].includes(ev.target.tagName)) return;
    if (ev.key === 'ArrowRight') {
      ev.preventDefault();
      stopPlay();
      next(1);
    }
    if (ev.key === 'ArrowLeft') {
      ev.preventDefault();
      stopPlay();
      next(-1);
    }
  });

  els.roles.forEach((btn) => {
    const on = btn.getAttribute('data-role') === role;
    btn.classList.toggle('is-active', on);
    btn.setAttribute('aria-selected', on ? 'true' : 'false');
  });
  if (index >= steps().length) index = 0;
  goTo(index);
})();
