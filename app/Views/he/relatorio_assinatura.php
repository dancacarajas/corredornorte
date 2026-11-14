<?php

use App\Helpers\View;

/** @var array<string,mixed>|null $documento */
/** @var string $token */
/** @var bool $assinaturaConfigurada */
/** @var string $csrf */
/** @var string $textoAceite */

$assinado = ($documento['status'] ?? null) === 'assinado';
$registroComIntegridade = $assinado && !empty($documento['arquivo_assinado_sha256'])
    && (!empty($documento['assinatura_texto_aceite']) || !empty($documento['assinatura_email_confirmado_em']));
$portalPath = '/assinar-relatorio/' . rawurlencode($token);
$hashPdf = (string) ($assinado ? ($documento['arquivo_assinado_sha256'] ?? '') : ($documento['hash_sha256'] ?? ''));
$pdfPath = $portalPath . '/pdf' . ($hashPdf === '' ? '' : '?versao=' . rawurlencode($hashPdf));
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>Assinatura do relatório de atividades · Arcadis RH</title>
    <link rel="stylesheet" href="<?= View::asset('css/tokens.css') ?>">
    <link rel="stylesheet" href="<?= View::asset('css/components.css') ?>">
    <style>
        .assinatura-header {
            background: var(--surface);
            border-bottom: 1px solid var(--border);
        }
        .assinatura-header-inner {
            width: min(100% - 2rem, 72rem);
            min-height: var(--header-height);
            margin-inline: auto;
            display: flex;
            align-items: center;
        }
        .assinatura-header img { width: 9rem; }
        .assinatura-portal {
            width: min(100% - 2rem, 72rem);
            margin: 2rem auto 3rem;
        }
        .assinatura-portal-restrita { max-width: 32rem; }
        .assinatura-portal h1 { font-size: clamp(1.4rem, 4vw, var(--text-2xl)); }
        .assinatura-portal .card-header { flex-wrap: wrap; }
        .assinatura-grid {
            display: grid;
            gap: 1.25rem;
            grid-template-columns: minmax(0, 1fr) minmax(18rem, 22rem);
            align-items: start;
        }
        .assinatura-pdf {
            display: block;
            width: 100%;
            height: clamp(25rem, 66vh, 54rem);
            border: 0;
            border-radius: 0 0 var(--radius-lg) var(--radius-lg);
            background: var(--canvas);
        }
        .assinatura-aceite {
            display: flex;
            gap: .6rem;
            align-items: flex-start;
        }
        .assinatura-aceite input {
            margin: .25rem 0 0;
            accent-color: var(--brand-primary);
        }
        .assinatura-nome {
            font-family: Georgia, serif;
            font-size: var(--text-lg);
            font-style: italic;
        }
        .assinatura-auditoria {
            display: grid;
            margin: 0;
            gap: .8rem;
        }
        .assinatura-auditoria dd { margin: 0; }
        .assinatura-auditoria code { overflow-wrap: anywhere; }
        @media (max-width: 850px) {
            .assinatura-grid { grid-template-columns: minmax(0, 1fr); }
        }
    </style>
</head>
<body>
<header class="assinatura-header">
    <div class="assinatura-header-inner">
        <img src="<?= View::asset('img/logo.png') ?>" alt="Arcadis">
    </div>
</header>

