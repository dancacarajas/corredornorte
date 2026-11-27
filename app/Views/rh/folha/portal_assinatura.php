<?php

use App\Helpers\AppUrl;
use App\Helpers\View;

/** @var array<string,mixed>|null $documento */
/** @var array<string,mixed> $resumoEspelho */
/** @var bool $modeloAnterior */
/** @var bool $assinaturaConfigurada */
/** @var string $csrf */
/** @var string $textoAceite */
/** @var string $token */
/** @var string|null $flash */
/** @var string|null $error */
/** @var string $nomeInformado */

$fmtMin = static function (int $min): string {
    if ($min <= 0) {
        return '0h00';
    }
    $h = intdiv($min, 60);
    $m = $min % 60;
    return sprintf('%dh%02d', $h, $m);
};
$diaSemanaPt = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
$resumoEspelho = $resumoEspelho ?? [
    'espelho_status' => 'enviado',
    'totais' => ['trab_min' => 0, 'he50_min' => 0, 'he100_min' => 0, 'falta_min' => 0, 'adn_min' => 0, 'dias' => 0],
    'dias' => [],
    'divergencias' => [],
];
$totais = $resumoEspelho['totais'] ?? [];
$diasResumo = $resumoEspelho['dias'] ?? [];
$divergencias = $resumoEspelho['divergencias'] ?? [];
$dados = is_array($dados ?? null) ? $dados : [];
if (empty($dados['ra_resumo'])) {
    if (!empty($resumoEspelho['ra_resumo']) && is_array($resumoEspelho['ra_resumo'])) {
        $dados['ra_resumo'] = $resumoEspelho['ra_resumo'];
    } elseif (!empty($documento['ra_resumo']) && is_array($documento['ra_resumo'])) {
        $dados['ra_resumo'] = $documento['ra_resumo'];
    } elseif ($documento !== null && ($pdoPortal = \App\Helpers\Db::tryConnection()) instanceof \PDO) {
        $assPortal = new \App\Services\AssinaturaEletronicaService($pdoPortal);
        if (method_exists($assPortal, 'portalDadosPorToken')) {
            try {
                $pd = $assPortal->portalDadosPorToken($token);
                if (is_array($pd) && !empty($pd['ra_resumo'])) {
                    $dados['ra_resumo'] = $pd['ra_resumo'];
                }
            } catch (\Throwable) {
            }
        }
    }
}
$emDivergencia = (($resumoEspelho['espelho_status'] ?? '') === 'em_divergencia')
    && (($documento['status'] ?? '') !== 'assinado');

