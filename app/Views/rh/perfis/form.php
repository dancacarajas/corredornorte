<?php

use App\Helpers\View;

/** @var ?array<string,mixed> $perfil */
/** @var list<array<string,mixed>> $permissoes */
/** @var ?string $error */

$isEdit = !empty($perfil['id']);
$isSistema = !empty($perfil['sistema']);
$selected = [];
foreach (($perfil['permissao_ids'] ?? []) as $pid) {
    $selected[(int) $pid] = true;
}

$grupos = [];
foreach ($permissoes as $perm) {
    $g = (string) ($perm['grupo'] ?? 'Outros');
    $grupos[$g][] = $perm;
}
$totalPerms = count($permissoes);
$marcadas = count($selected);
$chevron = '<svg class="perfis-mod-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>';
?>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger"><?= View::e((string) $error) ?></div>
<?php endif; ?>

<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title"><?= $isEdit ? 'Editar perfil' : 'Novo perfil' ?></h2>
            <p class="card-subtitle">Defina o perfil e ligue só os módulos necessários.</p>
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
                <h3>Acessos</h3>
                <div class="perfis-access-tools">
                    <input class="form-control perfis-search" type="search" id="perfisSearch"
                           placeholder="Filtrar…" aria-label="Filtrar permissões">
                    <span class="perfis-count" id="perfisCount"><?= (int) $marcadas ?>/<?= (int) $totalPerms ?></span>
                    <button class="btn btn-ghost btn-sm" type="button" data-perm-all>Tudo</button>
                    <button class="btn btn-ghost btn-sm" type="button" data-perm-none>Nada</button>
                </div>
            </div>

            <?php if ($isSistema): ?>
                <p class="perfis-hint">Administrador: mantenha “Gerenciar perfis” marcado.</p>
            <?php endif; ?>

            <div class="perfis-modules">
                <?php
                $gi = 0;
                foreach ($grupos as $grupo => $items):
                    $gi++;
                    $gid = 'g_' . substr(sha1($grupo), 0, 8);
                    $onCount = 0;
                    foreach ($items as $perm) {
                        if (!empty($selected[(int) $perm['id']])) {
                            $onCount++;
                        }
                    }
                    $open = $onCount > 0 && $onCount < count($items) ? true : ($gi === 1 && $marcadas === 0);
                    $allOn = $onCount === count($items) && count($items) > 0;
                    ?>
                    <div class="perfis-mod<?= $open ? ' is-open' : '' ?>" data-perm-group>
                        <div class="perfis-mod-head" data-mod-toggle role="button" tabindex="0" aria-expanded="<?= $open ? 'true' : 'false' ?>">
                            <label class="perfis-mod-check" onclick="event.stopPropagation()">
                                <input type="checkbox" data-group-master="<?= View::e($gid) ?>"
                                    <?= $allOn ? 'checked' : '' ?>
                                    <?= ($onCount > 0 && !$allOn) ? 'data-indeterminate="1"' : '' ?>
                                    aria-label="Marcar módulo <?= View::e($grupo) ?>">
                            </label>
                            <div class="perfis-mod-title">
                                <strong><?= View::e($grupo) ?></strong>
                                <span data-group-meta="<?= View::e($gid) ?>"><?= (int) $onCount ?> de <?= count($items) ?> ativos</span>
                            </div>
                            <?= $chevron ?>
                        </div>
                        <div class="perfis-mod-body" data-group-id="<?= View::e($gid) ?>"<?= $open ? '' : ' hidden' ?>>
                            <?php foreach ($items as $perm):
                                $pid = (int) $perm['id'];
                                $on = !empty($selected[$pid]);
                                $hay = mb_strtolower(
                                    (string) $perm['nome'] . ' ' . (string) $perm['codigo'] . ' ' . (string) ($perm['descricao'] ?? '') . ' ' . $grupo,
                                    'UTF-8'
                                );
                                ?>
                                <label class="perfis-row<?= $on ? ' is-on' : '' ?>" data-perm-item data-search="<?= View::e($hay) ?>">
                                    <input type="checkbox" name="permissao_ids[]" value="<?= $pid ?>"
                                        <?= $on ? 'checked' : '' ?>>
                                    <span>
                                        <span class="perfis-row-name"><?= View::e((string) $perm['nome']) ?></span>
                                        <?php if (!empty($perm['descricao'])): ?>
                                            <span class="perfis-row-desc"><?= View::e((string) $perm['descricao']) ?></span>
                                        <?php endif; ?>
                                    </span>
                                </label>
                            <?php endforeach; ?>
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

  const paintRow = (lab) => {
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
    if (meta) meta.textContent = on + ' de ' + total + ' ativos';
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
      const lab = b.closest('[data-perm-item]');
      if (lab) paintRow(lab);
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

  root.querySelectorAll('[data-perm-item]').forEach((lab) => {
    lab.querySelector('input')?.addEventListener('change', () => {
      paintRow(lab);
      const gid = lab.closest('[data-group-id]')?.getAttribute('data-group-id') || '';
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
        const lab = b.closest('[data-perm-item]');
        if (lab) paintRow(lab);
      });
      // abre o módulo ao marcar
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
    root.querySelectorAll('[data-perm-item]').forEach((lab) => {
      const hay = lab.getAttribute('data-search') || '';
      lab.hidden = q !== '' && !hay.includes(q);
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
