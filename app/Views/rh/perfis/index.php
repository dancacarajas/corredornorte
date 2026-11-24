<?php

use App\Helpers\Auth;
use App\Helpers\View;

/** @var bool $dbOk */
/** @var list<array<string,mixed>> $perfis */
/** @var ?string $flashOk */
/** @var ?string $flashErro */

$msgs = [
    'criado' => 'Perfil criado.',
    'atualizado' => 'Perfil atualizado.',
    'excluido' => 'Perfil excluído.',
];
$podeCriar = Auth::canAny('perfis.criar', 'perfis.operar', 'perfis.gerenciar');
$podeEditar = Auth::canAny('perfis.editar', 'perfis.operar', 'perfis.gerenciar');
$podeExcluir = Auth::canAny('perfis.excluir', 'perfis.gerenciar');
?>

<?php if (!$dbOk): ?>
    <div class="alert alert-warning">MySQL indisponível.</div>
<?php endif; ?>
<?php if (!empty($flashOk)): ?>
    <div class="alert alert-success"><?= View::e($msgs[(string) $flashOk] ?? 'OK') ?></div>
<?php endif; ?>
<?php if (!empty($flashErro)): ?>
    <div class="alert alert-danger"><?= View::e((string) $flashErro) ?></div>
<?php endif; ?>

<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Perfis de acesso</h2>
            <p class="card-subtitle">Definem o que cada usuário pode ver e fazer</p>
        </div>
    </div>
    <?php if ($perfis === []): ?>
        <div class="empty-state">
            <h3>Nenhum perfil</h3>
            <?php if ($podeCriar): ?><a class="btn btn-primary" href="/rh/perfis/novo">Novo perfil</a><?php endif; ?>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>Perfil</th>
                    <th>Código</th>
                    <th>Permissões</th>
                    <th>Usuários</th>
                    <th>Status</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($perfis as $p): ?>
                    <tr>
                        <td>
                            <div class="fw-700"><?= View::e((string) $p['nome']) ?></div>
                            <div class="text-xs muted"><?= View::e((string) ($p['descricao'] ?? '')) ?></div>
                            <?php if (!empty($p['sistema'])): ?>
                                <span class="pill pill-brand">sistema</span>
                            <?php endif; ?>
                        </td>
                        <td><code><?= View::e((string) $p['codigo']) ?></code></td>
                        <td><?= (int) ($p['total_permissoes'] ?? 0) ?></td>
                        <td><?= (int) ($p['total_usuarios'] ?? 0) ?></td>
                        <td>
                            <span class="pill <?= ($p['status'] ?? '') === 'ativo' ? 'pill-success' : 'pill-neutral' ?>">
                                <?= View::e((string) ($p['status'] ?? 'ativo')) ?>
                            </span>
                        </td>
                        <td style="white-space:nowrap;">
                            <?php if ($podeEditar): ?>
                            <a class="btn btn-ghost btn-sm" href="/rh/perfis/<?= (int) $p['id'] ?>/editar">Editar</a>
                            <?php endif; ?>
                            <?php if ($podeCriar): ?>
                            <button class="btn btn-ghost btn-sm" type="button"
                                    onclick="window.duplicarPerfil && window.duplicarPerfil(<?= (int) $p['id'] ?>, <?= json_encode((string) $p['codigo'], JSON_UNESCAPED_UNICODE) ?>, <?= json_encode((string) $p['nome'], JSON_UNESCAPED_UNICODE) ?>)">
                                Duplicar
                            </button>
                            <?php endif; ?>
                            <?php if ($podeExcluir && empty($p['sistema'])): ?>
                            <form method="post" action="/rh/perfis/<?= (int) $p['id'] ?>/excluir" style="display:inline;"
                                  data-confirm-title="Excluir perfil?"
                                  data-confirm="Remove ou inativa o perfil <?= View::e((string)$p['nome']) ?>."
                                  data-confirm-ok="Excluir"
                                  data-confirm-tone="danger">
                                <?= \App\Helpers\Csrf::field() ?>
                                <button class="btn btn-ghost btn-sm" type="submit" style="color:#B42318;">Excluir</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<form id="perfilDuplicarForm" method="post" action="" style="display:none;">
    <?= \App\Helpers\Csrf::field() ?>
    <input type="hidden" name="codigo" id="dupCodigo">
    <input type="hidden" name="nome" id="dupNome">
</form>
<script>
window.duplicarPerfil = function (id, codigoBase, nomeBase) {
  const codigo = window.prompt('Código do novo perfil:', String(codigoBase || 'perfil') + '_copia');
  if (!codigo) return;
  const nome = window.prompt('Nome do novo perfil:', String(nomeBase || 'Perfil') + ' (cópia)');
  if (!nome) return;
  const form = document.getElementById('perfilDuplicarForm');
  const c = document.getElementById('dupCodigo');
  const n = document.getElementById('dupNome');
  if (!form || !c || !n) return;
  form.action = '/rh/perfis/' + id + '/duplicar';
  c.value = codigo;
  n.value = nome;
  form.submit();
};
</script>
