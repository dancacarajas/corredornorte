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
  const muted = '#94a3b8';

  const porDia = Array.isArray(data.por_dia) ? data.por_dia : [];
  const porHora = Array.isArray(data.por_hora) ? data.por_hora : [];

  const elDia = document.getElementById('chartPorDia');
  if (elDia) {
    new Chart(elDia, {
      type: 'bar',
      data: {
        labels: porDia.map((d) => {
          const p = String(d.dia || '').split('-');
          return p.length === 3 ? `${p[2]}/${p[1]}` : d.dia;
        }),
        datasets: [
          {
            label: 'Logins',
            data: porDia.map((d) => Number(d.logins) || 0),
            backgroundColor: brand,
            borderRadius: 6,
            yAxisID: 'y',
          },
          {
            type: 'line',
            label: 'Minutos',
            data: porDia.map((d) => Number(d.minutos) || 0),
            borderColor: '#0f766e',
            backgroundColor: 'rgba(15,118,110,.15)',
            tension: 0.3,
            yAxisID: 'y1',
          },
        ],
      },
      options: {
        responsive: true,
        interaction: { mode: 'index', intersect: false },
        scales: {
          y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: '#f1f5f9' } },
          y1: {
            beginAtZero: true,
            position: 'right',
            grid: { drawOnChartArea: false },
            ticks: { callback: (v) => `${v}m` },
          },
          x: { grid: { display: false } },
        },
        plugins: {
          legend: { position: 'bottom' },
        },
      },
    });
  }

  const elHora = document.getElementById('chartPorHora');
  if (elHora) {
    new Chart(elHora, {
      type: 'line',
      data: {
        labels: porHora.map((h) => `${String(h.hora).padStart(2, '0')}h`),
        datasets: [{
          label: 'Logins',
          data: porHora.map((h) => Number(h.logins) || 0),
          borderColor: brand,
          backgroundColor: 'rgba(234,88,12,.18)',
          fill: true,
          tension: 0.35,
          pointRadius: 2,
        }],
      },
      options: {
        responsive: true,
        scales: {
          y: { beginAtZero: true, ticks: { precision: 0, color: muted }, grid: { color: '#f1f5f9' } },
          x: { grid: { display: false } },
        },
        plugins: { legend: { display: false } },
      },
    });
  }
})();
