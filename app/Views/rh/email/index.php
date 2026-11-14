<?php

use App\Helpers\View;

/** @var bool $dbOk */
/** @var list<array<string,mixed>> $contas */
/** @var ?array<string,mixed> $gmail */
/** @var ?array<string,mixed> $microsoft */
/** @var ?array<string,mixed> $smtpGmail */
/** @var ?array<string,mixed> $smtpMicrosoft */
/** @var list<array<string,mixed>> $templates */
/** @var ?array<string,mixed> $templateEdit */
/** @var ?string $flashOk */
/** @var ?string $flashErro */

$templates = $templates ?? [];
$templateEdit = $templateEdit ?? null;

$msgs = [
    'conectado' => 'Conta conectada com sucesso.',
    'smtp' => 'Conta conectada com e-mail e senha (SMTP validado).',
    'desconectado' => 'Conta desconectada.',
    'ativa' => 'Conta definida como ativa para envios.',
    'teste' => 'E-mail de teste enviado.',
    'config_gmail' => 'Credenciais do Gmail salvas. OAuth liberado.',
    'config_microsoft' => 'Credenciais da Microsoft salvas. OAuth liberado.',
    'template_salvo' => 'Template de e-mail salvo.',
    'template_reset' => 'Template restaurado para o padrão.',
];

$oauthCards = [
    'gmail' => [
        'title' => 'Gmail',
        'subtitle' => 'Google Workspace / conta pessoal',
        'cfg' => $gmail,
        'oauth_label' => 'Conectar com Google',
        'help' => 'Google Cloud Console → Credenciais OAuth (tipo Web) + Gmail API.',
    ],
    'microsoft' => [
        'title' => 'Microsoft',
        'subtitle' => 'Outlook / Microsoft 365',
        'cfg' => $microsoft,
        'oauth_label' => 'Conectar com Microsoft',
        'help' => 'Azure Portal → Registros de aplicativo → Mail.Send + User.Read + offline_access.',
    ],
];

$hints = [
    'gmail' => (string) (($smtpGmail['hint'] ?? '') ?: 'Use senha de app do Google.'),
    'microsoft' => (string) (($smtpMicrosoft['hint'] ?? '') ?: 'Se a senha falhar, use SMTP da TI ou OAuth avançado.'),
    'smtp' => 'Peça à TI: servidor, porta e criptografia. Não precisa de Client ID.',
];

$smtpBloqueadoMs = is_string($flashErro ?? null)
    && (str_contains((string) $flashErro, '535') || str_contains((string) $flashErro, 'recusou usuário/senha'));

$provedorLabel = static function (string $p): string {
    return match ($p) {
        'gmail' => 'Gmail',
        'microsoft' => 'Microsoft',
        'smtp' => 'SMTP empresa',
        default => ucfirst($p),
    };
};
?>

<?php if (!$dbOk): ?>
    <div class="alert alert-warning">MySQL indisponível. O módulo de e-mail precisa do banco.</div>
<?php endif; ?>

<?php if (!empty($flashOk)): ?>
    <div class="alert alert-success"><?= View::e($msgs[(string) $flashOk] ?? 'Operação concluída.') ?></div>
<?php endif; ?>
<?php if (!empty($flashErro)): ?>
    <div class="alert alert-danger">
        <?= View::e((string) $flashErro) ?>
        <?php if ($smtpBloqueadoMs): ?>
            <div style="margin-top:.75rem;display:flex;gap:.5rem;flex-wrap:wrap;">
                <a class="btn btn-secondary btn-sm" href="#conexao-rapida" onclick="document.getElementById('prov-smtp')?.click()">Usar SMTP da empresa</a>
                <a class="btn btn-primary btn-sm" href="#oauth-avancado">Abrir OAuth avançado</a>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<section class="card email-hero" id="conexao-rapida">
    <div class="card-body">
        <h2>Conecte o e-mail da empresa</h2>
        <p>Informe e-mail e senha. Não precisa de Client ID nem acesso ao Google Cloud / Azure.</p>
    </div>
