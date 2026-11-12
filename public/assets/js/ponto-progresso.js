(() => {
  const popup = document.getElementById('ponto-progresso');
  if (typeof popup?.showModal !== 'function' || typeof TextDecoderStream !== 'function') return;

  const barra = popup.querySelector('[role="progressbar"]');
  const preenchimento = barra.firstElementChild;
  const porcentagem = document.getElementById('ponto-progresso-porcentagem');
  const status = document.getElementById('ponto-progresso-status');
  const rodape = popup.querySelector('.modal-footer');
  const fechar = rodape.querySelector('button');
  const erroComunicacao = 'Não foi possível confirmar a conclusão. Confira a lista de espelhos antes de tentar novamente.';
  let ocupado = false;
  let urlAtualizacao = null;

  const exibirProgresso = (valor) => {
    porcentagem.textContent = `${valor}%`;
    barra.setAttribute('aria-valuenow', String(valor));
    preenchimento.style.width = `${valor}%`;
  };

  const exibirStatus = (mensagem) => {
    if (status.textContent !== mensagem) status.textContent = mensagem;
  };

  const lerResultado = async (body) => {
    const leitor = body.pipeThrough(new TextDecoderStream()).getReader();
    let restante = '';
    try {
      while (true) {
        const { value, done } = await leitor.read();
        const linhas = (restante + (value ?? '')).split('\n');
        restante = done ? '' : linhas.pop();
        for (const linha of linhas) {
          if (!linha.trim()) continue;
          const evento = JSON.parse(linha);
          if (evento.type === 'success' || evento.type === 'error') return evento;
          if (evento.type === 'progress' && Number.isFinite(evento.percent)) {
            exibirStatus(evento.phase === 'calculation' ? 'Calculando totais de ponto…' : 'Gerando espelhos…');
            exibirProgresso(Math.min(99, Math.max(0, Math.floor(evento.percent))));
          }
        }
        if (done) return null;
      }
    } finally {
      leitor.releaseLock();
    }
  };

  popup.addEventListener('cancel', (evento) => {
    if (ocupado) evento.preventDefault();
  });
  popup.addEventListener('close', () => {
    if (urlAtualizacao !== null) window.location.assign(urlAtualizacao);
  });

  document.addEventListener('submit', async (evento) => {
    const botao = evento.submitter;
    if (!botao?.hasAttribute('data-ponto-gerar')) return;
    evento.preventDefault();
    if (ocupado || popup.open) return;

    ocupado = true;
    urlAtualizacao = null;
    rodape.hidden = true;
    barra.classList.remove('is-success', 'is-danger');
    exibirProgresso(0);
    exibirStatus('Preparando a geração…');
    popup.showModal();

    try {
      const formulario = evento.target;
      const resposta = await fetch(botao.getAttribute('formaction') || formulario.action, {
        method: 'POST',
        headers: { Accept: 'application/x-ndjson' },
        body: new FormData(formulario),
      });
      if (resposta.redirected && new URL(resposta.url).pathname === '/login') {
        exibirStatus('Sua sessão expirou. Entre no sistema para gerar os espelhos.');
        barra.classList.add('is-danger');
        return;
      }
      if (!resposta.body || !resposta.headers.get('Content-Type')?.includes('application/x-ndjson')) {
        throw new Error('Resposta inesperada');
      }

      const resultado = await lerResultado(resposta.body);
      if (resultado?.type === 'error') {
        exibirStatus(resultado.message || erroComunicacao);
        barra.classList.add('is-danger');
        return;
      }
      if (!resposta.ok || resultado?.type !== 'success' || typeof resultado.url !== 'string') {
        throw new Error('Conclusão não confirmada');
      }
      const destino = new URL(resultado.url, window.location.href);
      if (destino.origin !== window.location.origin || destino.pathname !== '/rh/ponto') {
        throw new Error('Destino inesperado');
      }

      urlAtualizacao = destino.href;
      exibirProgresso(100);
      exibirStatus('Espelhos gerados com sucesso.');
      barra.classList.add('is-success');
    } catch {
      exibirStatus(erroComunicacao);
      barra.classList.add('is-danger');
    } finally {
      ocupado = false;
      rodape.hidden = false;
      fechar.focus();
    }
  });
})();
