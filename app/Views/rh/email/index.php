<?php

use App\Helpers\View;

/** @var bool $dbOk */
/** @var list<array<string,mixed>> $contas */
/** @var ?array<string,mixed> $gmail */
/** @var ?array<string,mixed> $microsoft */
/** @var ?array<string,mixed> $smtpGmail */
/** @var ?array<string,mixed> $smtpMicrosoft */
/** @var ?string $flashOk */
/** @var ?string $flashErro */

$msgs = [
    'conectado' => 'Conta conectada com sucesso.',
    'smtp' => 'Conta conectada com usuário e senha (SMTP validado).',
    'desconectado' => 'Conta desconectada.',
    'ativa' => 'Conta definida como ativa para envios.',
    'teste' => 'E-mail de teste enviado.',
    'config_gmail' => 'Credenciais do Gmail salvas. OAuth liberado.',
    'config_microsoft' => 'Credenciais da Microsoft salvas. OAuth liberado.',
];

$cards = [
    'gmail' => [
        'title' => 'Gmail',
        'subtitle' => 'Google Workspace / conta pessoal',
        'cfg' => $gmail,
        'smtp' => $smtpGmail,
        'oauth_label' => 'Conectar com Google (OAuth)',
        'help' => 'Google Cloud Console → Credenciais OAuth (tipo Web) + Gmail API.',
        'email_ph' => 'seu@gmail.com',
        'pass_ph' => 'Senha de app do Google',
    ],
    'microsoft' => [
        'title' => 'Microsoft',
        'subtitle' => 'Outlook / Microsoft 365',
        'cfg' => $microsoft,
        'smtp' => $smtpMicrosoft,
        'oauth_label' => 'Conectar com Microsoft (OAuth)',
        'help' => 'Recomendado: OAuth. Azure Portal → Registros de aplicativo → permissões Graph Mail.Send + User.Read + offline_access. Cole o Redirect URI abaixo.',
        'email_ph' => 'seu@outlook.com ou @empresa.com',
        'pass_ph' => 'Senha (muitas contas Microsoft bloqueiam SMTP)',
    ],
];

