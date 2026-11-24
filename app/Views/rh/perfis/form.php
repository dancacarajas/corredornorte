<?php

use App\Helpers\View;
use App\Services\PerfilService;

/** @var ?array<string,mixed> $perfil */
/** @var list<array{grupo:string,itens:list<array{recurso:string,label:string,menu:string,celulas:array<string,?array{id:int,codigo:string}>}>}> $matriz */
/** @var ?string $error */

$isEdit = !empty($perfil['id']);
$isSistema = !empty($perfil['sistema']);
$selected = [];
foreach (($perfil['permissao_ids'] ?? []) as $pid) {
    $selected[(int) $pid] = true;
}

$acoes = PerfilService::ACOES;
$acaoMeta = [
    'ver' => ['label' => 'Ver', 'icon' => 'eye'],
    'criar' => ['label' => 'Criar', 'icon' => 'plus'],
    'editar' => ['label' => 'Editar', 'icon' => 'pencil'],
    'excluir' => ['label' => 'Excluir', 'icon' => 'trash'],
    'operar' => ['label' => 'Operar', 'icon' => 'play'],
];

$totalCells = 0;
$marcadas = 0;
foreach ($matriz as $grupo) {
    foreach ($grupo['itens'] as $item) {
        foreach ($acoes as $acao) {
            $cel = $item['celulas'][$acao] ?? null;
            if ($cel === null) {
                continue;
            }
            $totalCells++;
            if (!empty($selected[(int) $cel['id']])) {
                $marcadas++;
            }
        }
    }
}

$icons = [
    'eye' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg>',
    'plus' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>',
    'pencil' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/></svg>',
    'trash' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/></svg>',
    'play' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 5v14l11-7z"/></svg>',
];
$chevron = '<svg class="perfis-mod-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>';
?>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger"><?= View::e((string) $error) ?></div>
<?php endif; ?>

