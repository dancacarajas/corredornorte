(() => {
  const raw = document.getElementById('atividadeChartData');
  if (!raw || typeof Chart === 'undefined') return;

  let data;
  try {
    data = JSON.parse(raw.textContent || '{}');
  } catch {
    return;
  }

  const brand = getComputedStyle(document.documentElement).getPropertyValue('--brand')?.trim() || '#ea580c';
  const ink = getComputedStyle(document.documentElement).getPropertyValue('--ink')?.trim() || '#1f2937';
  const muted = '#94a3b8';
  const grid = '#f1f5f9';

  let porDia = Array.isArray(data.por_dia) ? data.por_dia : [];
  const porFaixa = Array.isArray(data.por_faixa) ? data.por_faixa : [];

  const fmtDuracao = (minutos) => {
    const totalSeg = Math.max(0, Math.round((Number(minutos) || 0) * 60));
    if (totalSeg < 60) return totalSeg <= 0 ? '0 min' : `${totalSeg}s`;
    const m = Math.floor(totalSeg / 60);
    const s = totalSeg % 60;
    if (m < 60) return s ? `${m} min ${s}s` : `${m} min`;
    const h = Math.floor(m / 60);
    const rest = m % 60;
    return rest ? `${h}h ${String(rest).padStart(2, '0')}min` : `${h}h`;
  };

  const labelDia = (iso) => {
    const p = String(iso || '').split('-');
    if (p.length !== 3) return iso;
    return `${p[2]}/${p[1]}`;
  };

  // Com poucos dias ativos, corta o eixo para a leitura não “sumir” em 30 dias vazios.
  const ativos = porDia.filter((d) => (Number(d.logins) || 0) > 0 || (Number(d.minutos) || 0) > 0);
  if (ativos.length > 0 && ativos.length / Math.max(porDia.length, 1) < 0.35) {
    const firstIdx = porDia.findIndex((d) => (Number(d.logins) || 0) > 0 || (Number(d.minutos) || 0) > 0);
    const lastIdx = (() => {
      for (let i = porDia.length - 1; i >= 0; i -= 1) {
        if ((Number(porDia[i].logins) || 0) > 0 || (Number(porDia[i].minutos) || 0) > 0) return i;
      }
      return porDia.length - 1;
    })();
    const pad = 1;
    porDia = porDia.slice(Math.max(0, firstIdx - pad), Math.min(porDia.length, lastIdx + pad + 1));
  }

  const commonPlugins = {
    legend: { display: false },
    tooltip: {
      backgroundColor: '#0f172a',
      titleColor: '#fff',
      bodyColor: '#e2e8f0',
      padding: 10,
      cornerRadius: 8,
    },
  };

  const emptyNote = (canvas, msg) => {
    const wrap = canvas.parentElement;
    if (!wrap || wrap.querySelector('[data-chart-empty]')) return;
    const p = document.createElement('p');
    p.dataset.chartEmpty = '1';
    p.className = 'atividade-chart-empty';
    p.textContent = msg;
    wrap.appendChild(p);
  };

  const elDia = document.getElementById('chartTempoDia');
  if (elDia) {
    const valores = porDia.map((d) => Math.max(0, Number(d.minutos) || 0));
    const logins = porDia.map((d) => Math.max(0, Number(d.logins) || 0));
    const maxVal = Math.max(...valores, 0);
    const maxLogins = Math.max(...logins, 0);

    if (maxVal <= 0 && maxLogins <= 0) {
      emptyNote(elDia, 'Sem tempo ativo registrado neste período.');
    }

    // Se o tempo ainda é ~0 mas há logins, plota sessões para não ficar em branco.
    const usarSessoes = maxVal <= 0 && maxLogins > 0;
    const serie = usarSessoes ? logins : valores;
    const maxSerie = Math.max(...serie, 0);
    const suggested = maxSerie <= 0 ? 5 : Math.max(maxSerie * 1.25, usarSessoes ? 2 : 1);

    new Chart(elDia, {
      type: 'bar',
      data: {
        labels: porDia.map((d) => labelDia(d.dia)),
        datasets: [{
          label: usarSessoes ? 'Sessões' : 'Tempo ativo',
          data: serie,
          backgroundColor: brand,
          hoverBackgroundColor: '#c2410c',
          borderRadius: 8,
          borderSkipped: false,
          minBarLength: maxSerie > 0 ? 6 : 0,
          maxBarThickness: porDia.length > 20 ? 22 : 36,
        }],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        scales: {
          x: {
            grid: { display: false },
            ticks: {
              color: muted,
              maxRotation: 0,
              autoSkip: true,
              maxTicksLimit: 12,
            },
          },
          y: {
            beginAtZero: true,
            suggestedMax: suggested,
            grid: { color: grid },
            ticks: {
              color: muted,
              precision: usarSessoes ? 0 : undefined,
              callback: (v) => (usarSessoes ? String(v) : fmtDuracao(v)),
            },
          },
        },
        plugins: {
          ...commonPlugins,
          tooltip: {
            ...commonPlugins.tooltip,
            callbacks: {
              title: (items) => {
                const i = items[0]?.dataIndex ?? 0;
                const iso = porDia[i]?.dia || '';
                const p = String(iso).split('-');
                return p.length === 3 ? `${p[2]}/${p[1]}/${p[0]}` : iso;
              },
              label: (ctx) => {
                const i = ctx.dataIndex;
                const mins = valores[i] || 0;
                const n = logins[i] || 0;
                return [
                  `Tempo ativo: ${fmtDuracao(mins)}`,
                  `Sessões: ${n}`,
                ];
              },
            },
          },
        },
      },
    });
  }

  const elFaixa = document.getElementById('chartPerfilAcesso');
  if (elFaixa) {
    const valores = porFaixa.map((f) => Math.max(0, Number(f.minutos) || 0));
    const logins = porFaixa.map((f) => Math.max(0, Number(f.logins) || 0));
    const totalMin = valores.reduce((a, b) => a + b, 0);
    const totalLogins = logins.reduce((a, b) => a + b, 0);
    const usarSessoes = totalMin <= 0 && totalLogins > 0;
    const serie = usarSessoes ? logins : valores;
    const total = usarSessoes ? totalLogins : totalMin;
    const maxVal = Math.max(...serie, 0);
    const cores = ['#64748b', '#ea580c', '#c2410c', '#0f766e'];

    if (maxVal <= 0) {
      emptyNote(elFaixa, 'Sem acessos neste período.');
    }

    // Atualiza legenda % com base na série real.
    document.querySelectorAll('[data-faixa-pct]').forEach((el) => {
      const key = el.getAttribute('data-faixa-pct');
      const idx = porFaixa.findIndex((f) => f.chave === key);
      if (idx < 0) return;
      const v = serie[idx] || 0;
      const pct = total > 0 ? Math.round((v / total) * 100) : 0;
      el.textContent = `${pct}%`;
    });

    new Chart(elFaixa, {
      type: 'bar',
      data: {
        labels: porFaixa.map((f) => f.label),
        datasets: [{
          label: usarSessoes ? 'Sessões' : 'Tempo ativo',
          data: serie,
          backgroundColor: cores,
          borderRadius: 10,
          borderSkipped: false,
          minBarLength: maxVal > 0 ? 8 : 0,
          barPercentage: 0.7,
          categoryPercentage: 0.72,
        }],
      },
      options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        scales: {
          x: {
            beginAtZero: true,
            suggestedMax: maxVal <= 0 ? 5 : Math.max(maxVal * 1.25, usarSessoes ? 2 : 1),
            grid: { color: grid },
            ticks: {
              color: muted,
              precision: usarSessoes ? 0 : undefined,
              callback: (v) => (usarSessoes ? String(v) : fmtDuracao(v)),
            },
          },
          y: {
            grid: { display: false },
            ticks: { color: ink, font: { weight: '700' } },
          },
        },
        plugins: {
          ...commonPlugins,
          tooltip: {
            ...commonPlugins.tooltip,
            callbacks: {
              title: (items) => {
                const i = items[0]?.dataIndex ?? 0;
                const f = porFaixa[i];
                return f ? `${f.label} (${f.horario})` : '';
              },
              label: (ctx) => {
                const i = ctx.dataIndex;
                const mins = valores[i] || 0;
                const n = logins[i] || 0;
                const v = serie[i] || 0;
                const pct = total > 0 ? Math.round((v / total) * 100) : 0;
                return [
                  `Tempo ativo: ${fmtDuracao(mins)}`,
                  `Sessões: ${n} (${pct}%)`,
                ];
              },
            },
          },
        },
      },
    });
  }
})();
