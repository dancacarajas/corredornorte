<?php

use App\Helpers\AppClock;
use App\Helpers\View;

/** @var array<string,mixed> $usuario */
/** @var array{resumo:array,por_dia:list,por_faixa:list,online:bool} $dashboard */
/** @var list<array<string,mixed>> $historico */
/** @var int $dias */

$resumo = $dashboard['resumo'];
$fmtQuando = static fn (?string $dt): string => AppClock::format($dt, 'd/m/Y H:i:s');
$fmtDuracaoMin = static function (float|int $minutos): string {
    $segundos = (int) max(0, round(((float) $minutos) * 60));
    if ($segundos < 60) {
        return $segundos <= 0 ? '0 min' : $segundos . 's';
    }
    $m = intdiv($segundos, 60);
    $s = $segundos % 60;
    if ($m < 60) {
        return $s > 0 ? sprintf('%d min %ds', $m, $s) : sprintf('%d min', $m);
    }
    $h = intdiv($m, 60);
    $rest = $m % 60;
    return $rest > 0 ? sprintf('%dh %02dmin', $h, $rest) : sprintf('%dh', $h);
};
$fmtDuracaoSeg = static function (int $segundos): string {
    $segundos = max(0, $segundos);
    if ($segundos < 60) {
        return $segundos <= 0 ? '0 min' : $segundos . 's';
    }
    $h = intdiv($segundos, 3600);
    $m = intdiv($segundos % 3600, 60);
    if ($h > 0) {
        return sprintf('%dh %02dmin', $h, $m);
    }
    return sprintf('%d min', $m);
};
$chartPayload = [
    'por_dia' => $dashboard['por_dia'],
    'por_faixa' => $dashboard['por_faixa'],
];
$avPath = trim((string) ($usuario['avatar_path'] ?? ''));
$avUrl = $avPath !== ''
    ? '/conta/avatar?id=' . (int) $usuario['id'] . '&v=' . substr(sha1($avPath), 0, 8)
    : null;
$ini = mb_strtoupper(mb_substr(trim((string) $usuario['nome']), 0, 1)) ?: '?';
$faixaTopo = null;
$maxFaixaScore = -1.0;
foreach ($dashboard['por_faixa'] as $f) {
    $score = max((float) ($f['minutos'] ?? 0), (float) ($f['logins'] ?? 0));
    if ($score > $maxFaixaScore) {
        $maxFaixaScore = $score;
        $faixaTopo = $f;
    }
}
?>

<section class="atividade-user-hero">
    <div class="atividade-user-hero-main">
        <span class="atividade-avatar atividade-avatar-lg <?= $avUrl ? 'has-photo' : '' ?>" aria-hidden="true">
            <?php if ($avUrl): ?>
                <img src="<?= View::e($avUrl) ?>" alt="" onerror="const p=this.parentElement; if(p){p.classList.remove('has-photo'); p.textContent='<?= View::e($ini) ?>';}">
            <?php else: ?>
                <?= View::e($ini) ?>
            <?php endif; ?>
        </span>
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

<section class="atividade-kpis atividade-kpis-5">
    <article class="atividade-kpi">
        <span class="atividade-kpi-label">Dias ativos</span>
        <strong><?= (int) ($resumo['dias_ativos'] ?? 0) ?></strong>
        <small class="muted">de <?= (int) $resumo['dias'] ?> dias</small>
    </article>
    <article class="atividade-kpi">
        <span class="atividade-kpi-label">Tempo total</span>
        <strong><?= View::e($fmtDuracaoMin((float) ($resumo['minutos'] ?? 0))) ?></strong>
        <small class="muted"><?= (int) $resumo['logins'] ?> sessão(ões)</small>
    </article>
    <article class="atividade-kpi">
        <span class="atividade-kpi-label">Média por sessão</span>
        <strong><?= View::e($fmtDuracaoMin((float) ($resumo['media_minutos'] ?? 0))) ?></strong>
        <small class="muted">sessão mais longa: <?= View::e($fmtDuracaoMin((float) ($resumo['max_minutos'] ?? 0))) ?></small>
    </article>
    <article class="atividade-kpi">
        <span class="atividade-kpi-label">Faixa preferida</span>
        <strong><?= View::e((string) ($faixaTopo['label'] ?? '—')) ?></strong>
        <small class="muted"><?= View::e((string) ($faixaTopo['horario'] ?? '')) ?></small>
    </article>
    <article class="atividade-kpi">
        <span class="atividade-kpi-label">Último login</span>
        <strong class="atividade-kpi-compact"><?= View::e($fmtQuando(isset($resumo['ultimo_login']) ? (string) $resumo['ultimo_login'] : null)) ?></strong>
    </article>
</section>

<section class="atividade-charts">
    <article class="card atividade-chart-main">
        <div class="card-header">
            <div>
                <h2 class="card-title">Tempo ativo por dia</h2>
                <p class="card-subtitle">Quanto tempo o usuário permaneceu no sistema em cada dia do período</p>
            </div>
        </div>
        <div class="card-body atividade-chart-body">
            <canvas id="chartTempoDia" aria-label="Tempo ativo por dia"></canvas>
        </div>
    </article>
    <article class="card atividade-chart-side">
        <div class="card-header">
            <div>
                <h2 class="card-title">Perfil de acesso</h2>
                <p class="card-subtitle">Em quais faixas do dia ele mais usa o sistema</p>
            </div>
        </div>
        <div class="card-body atividade-chart-body atividade-chart-body-side">
            <canvas id="chartPerfilAcesso" aria-label="Perfil de acesso por faixa do dia"></canvas>
        </div>
        <?php if ($dashboard['por_faixa'] !== []): ?>
            <ul class="atividade-faixa-legend" aria-hidden="true">
                <?php
                $totalSegFaixa = array_sum(array_map(static fn ($f) => (int) ($f['segundos'] ?? 0), $dashboard['por_faixa']));
                $totalLoginFaixa = array_sum(array_map(static fn ($f) => (int) ($f['logins'] ?? 0), $dashboard['por_faixa']));
                $usarLoginPct = $totalSegFaixa <= 0 && $totalLoginFaixa > 0;
                foreach ($dashboard['por_faixa'] as $f):
                    $base = $usarLoginPct ? (int) ($f['logins'] ?? 0) : (int) ($f['segundos'] ?? 0);
                    $tot = $usarLoginPct ? $totalLoginFaixa : $totalSegFaixa;
                    $pct = $tot > 0 ? (int) round(($base / $tot) * 100) : 0;
                    ?>
                    <li>
                        <span><?= View::e((string) $f['label']) ?> <small><?= View::e((string) $f['horario']) ?></small></span>
                        <strong data-faixa-pct="<?= View::e((string) $f['chave']) ?>"><?= $pct ?>%</strong>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
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
                        <td><?= View::e($fmtDuracaoSeg((int) ($h['segundos'] ?? 0))) ?></td>
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
