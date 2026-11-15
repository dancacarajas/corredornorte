(function () {
    'use strict';

    var root = document.querySelector('.alertas-page');
    if (!root) {
        return;
    }
    var csrf = root.getAttribute('data-csrf') || '';

    function postAction(url, bodyParams) {
        var body = '_csrf=' + encodeURIComponent(csrf);
        if (bodyParams) {
            Object.keys(bodyParams).forEach(function (k) {
                body += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(bodyParams[k]);
            });
        }
        return fetch(url, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: body,
            credentials: 'same-origin'
        }).then(function (r) {
            return r.ok ? r.json() : { ok: false };
        }).catch(function () {
            return { ok: false };
        });
    }

    function adjustCounter(name, delta) {
        var els = document.querySelectorAll('[data-stat-counter="' + name + '"]');
        els.forEach(function (el) {
            var cur = parseInt(el.textContent || '0', 10) || 0;
            var next = Math.max(0, cur + delta);
            el.textContent = String(next);
        });
    }

    function adjustNavBadge(delta) {
        var badge = document.querySelector('.nav-item[href="/rh/alertas"] .nav-badge');
        if (!badge) {
            return;
        }
        if (delta === 'zero') {
            badge.remove();
            return;
        }
        var cur = parseInt(badge.textContent || '0', 10) || 0;
        var next = Math.max(0, cur + delta);
        if (next <= 0) {
            badge.remove();
        } else {
            badge.textContent = next > 99 ? '99+' : String(next);
        }
    }

    function markCardAsReadInDom(card) {
        if (!card || card.getAttribute('data-lido') === '1') {
            return;
        }
        card.setAttribute('data-lido', '1');
        card.classList.remove('is-unread');
        card.classList.add('is-read');

        var unreadDot = card.querySelector('[data-alerta-unread-indicator]');
        if (unreadDot) {
            unreadDot.remove();
        }
        var lidoForm = card.querySelector('[data-alerta-lido]');
        if (lidoForm) {
            lidoForm.remove();
        }

        adjustCounter('nao_lidos', -1);
        adjustCounter('nao_lidos_btn', -1);
        adjustNavBadge(-1);

        var unreadStat = document.querySelector('[data-stat-counter="nao_lidos"]');
        var remainingUnread = unreadStat ? (parseInt(unreadStat.textContent || '0', 10) || 0) : 0;
        if (remainingUnread <= 0) {
            var btnAll = document.querySelector('[data-alertas-lidos-todos]');
            if (btnAll) {
                btnAll.remove();
            }
        }
    }

    function checkEmptyList() {
        var list = document.querySelector('.alertas-list');
        if (!list) {
            return;
        }
        var remainingCards = list.querySelectorAll('.alerta-card');
        if (remainingCards.length === 0) {
            var empty = document.createElement('div');
            empty.className = 'empty-state';
            empty.innerHTML =
                '<div class="empty-state__title">Operação em dia — nenhuma pendência restante nesta visualização</div>' +
                '<p class="empty-state__description">Todos os alertas exibidos foram tratados ou arquivados da sua fila.</p>' +
                '<div class="empty-state__actions">' +
                '<a class="btn btn--secondary btn--sm" href="/rh/alertas">Recarregar fila</a>' +
                '<a class="btn btn--primary btn--sm" href="/">Ir para a Visão Geral</a>' +
                '</div>';
            list.replaceWith(empty);
        }
    }

    // Marcar como lido individualmente
    root.addEventListener('submit', function (ev) {
        var form = ev.target;
        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        if (form.hasAttribute('data-alerta-lido')) {
            ev.preventDefault();
            var id = form.getAttribute('data-alerta-lido');
            var card = form.closest('.alerta-card');
            var btn = form.querySelector('button[type="submit"]');
            if (btn) {
                btn.disabled = true;
            }
            postAction('/rh/alertas/' + id + '/lido').then(function (res) {
                if (res && res.ok) {
                    markCardAsReadInDom(card);
                } else if (btn) {
                    btn.disabled = false;
                }
            });
            return;
        }

        if (form.hasAttribute('data-alerta-dispensar')) {
            ev.preventDefault();
            var dId = form.getAttribute('data-alerta-dispensar');
            var dCard = form.closest('.alerta-card');
            var dBtn = form.querySelector('button[type="submit"]');
            if (dBtn) {
                dBtn.disabled = true;
            }
            postAction('/rh/alertas/' + dId + '/dispensar').then(function (res) {
                if (res && res.ok && dCard) {
                    var wasUnread = dCard.getAttribute('data-lido') === '0';
                    var sev = dCard.getAttribute('data-severidade') || '';
                    var isCob = dCard.getAttribute('data-cobranca') === '1';

                    adjustCounter('abertos', -1);
                    if (wasUnread) {
                        adjustCounter('nao_lidos', -1);
                        adjustCounter('nao_lidos_btn', -1);
                        adjustNavBadge(-1);
                    }
                    if (sev === 'critica') {
                        adjustCounter('criticos', -1);
                    } else if (sev === 'alta') {
                        adjustCounter('altas', -1);
                    }
                    if (isCob) {
                        adjustCounter('cobrancas', -1);
                    }

                    dCard.remove();
                    checkEmptyList();
                } else if (dBtn) {
                    dBtn.disabled = false;
                }
            });
            return;
        }

        if (form.hasAttribute('data-alertas-lidos-todos')) {
            ev.preventDefault();
            var allBtn = form.querySelector('button[type="submit"]');
            if (allBtn) {
                allBtn.disabled = true;
            }
            postAction('/rh/alertas/marcar-todos-lidos').then(function (res) {
                if (res && res.ok) {
                    document.querySelectorAll('.alerta-card.is-unread').forEach(function (c) {
                        c.setAttribute('data-lido', '1');
                        c.classList.remove('is-unread');
                        c.classList.add('is-read');
                    });
                    document.querySelectorAll('[data-alerta-unread-indicator], [data-alerta-lido]').forEach(function (el) {
                        el.remove();
                    });
                    document.querySelectorAll('[data-stat-counter="nao_lidos"], [data-stat-counter="nao_lidos_btn"]').forEach(function (el) {
                        el.textContent = '0';
                    });
                    adjustNavBadge('zero');
                    form.remove();
                } else if (allBtn) {
                    allBtn.disabled = false;
                }
            });
        }
    });

    // Atribuição rápida de responsável via select
    root.addEventListener('change', function (ev) {
        var select = ev.target;
        if (!(select instanceof HTMLSelectElement) || !select.classList.contains('alerta-assign-select')) {
            return;
        }
        var form = select.closest('[data-alerta-atribuir]');
        if (!form) {
            return;
        }
        var id = form.getAttribute('data-alerta-atribuir');
        var card = form.closest('.alerta-card');
        select.disabled = true;
        postAction('/rh/alertas/' + id + '/atribuir', { usuario_id: select.value }).then(function (res) {
            select.disabled = false;
            if (res && res.ok && card) {
                var ownerLabel = card.querySelector('[data-alerta-owner-label]');
                var ownerChip = card.querySelector('[data-alerta-owner-chip]');
                var selectedText = select.options[select.selectedIndex]
                    ? select.options[select.selectedIndex].text.replace(/^Responsável:\s*/i, '')
                    : '';
                if (ownerLabel) {
                    ownerLabel.textContent = select.value === '0' ? 'Sem responsável definido' : selectedText;
                }
                if (ownerChip) {
                    ownerChip.classList.toggle('alerta-chip--owner', select.value !== '0');
                }
            }
        });
    });

    // Ao clicar no botão principal de ação, marca como lido em segundo plano
    root.addEventListener('click', function (ev) {
        var link = ev.target instanceof Element ? ev.target.closest('[data-alerta-abrir]') : null;
        if (!link) {
            return;
        }
        var id = link.getAttribute('data-alerta-abrir');
        if (!id) {
            return;
        }
        if (navigator.sendBeacon) {
            var fd = new URLSearchParams();
            fd.set('_csrf', csrf);
            navigator.sendBeacon('/rh/alertas/' + id + '/lido', fd);
        } else {
            postAction('/rh/alertas/' + id + '/lido');
        }
    });
})();