<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title"><?= $isEdit ? 'Editar perfil' : 'Novo perfil' ?></h2>
            <p class="card-subtitle">Matriz 360° — liberar ou bloquear Ver, Criar, Editar, Excluir e Operar por tela do menu.</p>
        </div>
        <a class="btn btn-ghost btn-sm" href="/rh/perfis">Voltar</a>
    </div>

    <form method="post" action="<?= $isEdit ? '/rh/perfis/' . (int) $perfil['id'] : '/rh/perfis' ?>" class="card-body" id="perfilForm">
        <div class="perfis-form-top">
            <div class="form-field">
                <label class="form-label">Código</label>
                <input class="form-control" name="codigo" required
                       value="<?= View::e((string) ($perfil['codigo'] ?? '')) ?>"
                       <?= $isEdit ? 'readonly' : '' ?>
                       placeholder="ex.: auditor">
            </div>
            <div class="form-field">
                <label class="form-label">Nome</label>
                <input class="form-control" name="nome" required value="<?= View::e((string) ($perfil['nome'] ?? '')) ?>">
            </div>
            <div class="form-field">
                <label class="form-label">Descrição</label>
                <input class="form-control" name="descricao" value="<?= View::e((string) ($perfil['descricao'] ?? '')) ?>"
                       placeholder="Para que serve este perfil">
            </div>
            <div class="form-field">
                <label class="form-label">Status</label>
                <select class="form-control" name="status" <?= $isSistema ? 'disabled' : '' ?>>
                    <?php foreach (['ativo', 'inativo'] as $st): ?>
                        <option value="<?= $st ?>" <?= (($perfil['status'] ?? 'ativo') === $st) ? 'selected' : '' ?>><?= $st ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($isSistema): ?>
                    <input type="hidden" name="status" value="ativo">
                <?php endif; ?>
            </div>
        </div>

        <div class="perfis-access" id="perfisMatrix">
            <div class="perfis-access-head">
                <div>
                    <h3>Gestão de acessos 360°</h3>
                    <p class="perfis-access-sub">Cada linha é uma tela do sistema. Marque só o que o perfil pode fazer.</p>
                </div>
                <div class="perfis-access-tools">
                    <input class="form-control perfis-search" type="search" id="perfisSearch"
                           placeholder="Buscar… gestor, feriado, ponto" aria-label="Filtrar matriz">
                    <span class="perfis-count" id="perfisCount"><?= (int) $marcadas ?>/<?= (int) $totalCells ?></span>
                    <button class="btn btn-ghost btn-sm" type="button" data-perm-all>Tudo</button>
                    <button class="btn btn-ghost btn-sm" type="button" data-perm-none>Nada</button>
                </div>
            </div>

            <?php if ($isSistema): ?>
                <p class="perfis-hint">Administrador: mantenha Perfis → Operar ou Editar para não se travar fora desta tela.</p>
            <?php endif; ?>

            <div class="perfis-matrix-legend" aria-hidden="true">
                <?php foreach ($acoes as $acao): ?>
                    <span class="perfis-legend-item">
                        <span class="perfis-acao-ico"><?= $icons[$acaoMeta[$acao]['icon']] ?></span>
                        <?= View::e($acaoMeta[$acao]['label']) ?>
                    </span>
                <?php endforeach; ?>
            </div>

            <div class="perfis-modules">
                <?php
                foreach ($matriz as $grupo):
                    $gid = 'g_' . substr(sha1($grupo['grupo']), 0, 8);
                    $onCount = 0;
                    $cellCount = 0;
                    foreach ($grupo['itens'] as $item) {
                        foreach ($acoes as $acao) {
                            $cel = $item['celulas'][$acao] ?? null;
                            if ($cel === null) {
                                continue;
                            }
                            $cellCount++;
                            if (!empty($selected[(int) $cel['id']])) {
                                $onCount++;
                            }
                        }
                    }
                    $allOn = $cellCount > 0 && $onCount === $cellCount;
                    ?>
                    <div class="perfis-mod is-open" data-perm-group>
                        <div class="perfis-mod-head" data-mod-toggle role="button" tabindex="0" aria-expanded="true">
                            <label class="perfis-mod-check" onclick="event.stopPropagation()">
                                <input type="checkbox" data-group-master="<?= View::e($gid) ?>"
                                    <?= $allOn ? 'checked' : '' ?>
                                    <?= ($onCount > 0 && !$allOn) ? 'data-indeterminate="1"' : '' ?>
                                    aria-label="Marcar módulo <?= View::e($grupo['grupo']) ?>">
                            </label>
                            <div class="perfis-mod-title">
                                <strong><?= View::e($grupo['grupo']) ?></strong>
                                <span data-group-meta="<?= View::e($gid) ?>"><?= (int) $onCount ?> de <?= (int) $cellCount ?> ações</span>
                            </div>
                            <?= $chevron ?>
                        </div>
                        <div class="perfis-mod-body" data-group-id="<?= View::e($gid) ?>">
                            <div class="perfis-matrix-table" role="table">
                                <div class="perfis-matrix-head" role="row">
                                    <div class="perfis-matrix-cell is-label" role="columnheader">Menu / tela</div>
                                    <?php foreach ($acoes as $acao): ?>
                                        <div class="perfis-matrix-cell is-acao" role="columnheader" title="<?= View::e($acaoMeta[$acao]['label']) ?>">
                                            <span class="perfis-acao-ico"><?= $icons[$acaoMeta[$acao]['icon']] ?></span>
                                            <span class="perfis-acao-txt"><?= View::e($acaoMeta[$acao]['label']) ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php foreach ($grupo['itens'] as $item):
                                    $hay = mb_strtolower(
                                        $item['label'] . ' ' . $item['menu'] . ' ' . $item['recurso'] . ' ' . $grupo['grupo'],
                                        'UTF-8'
                                    );
                                    ?>
                                    <div class="perfis-matrix-row" role="row" data-perm-item data-search="<?= View::e($hay) ?>">
                                        <div class="perfis-matrix-cell is-label" role="cell">
                                            <span class="perfis-row-name"><?= View::e($item['label']) ?></span>
                                            <span class="perfis-row-desc"><?= View::e($item['menu']) ?></span>
                                        </div>
                                        <?php foreach ($acoes as $acao):
                                            $cel = $item['celulas'][$acao] ?? null;
                                            if ($cel === null): ?>
                                                <div class="perfis-matrix-cell is-acao is-na" role="cell" aria-hidden="true">—</div>
                                            <?php else:
                                                $pid = (int) $cel['id'];
                                                $on = !empty($selected[$pid]);
                                                ?>
                                                <label class="perfis-matrix-cell is-acao<?= $on ? ' is-on' : '' ?>" role="cell" title="<?= View::e($acaoMeta[$acao]['label'] . ' · ' . $item['label']) ?>">
                                                    <input type="checkbox" name="permissao_ids[]" value="<?= $pid ?>"
                                                        <?= $on ? 'checked' : '' ?>
                                                        aria-label="<?= View::e($acaoMeta[$acao]['label'] . ' ' . $item['label']) ?>">
                                                    <span class="perfis-check" aria-hidden="true"></span>
                                                </label>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="perfis-actions">
            <button class="btn btn-primary" type="submit">Salvar perfil</button>
            <a class="btn btn-ghost" href="/rh/perfis">Cancelar</a>
        </div>
    </form>
</section>