$smtpBloqueadoMs = is_string($flashErro ?? null)
    && (str_contains((string) $flashErro, '535') || str_contains((string) $flashErro, 'recusou usuário/senha'));
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
            <div style="margin-top:.75rem;">
                <a class="btn btn-primary btn-sm" href="#microsoft">Ir para OAuth Microsoft</a>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Conectar conta</h2>
            <p class="card-subtitle">
                OAuth fica ativo depois de salvar Client ID + Secret no card.
                Usuário/senha funciona na hora com e-mail real (não use @arcadis.local).
            </p>
        </div>
    </div>
    <div class="card-body" style="display:grid;gap:1rem;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));">
        <?php foreach ($cards as $key => $card): ?>
            <?php
            $cfg = $card['cfg'] ?? [];
            $ready = $dbOk && !empty($cfg['configured']);
            ?>
            <div id="<?= View::e($key) ?>" style="border:1px solid var(--border);border-radius:12px;padding:1.1rem;display:flex;flex-direction:column;gap:.85rem;">
                <div style="display:flex;align-items:center;justify-content:space-between;gap:.75rem;">
                    <div style="display:flex;align-items:center;gap:.75rem;">
                        <?php if ($key === 'gmail'): ?>
                            <div style="width:42px;height:42px;border-radius:10px;background:#fff;border:1px solid var(--border);display:grid;place-items:center;">
                                <svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true"><path fill="#EA4335" d="M12 12.7L2.4 5.4A2 2 0 0 1 4 5h16a2 2 0 0 1 1.6.4z"/><path fill="#34A853" d="M12 12.7L2.4 5.4V19a2 2 0 0 0 2 2h3.2V9.7z"/><path fill="#4285F4" d="M21.6 5.4L12 12.7l6.4 8.3H20a2 2 0 0 0 2-2V5.4z"/><path fill="#FBBC05" d="M12 12.7l6.4 8.3H5.6z"/></svg>
                            </div>
                        <?php else: ?>
                            <div style="width:42px;height:42px;border-radius:10px;background:#fff;border:1px solid var(--border);display:grid;place-items:center;">
                                <svg width="22" height="22" viewBox="0 0 23 23" aria-hidden="true">
                                    <rect width="10" height="10" x="1" y="1" fill="#F25022"/>
                                    <rect width="10" height="10" x="12" y="1" fill="#7FBA00"/>
                                    <rect width="10" height="10" x="1" y="12" fill="#00A4EF"/>
                                    <rect width="10" height="10" x="12" y="12" fill="#FFB900"/>
                                </svg>
                            </div>
                        <?php endif; ?>
                        <div>
                            <div class="fw-700"><?= View::e($card['title']) ?></div>
                            <div class="text-sm muted"><?= View::e($card['subtitle']) ?></div>
                        </div>
                    </div>
                    <span class="pill <?= $ready ? 'pill-success' : 'pill-neutral' ?>">
                        OAuth <?= $ready ? 'ativo' : 'inativo' ?>
                    </span>
                </div>

                <div style="background:var(--surface-2, #f6f7f8);border-radius:10px;padding:.85rem;display:flex;flex-direction:column;gap:.55rem;">
                    <div class="fw-700 text-sm">1) Ativar OAuth</div>
                    <div class="text-xs muted"><?= View::e($card['help']) ?></div>
                    <form method="post" action="/rh/email/config" class="form-grid" style="gap:.55rem;">
                        <input type="hidden" name="provedor" value="<?= View::e($key) ?>">
                        <input type="hidden" name="redirect_uri" value="<?= View::e((string) ($cfg['redirect_uri'] ?? '')) ?>">
                        <input class="form-control" name="client_id" required
                               placeholder="Client ID"
                               value="<?= View::e((string) ($cfg['client_id'] ?? '')) ?>"
                               autocomplete="off" <?= !$dbOk ? 'disabled' : '' ?>>
                        <input class="form-control" type="password" name="client_secret"
                               placeholder="<?= !empty($cfg['client_secret']) ? 'Client Secret (deixe vazio para manter)' : 'Client Secret' ?>"
                               autocomplete="new-password" <?= !$dbOk ? 'disabled' : '' ?> <?= empty($cfg['client_secret']) ? 'required' : '' ?>>
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
                                <?= $ready ? View::e($card['oauth_label']) : 'Salvar e conectar OAuth' ?>
                            </button>
                        </div>
                    </form>
                    <?php if ($ready): ?>
                        <a class="btn btn-ghost" href="/rh/email/conectar/<?= View::e($key) ?>">Abrir login <?= View::e($card['title']) ?></a>
                    <?php endif; ?>
                </div>

                <div style="display:flex;flex-direction:column;gap:.55rem;">
                    <div class="fw-700 text-sm">2) Ou usuário e senha (SMTP)</div>
                    <form method="post" action="/rh/email/conectar-smtp" class="form-grid" style="gap:.55rem;">
                        <input type="hidden" name="provedor" value="<?= View::e($key) ?>">
                        <input class="form-control" type="email" name="email" required
                               placeholder="<?= View::e($card['email_ph']) ?>" <?= !$dbOk ? 'disabled' : '' ?>>
                        <input class="form-control" type="password" name="password" required
                               placeholder="<?= View::e($card['pass_ph']) ?>" <?= !$dbOk ? 'disabled' : '' ?>>
                        <button class="btn btn-secondary" type="submit" <?= !$dbOk ? 'disabled' : '' ?>>Conectar com usuário e senha</button>
                        <?php if (!empty($card['smtp'])): ?>
                            <div class="text-xs muted"><?= View::e((string) $card['smtp']['hint']) ?></div>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
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
            <p>Ative o OAuth com Client ID/Secret ou conecte com e-mail real + senha.</p>
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
                    ?>
                    <tr>
                        <td class="fw-700">
                            <?= View::e(ucfirst((string) $c['provedor'])) ?>
                            <div class="text-xs muted"><?= View::e(((string) ($c['auth_mode'] ?? 'oauth')) === 'smtp' ? 'Usuário/senha (SMTP)' : 'OAuth') ?></div>
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
