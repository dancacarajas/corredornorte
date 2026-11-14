(function () {
  'use strict';

  const getCsrf = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

  const buildFormBody = (params, ids, idFieldName) => {
    const fd = new FormData();
    const csrf = getCsrf();
    if (csrf) fd.append('_csrf', csrf);
    Object.entries(params || {}).forEach(([k, v]) => {
      if (v !== undefined && v !== null) fd.append(k, String(v));
    });
    ids.forEach((id) => fd.append(idFieldName || 'espelho_ids[]', String(id)));
    return fd;
  };

  /**
   * Executes a server batch endpoint in sequential JSON chunks with progress tracking.
   */
  const run = async (opts) => {
    const ids = Array.isArray(opts.ids) ? opts.ids : [];
    const chunkSize = Math.max(1, Number(opts.chunkSize || 5));
    const url = String(opts.url || '');
    const idFieldName = opts.idFieldName || 'espelho_ids[]';
    const signal = opts.signal;

    let done = 0;
    let totalOk = 0;
    let totalSkipped = 0;
    const errors = [];
    const items = [];

    for (let i = 0; i < ids.length; i += chunkSize) {
      if (signal && signal.aborted) {
        throw new Error('Operação cancelada pelo operador.');
      }
      const slice = ids.slice(i, i + chunkSize);
      const body = buildFormBody(opts.params || {}, slice, idFieldName);
      const res = await fetch(url, {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          'X-CSRF-Token': getCsrf(),
        },
        body,
        signal,
      });

      const ct = res.headers.get('content-type') || '';
      if (!ct.includes('application/json')) {
        throw new Error(`Resposta inesperada do servidor (HTTP ${res.status}).`);
      }
      const data = await res.json();
      if (!res.ok || data.ok === false) {
        throw new Error(data.error || `Falha ao processar lote (HTTP ${res.status}).`);
      }

      done += slice.length;
      totalOk += Number(data.processed || 0);
      totalSkipped += Number(data.skipped || 0);
      if (Array.isArray(data.errors) && data.errors.length > 0) {
        data.errors.forEach((err) => errors.push(String(err)));
      }
      if (Array.isArray(data.items)) {
        data.items.forEach((it) => items.push(it));
      }
      if (typeof opts.onProgress === 'function') {
        opts.onProgress({
          done,
          total: ids.length,
          ok: totalOk,
          skipped: totalSkipped,
          errors: errors.slice(),
          items: items.slice(),
        });
      }
    }

    const summary = {
      done,
      total: ids.length,
      ok: totalOk,
      skipped: totalSkipped,
      errors,
      items,
    };
    if (typeof opts.onDone === 'function') {
      opts.onDone(summary);
    }
    return summary;
  };

  window.BatchRunner = { run };
})();