<script>
(function () {
  const root = document.getElementById('perfisMatrix');
  const search = document.getElementById('perfisSearch');
  const countEl = document.getElementById('perfisCount');
  if (!root) return;

  const boxes = () => Array.from(root.querySelectorAll('input[type="checkbox"][name="permissao_ids[]"]'));

  const paintCell = (lab) => {
    const input = lab.querySelector('input[type="checkbox"]');
    lab.classList.toggle('is-on', !!(input && input.checked));
  };

  const refreshGroup = (gid) => {
    const body = root.querySelector('[data-group-id="' + gid + '"]');
    const master = root.querySelector('[data-group-master="' + gid + '"]');
    const meta = root.querySelector('[data-group-meta="' + gid + '"]');
    if (!body || !master) return;
    const list = Array.from(body.querySelectorAll('input[type="checkbox"][name="permissao_ids[]"]'));
    const visible = list.filter((b) => !b.closest('[data-perm-item]')?.hidden);
    const on = visible.filter((b) => b.checked).length;
    const total = visible.length;
    master.checked = total > 0 && on === total;
    master.indeterminate = on > 0 && on < total;
    if (meta) meta.textContent = on + ' de ' + total + ' ações';
  };

  const refreshAllGroups = () => {
    root.querySelectorAll('[data-group-id]').forEach((el) => {
      refreshGroup(el.getAttribute('data-group-id') || '');
    });
    const all = boxes();
    const on = all.filter((b) => b.checked).length;
    if (countEl) countEl.textContent = on + '/' + all.length;
  };

  const setAll = (checked) => {
    boxes().forEach((b) => {
      if (b.closest('[data-perm-item]')?.hidden) return;
      b.checked = checked;
      const lab = b.closest('.perfis-matrix-cell');
      if (lab) paintCell(lab);
    });
    refreshAllGroups();
  };

  root.querySelectorAll('[data-mod-toggle]').forEach((head) => {
    const toggle = () => {
      const mod = head.closest('.perfis-mod');
      const body = mod?.querySelector('.perfis-mod-body');
      if (!mod || !body) return;
      const open = mod.classList.toggle('is-open');
      body.hidden = !open;
      head.setAttribute('aria-expanded', open ? 'true' : 'false');
    };
    head.addEventListener('click', (ev) => {
      if (ev.target instanceof HTMLInputElement) return;
      toggle();
    });
    head.addEventListener('keydown', (ev) => {
      if (ev.key === 'Enter' || ev.key === ' ') {
        ev.preventDefault();
        toggle();
      }
    });
  });

  root.querySelectorAll('.perfis-matrix-cell.is-acao input[type="checkbox"]').forEach((input) => {
    const lab = input.closest('.perfis-matrix-cell');
    input.addEventListener('change', () => {
      if (lab) paintCell(lab);
      const gid = input.closest('[data-group-id]')?.getAttribute('data-group-id') || '';
      refreshGroup(gid);
      refreshAllGroups();
    });
  });

  root.querySelectorAll('[data-group-master]').forEach((master) => {
    if (master.getAttribute('data-indeterminate') === '1') {
      master.indeterminate = true;
    }
    master.addEventListener('change', () => {
      const gid = master.getAttribute('data-group-master') || '';
      const body = root.querySelector('[data-group-id="' + gid + '"]');
      if (!body) return;
      const checked = master.checked;
      body.querySelectorAll('input[type="checkbox"][name="permissao_ids[]"]').forEach((b) => {
        if (b.closest('[data-perm-item]')?.hidden) return;
        b.checked = checked;
        const lab = b.closest('.perfis-matrix-cell');
        if (lab) paintCell(lab);
      });
      const mod = body.closest('.perfis-mod');
      if (mod && checked) {
        mod.classList.add('is-open');
        body.hidden = false;
      }
      refreshAllGroups();
    });
  });

  root.querySelector('[data-perm-all]')?.addEventListener('click', () => setAll(true));
  root.querySelector('[data-perm-none]')?.addEventListener('click', () => setAll(false));

  search?.addEventListener('input', () => {
    const q = (search.value || '').trim().toLowerCase();
    root.querySelectorAll('[data-perm-item]').forEach((row) => {
      const hay = row.getAttribute('data-search') || '';
      row.hidden = q !== '' && !hay.includes(q);
    });
    root.querySelectorAll('[data-perm-group]').forEach((group) => {
      const visible = group.querySelectorAll('[data-perm-item]:not([hidden])').length;
      group.hidden = visible === 0;
      if (q !== '' && visible > 0) {
        group.classList.add('is-open');
        const body = group.querySelector('.perfis-mod-body');
        if (body) body.hidden = false;
      }
    });
    refreshAllGroups();
  });

  refreshAllGroups();
})();
</script>
