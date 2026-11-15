<?php

use App\Helpers\View;

/** @var bool $dbOk */
/** @var list<array<string,mixed>> $usuarios */
/** @var string $busca */
/** @var ?string $flashOk */
/** @var ?string $flashErro */

$msgs = [
    'criado' => 'Usuário criado com sucesso.',
    'atualizado' => 'Usuário atualizado.',
    'reset_enviado' => 'E-mail de redefinição enviado.',
];
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
            <h2 class="card-title">Usuários</h2>
            <p class="card-subtitle"><?= count($usuarios) ?> conta(s)</p>
        </div>
        <form method="get" action="/rh/usuarios" style="display:flex;gap:.5rem;">
            <input class="form-control" type="search" name="q" value="<?= View::e($busca) ?>" placeholder="Buscar nome ou e-mail">
            <button class="btn btn-secondary btn-sm" type="submit">Buscar</button>
        </form>
    </div>
    <?php if ($usuarios === []): ?>
        <div class="empty-state">
            <h3>Nenhum usuário</h3>
            <p>Crie a primeira conta de acesso.</p>
            <a class="btn btn-primary" href="/rh/usuarios/novo">Novo usuário</a>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th></th>
                    <th>Nome</th>
                    <th>E-mail</th>
                    <th>Perfis</th>
                    <th>Último acesso</th>
                    <th>Status</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($usuarios as $u): ?>
                    <?php
                    $av = trim((string) ($u['avatar_path'] ?? ''));
                    $avUrl = $av !== ''
                        ? '/conta/avatar?id=' . (int) $u['id'] . '&v=' . substr(sha1($av), 0, 8)
                        : null;
                    $ini = mb_strtoupper(mb_substr((string) $u['nome'], 0, 1));
                    ?>
                    <tr>
                        <td style="width:44px;">
                            <div class="avatar <?= $avUrl ? 'has-photo' : '' ?>" aria-hidden="true">
                                <?php if ($avUrl): ?>
                                    <img src="<?= View::e($avUrl) ?>" alt="">
                                <?php else: ?>
                                    <?= View::e($ini) ?>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="fw-700"><?= View::e((string) $u['nome']) ?></td>
                        <td><?= View::e((string) $u['email']) ?></td>
                        <td class="text-sm"><?= View::e((string) ($u['perfis'] ?: '—')) ?></td>
                        <td class="text-sm muted">
                            <?= View::e(
                                !empty($u['last_login_at'])
                                    ? date('d/m/Y H:i', strtotime((string) $u['last_login_at']))
                                    : '—'
                            ) ?>
                        </td>
                        <td>
                            <span class="pill <?= ($u['status'] ?? '') === 'ativo' ? 'pill-success' : 'pill-neutral' ?>">
                                <?= View::e((string) $u['status']) ?>
                            </span>
                        </td>
                        <td>
                            <a class="btn btn-ghost btn-sm" href="/rh/usuarios/<?= (int) $u['id'] ?>/editar">Editar</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