<main class="assinatura-portal section-stack <?= $documento === null ? 'assinatura-portal-restrita' : '' ?>">
    <h1>Assinatura do relatório de atividades</h1>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger" role="alert"><?= View::e((string) $error) ?></div>
    <?php endif; ?>

    <?php if ($documento === null): ?>
        <div class="alert alert-danger" role="alert">Este link é inválido ou o relatório está indisponível. Solicite um novo link ao RH.</div>
    <?php else: ?>
        <?php if ($registroComIntegridade && ($flash ?? null) === 'assinado'): ?>
            <div class="alert alert-success" role="status">Assinatura eletrônica registrada. O PDF assinado está disponível.</div>
        <?php endif; ?>
        <div class="assinatura-grid">
            <section class="card" aria-labelledby="titulo-documento">
                <div class="card-header">
                    <div>
                        <h2 class="card-title" id="titulo-documento">Relatório de atividades</h2>
                        <p class="card-subtitle">
                            <?= View::e((string) $documento['nome']) ?>
                            · <?= View::e(date('d/m/Y', strtotime((string) $documento['data_inicio'])) . ' a ' . date('d/m/Y', strtotime((string) $documento['data_fim']))) ?>
                        </p>
                    </div>
                    <a class="btn btn-secondary btn-sm" href="<?= View::e($pdfPath) ?>"
                        target="_blank" rel="noopener noreferrer">
                        <?= $assinado ? 'Abrir PDF assinado' : 'Abrir PDF' ?>
                    </a>
                </div>
                <iframe class="assinatura-pdf" src="<?= View::e($pdfPath) ?>"
                    title="<?= $assinado ? 'Relatório de atividades assinado' : 'Relatório de atividades para assinatura' ?>"></iframe>
            </section>

            <?php if (!$assinado): ?>
                <section class="card" aria-labelledby="titulo-assinatura">
                    <div class="card-header">
                        <div>
                            <h2 class="card-title" id="titulo-assinatura">Assinatura eletrônica</h2>
                            <p class="card-subtitle">Confira o relatório antes de assinar.</p>
                        </div>
                    </div>
                    <?php if ($assinaturaConfigurada): ?>
                        <form method="post" action="<?= View::e($portalPath) ?>/assinar" class="card-body section-stack">
                            <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
                            <input type="hidden" name="hash_sha256" value="<?= View::e($hashPdf) ?>">
                            <div class="form-field">
                                <label class="form-label" for="nome-assinatura">Nome completo</label>
                                <input class="form-control assinatura-nome" id="nome-assinatura" name="nome"
                                    autocomplete="name" maxlength="160" required aria-describedby="instrucao-nome">
                                <p class="form-hint" id="instrucao-nome">Digite o nome conforme o cadastro. Ele aparecerá na assinatura do PDF.</p>
                            </div>
                            <label class="assinatura-aceite text-sm">
                                <input type="checkbox" name="aceite" value="1" required>
                                <span><?= View::e($textoAceite) ?></span>
                            </label>
                            <div><button class="btn btn-primary" type="submit">Assinar eletronicamente</button></div>
                        </form>
                    <?php else: ?>
                        <div class="card-body">
                            <p class="text-sm muted">A assinatura está indisponível no momento. Entre em contato com o RH.</p>
                        </div>
                    <?php endif; ?>
                </section>
            <?php else: ?>
                <section class="card" aria-labelledby="titulo-comprovante">
                    <div class="card-header">
                        <h2 class="card-title" id="titulo-comprovante">Comprovante</h2>
                        <span class="pill pill-brand"><?= $registroComIntegridade ? 'Assinado eletronicamente' : 'Registro anterior' ?></span>
                    </div>
                    <div class="card-body section-stack">
                        <dl class="assinatura-auditoria text-sm">
                            <div>
                                <dt class="muted text-xs">Nome registrado</dt>
                                <dd><?= View::e((string) ($documento['assinatura_nome'] ?? '—')) ?></dd>
                            </div>
                            <div>
                                <dt class="muted text-xs">Data e hora</dt>
                                <dd><?= View::e((string) ($documento['assinatura_em'] ?? '—')) ?></dd>
                            </div>
                            <div>
                                <dt class="muted text-xs">SHA-256 do PDF assinado</dt>
                                <dd><code><?= View::e((string) ($documento['arquivo_assinado_sha256'] ?? '—')) ?></code></dd>
                            </div>
                        </dl>
                        <div>
                            <a class="btn btn-secondary" href="/verificar-relatorio/<?= View::e(rawurlencode((string) $documento['codigo_verificacao'])) ?>">
                                Verificar documento
                            </a>
                        </div>
                    </div>
                </section>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</main>
</body>
</html>

