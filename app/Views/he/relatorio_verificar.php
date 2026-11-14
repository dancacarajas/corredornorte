<?php

use App\Helpers\View;

/** @var array<string,mixed>|null $documento */
/** @var string $codigo */
/** @var array{original:bool,assinado:bool}|null $integridade */

$status = $documento['status'] ?? null;
$assinado = $status === 'assinado';
$hashAssinado = strtolower((string) ($documento['arquivo_assinado_sha256'] ?? ''));
$hashOriginal = strtolower((string) ($documento['hash_sha256'] ?? ''));
$comparacaoDisponivel = $assinado && preg_match('/\A[0-9a-f]{64}\z/', $hashAssinado) === 1;
$registroComIntegridade = $comparacaoDisponivel
    && (!empty($documento['assinatura_texto_aceite']) || !empty($documento['assinatura_email_confirmado_em']));

if ($status === 'invalidado') {
    $rotulo = 'Invalidado';
    $cor = 'danger';
    $mensagem = 'Este registro foi invalidado. Solicite ao RH a versão vigente do relatório.';
} elseif ($registroComIntegridade) {
    $rotulo = 'Assinado eletronicamente';
    $cor = ($integridade['original'] ?? false) && ($integridade['assinado'] ?? false) ? 'success' : 'warning';
    $mensagem = $cor === 'success'
        ? 'Os arquivos arquivados correspondem aos hashes SHA-256 registrados.'
        : 'A conferência dos arquivos arquivados falhou. Entre em contato com o RH.';
} elseif ($assinado) {
    $rotulo = 'Registro anterior';
    $cor = 'info';
    $mensagem = 'Este registro foi emitido em uma versão anterior do portal de assinatura.';
} else {
    $rotulo = 'Pendente';
    $cor = 'brand';
    $mensagem = 'O relatório ainda aguarda assinatura.';
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>Verificação do relatório de atividades · Arcadis RH</title>
    <link rel="stylesheet" href="<?= View::asset('css/tokens.css') ?>">
    <link rel="stylesheet" href="<?= View::asset('css/components.css') ?>">
    <style>
        .verificacao-header {
            background: var(--surface);
            border-bottom: 1px solid var(--border);
        }
        .verificacao-header img {
            width: 9rem;
            margin-inline-start: max(1rem, (100% - 52rem) / 2);
        }
        .verificacao-portal {
            width: min(100% - 2rem, 52rem);
            margin: 2rem auto 3rem;
        }
        .verificacao-portal h1 { font-size: clamp(1.4rem, 4vw, var(--text-2xl)); }
        .verificacao-portal .card-header { flex-wrap: wrap; }
        .verificacao-portal dl { margin: 0; }
        .verificacao-portal dd { margin: .2rem 0 0; }
        .verificacao-portal code { overflow-wrap: anywhere; }
        .verificacao-arquivo::file-selector-button {
            margin-inline-end: .75rem;
            padding: .35rem .65rem;
            color: var(--ink);
            background: var(--canvas);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            font: inherit;
            cursor: pointer;
        }
        .verificacao-portal .btn:disabled { opacity: .65; cursor: default; }
        #resultado-comparacao:empty { padding: 0; }
        #resultado-comparacao:not(:empty) { margin-top: .85rem; }
    </style>
</head>
<body>
<header class="verificacao-header">
    <img src="<?= View::asset('img/logo.png') ?>" alt="Arcadis">
</header>
<main class="verificacao-portal section-stack">
    <h1>Verificação do relatório de atividades</h1>

    <?php if ($documento === null): ?>
        <div class="alert alert-danger" role="alert">Documento não encontrado ou código inválido.</div>
    <?php else: ?>
        <section class="card" aria-labelledby="titulo-registro">
            <div class="card-header">
                <h2 class="card-title" id="titulo-registro">Registro do documento</h2>
                <span class="pill pill-<?= $cor ?>"><?= View::e($rotulo) ?></span>
            </div>
            <div class="card-body section-stack">
                <div class="alert alert-<?= $cor ?>"><?= View::e($mensagem) ?></div>
                <dl class="form-grid cols-2 text-sm">
                    <div>
                        <dt class="muted text-xs">Código de verificação</dt>
                        <dd><code><?= View::e($codigo) ?></code></dd>
                    </div>
                    <div>
                        <dt class="muted text-xs">Período do relatório</dt>
                        <dd><?= View::e(date('d/m/Y', strtotime((string) $documento['data_inicio'])) . ' a ' . date('d/m/Y', strtotime((string) $documento['data_fim']))) ?></dd>
                    </div>
                    <?php if ($assinado && !empty($documento['assinatura_em'])): ?>
                        <div>
                            <dt class="muted text-xs">Data e hora do registro</dt>
                            <dd><?= View::e((string) $documento['assinatura_em']) ?></dd>
                        </div>
                    <?php endif; ?>
                </dl>
                <?php if ($assinado && ($hashOriginal !== '' || $comparacaoDisponivel)): ?>
                    <dl class="section-stack text-sm">
                        <?php if ($hashOriginal !== ''): ?>
                            <div>
                                <dt class="muted text-xs">SHA-256 do PDF original</dt>
                                <dd><code><?= View::e($hashOriginal) ?></code></dd>
                            </div>
                        <?php endif; ?>
                        <?php if ($comparacaoDisponivel): ?>
                            <div>
                                <dt class="muted text-xs">SHA-256 do PDF assinado</dt>
                                <dd><code><?= View::e($hashAssinado) ?></code></dd>
                            </div>
                        <?php endif; ?>
                    </dl>
                <?php endif; ?>
            </div>
        </section>

        <?php if ($comparacaoDisponivel): ?>
            <section class="card" aria-labelledby="titulo-comparacao">
                <div class="card-header">
                    <h2 class="card-title" id="titulo-comparacao">Comparar seu PDF</h2>
                </div>
                <div class="card-body section-stack">
                    <p class="text-sm" id="instrucao-comparacao">
                        Selecione a sua cópia do PDF assinado para comparar o SHA-256 com o registro.
                        O arquivo fica no seu dispositivo.
                    </p>
                    <div class="form-field">
                        <label class="form-label" for="arquivo-comparacao">Arquivo PDF assinado</label>
                        <input class="form-control verificacao-arquivo" id="arquivo-comparacao"
                            type="file" accept="application/pdf,.pdf" disabled aria-describedby="instrucao-comparacao">
                    </div>
                    <div>
                        <button class="btn btn-primary" id="comparar-arquivo" type="button"
                            data-sha256="<?= View::e($hashAssinado) ?>" disabled>Comparar arquivo</button>
                        <div class="alert" id="resultado-comparacao" role="status" aria-live="polite"></div>
                    </div>
                    <noscript><p class="text-sm muted">Ative o JavaScript para comparar o arquivo neste navegador.</p></noscript>
                </div>
            </section>
            <script>
                (() => {
                    const input = document.getElementById('arquivo-comparacao');
                    const botao = document.getElementById('comparar-arquivo');
                    const resultado = document.getElementById('resultado-comparacao');

                    function mostrar(mensagem, tipo) {
                        resultado.className = 'alert alert-' + tipo;
                        resultado.textContent = mensagem;
                    }

                    if (!window.crypto || !window.crypto.subtle || typeof File === 'undefined' || typeof File.prototype.arrayBuffer !== 'function') {
                        mostrar('A comparação requer um navegador compatível e uma página acessada por HTTPS.', 'warning');
                        return;
                    }

                    input.disabled = false;
                    input.addEventListener('change', () => {
                        botao.disabled = input.files.length === 0;
                        resultado.textContent = '';
                    });

                    botao.addEventListener('click', async () => {
                        const arquivo = input.files[0];
                        if (!arquivo) {
                            return;
                        }
                        input.disabled = true;
                        botao.disabled = true;
                        mostrar('Calculando o SHA-256 do arquivo…', 'info');
                        try {
                            const bytes = await arquivo.arrayBuffer();
                            const digest = await window.crypto.subtle.digest('SHA-256', bytes);
                            const hash = Array.from(new Uint8Array(digest), byte => byte.toString(16).padStart(2, '0')).join('');
                            if (hash === botao.dataset.sha256) {
                                mostrar('O arquivo coincide com o SHA-256 registrado.', 'success');
                            } else {
                                mostrar('O arquivo difere do PDF assinado registrado.', 'danger');
                            }
                        } catch (erro) {
                            mostrar('Não foi possível ler o arquivo. Selecione o PDF novamente.', 'danger');
                        } finally {
                            input.disabled = false;
                            botao.disabled = input.files.length === 0;
                        }
                    });
                })();
            </script>
        <?php endif; ?>
    <?php endif; ?>
</main>
</body>
</html>

