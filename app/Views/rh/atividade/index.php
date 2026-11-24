<?php

use App\Helpers\AppClock;
use App\Helpers\View;

/** @var bool $dbOk */
/** @var list<array<string,mixed>> $usuarios */
/** @var list<array<string,mixed>> $online */
/** @var array{online:int,sessoes_hoje:int,usuarios_ativos_7d:int} $resumo */
/** @var string $busca */

$fmtQuando = static fn (?string $dt): string => AppClock::format($dt, 'd/m/Y H:i');
$inicial = static function (string $nome): string {
    $n = trim($nome);
    return $n !== '' ? mb_strtoupper(mb_substr($n, 0, 1)) : '?';
};
$avatarUrl = static function (array $row, string $idKey = 'id'): ?string {
    $ready = trim((string) ($row['avatar_url'] ?? ''));
    if ($ready !== '') {
        return $ready;
    }
    $path = trim((string) ($row['avatar_path'] ?? ''));
    if ($path === '') {
        return null;
    }
    $id = (int) ($row[$idKey] ?? 0);
    if ($id <= 0) {
        return null;
    }
    return '/conta/avatar?id=' . $id . '&v=' . substr(sha1($path), 0, 8);
};
?>

<?php if (!$dbOk): ?>
    <div class="alert alert-warning">Banco indisponível para o painel de atividade.</div>
<?php endif; ?>

<section class="atividade-kpis" id="atividadeKpis" aria-live="polite">
    <article class="atividade-kpi is-online">
        <span class="atividade-kpi-label">Online agora</span>
        <strong id="kpiOnline"><?= (int) $resumo['online'] ?></strong>
    </article>
    <article class="atividade-kpi">
        <span class="atividade-kpi-label">Sessões hoje</span>
        <strong id="kpiHoje"><?= (int) $resumo['sessoes_hoje'] ?></strong>
    </article>
    <article class="atividade-kpi">
        <span class="atividade-kpi-label">Ativos em 7 dias</span>
        <strong id="kpiAtivos"><?= (int) $resumo['usuarios_ativos_7d'] ?></strong>
    </article>
    <article class="atividade-kpi atividade-kpi-live">
        <span class="atividade-live-dot" aria-hidden="true"></span>
        <span class="atividade-kpi-label">Atualização ao vivo</span>
        <strong id="kpiLive">conectando…</strong>
    </article>
</section>

<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Quem está online</h2>
            <p class="card-subtitle">Presença com base em heartbeat (últimos 2 minutos)</p>
        </div>
    </div>
    <div class="card-body" id="atividadeOnlineWrap">
        <?php if ($online === []): ?>
            <p class="muted text-sm" id="atividadeOnlineEmpty">Ninguém online no momento.</p>
            <div class="atividade-online-grid" id="atividadeOnlineGrid" hidden></div>
        <?php else: ?>
            <p class="muted text-sm" id="atividadeOnlineEmpty" hidden>Ninguém online no momento.</p>
            <div class="atividade-online-grid" id="atividadeOnlineGrid">
                <?php foreach ($online as $o):
                    $av = $avatarUrl($o, 'usuario_id');
                    ?>
                    <a class="atividade-online-card" href="/rh/atividade/<?= (int) $o['usuario_id'] ?>">
                        <span class="atividade-avatar <?= $av ? 'has-photo' : '' ?>" aria-hidden="true">
                            <?php if ($av): ?>
                                <img src="<?= View::e($av) ?>" alt="">
                            <?php else: ?>
                                <?= View::e($inicial((string) $o['nome'])) ?>
                            <?php endif; ?>
                        </span>
                        <span>
                            <strong><?= View::e((string) $o['nome']) ?></strong>
                            <small>desde <?= View::e($fmtQuando((string) $o['logged_in_at'])) ?></small>
                        </span>
                        <span class="pill pill-success">Online</span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Usuários e histórico</h2>
            <p class="card-subtitle">Clique em <strong>Analisar</strong> para ver gráficos de uso</p>
        </div>
        <form method="get" action="/rh/atividade" class="atividade-busca" id="atividadeBuscaForm">
            <input class="form-control" type="search" name="q" id="atividadeBusca" value="<?= View::e($busca) ?>" placeholder="Buscar nome ou e-mail">
            <button class="btn btn-secondary btn-sm" type="submit">Buscar</button>
        </form>
    </div>
    <?php if ($usuarios === []): ?>
        <div class="empty-state"><h3>Nenhum usuário</h3></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table" id="atividadeUsersTable">
                <thead>
                <tr>
                    <th>Usuário</th>
                    <th>Status</th>
                    <th>Último login</th>
                    <th>Último logout</th>
                    <th>Logins 30d</th>
                    <th></th>
                </tr>
                </thead>
                <tbody id="atividadeUsersBody">
                <?php foreach ($usuarios as $u):
                    $isOnline = !empty($u['online_at']);
                    $av = $avatarUrl($u, 'id');
                    ?>
                    <tr data-user-id="<?= (int) $u['id'] ?>">
                        <td>
                            <div class="atividade-user-cell">
                                <span class="atividade-avatar <?= $isOnline ? 'is-online' : '' ?> <?= $av ? 'has-photo' : '' ?>">
                                    <?php if ($av): ?>
                                        <img src="<?= View::e($av) ?>" alt="">
                                    <?php else: ?>
                                        <?= View::e($inicial((string) $u['nome'])) ?>
                                    <?php endif; ?>
                                </span>
                                <span>
                                    <strong><?= View::e((string) $u['nome']) ?></strong>
                                    <small class="muted"><?= View::e((string) $u['email']) ?></small>
                                </span>
                            </div>
                        </td>
                        <td>
                            <?php if ($isOnline): ?>
                                <span class="pill pill-success">Online</span>
                            <?php else: ?>
                                <span class="pill">Offline</span>
                            <?php endif; ?>
                        </td>
                        <td><?= View::e($fmtQuando(isset($u['ultimo_login']) ? (string) $u['ultimo_login'] : null)) ?></td>
                        <td><?= View::e($isOnline ? '—' : $fmtQuando(isset($u['ultimo_logout']) ? (string) $u['ultimo_logout'] : null)) ?></td>
                        <td><?= (int) ($u['logins_30d'] ?? 0) ?></td>
                        <td class="text-right">
                            <a class="btn btn-primary btn-sm" href="/rh/atividade/<?= (int) $u['id'] ?>">Analisar</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
