<?php

use App\Helpers\View;

/** @var array<string,mixed> $usuario */
/** @var array{resumo:array,por_dia:list,por_hora:list,online:bool} $dashboard */
/** @var list<array<string,mixed>> $historico */
/** @var int $dias */

$resumo = $dashboard['resumo'];
$fmtQuando = static function (?string $dt): string {
    if ($dt === null || $dt === '') {
        return '—';
    }
    $ts = strtotime($dt);
    return $ts ? date('d/m/Y H:i:s', $ts) : '—';
};
$fmtDuracao = static function (int $segundos): string {
    $segundos = max(0, $segundos);
    $h = intdiv($segundos, 3600);
    $m = intdiv($segundos % 3600, 60);
    if ($h > 0) {
        return sprintf('%dh %02dmin', $h, $m);
    }
    return sprintf('%d min', $m);
};
$chartPayload = [
    'por_dia' => $dashboard['por_dia'],
    'por_hora' => $dashboard['por_hora'],
];
?>

<section class="atividade-user-hero">
    <div>
        <div class="atividade-user-hero-top">
            <h2><?= View::e((string) $usuario['nome']) ?></h2>
            <?php if (!empty($dashboard['online'])): ?>
                <span class="pill pill-success">Online agora</span>
            <?php else: ?>
                <span class="pill">Offline</span>
            <?php endif; ?>
        </div>
        <p class="muted"><?= View::e((string) $usuario['email']) ?> · conta <?= View::e((string) ($usuario['status'] ?? '')) ?></p>
    </div>
    <form method="get" action="/rh/atividade/<?= (int) $usuario['id'] ?>" class="atividade-dias">
        <label class="form-label" for="diasUso">Período</label>
        <select class="form-control" name="dias" id="diasUso" onchange="this.form.submit()">
            <?php foreach ([7 => '7 dias', 14 => '14 dias', 30 => '30 dias', 60 => '60 dias', 90 => '90 dias'] as $v => $lab): ?>
                <option value="<?= $v ?>" <?= $dias === $v ? 'selected' : '' ?>><?= View::e($lab) ?></option>
            <?php endforeach; ?>
        </select>
    </form>
</section>

<section class="atividade-kpis">
    <article class="atividade-kpi">
        <span class="atividade-kpi-label">Logins (<?= (int) $resumo['dias'] ?>d)</span>
        <strong><?= (int) $resumo['logins'] ?></strong>
    </article>
    <article class="atividade-kpi">
        <span class="atividade-kpi-label">Tempo total</span>
        <strong><?= (int) $resumo['minutos'] ?> min</strong>
    </article>
    <article class="atividade-kpi">
        <span class="atividade-kpi-label">Média por sessão</span>
        <strong><?= View::e((string) $resumo['media_minutos']) ?> min</strong>
    </article>
</section>

<section class="atividade-charts">
    <article class="card">
        <div class="card-header">
            <div>
                <h2 class="card-title">Logins e tempo por dia</h2>
                <p class="card-subtitle">Uso diário no período selecionado</p>
            </div>
        </div>
        <div class="card-body">
            <canvas id="chartPorDia" height="120" aria-label="Gráfico de uso diário"></canvas>
        </div>
    </article>
    <article class="card">
        <div class="card-header">
            <div>
                <h2 class="card-title">Horário dos logins</h2>
                <p class="card-subtitle">Distribuição por hora do dia</p>
            </div>
        </div>
        <div class="card-body">
            <canvas id="chartPorHora" height="120" aria-label="Gráfico por hora"></canvas>
        </div>
    </article>
</section>

<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Histórico de sessões</h2>
            <p class="card-subtitle">Login, logout e duração</p>
        </div>
    </div>
    <?php if ($historico === []): ?>
        <div class="empty-state"><p>Ainda não há sessões registradas para este usuário.</p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>Login</th>
                    <th>Logout</th>
                    <th>Duração</th>
                    <th>IP</th>
                    <th>Motivo</th>
                    <th>Navegador</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($historico as $h):
                    $aberta = empty($h['logged_out_at']);
                    ?>
                    <tr>
                        <td><?= View::e($fmtQuando((string) $h['logged_in_at'])) ?></td>
                        <td><?= $aberta ? '<span class="pill pill-success">Em andamento</span>' : View::e($fmtQuando((string) $h['logged_out_at'])) ?></td>
                        <td><?= View::e($fmtDuracao((int) ($h['segundos'] ?? 0))) ?></td>
                        <td><code><?= View::e((string) ($h['ip'] ?? '—')) ?></code></td>
                        <td><?= View::e((string) ($h['ended_reason'] ?? ($aberta ? '—' : 'logout'))) ?></td>
                        <td class="atividade-ua" title="<?= View::e((string) ($h['user_agent'] ?? '')) ?>"><?= View::e(mb_substr((string) ($h['user_agent'] ?? '—'), 0, 48)) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<script type="application/json" id="atividadeChartData"><?= json_encode($chartPayload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS) ?></script>
