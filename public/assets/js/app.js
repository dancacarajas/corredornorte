(() => {
  const sidebar = document.getElementById('sidebar');
  const backdrop = document.getElementById('sidebarBackdrop');
  const toggle = document.getElementById('sidebarToggle');

  const close = () => {
    sidebar?.classList.remove('is-open');
    backdrop?.classList.remove('is-open');
  };

  const open = () => {
    sidebar?.classList.add('is-open');
    backdrop?.classList.add('is-open');
  };

  toggle?.addEventListener('click', () => {
    if (sidebar?.classList.contains('is-open')) close();
    else open();
  });

  backdrop?.addEventListener('click', close);

  document.querySelectorAll('[data-modal-open]').forEach((el) => {
    el.addEventListener('click', () => {
      const id = el.getAttribute('data-modal-open');
      document.getElementById(id)?.classList.add('is-open');
    });
  });

  document.querySelectorAll('[data-modal-close]').forEach((el) => {
    el.addEventListener('click', () => {
      el.closest('.modal-backdrop')?.classList.remove('is-open');
    });
  });
})();
