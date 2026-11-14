(() => {
  const popup = document.getElementById('ponto-progresso');
  const confirmPopup = document.getElementById('ponto-periodo-existe');
  if (typeof popup?.showModal !== 'function') return;

  const barra = popup.querySelector('[role="progressbar"]');
  const preenchimento = barra.firstElementChild;
  const porcentagem = document.getElementById('ponto-progresso-porcentagem');
  const status = document.getElementById('ponto-progresso-status');
  const rodape = popup.querySelector('.modal-footer');
  const fechar = rodape.querySelector('button');
  const erroComunicacao = 'Não foi possível confirmar a conclusão. Confira a lista de espelhos antes de tentar novamente.';
  let ocupado = false;
  let urlAtualizacao = null;
  let pendenteFormulario = null;
  let pendenteEndpoint = null;

  const exibirProgresso = (valor) => {
    const v = Math.min(100, Math.max(0, Math.floor(valor)));
    porcentagem.textContent = `${v}%`;
    barra.setAttribute('aria-valuenow', String(v));
    preenchimento.style.width = `${v}%`;
  };

  const exibirStatus = (mensagem) => {
    if (status.textContent !== mensagem) status.textContent = mensagem;
  };

  const destinoValido = (url) => {
    try {
      const destino = new URL(url, window.location.href);
      if (destino.origin !== window.location.origin) return null;
      if (!destino.pathname.includes('/rh/ponto')) return null;
      return destino.href;
    } catch {
      return null;
    }
  };

  const lerJson = async (resposta) => {
    const tipo = resposta.headers.get('Content-Type') || '';
    if (!tipo.includes('application/json')) {
      const texto = await resposta.text();
      if (resposta.redirected || /\/login/i.test(resposta.url)) {
        throw new Error('Sessão expirada');
      }
      throw new Error(texto.slice(0, 180) || 'Resposta inesperada');
    }
    return resposta.json();
  };

  const postGeracao = async (formulario, endpoint, extras = {}) => {
    const body = new FormData(formulario);
    Object.entries(extras).forEach(([k, v]) => body.set(k, String(v)));
    const resposta = await fetch(endpoint, {
      method: 'POST',
      headers: { Accept: 'application/json' },
      credentials: 'same-origin',
      body,
    });
    if (resposta.redirected && new URL(resposta.url).pathname.endsWith('/login')) {
      throw new Error('Sessão expirada');
    }
    return lerJson(resposta);
  };

  const executarGeracao = async (formulario, endpoint, force = false) => {
    ocupado = true;
    urlAtualizacao = null;
    rodape.hidden = true;
    barra.classList.remove('is-success', 'is-danger');
    exibirProgresso(0);
    exibirStatus('Preparando a geração…');
    if (!popup.open) popup.showModal();

    try {
      let phase = 'generation';
      let batchOffset = 0;
      let guard = 0;

      while (guard < 5000) {
        guard += 1;
        const eventoResp = await postGeracao(formulario, endpoint, {
          phase,
          batch_offset: batchOffset,
          batch_size: 1,
          force: force ? '1' : '0',
        });

        if (eventoResp?.type === 'exists_confirm') {
          ocupado = false;
          popup.close();
          const detalhe = document.getElementById('ponto-periodo-existe-detalhe');
          if (detalhe) {
            const n = Number(eventoResp.espelhos) || 0;
            detalhe.textContent = n > 0
              ? `Há ${n} cartão(ões) neste período.`
              : 'O período já foi registrado no sistema.';
          }
          pendenteFormulario = formulario;
          pendenteEndpoint = endpoint;
          if (typeof confirmPopup?.showModal === 'function') confirmPopup.showModal();
          return;
        }

        if (eventoResp?.type === 'error') {
          exibirStatus(eventoResp.message || erroComunicacao);
          barra.classList.add('is-danger');
          return;
        }

        if (typeof eventoResp?.percent === 'number') {
          exibirProgresso(eventoResp.percent);
        }
        if (typeof eventoResp?.message === 'string' && eventoResp.message) {
          exibirStatus(eventoResp.message);
        } else if (eventoResp?.phase === 'calculation') {
          exibirStatus('Calculando totais de ponto…');
        } else {
          exibirStatus('Gerando espelhos…');
        }

        if (eventoResp?.type === 'success' || eventoResp?.done === true) {
          const destino = destinoValido(eventoResp.url);
          if (!destino) throw new Error('Destino inesperado');
          urlAtualizacao = destino;
          exibirProgresso(100);
          exibirStatus(eventoResp.message || 'Espelhos gerados com sucesso.');
          barra.classList.add('is-success');
          return;
        }

        if (eventoResp?.type !== 'progress') {
          throw new Error('Conclusão não confirmada');
        }

        phase = eventoResp.phase === 'calculation' ? 'calculation' : 'generation';
        batchOffset = Number(eventoResp.batch_offset);
        if (!Number.isFinite(batchOffset)) {
          throw new Error('Progresso inválido');
        }
      }

      throw new Error('Limite de lotes excedido');
    } catch (erro) {
      const msg = erro instanceof Error && erro.message === 'Sessão expirada'
        ? 'Sua sessão expirou. Entre no sistema para gerar os espelhos.'
        : (erro instanceof Error && erro.message && erro.message !== 'Destino inesperado'
          ? erro.message
          : erroComunicacao);
      exibirStatus(msg);
      barra.classList.add('is-danger');
    } finally {
      ocupado = false;
      rodape.hidden = false;
      fechar.focus();
    }
  };

  popup.addEventListener('cancel', (evento) => {
    if (ocupado) evento.preventDefault();
  });
  popup.addEventListener('close', () => {
    if (urlAtualizacao !== null) window.location.assign(urlAtualizacao);
  });

  if (confirmPopup) {
    document.getElementById('ponto-periodo-existe-cancelar')?.addEventListener('click', () => {
      confirmPopup.close();
      pendenteFormulario = null;
      pendenteEndpoint = null;
    });
    document.getElementById('ponto-periodo-existe-sobrescrever')?.addEventListener('click', () => {
      confirmPopup.close();
      if (!pendenteFormulario || !pendenteEndpoint) return;
      const form = pendenteFormulario;
      const endpoint = pendenteEndpoint;
      pendenteFormulario = null;
      pendenteEndpoint = null;
      void executarGeracao(form, endpoint, true);
    });
  }

  document.addEventListener('submit', async (evento) => {
    const botao = evento.submitter;
    if (!botao?.hasAttribute('data-ponto-gerar')) return;
    evento.preventDefault();
    if (ocupado || popup.open) return;

    const formulario = evento.target;
    const endpoint = botao.getAttribute('formaction') || formulario.action;

    try {
      const check = await postGeracao(formulario, endpoint, { phase: 'check', batch_offset: 0, batch_size: 1 });
      if (check?.type === 'exists_confirm' || check?.exists === true) {
        const detalhe = document.getElementById('ponto-periodo-existe-detalhe');
        if (detalhe) {
          const n = Number(check.espelhos) || 0;
          detalhe.textContent = n > 0
            ? `Há ${n} cartão(ões) neste período.`
            : 'O período já foi registrado no sistema.';
        }
        pendenteFormulario = formulario;
        pendenteEndpoint = endpoint;
        if (typeof confirmPopup?.showModal === 'function') {
          confirmPopup.showModal();
          return;
        }
      }
      if (check?.type === 'error') {
        throw new Error(check.message || erroComunicacao);
      }
      await executarGeracao(formulario, endpoint, false);
    } catch (erro) {
      ocupado = true;
      urlAtualizacao = null;
      rodape.hidden = false;
      barra.classList.remove('is-success');
      barra.classList.add('is-danger');
      exibirProgresso(0);
      exibirStatus(erro instanceof Error ? erro.message : erroComunicacao);
      popup.showModal();
      ocupado = false;
      fechar.focus();
    }
  });
})();