$periodoTexto = '';
if ($documento !== null) {
    if (!empty($documento['periodo_inicio']) && !empty($documento['periodo_fim'])) {
        $periodoTexto = date('d/m/Y', strtotime((string) $documento['periodo_inicio']))
            . ' a ' . date('d/m/Y', strtotime((string) $documento['periodo_fim']));
    } elseif (!empty($documento['data_inicio']) && !empty($documento['data_fim'])) {
        $periodoTexto = date('d/m/Y', strtotime((string) $documento['data_inicio']))
            . ' a ' . date('d/m/Y', strtotime((string) $documento['data_fim']));
    } else {
        $periodoTexto = (string) ($documento['competencia'] ?? '');
    }
}
$assinada = $documento !== null && (($documento['status'] ?? '') === 'assinado');
$disponivel = $documento !== null && (($documento['status'] ?? '') === 'enviado') && !$modeloAnterior && !$emDivergencia;
$pdfUrl = '/assinar/' . rawurlencode($token) . '/pdf';
$verificarUrl = $documento !== null ? '/verificar/' . rawurlencode((string) ($documento['codigo_verificacao'] ?? '')) : '';
$ogBase = AppUrl::base();
$ogUrl = $ogBase . '/assinar/' . rawurlencode($token);
$ogImage = $ogBase . '/og/arcadis-folha.jpg';
$ogTitle = 'Assinar Folha de Ponto — Arcadis RH';
$ogDesc = $periodoTexto !== ''
    ? ('Folha de Ponto (' . $periodoTexto . ') pronta para conferência e assinatura eletrônica.')
    : 'Folha de Ponto Arcadis — conferência e assinatura eletrônica.';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= View::e($ogTitle) ?></title>
    <meta name="description" content="<?= View::e($ogDesc) ?>">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Arcadis RH — Corredor Norte">
    <meta property="og:title" content="<?= View::e($ogTitle) ?>">
    <meta property="og:description" content="<?= View::e($ogDesc) ?>">
    <meta property="og:url" content="<?= View::e($ogUrl) ?>">
    <meta property="og:image" content="<?= View::e($ogImage) ?>">
    <meta property="og:image:secure_url" content="<?= View::e($ogImage) ?>">
    <meta property="og:image:type" content="image/jpeg">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:alt" content="Arcadis RH — Corredor Norte">
    <meta property="og:locale" content="pt_BR">
    <link rel="image_src" href="<?= View::e($ogImage) ?>">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= View::e($ogTitle) ?>">
    <meta name="twitter:description" content="<?= View::e($ogDesc) ?>">
    <meta name="twitter:image" content="<?= View::e($ogImage) ?>">
    <link rel="stylesheet" href="/assets/css/tokens.css?v=brand-20261127a">
    <link rel="stylesheet" href="/assets/css/components.css?v=brand-20261127a">
    <style>
        body {
            margin: 0;
            background: var(--canvas, #F3F4F6);
            color: var(--ink);
            font-family: var(--font-sans, "Source Sans 3", system-ui, -apple-system, sans-serif);
            line-height: 1.5;
        }
        .ap-topbar {
            background: #0F172A;
            color: #fff;
            border-bottom: 4px solid var(--brand-primary, #EA580C);
            padding: 14px 16px;
        }
        .ap-topbar-in {
            max-width: 1120px;
            margin: 0 auto;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }
        .ap-brand-eyebrow {
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
            color: #FDBA74;
        }
        .ap-brand-title {
            font-size: 1.15rem;
            font-weight: 700;
            margin: 2px 0 0;
        }
        .ap-shell {
            max-width: 1120px;
            margin: 0 auto;
            padding: 18px 14px 44px;
            display: grid;
            gap: 16px;
        }
        .ap-card {
            background: var(--surface, #fff);
            border: 1px solid var(--border, #E2E8F0);
            border-radius: var(--radius-lg, 14px);
            padding: 18px;
            box-shadow: var(--shadow-sm);
        }
        .ap-kpi-strip {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(112px, 1fr));
            gap: 10px;
            margin-top: 12px;
        }
        .ap-kpi {
            background: #F8FAFC;
            border: 1px solid var(--border, #E2E8F0);
            border-radius: 10px;
            padding: 10px 12px;
        }
        .ap-kpi span {
            display: block;
            font-size: .74rem;
            text-transform: uppercase;
            letter-spacing: .04em;
            color: var(--ink-subtle, #64748B);
            font-weight: 700;
        }
        .ap-kpi strong {
            font-size: 1.15rem;
            font-weight: 800;
            color: var(--ink);
        }
        .ap-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 16px;
            align-items: start;
        }
        @media (min-width: 940px) {
            .ap-grid {
                grid-template-columns: minmax(0, 1.45fr) minmax(320px, .95fr);
            }
        }
        .ap-day-list {
            display: grid;
            gap: 8px;
            margin-top: 12px;
            max-height: 360px;
            overflow: auto;
            padding-right: 4px;
        }
        .ap-day-item {
            display: grid;
            grid-template-columns: 78px 1fr auto;
            gap: 10px;
            align-items: center;
            padding: 9px 11px;
            border: 1px solid var(--border, #E2E8F0);
            border-radius: 10px;
            background: #fff;
            font-size: .88rem;
        }
        .ap-day-item.is-off { background: #F8FAFC; color: var(--ink-subtle); }
        .ap-viewer-bar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            padding: 10px 12px;
            background: #F8FAFC;
            border: 1px solid var(--border, #E2E8F0);
            border-radius: 10px 10px 0 0;
        }
        .ap-viewer-controls {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }
        .ap-viewer-stage {
            border: 1px solid var(--border, #E2E8F0);
            border-top: 0;
            border-radius: 0 0 10px 10px;
            background: #334155;
            min-height: 380px;
            max-height: 76vh;
            overflow: auto;
            display: flex;
            justify-content: center;
            padding: 12px;
        }
        .ap-viewer-stage canvas {
            max-width: 100%;
            height: auto;
            background: #fff;
            border-radius: 4px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, .28);
        }
        .ap-viewer-iframe {
            width: 100%;
            height: 72vh;
            min-height: 460px;
            border: 0;
            background: #fff;
            border-radius: 6px;
        }
        .ap-field { display: grid; gap: 6px; margin-bottom: 14px; }
        .ap-field label { font-weight: 700; font-size: .92rem; }
        .ap-field input[type="text"],
        .ap-field select,
        .ap-field textarea {
            width: 100%;
            box-sizing: border-box;
            min-height: 46px;
            padding: 10px 12px;
            border: 1px solid #CBD5E1;
            border-radius: 10px;
            font: inherit;
            background: #fff;
            color: var(--ink);
        }
        .ap-check {
            display: flex;
            gap: 10px;
            align-items: flex-start;
            padding: 12px;
            border: 1px solid var(--border, #E2E8F0);
            border-radius: 10px;
            background: #F8FAFC;
            font-size: .9rem;
            margin-bottom: 14px;
            cursor: pointer;
        }
        .ap-check input { width: 20px; height: 20px; margin-top: 2px; flex-shrink: 0; accent-color: var(--brand-primary, #EA580C); }
        .ap-btn-main {
            width: 100%;
            min-height: 48px;
            border: 0;
            border-radius: 10px;
            background: var(--brand-primary, #EA580C);
            color: #fff;
            font: inherit;
            font-weight: 700;
            font-size: 1rem;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .ap-btn-main:disabled { opacity: .65; cursor: wait; }
        .ap-btn-sec {
            width: 100%;
            min-height: 44px;
            margin-top: 10px;
            border: 1px solid #CBD5E1;
            border-radius: 10px;
            background: #fff;
            color: var(--ink);
            font: inherit;
            font-weight: 600;
            cursor: pointer;
        }
        .ap-receipt {
            border-left: 4px solid #16A34A;
            background: #F0FDF4;
        }
        .ap-receipt-box {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 14px;
            margin-top: 12px;
            padding: 12px;
            background: #fff;
            border: 1px solid #BBF7D0;
            border-radius: 10px;
        }
        .ap-code {
            font-family: Consolas, ui-monospace, monospace;
            font-size: .95rem;
            font-weight: 700;
            background: #F1F5F9;
            padding: 6px 10px;
            border-radius: 8px;
            word-break: break-all;
        }
        .ap-spinner {
            width: 16px;
            height: 16px;
            border: 2px solid rgba(255,255,255,.45);
            border-top-color: #fff;
            border-radius: 50%;
            animation: ap-spin .7s linear infinite;
            display: none;
        }
        .is-loading .ap-spinner { display: inline-block; }
        @keyframes ap-spin { to { transform: rotate(360deg); } }
        dialog.ap-modal {
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 0;
            max-width: 480px;
            width: calc(100% - 24px);
            box-shadow: 0 24px 48px rgba(15, 23, 42, .25);
        }
        dialog.ap-modal::backdrop { background: rgba(15, 23, 42, .55); }
        .ap-modal-head {
            padding: 16px 18px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }
        .ap-modal-body { padding: 18px; }
    </style>
</head>
<body>
<header class="ap-topbar">
    <div class="ap-topbar-in">
        <div>
            <div class="ap-brand-eyebrow">Arcadis · Corredor Norte</div>
            <h1 class="ap-brand-title">Portal do Colaborador — Folha de Ponto</h1>
        </div>
        <?php if ($documento !== null): ?>
            <div style="font-size:.85rem;color:#E2E8F0;">
                Período: <strong><?= View::e($periodoTexto) ?></strong>
            </div>
        <?php endif; ?>
    </div>
</header>

<main class="ap-shell">
    <?php if ($documento === null): ?>
        <section class="ap-card">
            <h2 style="margin:0 0 8px;">Link inválido ou indisponível</h2>
            <p class="muted" style="margin:0;">Este link de folha de ponto não foi encontrado ou foi substituído por uma via mais recente. Solicite um novo link ao RH.</p>
        </section>
    <?php else: ?>
        <?php if ($flash === 'assinado'): ?>
            <div class="alert alert-success" role="status">
                <strong>Assinatura registrada com sucesso!</strong> Sua folha de ponto assinada eletronicamente está pronta logo abaixo.
            </div>
        <?php elseif ($flash === 'divergencia'): ?>
            <div class="alert alert-warning" role="status">
                <strong>Divergência enviada ao RH!</strong> A assinatura desta folha foi pausada até que o RH revise os apontamentos e disponibilize a folha corrigida.
            </div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger" role="alert"><?= View::e($error) ?></div>
        <?php endif; ?>

        <?php if ($emDivergencia): ?>
            <section class="ap-card" style="border-left:4px solid var(--brand-primary);background:#FFF7ED;">
                <h2 style="margin:0 0 6px;font-size:1.05rem;color:#9A3412;">Em revisão pelo RH</h2>
                <p style="margin:0;color:#7C2D12;">Você reportou uma divergência para este espelho. A assinatura permanecerá bloqueada até o RH corrigir o ponto e reenviar a folha atualizada.</p>
                <?php if ($divergencias !== []): ?>
                    <ul style="margin:10px 0 0;padding-left:18px;color:#7C2D12;font-size:.9rem;">
                        <?php foreach ($divergencias as $d): ?>
                            <li>
                                <?php if (!empty($d['data_ocorrencia'])): ?>
                                    <strong><?= View::e(date('d/m/Y', strtotime((string) $d['data_ocorrencia']))) ?>:</strong>
                                <?php endif; ?>
                                <?= View::e((string) $d['motivo']) ?>
                                <span class="muted">(<?= View::e((string) $d['status']) ?>)</span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
        <?php elseif ($modeloAnterior): ?>
            <div class="alert alert-warning" role="alert">
                Esta folha foi emitida em versão anterior do documento. Peça ao RH para atualizar o PDF antes de assinar.
            </div>
        <?php elseif (($documento['status'] ?? '') === 'invalidado'): ?>
            <div class="alert alert-danger" role="alert">
                Esta via foi invalidada porque houve alteração no ponto. Solicite o link atualizado ao RH.
            </div>
        <?php endif; ?>

        <?php if ($assinada): ?>
            <section class="ap-card ap-receipt" aria-label="Comprovante de assinatura eletrônica">
                <div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:10px;">
                    <div>
                        <span class="pill pill-brand">Folha assinada eletronicamente</span>
                        <h2 style="margin:6px 0 2px;font-size:1.15rem;">Comprovante de Registro</h2>
                        <p class="muted" style="margin:0;font-size:.9rem;">
                            Assinada por <strong><?= View::e((string) ($documento['assinado_nome_digitado'] ?: $documento['nome'])) ?></strong>
                            <?php if (!empty($documento['assinado_em'])): ?>
                                em <?= View::e(date('d/m/Y H:i', strtotime((string) $documento['assinado_em']))) ?>
                            <?php endif; ?>
                        </p>
                    </div>
                    <a class="btn btn-primary" href="<?= View::e($pdfUrl) ?>" download>Baixar PDF assinado</a>
                </div>
                <div class="ap-receipt-box">
                    <div id="apQrReceipt" data-verify-url="<?= View::e($verificarUrl) ?>" aria-label="QR Code de verificação" style="min-width:88px;min-height:88px;display:grid;place-items:center;background:#fff;padding:4px;border:1px solid #E2E8F0;border-radius:8px;"></div>
                    <div style="flex:1 1 220px;">
                        <div style="font-size:.82rem;color:var(--ink-subtle);font-weight:700;">Código de verificação</div>
                        <div style="display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin-top:4px;">
                            <span class="ap-code" id="apVerifyCode"><?= View::e((string) ($documento['codigo_verificacao'] ?? '')) ?></span>
                            <button type="button" class="btn btn-secondary btn-sm" id="apCopyCode">Copiar código</button>
                            <?php if ($verificarUrl !== ''): ?>
                                <a class="btn btn-ghost btn-sm" href="<?= View::e($verificarUrl) ?>" target="_blank" rel="noopener">Abrir validação pública</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </section>
        <?php endif; ?>

        <section class="ap-card" aria-label="Resumo do período">
            <div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:8px;">
                <div>
                    <h2 style="margin:0;font-size:1.1rem;"><?= View::e((string) $documento['nome']) ?></h2>
                    <div class="muted" style="font-size:.88rem;">
                        Matrícula <?= View::e((string) ($documento['matricula'] ?? '—')) ?> · Período <?= View::e($periodoTexto) ?>
                    </div>
                </div>
                <span class="pill <?= $assinada ? 'pill-brand' : ($emDivergencia ? 'pill-danger' : 'pill-neutral') ?>">
                    <?= $assinada ? 'Assinado' : ($emDivergencia ? 'Em divergência' : 'Aguardando assinatura') ?>
                </span>
            </div>

            <div class="ap-kpi-strip">
                <div class="ap-kpi"><span>Dias apontados</span><strong><?= (int) ($totais['dias'] ?? 0) ?></strong></div>
                <div class="ap-kpi"><span>Horas trab.</span><strong><?= View::e($fmtMin((int) ($totais['trab_min'] ?? 0))) ?></strong></div>
                <div class="ap-kpi"><span>HE 50%</span><strong><?= View::e($fmtMin((int) ($totais['he50_min'] ?? 0))) ?></strong></div>
                <div class="ap-kpi"><span>HE 100%</span><strong><?= View::e($fmtMin((int) ($totais['he100_min'] ?? 0))) ?></strong></div>
                <div class="ap-kpi"><span>Faltas</span><strong><?= View::e($fmtMin((int) ($totais['falta_min'] ?? 0))) ?></strong></div>
                <div class="ap-kpi"><span>Ad. Noturno</span><strong><?= View::e($fmtMin((int) ($totais['adn_min'] ?? 0))) ?></strong></div>
            </div>

            <?php if ($diasResumo !== []): ?>
                <details style="margin-top:14px;">
                    <summary style="cursor:pointer;font-weight:700;color:var(--brand-primary-strong,#C2410C);">
                        Conferir marcações dia a dia no celular (<?= count($diasResumo) ?> dias)
                    </summary>
                    <div class="ap-day-list">
                        <?php foreach ($diasResumo as $dr):
                            $ts = strtotime((string) $dr['data']);
                            $dow = $ts !== false ? ($diaSemanaPt[(int) date('w', $ts)] ?? '') : '';
                            $isOff = ($dr['tipo_dia'] ?? 'trabalho') !== 'trabalho';
                            $batidasTxt = $dr['batidas'] !== [] ? implode(' · ', $dr['batidas']) : ($isOff ? 'Folga / Feriado' : 'Sem batidas');
                            ?>
                            <div class="ap-day-item <?= $isOff ? 'is-off' : '' ?>">
                                <div>
                                    <strong><?= View::e(date('d/m', $ts ?: time())) ?></strong>
                                    <span class="muted text-sm"><?= View::e($dow) ?></span>
                                </div>
                                <div>
                                    <div class="fw-600"><?= View::e($batidasTxt) ?></div>
                                    <?php if (!empty($dr['resumo'])): ?>
                                        <div class="muted text-sm"><?= View::e((string) $dr['resumo']) ?></div>
                                    <?php endif; ?>
                                </div>
                                <div style="text-align:right;font-size:.82rem;">
                                    <?php if ((int) $dr['trab_min'] > 0): ?>
                                        <div>Trab <?= View::e($fmtMin((int) $dr['trab_min'])) ?></div>
                                    <?php endif; ?>
                                    <?php if ((int) $dr['he_min'] > 0): ?>
                                        <div style="color:#C2410C;font-weight:700;">+HE <?= View::e($fmtMin((int) $dr['he_min'])) ?></div>
                                    <?php endif; ?>
                                    <?php if ((int) $dr['falta_min'] > 0): ?>
                                        <div style="color:#B91C1C;font-weight:700;">Falta <?= View::e($fmtMin((int) $dr['falta_min'])) ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </details>
            <?php endif; ?>
        </section>

        <div class="ap-grid">
            <section class="ap-card" style="padding:0;overflow:hidden;" aria-label="Visualização do documento PDF">
                <div style="padding:10px 12px;background:#EFF6FF;border-bottom:1px solid var(--border, #E2E8F0);display:flex;justify-content:center;">
                    <a class="btn btn-primary btn-sm" href="<?= View::e($pdfUrl) ?>" target="_blank" rel="noopener noreferrer" style="width:100%;justify-content:center;text-align:center;font-weight:700;">
                        Abrir / Baixar PDF da Folha em tela cheia
                    </a>
                </div>
                <div class="ap-viewer-bar">
                    <div class="ap-viewer-controls">
                        <button type="button" class="btn btn-secondary btn-sm" id="apPdfPrev" aria-label="Página anterior">‹</button>
                        <span class="text-sm fw-700" id="apPdfPageInfo">Carregando PDF…</span>
                        <button type="button" class="btn btn-secondary btn-sm" id="apPdfNext" aria-label="Próxima página">›</button>
                    </div>
                    <div class="ap-viewer-controls">
                        <button type="button" class="btn btn-secondary btn-sm" id="apPdfZoomOut" aria-label="Diminuir zoom">−</button>
                        <button type="button" class="btn btn-secondary btn-sm" id="apPdfZoomIn" aria-label="Aumentar zoom">+</button>
                        <a class="btn btn-secondary btn-sm" href="<?= View::e($pdfUrl) ?>" target="_blank" rel="noopener">Abrir</a>
                        <a class="btn btn-primary btn-sm" href="<?= View::e($pdfUrl) ?>" download>Baixar</a>
                    </div>
                </div>
                <div class="ap-viewer-stage" id="apPdfStage" data-pdf-url="<?= View::e($pdfUrl) ?>">
                    <canvas id="apPdfCanvas"></canvas>
                </div>
                <div style="padding:10px 12px;background:#F8FAFC;border-top:1px solid var(--border, #E2E8F0);display:flex;justify-content:center;">
                    <a class="btn btn-secondary btn-sm" href="<?= View::e($pdfUrl) ?>" target="_blank" rel="noopener noreferrer" download style="width:100%;justify-content:center;text-align:center;">
                        Abrir / Baixar PDF da Folha em tela cheia
                    </a>
                </div>
            </section>

            <div style="display:grid;gap:16px;align-content:start;">
                <section class="ap-card" aria-label="Ações de assinatura">
                    <?php if ($disponivel): ?>
                        <h2 style="margin:0 0 6px;font-size:1.1rem;">Confirmar e Assinar Folha</h2>
                        <p class="muted" style="margin:0 0 14px;font-size:.9rem;">
                            Confira o resumo acima e o PDF ao lado. Se tudo estiver correto, preencha seu nome completo para registrar a assinatura eletrônica<?= $assinaturaConfigurada ? ' com selo PAdES' : '' ?>.
                        </p>
                        <form method="post" action="/assinar/<?= View::e($token) ?>/assinar" id="apSignForm">
                            <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                            <input type="hidden" name="hash_sha256" value="<?= View::e((string) $documento['hash_sha256']) ?>">

                            <label class="ap-check">
                                <input type="checkbox" name="aceite" value="1" required>
                                <span><?= View::e($textoAceite) ?></span>
                            </label>

                            <div class="ap-field">
                                <label for="apNomeInput">Nome completo (conforme consta na folha)</label>
                                <input id="apNomeInput" name="nome" type="text" required maxlength="160" autocomplete="name"
                                       value="<?= View::e($nomeInformado !== '' ? $nomeInformado : (string) ($documento['colaborador_nome'] ?? $documento['nome'] ?? '')) ?>"
                                       placeholder="Digite seu nome completo">
                            </div>

                            <button type="submit" class="ap-btn-main" id="apSignBtn">
                                <span class="ap-spinner" aria-hidden="true"></span>
                                <span class="ap-btn-label">Assinar Folha Eletronicamente</span>
                            </button>
                        </form>

                        <button type="button" class="ap-btn-sec" id="apOpenDivBtn">
                            Reportar divergência ao RH
                        </button>
                    <?php elseif ($assinada): ?>
                        <h2 style="margin:0 0 6px;font-size:1.1rem;">Documento concluído</h2>
                        <p class="muted" style="margin:0;font-size:.92rem;">
                            Esta folha já foi assinada eletronicamente e encontra-se protegida para consulta e verificação.
                        </p>
                    <?php elseif ($emDivergencia): ?>
                        <h2 style="margin:0 0 6px;font-size:1.1rem;">Aguardando revisão do RH</h2>
                        <p class="muted" style="margin:0;font-size:.92rem;">
                            Enquanto a divergência estiver em análise, a assinatura permanece bloqueada para evitar validação com dados incorretos.
                        </p>
                        <button type="button" class="ap-btn-sec" id="apOpenDivBtn">
                            Complementar divergência ao RH
                        </button>
                    <?php else: ?>
                        <h2 style="margin:0 0 6px;font-size:1.1rem;">Assinatura indisponível</h2>
                        <p class="muted" style="margin:0;font-size:.92rem;">Solicite ao RH a atualização e o reenvio desta folha de ponto.</p>
                    <?php endif; ?>
                </section>

                <?php if (!empty($dados['ra_resumo']) && is_array($dados['ra_resumo'])):
                    $ra = $dados['ra_resumo'];
                    $raStatus = (string) ($ra['status_assinatura'] ?? $ra['documento_status'] ?? $ra['status'] ?? 'pendente');
                    $raAssinado = ($raStatus === 'assinado' || !empty($ra['assinado']));
                    $raUrl = (string) ($ra['assinar_url'] ?? $ra['portal_url'] ?? $ra['url'] ?? '/he');
                    $raLabelBtn = $raAssinado
                        ? 'Visualizar Relatório de Atividades (RA) assinado'
                        : (!empty($ra['assinar_url']) && str_contains((string) $ra['assinar_url'], '/assinar-relatorio/')
                            ? 'Assinar Relatório de Atividades (RA)'
                            : 'Preencher / Assinar Relatório de Atividades (RA)');
                ?>
                    <section class="ap-card" style="border-left:4px solid var(--brand-primary, #EA580C);background:#FFFBEB;" aria-label="Relatório de Atividades (RA) do Período">
                        <div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:8px;margin-bottom:8px;">
                            <h2 style="margin:0;font-size:1.05rem;">Relatório de Atividades (RA) do Período</h2>
                            <span class="pill <?= $raAssinado ? 'pill-brand' : 'pill-warning' ?>">
                                <?= View::e($raAssinado ? 'RA Assinado' : 'RA Pendente') ?>
                            </span>
                        </div>
                        <p class="muted" style="margin:0 0 12px;font-size:.9rem;">
                            <?= $raAssinado
                                ? 'Seu Relatório de Atividades deste período já foi assinado eletronicamente.'
                                : (str_contains($raUrl, '/assinar-relatorio/')
                                    ? 'Complete sua jornada do período assinando também o Relatório de Atividades (RA) — mesmo tipo de link desta folha.'
                                    : 'Complete sua jornada do período: o botão abre o Portal do Colaborador já direcionado ao RA deste período (login com código OTP do e-mail).') ?>
                        </p>
                        <?php if ($raUrl !== ''): ?>
                            <a class="ap-btn-main" href="<?= View::e($raUrl) ?>" style="text-decoration:none;">
                                <?= View::e($raLabelBtn) ?>
                            </a>
                        <?php endif; ?>
                    </section>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</main>

<?php if ($documento !== null && !$assinada && ($documento['status'] ?? '') !== 'invalidado'): ?>
<dialog id="apDivDialog" class="ap-modal" aria-labelledby="apDivTitle">
    <div class="ap-modal-head">
        <strong id="apDivTitle" style="font-size:1.05rem;">Reportar divergência ao RH</strong>
        <button type="button" class="btn btn-ghost btn-sm" id="apCloseDivBtn" aria-label="Fechar">✕</button>
    </div>
    <form method="post" action="/assinar/<?= View::e($token) ?>/divergencia" class="ap-modal-body" id="apDivForm">
        <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
        <p class="muted" style="margin:0 0 12px;font-size:.88rem;">
            Ao enviar, sua assinatura é pausada automaticamente e o RH recebe um alerta no painel para corrigir e reenviar a folha.
        </p>
        <div class="ap-field">
            <label for="apDivData">Dia com divergência (opcional)</label>
            <select id="apDivData" name="data_ocorrencia">
                <option value="">Espelho inteiro / Geral</option>
                <?php foreach ($diasResumo as $dr): ?>
                    <option value="<?= View::e((string) $dr['data']) ?>"><?= View::e(date('d/m/Y', strtotime((string) $dr['data']))) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="ap-field">
            <label for="apDivMotivo">Descreva o que precisa ser ajustado *</label>
            <textarea id="apDivMotivo" name="motivo" rows="4" required minlength="8" maxlength="1000"
                      placeholder="Ex.: No dia 18/10 trabalhei até 19:00 em atividade autorizada e consta saída às 17:18."></textarea>
        </div>
        <button type="submit" class="ap-btn-main" id="apDivSubmit">
            <span class="ap-spinner" aria-hidden="true"></span>
            <span class="ap-btn-label">Enviar divergência e pausar assinatura</span>
        </button>
    </form>
</dialog>
<?php endif; ?>

<script>
(function () {
    // 1. Spinner + double-tap lock nos formulários
    const lockForm = (formId, btnId, busyText) => {
        const form = document.getElementById(formId);
        const btn = document.getElementById(btnId);
        if (!form || !btn) return;
        form.addEventListener('submit', (ev) => {
            if (form.dataset.submitting === '1') {
                ev.preventDefault();
                return;
            }
            if (!form.checkValidity()) return;
            form.dataset.submitting = '1';
            btn.disabled = true;
            btn.classList.add('is-loading');
            const label = btn.querySelector('.ap-btn-label');
            if (label) label.textContent = busyText;
        });
    };
    lockForm('apSignForm', 'apSignBtn', 'Registrando assinatura…');
    lockForm('apDivForm', 'apDivSubmit', 'Enviando para o RH…');

    // 2. Modal de divergência
    const dlg = document.getElementById('apDivDialog');
    const openBtn = document.getElementById('apOpenDivBtn');
    const closeBtn = document.getElementById('apCloseDivBtn');
    if (dlg && openBtn) {
        openBtn.addEventListener('click', () => {
            if (typeof dlg.showModal === 'function') dlg.showModal();
            else dlg.setAttribute('open', '');
        });
    }
    if (dlg && closeBtn) {
        closeBtn.addEventListener('click', () => {
            if (typeof dlg.close === 'function') dlg.close();
            else dlg.removeAttribute('open');
        });
    }

    // 3. Copiar código de verificação
    const copyBtn = document.getElementById('apCopyCode');
    const codeEl = document.getElementById('apVerifyCode');
    if (copyBtn && codeEl) {
        copyBtn.addEventListener('click', async () => {
            const text = (codeEl.textContent || '').trim();
            if (!text) return;
            try {
                await navigator.clipboard.writeText(text);
                copyBtn.textContent = 'Copiado!';
                setTimeout(() => { copyBtn.textContent = 'Copiar código'; }, 1800);
            } catch (_) {
                copyBtn.textContent = text;
            }
        });
    }

    // 4. QR Code de validação com fallback seguro
    const qrBox = document.getElementById('apQrReceipt');
    if (qrBox && qrBox.dataset.verifyUrl) {
        const fullUrl = window.location.origin + qrBox.dataset.verifyUrl;
        const s = document.createElement('script');
        s.src = '/assets/vendor/qrcode/qrcode.min.js';
        s.onload = () => {
            if (window.QRCode) {
                qrBox.innerHTML = '';
                new window.QRCode(qrBox, { text: fullUrl, width: 88, height: 88, correctLevel: window.QRCode.CorrectLevel.M });
            }
        };
        s.onerror = () => { qrBox.innerHTML = '<span class="text-sm muted">QR</span>'; };
        document.head.appendChild(s);
    }

    // 5. Visualizador PDF.js (self-host) com fallback automático para iframe
    const stage = document.getElementById('apPdfStage');
    const canvas = document.getElementById('apPdfCanvas');
    const info = document.getElementById('apPdfPageInfo');
    if (!stage || !canvas) return;
    const pdfUrl = stage.dataset.pdfUrl;
    const mountFallback = () => {
        stage.innerHTML = '<iframe class="ap-viewer-iframe" src="' + pdfUrl + '#view=FitH" title="Visualizador da folha de ponto"></iframe>';
        if (info) info.textContent = 'PDF nativo';
    };

    const pdfScript = document.createElement('script');
    pdfScript.src = '/assets/vendor/pdfjs/pdf.min.js';
    pdfScript.onload = async () => {
        if (!window.pdfjsLib) return mountFallback();
        window.pdfjsLib.GlobalWorkerOptions.workerSrc = '/assets/vendor/pdfjs/pdf.worker.min.js';
        try {
            const pdf = await window.pdfjsLib.getDocument(pdfUrl).promise;
            let pageNum = 1;
            let scale = window.innerWidth < 640 ? 1.0 : 1.25;
            const ctx = canvas.getContext('2d');
            const renderPage = async () => {
                const page = await pdf.getPage(pageNum);
                const viewport = page.getViewport({ scale });
                const dpr = Math.min(window.devicePixelRatio || 1, 2);
                canvas.width = Math.floor(viewport.width * dpr);
                canvas.height = Math.floor(viewport.height * dpr);
                canvas.style.width = Math.floor(viewport.width) + 'px';
                ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
                await page.render({ canvasContext: ctx, viewport }).promise;
                if (info) info.textContent = 'Pág. ' + pageNum + ' / ' + pdf.numPages;
            };
            await renderPage();
            document.getElementById('apPdfPrev')?.addEventListener('click', () => {
                if (pageNum > 1) { pageNum--; renderPage(); }
            });
            document.getElementById('apPdfNext')?.addEventListener('click', () => {
                if (pageNum < pdf.numPages) { pageNum++; renderPage(); }
            });
            document.getElementById('apPdfZoomIn')?.addEventListener('click', () => {
                scale = Math.min(2.4, scale + 0.2); renderPage();
            });
            document.getElementById('apPdfZoomOut')?.addEventListener('click', () => {
                scale = Math.max(0.6, scale - 0.2); renderPage();
            });
        } catch (_) {
            mountFallback();
        }
    };
    pdfScript.onerror = mountFallback;
    document.head.appendChild(pdfScript);
})();
</script>
</body>
</html>