</section>

<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Conexão rápida</h2>
            <p class="card-subtitle">Caminho recomendado para a maioria das empresas</p>
        </div>
    </div>
    <div class="card-body">
        <form method="post" action="/rh/email/conectar-smtp" class="form-grid" id="emailSmtpForm" style="gap:.7rem;max-width:560px;">
            <div class="email-provider-tabs" role="radiogroup" aria-label="Tipo de conta">
                <label class="is-active">
                    <input type="radio" name="provedor" value="gmail" id="prov-gmail" checked <?= !$dbOk ? 'disabled' : '' ?>>
                    Gmail
                </label>
                <label>
                    <input type="radio" name="provedor" value="microsoft" id="prov-microsoft" <?= !$dbOk ? 'disabled' : '' ?>>
                    Microsoft
                </label>
                <label>
                    <input type="radio" name="provedor" value="smtp" id="prov-smtp" <?= !$dbOk ? 'disabled' : '' ?>>
                    SMTP da empresa
                </label>
            </div>

            <p class="email-hint" id="emailSmtpHint"><?= View::e($hints['gmail']) ?></p>

            <div class="email-smtp-extra" id="emailSmtpExtra">
                <div class="form-field">
                    <label class="form-label" for="smtp_host">Servidor SMTP</label>
                    <input class="form-control" id="smtp_host" name="smtp_host"
                           placeholder="smtp.empresa.com" autocomplete="off" <?= !$dbOk ? 'disabled' : '' ?>>
                </div>
                <div class="form-field">
                    <label class="form-label" for="smtp_port">Porta</label>
                    <input class="form-control" id="smtp_port" name="smtp_port" type="number" min="1" max="65535"
                           value="587" <?= !$dbOk ? 'disabled' : '' ?>>
                </div>
                <div class="form-field">
                    <label class="form-label" for="smtp_encryption">Criptografia</label>
                    <select class="form-control" id="smtp_encryption" name="smtp_encryption" <?= !$dbOk ? 'disabled' : '' ?>>
                        <option value="tls" selected>TLS (587)</option>
                        <option value="ssl">SSL (465)</option>
                        <option value="none">Nenhuma</option>
                    </select>
                </div>
            </div>

            <div class="form-field">
                <label class="form-label" for="email_smtp_email">E-mail</label>
                <input class="form-control" id="email_smtp_email" type="email" name="email" required
                       placeholder="seu@empresa.com" autocomplete="username" <?= !$dbOk ? 'disabled' : '' ?>>
            </div>
            <div class="form-field" id="smtpUserWrap" hidden>
                <label class="form-label" for="smtp_user">Usuário SMTP (se diferente do e-mail)</label>
                <input class="form-control" id="smtp_user" name="smtp_user"
                       placeholder="Opcional" autocomplete="off" <?= !$dbOk ? 'disabled' : '' ?>>
            </div>
            <div class="form-field">
                <label class="form-label" for="email_smtp_password">Senha</label>
                <input class="form-control" id="email_smtp_password" type="password" name="password" required
                       placeholder="Senha ou senha de app" autocomplete="current-password" <?= !$dbOk ? 'disabled' : '' ?>>
            </div>
            <div>
                <button class="btn btn-primary" type="submit" <?= !$dbOk ? 'disabled' : '' ?>>Conectar</button>
            </div>
        </form>
    </div>
</section>

