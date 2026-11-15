(function () {
    'use strict';

    // 1. Aba Regras e Prazos: toggle visual de regra ativa/inativa e presets de SLA
    document.querySelectorAll('[data-rule-toggle]').forEach(function (chk) {
        chk.addEventListener('change', function () {
            var item = chk.closest('[data-rule-item]');
            var txt = chk.parentElement ? chk.parentElement.querySelector('.switch-text') : null;
            if (item) {
                item.classList.toggle('is-enabled', chk.checked);
                item.classList.toggle('is-disabled', !chk.checked);
            }
            if (txt) {
                txt.textContent = chk.checked ? 'Ativa' : 'Inativa';
            }
        });
    });

    document.querySelectorAll('[data-sla-set]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var val = btn.getAttribute('data-sla-set') || '';
            var box = btn.closest('.alertas-cfg-sla-box');
            var input = box ? box.querySelector('[data-sla-input]') : null;
            if (input) {
                input.value = val;
                input.focus();
            }
        });
    });

    // 2. Aba Destinatários: chips interativos + contador ao vivo + botão Limpar seleção
    function updateDestinoCard(card) {
        if (!card) {
            return;
        }
        var checks = card.querySelectorAll('[data-destino-check]');
        var count = 0;
        checks.forEach(function (c) {
            var lbl = c.closest('.chip-check');
            if (lbl) {
                lbl.classList.toggle('is-checked', c.checked);
            }
            if (c.checked) {
                count++;
            }
        });
        var badge = card.querySelector('[data-destino-badge]');
        if (badge) {
            badge.textContent = count > 0
                ? ('Restrito a ' + count + ' selecionado(s)')
                : 'Visível para toda a equipe RH';
            badge.classList.toggle('is-restricted', count > 0);
            badge.classList.toggle('is-all', count === 0);
        }
        var clearBtn = card.querySelector('[data-destino-clear]');
        if (clearBtn) {
            clearBtn.hidden = count === 0;
        }
    }

    document.querySelectorAll('[data-destino-card]').forEach(function (card) {
        card.addEventListener('change', function (ev) {
            if (ev.target && ev.target.hasAttribute('data-destino-check')) {
                updateDestinoCard(card);
            }
        });
        var clearBtn = card.querySelector('[data-destino-clear]');
        if (clearBtn) {
            clearBtn.addEventListener('click', function () {
                card.querySelectorAll('[data-destino-check]').forEach(function (c) {
                    c.checked = false;
                });
                updateDestinoCard(card);
            });
        }
    });

    // 3. Aba Geral: copiar comandos cURL do Cron
    document.querySelectorAll('[data-copy-text]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var txt = btn.getAttribute('data-copy-text') || '';
            if (!txt) {
                return;
            }
            var original = btn.textContent;
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(txt).then(function () {
                    btn.textContent = '✓ Copiado!';
                    setTimeout(function () {
                        btn.textContent = original;
                    }, 1800);
                });
            }
        });
    });

    // 4. Aba Manutenção em Lote: filtro instantâneo, seleção e contador
    var checkAll = document.querySelector('[data-alertas-check-all]');
    var searchInput = document.querySelector('[data-alertas-batch-search]');
    var sevFilter = document.querySelector('[data-alertas-batch-sev]');
    var counterEl = document.querySelector('[data-alertas-sel-counter]');
    var batchBtns = document.querySelectorAll('[data-batch-btn]');
    var rows = document.querySelectorAll('[data-batch-row]');

    function updateBatchSelectionState() {
        var totalVisible = 0;
        var selectedCount = 0;
        rows.forEach(function (row) {
            if (!row.hidden) {
                totalVisible++;
            }
            var chk = row.querySelector('[data-alerta-check]');
            if (chk && chk.checked) {
                selectedCount++;
            }
        });
        if (counterEl) {
            counterEl.textContent = selectedCount + ' selecionado(s) de ' + totalVisible;
        }
        batchBtns.forEach(function (btn) {
            btn.disabled = selectedCount === 0;
        });
    }

    function applyBatchFilters() {
        var q = searchInput ? (searchInput.value || '').toLowerCase().trim() : '';
        var sev = sevFilter ? (sevFilter.value || '') : '';
        rows.forEach(function (row) {
            var rSev = row.getAttribute('data-row-sev') || '';
            var rSearch = row.getAttribute('data-row-search') || '';
            var matchSev = sev === '' || rSev === sev;
            var matchQ = q === '' || rSearch.indexOf(q) !== -1;
            var visible = matchSev && matchQ;
            row.hidden = !visible;
            if (!visible) {
                var chk = row.querySelector('[data-alerta-check]');
                if (chk) {
                    chk.checked = false;
                }
            }
        });
        if (checkAll) {
            checkAll.checked = false;
        }
        updateBatchSelectionState();
    }

    if (checkAll) {
        checkAll.addEventListener('change', function () {
            rows.forEach(function (row) {
                if (row.hidden) {
                    return;
                }
                var chk = row.querySelector('[data-alerta-check]');
                if (chk) {
                    chk.checked = checkAll.checked;
                }
            });
            updateBatchSelectionState();
        });
    }

    document.querySelectorAll('[data-alerta-check]').forEach(function (chk) {
        chk.addEventListener('change', updateBatchSelectionState);
    });

    if (searchInput) {
        searchInput.addEventListener('input', applyBatchFilters);
    }
    if (sevFilter) {
        sevFilter.addEventListener('change', applyBatchFilters);
    }
})();
