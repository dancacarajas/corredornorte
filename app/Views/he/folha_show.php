<?php

use App\Helpers\Csrf;
use App\Helpers\View;

$documento = $documento ?? [];
$textoAceite = $textoAceite ?? \App\Services\AssinaturaEletronicaService::TEXTO_ACEITE;
$nomeAssinaturaInformado = $nomeAssinaturaInformado ?? '';
$flash = $flash ?? null;
$error = $error ?? null;
$status = (string) ($documento['status'] ?? '');
$assinado = $status === 'assinado';
$docId = (int) ($documento['id'] ?? 0);
$nomeCadastro = (string) ($documento['nome'] ?? '');
?>

<?php if ($flash): ?>
    <div class="alert alert-success"><?= View::e((string) $flash) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><?= View::e((string) $error) ?></div>
<?php endif; ?>

<section class="card ra-page" data-modo="<?= $assinado ? 'concluido' : 'assinar' ?>">
    <div class="ra-page-head">
        <div>
            <h2 class="card-title" style="margin:0;">
                <?= $assinado ? 'Folha assinada' : 'Assinar folha de ponto' ?>
            </h2>
            <p class="muted text-sm" style="margin:.35rem 0 0;">
                Conferência e assinatura eletrônica liberadas pela medição.
            </p>
        </div>
        <a class="btn btn-ghost btn-sm" href="/he/folhas">Voltar</a>
    </div>

    <?php if ($assinado): ?>
        <div class="ra-next ra-next-done" role="status">
            <div>
                <strong>Concluído.</strong> Folha de ponto assinada eletronicamente.
            </div>
            <div class="ra-sign-row">
                <a class="btn btn-primary btn-sm" href="/he/folhas/<?= $docId ?>/pdf" target="_blank" rel="noopener">
                    Abrir PDF assinado
                </a>
            </div>
        </div>
    <?php else: ?>
        <div class="ra-next ra-next-sign" role="status">
            <div>
                <strong>Sua vez:</strong> confira a folha e assine aqui no portal do colaborador.
            </div>
            <div class="ra-sign-row">
                <a class="btn btn-secondary btn-sm" href="/he/folhas/<?= $docId ?>/pdf" target="_blank" rel="noopener">
                    Visualizar PDF
                </a>
            </div>
        </div>

        <div class="ra-sign-box">
            <form method="post" action="/he/folhas/<?= $docId ?>/assinar" class="ra-embed-sign">
                <?= Csrf::field() ?>
                <div>
                    <strong style="font-size:1.05rem;">Assinar Folha de Ponto agora</strong>
                    <p class="text-sm muted" style="margin:.25rem 0 0;">
                        A assinatura sela o PDF com o mesmo fluxo seguro do link enviado pela medição.
                    </p>
                </div>
                <label class="ra-embed-sign-check">
                    <input type="checkbox" name="aceite" value="1" required>
                    <span><?= View::e($textoAceite) ?></span>
                </label>
                <div class="form-grid cols-2">
                    <div class="form-field">
                        <label class="form-label" for="folhaNomeAssinar">Nome completo (conforme cadastro)</label>
                        <input class="form-control" id="folhaNomeAssinar" name="nome" type="text" required maxlength="160"
                               autocomplete="name"
                               value="<?= View::e($nomeAssinaturaInformado !== '' ? $nomeAssinaturaInformado : $nomeCadastro) ?>"
                               placeholder="Digite seu nome completo conforme o cadastro">
                    </div>
                    <div class="form-field" style="align-self:end;">
                        <button class="btn btn-primary" type="submit" style="width:100%;min-height:46px;">
                            Assinar Folha Agora
                        </button>
                    </div>
                </div>
            </form>
        </div>
    <?php endif; ?>
</section>
