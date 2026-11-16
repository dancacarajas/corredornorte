(() => {
  const csrf = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
  let timer = null;

  const beat = async () => {
    try {
      const token = csrf();
      const body = new URLSearchParams();
      if (token) body.set('_csrf', token);
      await fetch('/rh/atividade/heartbeat', {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          ...(token ? { 'X-CSRF-Token': token } : {}),
          'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
        },
        credentials: 'same-origin',
        body,
        cache: 'no-store',
      });
    } catch {
      // silencioso — presença é best-effort
    }
  };

  const start = () => {
    if (timer) return;
    beat();
    timer = window.setInterval(beat, 45000);
  };

  const stop = () => {
    if (!timer) return;
    window.clearInterval(timer);
    timer = null;
  };

  if (document.readyState === 'complete' || document.readyState === 'interactive') {
    start();
  } else {
    document.addEventListener('DOMContentLoaded', start);
  }

  document.addEventListener('visibilitychange', () => {
    if (document.hidden) stop();
    else start();
  });
})();