<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Contas conectadas</h2>
            <p class="card-subtitle">Somente a conta ativa é usada nos envios do sistema</p>
        </div>
    </div>
    <?php if ($contas === []): ?>
        <div class="empty-state">
            <h3>Nenhuma conta ainda</h3>
            <p>Informe e-mail e senha acima, ou o SMTP fornecido pela TI.</p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>Provedor</th>
                    <th>E-mail</th>
                    <th>Status</th>
                    <th>Conectada em</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($contas as $c): ?>
                    <?php
                    $status = (string) ($c['status'] ?? '');
                    $pill = match ($status) {
                        'conectado' => 'pill-success',
                        'erro', 'expirado' => 'pill-danger',
                        default => 'pill-neutral',
                    };
                    $modo = ((string) ($c['auth_mode'] ?? 'oauth')) === 'smtp' ? 'E-mail e senha' : 'OAuth';
                    ?>
                    <tr>
                        <td class="fw-700">
                            <?= View::e($provedorLabel((string) $c['provedor'])) ?>
                            <div class="text-xs muted"><?= View::e($modo) ?></div>
                        </td>
                        <td>
                            <div class="fw-700"><?= View::e((string) $c['email']) ?></div>
                            <?php if (!empty($c['nome_exibicao'])): ?>
                                <div class="text-xs muted"><?= View::e((string) $c['nome_exibicao']) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($c['smtp_host'])): ?>
                                <div class="text-xs muted"><?= View::e((string) $c['smtp_host']) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($c['ultimo_erro'])): ?>
                                <div class="text-xs" style="color:var(--danger);"><?= View::e((string) $c['ultimo_erro']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="pill <?= $pill ?>"><?= View::e($status) ?></span>
                            <?php if (!empty($c['ativo'])): ?>
                                <span class="pill pill-brand">ativa</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-sm"><?= View::e((string) ($c['conectado_em'] ?? '—')) ?></td>
                        <td>
                            <div style="display:flex;gap:.35rem;flex-wrap:wrap;justify-content:flex-end;">
                                <?php if (empty($c['ativo']) && $status !== 'desconectado'): ?>
                                    <form method="post" action="/rh/email/ativar">
                                        <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                                        <button class="btn btn-ghost btn-sm" type="submit">Usar esta</button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($status !== 'desconectado'): ?>
                                    <form method="post" action="/rh/email/desconectar"
                                          data-confirm-title="Desconectar conta?"
                                          data-confirm="A conta deixará de enviar e-mails pelo sistema."
                                          data-confirm-ok="Desconectar"
                                          data-confirm-tone="warning">
                                        <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                                        <button class="btn btn-ghost btn-sm" type="submit">Desconectar</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="card-body" style="border-top:1px solid var(--border);">
            <form method="post" action="/rh/email/testar" class="form-grid cols-2" style="align-items:end;">
                <div class="form-field">
                    <label class="form-label">Enviar e-mail de teste para</label>
                    <input class="form-control" type="email" name="destinatario" placeholder="seu@email.com">
                </div>
                <div>
                    <button class="btn btn-secondary" type="submit">Testar envio</button>
                </div>
            </form>
        </div>
    <?php endif; ?>
</section>

<section class="card" id="templates">
    <div class="card-header">
        <div>
            <h2 class="card-title">Templates de e-mail</h2>
            <p class="card-subtitle">Layout padrão Arcadis (logo + card + botão laranja). Edite só o texto de cada gatilho.</p>
        </div>
    </div>
    <div class="card-body email-tpl-list">
        <?php if ($templates === []): ?>
            <p class="muted">Nenhum template disponível.</p>
        <?php else: ?>
            <?php foreach ($templates as $tpl): ?>
                <div class="email-tpl-row <?= ($templateEdit['chave'] ?? '') === ($tpl['chave'] ?? '') ? 'is-open' : '' ?>">
                    <div>
                        <div class="fw-700"><?= View::e((string) $tpl['label']) ?></div>
                        <div class="text-xs muted"><?= View::e((string) $tpl['trigger']) ?></div>
                        <div class="text-xs muted" style="margin-top:.2rem;">
                            <code><?= View::e((string) $tpl['chave']) ?></code>
                            · <?= !empty($tpl['customizado']) ? 'personalizado' : 'padrão do sistema' ?>
                            <?php if (!empty($tpl['atualizado_em'])): ?>
                                · <?= View::e((string) $tpl['atualizado_em']) ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <a class="btn btn-secondary btn-sm" href="/rh/email?tpl=<?= rawurlencode((string) $tpl['chave']) ?>#templates">
                        Editar conteúdo
                    </a>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if (is_array($templateEdit)): ?>
            <div class="email-tpl-editor">
                <div class="email-tpl-editor-head">
                    <div>
                        <div class="fw-700">Editando: <?= View::e((string) $templateEdit['label']) ?></div>
                        <div class="text-xs muted">Variáveis: <?php
                            $vars = array_map(static fn ($v) => '{{' . $v . '}}', $templateEdit['vars'] ?? []);
                            echo View::e(implode(' ', $vars));
                        ?></div>
                    </div>
                    <a class="btn btn-ghost btn-sm" href="/rh/email#templates">Fechar</a>
                </div>
                <form method="post" action="/rh/email/template" class="form-grid" style="gap:.7rem;">
                    <input type="hidden" name="chave" value="<?= View::e((string) $templateEdit['chave']) ?>">
                    <div class="form-field">
                        <label class="form-label" for="tpl_assunto">Assunto</label>
                        <input class="form-control" id="tpl_assunto" name="assunto" required
                               value="<?= View::e((string) $templateEdit['assunto']) ?>" <?= !$dbOk ? 'disabled' : '' ?>>
                    </div>
                    <div class="form-field">
                        <label class="form-label" for="tpl_titulo">Título no e-mail</label>
                        <input class="form-control" id="tpl_titulo" name="titulo" required
                               value="<?= View::e((string) $templateEdit['titulo']) ?>" <?= !$dbOk ? 'disabled' : '' ?>>
                    </div>
                    <div class="form-field">
                        <label class="form-label" for="tpl_texto">Versão texto (clientes sem HTML)</label>
                        <textarea class="form-control" id="tpl_texto" name="texto" rows="7" required <?= !$dbOk ? 'disabled' : '' ?>><?= View::e((string) $templateEdit['texto']) ?></textarea>
                    </div>
                    <div class="form-field">
                        <label class="form-label" for="tpl_html">Corpo HTML</label>
                        <textarea class="form-control email-tpl-code" id="tpl_html" name="corpo_html" rows="14" required <?= !$dbOk ? 'disabled' : '' ?>><?= View::e((string) $templateEdit['corpo_html']) ?></textarea>
                    </div>
                    <div class="form-field">
                        <label class="form-label" for="tpl_rodape">Rodapé</label>
                        <input class="form-control" id="tpl_rodape" name="rodape"
                               value="<?= View::e((string) $templateEdit['rodape']) ?>" <?= !$dbOk ? 'disabled' : '' ?>>
                    </div>
                    <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
                        <button class="btn btn-primary" type="submit" <?= !$dbOk ? 'disabled' : '' ?>>Salvar template</button>
                    </div>
                </form>
                <?php if (!empty($templateEdit['customizado'])): ?>
                    <form method="post" action="/rh/email/template/reset" style="margin-top:.75rem;"
                          data-confirm-title="Restaurar padrão?"
                          data-confirm="As alterações deste template serão descartadas."
                          data-confirm-ok="Restaurar"
                          data-confirm-tone="warning">
                        <input type="hidden" name="chave" value="<?= View::e((string) $templateEdit['chave']) ?>">
                        <button class="btn btn-ghost btn-sm" type="submit" <?= !$dbOk ? 'disabled' : '' ?>>Restaurar padrão do sistema</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<details class="email-advanced" id="oauth-avancado">
    <summary>
        <span>Opção avançada (OAuth)</span>
    </summary>
    <div class="email-advanced-body">
        <p class="text-sm muted" style="grid-column:1/-1;margin:0;">
            Só use se a TI fornecer Client ID e Client Secret. A maioria das empresas não precisa disso.
        </p>
        <?php foreach ($oauthCards as $key => $card): ?>
            <?php
            $cfg = $card['cfg'] ?? [];
            $ready = $dbOk && !empty($cfg['configured']);
            ?>
            <div class="email-oauth-card" id="oauth-<?= View::e($key) ?>">
                <div class="email-oauth-card-head">
                    <div>
                        <div class="fw-700"><?= View::e($card['title']) ?></div>
                        <div class="text-sm muted"><?= View::e($card['subtitle']) ?></div>
                    </div>
                    <span class="pill <?= $ready ? 'pill-success' : 'pill-neutral' ?>">
                        OAuth <?= $ready ? 'ativo' : 'inativo' ?>
                    </span>
                </div>
                <div class="text-xs muted"><?= View::e($card['help']) ?></div>
                <form method="post" action="/rh/email/config" class="form-grid" style="gap:.55rem;">
                    <input type="hidden" name="provedor" value="<?= View::e($key) ?>">
                    <input type="hidden" name="redirect_uri" value="<?= View::e((string) ($cfg['redirect_uri'] ?? '')) ?>">
                    <input class="form-control" name="client_id"
                           placeholder="Client ID"
                           value="<?= View::e((string) ($cfg['client_id'] ?? '')) ?>"
                           autocomplete="off" <?= !$dbOk ? 'disabled' : '' ?>>
                    <input class="form-control" type="password" name="client_secret"
                           placeholder="<?= !empty($cfg['client_secret']) ? 'Client Secret (vazio = manter)' : 'Client Secret' ?>"
                           autocomplete="new-password" <?= !$dbOk ? 'disabled' : '' ?>>
                    <?php if ($key === 'microsoft'): ?>
                        <input class="form-control" name="tenant"
                               value="<?= View::e((string) ($cfg['tenant'] ?? 'common')) ?>"
                               placeholder="Tenant (common)" <?= !$dbOk ? 'disabled' : '' ?>>
                    <?php endif; ?>
                    <div class="text-xs muted">Redirect URI (cole no Azure/Google):</div>
                    <input class="form-control" value="<?= View::e((string) ($cfg['redirect_uri'] ?? '')) ?>" readonly>
                    <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
                        <button class="btn btn-secondary" type="submit" name="conectar_agora" value="0" <?= !$dbOk ? 'disabled' : '' ?>>Salvar</button>
                        <button class="btn btn-primary" type="submit" name="conectar_agora" value="1" <?= !$dbOk ? 'disabled' : '' ?>>
                            <?= $ready ? View::e($card['oauth_label']) : 'Salvar e conectar' ?>
                        </button>
                    </div>
                </form>
                <?php if ($ready): ?>
                    <a class="btn btn-ghost" href="/rh/email/conectar/<?= View::e($key) ?>">Abrir login <?= View::e($card['title']) ?></a>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</details>

<script>
(function () {
    const hints = <?= json_encode($hints, JSON_UNESCAPED_UNICODE) ?>;
    const form = document.getElementById('emailSmtpForm');
    if (!form) return;
    const hintEl = document.getElementById('emailSmtpHint');
    const extra = document.getElementById('emailSmtpExtra');
    const userWrap = document.getElementById('smtpUserWrap');
    const tabs = form.querySelectorAll('.email-provider-tabs label');
    const host = document.getElementById('smtp_host');

    function sync() {
        const checked = form.querySelector('input[name="provedor"]:checked');
        const value = checked ? checked.value : 'gmail';
        tabs.forEach((lab) => {
            const input = lab.querySelector('input');
            lab.classList.toggle('is-active', !!(input && input.checked));
        });
        if (hintEl) hintEl.textContent = hints[value] || '';
        const custom = value === 'smtp';
        if (extra) extra.classList.toggle('is-open', custom);
        if (userWrap) userWrap.hidden = !custom;
        if (host) host.required = custom;
    }

    form.querySelectorAll('input[name="provedor"]').forEach((el) => {
        el.addEventListener('change', sync);
    });
    sync();

    if (window.location.hash === '#oauth-avancado') {
        const adv = document.getElementById('oauth-avancado');
        if (adv) adv.open = true;
    }
})();
</script>
