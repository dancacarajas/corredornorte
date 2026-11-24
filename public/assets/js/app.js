(() => {
  // Horário de negócio do sistema: sempre São Paulo / Brasil.
  const APP_TZ = 'America/Sao_Paulo';
  const formatSaoPaulo = (value, opts = {}) => {
    const options = {
      timeZone: APP_TZ,
      hour: '2-digit',
      minute: '2-digit',
      ...opts,
    };
    if (value == null || value === '') {
      return new Intl.DateTimeFormat('pt-BR', options).format(new Date());
    }
    if (value instanceof Date) {
      return new Intl.DateTimeFormat('pt-BR', options).format(value);
    }
    const raw = String(value).trim();
    // Datetime MySQL sem offset = horário de São Paulo (wall-clock).
    if (/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/.test(raw) && !/[zZ]|[+\-]\d{2}:?\d{2}$/.test(raw)) {
      const iso = raw.replace(' ', 'T') + '-03:00';
      const d = new Date(iso);
      if (!Number.isNaN(d.getTime())) {
        return new Intl.DateTimeFormat('pt-BR', options).format(d);
      }
    }
    const d = new Date(raw);
    if (Number.isNaN(d.getTime())) return '—';
    return new Intl.DateTimeFormat('pt-BR', options).format(d);
  };
  window.AppClock = {
    timeZone: APP_TZ,
    nowTime: (withSeconds = false) => formatSaoPaulo(null, withSeconds ? { second: '2-digit' } : {}),
    format: formatSaoPaulo,
    formatDateTime: (value) => formatSaoPaulo(value, {
      day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit',
    }),
  };

  const sidebar = document.getElementById('sidebar');
  const backdrop = document.getElementById('sidebarBackdrop');
  const toggle = document.getElementById('sidebarToggle');

  const close = () => {
    sidebar?.classList.remove('is-open');
    backdrop?.classList.remove('is-open');
    toggle?.setAttribute('aria-expanded', 'false');
  };

  const open = () => {
    sidebar?.classList.add('is-open');
    backdrop?.classList.add('is-open');
    toggle?.setAttribute('aria-expanded', 'true');
  };

  toggle?.addEventListener('click', () => {
    if (sidebar?.classList.contains('is-open')) close();
    else open();
  });

  backdrop?.addEventListener('click', close);

  // Acordeão do menu lateral (um grupo aberto por vez dentro da mesma seção)
  document.querySelectorAll('[data-nav-group]').forEach((group) => {
    const toggleBtn = group.querySelector('.nav-group-toggle');
    const sublist = group.querySelector('.nav-sublist');
    if (!(toggleBtn instanceof HTMLElement) || !(sublist instanceof HTMLElement)) return;

    toggleBtn.addEventListener('click', () => {
      const willOpen = !group.classList.contains('is-open');
      const section = group.closest('.nav-section');
      section?.querySelectorAll('[data-nav-group].is-open').forEach((openGroup) => {
        if (openGroup === group) return;
        openGroup.classList.remove('is-open');
        const otherBtn = openGroup.querySelector('.nav-group-toggle');
        const otherList = openGroup.querySelector('.nav-sublist');
        otherBtn?.setAttribute('aria-expanded', 'false');
        if (otherList instanceof HTMLElement) otherList.hidden = true;
      });

      group.classList.toggle('is-open', willOpen);
      toggleBtn.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
      sublist.hidden = !willOpen;
    });
  });

  document.querySelectorAll('[data-modal-open]').forEach((el) => {
    el.addEventListener('click', () => {
      const id = el.getAttribute('data-modal-open');
      const modalBackdrop = document.getElementById(id);
      if (!modalBackdrop) return;
      modalBackdrop.classList.add('is-open');
      const dialogEl = modalBackdrop.querySelector('.modal');
      dialogEl?.setAttribute('role', 'dialog');
      dialogEl?.setAttribute('aria-modal', 'true');
    });
  });

  document.querySelectorAll('[data-modal-close]').forEach((el) => {
    el.addEventListener('click', () => {
      el.closest('.modal-backdrop')?.classList.remove('is-open');
    });
  });

  document.querySelectorAll('.modal-backdrop').forEach((mb) => {
    mb.addEventListener('click', (event) => {
      if (event.target === mb && mb.id !== 'uiConfirmRoot') {
        mb.classList.remove('is-open');
      }
    });
  });

  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    if (sidebar?.classList.contains('is-open')) close();
    document.querySelectorAll('.modal-backdrop.is-open').forEach((mb) => {
      if (mb.id !== 'uiConfirmRoot') mb.classList.remove('is-open');
    });
  });

  const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
  const injectCsrfIntoForm = (form) => {
    if (!(form instanceof HTMLFormElement)) return;
    if ((form.method || '').toLowerCase() !== 'post') return;
    if (form.querySelector('input[name="_csrf"], input[name="_token"]')) return;
    const token = csrfToken();
    if (!token) return;
    const hidden = document.createElement('input');
    hidden.type = 'hidden';
    hidden.name = '_csrf';
    hidden.value = token;
    form.appendChild(hidden);
  };

  document.querySelectorAll('form[method="post" i]').forEach(injectCsrfIntoForm);

  const TONE_ICON = {
    brand: '!',
    danger: '!',
    warning: '!',
    info: 'i',
  };

  const ensureConfirmModal = () => {
    let root = document.getElementById('uiConfirmRoot');
    if (root) return root;

    root = document.createElement('div');
    root.id = 'uiConfirmRoot';
    root.className = 'modal-backdrop ui-confirm-backdrop';
    root.setAttribute('role', 'presentation');
    root.innerHTML = `
      <div class="modal ui-confirm" data-tone="brand" role="dialog" aria-modal="true"
           aria-labelledby="uiConfirmTitle" aria-describedby="uiConfirmMessage">
        <div class="modal-header ui-confirm-header">
          <span class="ui-confirm-icon" id="uiConfirmIcon" aria-hidden="true">!</span>
          <div class="ui-confirm-copy">
            <h3 class="ui-confirm-title" id="uiConfirmTitle">Confirmar</h3>
          </div>
        </div>
        <div class="modal-body ui-confirm-body" id="uiConfirmMessage"></div>
        <div class="modal-footer ui-confirm-footer">
          <button type="button" class="btn btn-secondary" id="uiConfirmCancel">Cancelar</button>
          <button type="button" class="btn btn-primary" id="uiConfirmOk">Confirmar</button>
        </div>
      </div>
    `;
    document.body.appendChild(root);
    return root;
  };

  /**
   * Modal de confirmação padrão do sistema.
   * @param {string|object} options
   * @returns {Promise<boolean>}
   */
  const uiConfirm = (options = {}) => {
    const opts = typeof options === 'string'
      ? { message: options }
      : (options || {});

    const title = opts.title || 'Confirmar ação';
    const message = opts.message || opts.text || '';
    const confirmText = opts.confirmText || opts.okText || 'Confirmar';
    const cancelText = opts.cancelText || 'Cancelar';
    const tone = ['brand', 'danger', 'warning', 'info'].includes(opts.tone) ? opts.tone : 'brand';
    const danger = !!opts.danger || tone === 'danger';

    const root = ensureConfirmModal();
    const dialog = root.querySelector('.ui-confirm');
    const titleEl = root.querySelector('#uiConfirmTitle');
    const messageEl = root.querySelector('#uiConfirmMessage');
    const iconEl = root.querySelector('#uiConfirmIcon');
    const okBtn = root.querySelector('#uiConfirmOk');
    const cancelBtn = root.querySelector('#uiConfirmCancel');
    const previousFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;

    dialog.dataset.tone = danger ? 'danger' : tone;
    titleEl.textContent = title;
    messageEl.textContent = message;
    iconEl.textContent = TONE_ICON[dialog.dataset.tone] || '!';
    cancelBtn.textContent = cancelText;
    okBtn.textContent = confirmText;
    okBtn.className = danger ? 'btn btn-danger' : 'btn btn-primary';

    return new Promise((resolve) => {
      const finish = (value) => {
        root.classList.remove('is-open');
        document.body.classList.remove('ui-confirm-open');
        root.removeEventListener('click', onBackdrop);
        document.removeEventListener('keydown', onKey);
        okBtn.removeEventListener('click', onOk);
        cancelBtn.removeEventListener('click', onCancel);
        previousFocus?.focus?.();
        resolve(value);
      };
      const onOk = () => finish(true);
      const onCancel = () => finish(false);
      const onBackdrop = (event) => {
        if (event.target === root) finish(false);
      };
      const onKey = (event) => {
        if (event.key === 'Escape') {
          event.preventDefault();
          finish(false);
          return;
        }
        if (event.key === 'Tab') {
          event.preventDefault();
          (document.activeElement === cancelBtn ? okBtn : cancelBtn).focus();
          return;
        }
        if (event.key === 'Enter') {
          const active = document.activeElement;
          if (active === cancelBtn) {
            event.preventDefault();
            finish(false);
            return;
          }
          if (!(active instanceof HTMLButtonElement)) {
            event.preventDefault();
            finish(true);
          }
        }
      };

      okBtn.addEventListener('click', onOk);
      cancelBtn.addEventListener('click', onCancel);
      root.addEventListener('click', onBackdrop);
      document.addEventListener('keydown', onKey);

      root.classList.add('is-open');
      document.body.classList.add('ui-confirm-open');
      // Sempre foca Confirmar: o usuário já iniciou a ação (ex.: Excluir).
      queueMicrotask(() => okBtn.focus());
    });
  };

  window.uiConfirm = uiConfirm;

  const readConfirmOptions = (el) => ({
    title: el.getAttribute('data-confirm-title') || 'Confirmar ação',
    message: el.getAttribute('data-confirm') || el.getAttribute('data-confirm-message') || '',
    confirmText: el.getAttribute('data-confirm-ok') || 'Confirmar',
    cancelText: el.getAttribute('data-confirm-cancel') || 'Cancelar',
    tone: el.getAttribute('data-confirm-tone') || 'brand',
    danger: el.hasAttribute('data-confirm-danger'),
  });

  const nativeSubmit = (form, submitter = null) => {
    if (!(form instanceof HTMLFormElement)) return;
    injectCsrfIntoForm(form);
    // Avisa a página (ex.: limpar beforeunload) antes de navegar.
    form.dispatchEvent(new CustomEvent('arcadis:beforenativesubmit', { bubbles: true }));
    // requestSubmit dispara o evento submit (validação/HE/dirty). Fallback: submit() nativo.
    form.dataset.uiConfirmAccepted = '1';
    if (typeof form.requestSubmit === 'function') {
      try {
        if (submitter instanceof HTMLElement) {
          form.requestSubmit(submitter);
        } else {
          form.requestSubmit();
        }
        return;
      } catch (_) {
        // continua no fallback
      }
    }
    HTMLFormElement.prototype.submit.call(form);
  };

  document.addEventListener('click', async (event) => {
    const raw = event.target instanceof Element ? event.target : null;
    if (!raw) return;

    // Botão/link com data-confirm (ou submit dentro de form[data-confirm]).
    let trigger = raw.closest('[data-confirm]');
    if (trigger && trigger.tagName === 'FORM') {
      const submitter = raw.closest('button, input[type="submit"]');
      trigger = submitter || null;
    }
    if (!trigger) return;
    if (trigger.disabled) return;
    if (trigger.dataset.uiConfirmSkip === '1') {
      delete trigger.dataset.uiConfirmSkip;
      return;
    }

    const formForConfirm = trigger.tagName === 'FORM'
      ? trigger
      : (trigger.form || trigger.closest('form'));
    const optsEl = trigger.hasAttribute('data-confirm')
      ? trigger
      : (formForConfirm && formForConfirm.hasAttribute('data-confirm') ? formForConfirm : trigger);
    if (!optsEl || !optsEl.hasAttribute('data-confirm')) return;

    event.preventDefault();
    event.stopPropagation();

    const ok = await uiConfirm(readConfirmOptions(optsEl));
    if (!ok) return;

    if (trigger.tagName === 'A' && trigger.getAttribute('href')) {
      window.location.href = trigger.href;
      return;
    }

    const isSubmitter = (trigger.tagName === 'BUTTON' || trigger.tagName === 'INPUT')
      && String(trigger.type || 'submit') === 'submit';
    if (isSubmitter) {
      const form = trigger.form || trigger.closest('form');
      if (!form) return;
      nativeSubmit(form, trigger);
      return;
    }

    trigger.dataset.uiConfirmSkip = '1';
    trigger.click();
  }, true);

  document.addEventListener('submit', async (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) return;
    injectCsrfIntoForm(form);

    const submitter = event.submitter instanceof HTMLElement ? event.submitter : null;
    const optsEl = (submitter && submitter.hasAttribute('data-confirm'))
      ? submitter
      : (form.hasAttribute('data-confirm') ? form : null);
    if (!optsEl) return;
    if (form.dataset.uiConfirmAccepted === '1') {
      delete form.dataset.uiConfirmAccepted;
      return;
    }

    event.preventDefault();
    event.stopPropagation();
    const ok = await uiConfirm(readConfirmOptions(optsEl));
    if (!ok) return;
    nativeSubmit(form, submitter);
  }, true);

  const ensureToastStack = () => {
    let stack = document.getElementById('uiToastStack');
    if (stack) return stack;
    stack = document.createElement('div');
    stack.id = 'uiToastStack';
    stack.className = 'toast-stack';
    stack.setAttribute('aria-live', 'polite');
    stack.setAttribute('aria-atomic', 'false');
    document.body.appendChild(stack);
    return stack;
  };

  const Toast = {
    show(message, type = 'info', duration = 4200) {
      const msg = String(message || '').trim();
      if (!msg) return null;
      const tone = ['success', 'warning', 'danger', 'info'].includes(type) ? type : 'info';
      const stack = ensureToastStack();
      const item = document.createElement('div');
      item.className = `toast is-${tone}`;
      item.setAttribute('role', tone === 'danger' || tone === 'warning' ? 'alert' : 'status');

      const body = document.createElement('span');
      body.className = 'toast-msg';
      body.textContent = msg;

      const close = document.createElement('button');
      close.type = 'button';
      close.className = 'toast-close';
      close.setAttribute('aria-label', 'Fechar notificação');
      close.textContent = '×';

      const remove = () => {
        item.classList.add('is-leaving');
        window.setTimeout(() => item.remove(), 180);
      };
      close.addEventListener('click', remove);
      item.appendChild(body);
      item.appendChild(close);
      stack.appendChild(item);

      if (duration > 0) {
        window.setTimeout(remove, duration);
      }
      return item;
    },
  };

  window.Toast = Toast;

  window.copyTextWithToast = async (text, successMessage = 'Copiado para a área de transferência.') => {
    const val = String(text || '');
    if (!val) return false;
    try {
      if (navigator.clipboard && navigator.clipboard.writeText) {
        await navigator.clipboard.writeText(val);
      } else {
        const tmp = document.createElement('textarea');
        tmp.value = val;
        tmp.style.position = 'fixed';
        tmp.style.opacity = '0';
        document.body.appendChild(tmp);
        tmp.select();
        document.execCommand('copy');
        tmp.remove();
      }
      Toast.show(successMessage, 'success');
      return true;
    } catch (_err) {
      Toast.show('Não foi possível copiar automaticamente.', 'warning');
      return false;
    }
  };
})();

