<?php

use App\Helpers\View;

/** @var bool $dbOk */
/** @var array<string,mixed>|null $colaborador */
/** @var list<array<string,mixed>> $lotacoes */
/** @var list<array<string,mixed>> $empresas */
/** @var array<string,string> $errors */
/** @var bool $isEdit */
/** @var int $colaboradorId */
/** @var string $formAction */

$c = $colaborador ?? [];
$colaboradorId = (int) ($colaboradorId ?? ($c['id'] ?? 0));
$isEdit = !empty($isEdit) || $colaboradorId > 0;
$formAction = $formAction ?? ($isEdit ? '/rh/colaboradores/' . $colaboradorId : '/rh/colaboradores');
$cancelHref = $isEdit ? '/rh/colaboradores/' . $colaboradorId : '/rh/efetivo';
?>

<?php if (!$dbOk): ?>
    <div class="alert alert-warning">MySQL indisponível. Não é possível salvar colaboradores agora.</div>
<?php endif; ?>

<?php if (!empty($errors['geral'])): ?>
    <div class="alert alert-danger"><?= View::e($errors['geral']) ?></div>
<?php endif; ?>

<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title"><?= $isEdit ? 'Editar identificação' : 'Identificação' ?></h2>
            <p class="card-subtitle">Dados mestres do colaborador</p>
        </div>
    </div>
    <form method="post" action="<?= View::e($formAction) ?>" class="card-body section-stack">
        <?php if ($isEdit): ?>
            <input type="hidden" name="id" value="<?= $colaboradorId ?>">
        <?php endif; ?>
        <div class="form-grid cols-3">
            <div class="form-field">
                <label class="form-label">Empresa</label>
                <select class="form-control" name="empresa_id" required>
                    <option value="">Selecione</option>
                    <?php foreach ($empresas as $empresa): ?>
                        <option value="<?= (int) $empresa['id'] ?>" <?= ((string) ($c['empresa_id'] ?? '') === (string) $empresa['id']) ? 'selected' : '' ?>>
                            <?= View::e((string) $empresa['nome']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (!empty($errors['empresa_id'])): ?><div class="form-hint" style="color:var(--danger)"><?= View::e($errors['empresa_id']) ?></div><?php endif; ?>
            </div>
            <div class="form-field">
                <label class="form-label">Matrícula</label>
                <input class="form-control" name="matricula" value="<?= View::e((string) ($c['matricula'] ?? '')) ?>" required>
                <?php if (!empty($errors['matricula'])): ?><div class="form-hint" style="color:var(--danger)"><?= View::e($errors['matricula']) ?></div><?php endif; ?>
            </div>
            <div class="form-field">
                <label class="form-label">CPF</label>
                <input class="form-control" name="cpf" value="<?= View::e((string) ($c['cpf'] ?? '')) ?>" required>
                <?php if (!empty($errors['cpf'])): ?><div class="form-hint" style="color:var(--danger)"><?= View::e($errors['cpf']) ?></div><?php endif; ?>
            </div>
            <div class="form-field" style="grid-column:1/-1;">
                <label class="form-label">Nome completo</label>
                <input class="form-control" name="nome" value="<?= View::e((string) ($c['nome'] ?? '')) ?>" required>
                <?php if (!empty($errors['nome'])): ?><div class="form-hint" style="color:var(--danger)"><?= View::e($errors['nome']) ?></div><?php endif; ?>
            </div>
            <div class="form-field">
                <label class="form-label">E-mail corporativo</label>
                <input class="form-control" type="email" name="email_corp" value="<?= View::e((string) ($c['email_corp'] ?? '')) ?>">
            </div>
            <div class="form-field">
                <label class="form-label">E-mail alternativo</label>
                <input class="form-control" type="email" name="email_alt" value="<?= View::e((string) ($c['email_alt'] ?? '')) ?>">
            </div>
            <div class="form-field">
                <label class="form-label">Telefone</label>
                <input class="form-control" name="telefone" value="<?= View::e((string) ($c['telefone'] ?? '')) ?>">
            </div>
            <div class="form-field">
                <label class="form-label">Admissão</label>
                <input class="form-control" type="date" name="data_admissao" value="<?= View::e((string) ($c['data_admissao'] ?? ($isEdit ? '' : date('Y-m-d')))) ?>">
            </div>
            <div class="form-field">
                <label class="form-label">Cargo</label>
                <select class="form-control" name="cargo">
                    <option value="">Selecione</option>
                    <?php
                    $cargoAtual = (string) ($c['cargo'] ?? '');
                    $cargoNomes = array_map(static fn ($r) => (string) ($r['nome'] ?? ''), $cargos ?? []);
                    if ($cargoAtual !== '' && !in_array($cargoAtual, $cargoNomes, true)):
                    ?>
                        <option value="<?= View::e($cargoAtual) ?>" selected><?= View::e($cargoAtual) ?> (atual)</option>
                    <?php endif; ?>
                    <?php foreach (($cargos ?? []) as $cargo): ?>
                        <?php $cargoNome = (string) ($cargo['nome'] ?? ''); ?>
                        <option value="<?= View::e($cargoNome) ?>" <?= ($cargoAtual === $cargoNome) ? 'selected' : '' ?>>
                            <?= View::e(trim(($cargo['codigo'] ?? '') . ' — ' . $cargoNome, ' —')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (($cargos ?? []) === []): ?>
                    <div class="form-hint">Nenhum cargo ativo. Cadastre em <a href="/rh/cargos">Cargos</a>.</div>
                <?php endif; ?>
            </div>
            <div class="form-field">
                <label class="form-label">Função</label>
                <select class="form-control" name="funcao">
                    <option value="">Selecione</option>
                    <?php
                    $funcaoAtual = (string) ($c['funcao'] ?? '');
                    $funcaoNomes = array_map(static fn ($r) => (string) ($r['nome'] ?? ''), $funcoes ?? []);
                    if ($funcaoAtual !== '' && !in_array($funcaoAtual, $funcaoNomes, true)):
                    ?>
                        <option value="<?= View::e($funcaoAtual) ?>" selected><?= View::e($funcaoAtual) ?> (atual)</option>
                    <?php endif; ?>
                    <?php foreach (($funcoes ?? []) as $funcao): ?>
                        <?php $funcaoNome = (string) ($funcao['nome'] ?? ''); ?>
                        <option value="<?= View::e($funcaoNome) ?>" <?= ($funcaoAtual === $funcaoNome) ? 'selected' : '' ?>>
                            <?= View::e(trim(($funcao['codigo'] ?? '') . ' — ' . $funcaoNome, ' —')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (($funcoes ?? []) === []): ?>
                    <div class="form-hint">Nenhuma função ativa. Cadastre em <a href="/rh/funcoes">Funções</a>.</div>
                <?php endif; ?>
            </div>
            <div class="form-field">
                <label class="form-label">Status</label>
                <select class="form-control" name="status">
                    <?php foreach (['ativo','afastado','inativo','desligado'] as $st): ?>
                        <option value="<?= $st ?>" <?= (($c['status'] ?? 'ativo') === $st) ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-field">
                <label class="form-label">Gestor</label>
                <select class="form-control" name="gestor_id">
                    <option value="">—</option>
                    <?php foreach (($gestoresArcadis ?? []) as $g): ?>
                        <option value="<?= (int) $g['id'] ?>" <?= ((string) ($c['gestor_id'] ?? '') === (string) $g['id']) ? 'selected' : '' ?>>
                            <?= View::e(trim(($g['matricula'] ?? '') . ' — ' . ($g['nome'] ?? ''), ' —')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (($gestoresArcadis ?? []) === []): ?>
                    <div class="form-hint">Nenhum gestor ativo. Cadastre em <a href="/rh/gestores-arcadis">Gestores Arcadis</a>.</div>
                <?php endif; ?>
            </div>
            <div class="form-field">
                <label class="form-label">Supervisor</label>
                <select class="form-control" name="supervisor_id">
                    <option value="">—</option>
                    <?php foreach (($supervisores ?? []) as $s): ?>
                        <option value="<?= (int) $s['id'] ?>" <?= ((string) ($c['supervisor_id'] ?? '') === (string) $s['id']) ? 'selected' : '' ?>>
                            <?= View::e(trim(($s['matricula'] ?? '') . ' — ' . ($s['nome'] ?? ''), ' —')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (($supervisores ?? []) === []): ?>
                    <div class="form-hint">Nenhum supervisor ativo. Cadastre em <a href="/rh/supervisores">Supervisores</a>.</div>
                <?php endif; ?>
            </div>
            <div class="form-field">
                <label class="form-label">HE via Gestor Cliente</label>
                <label class="text-sm" style="display:flex;align-items:center;gap:.5rem;">
                    <input type="checkbox" name="he_fluxo" value="cliente" <?= (($c['he_fluxo'] ?? 'off') === 'cliente') ? 'checked' : '' ?>>
                    Ativar (autorização fora do sistema / SharePoint)
                </label>
            </div>
            <div class="form-field">
                <label class="form-label">Gestor Cliente</label>
                <select class="form-control" name="gestor_cliente_id">
                    <option value="">—</option>
                    <?php foreach (($gestoresCliente ?? []) as $gc): ?>
                        <option value="<?= (int) $gc['id'] ?>" <?= ((string) ($c['gestor_cliente_id'] ?? '') === (string) $gc['id']) ? 'selected' : '' ?>>
                            <?= View::e($gc['nome'] . (!empty($gc['cliente']) ? ' — ' . $gc['cliente'] : '')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (($gestoresCliente ?? []) === []): ?>
                    <div class="form-hint">Nenhum gestor cliente ativo. Cadastre em <a href="/rh/gestores-cliente">Gestores Cliente</a>.</div>
                <?php endif; ?>
            </div>
            <?php if (!$isEdit): ?>
            <div class="form-field">
                <label class="form-label">Lotação inicial</label>
                <select class="form-control" name="lotacao_id" required>
                    <option value="">Selecione o pacote operacional</option>
                    <?php foreach (($lotacoes ?? []) as $lot): ?>
                        <option value="<?= (int) $lot['id'] ?>" <?= ((string) ($c['lotacao_id'] ?? '') === (string) $lot['id']) ? 'selected' : '' ?>>
                            <?= View::e($lot['codigo'] . ' — ' . $lot['nome']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="form-hint">Horário e calendário são herdados da lotação.</div>
                <?php if (($lotacoes ?? []) === []): ?>
                    <div class="form-hint">Nenhuma lotação ativa. Cadastre em <a href="/rh/lotacoes">Lotações</a>.</div>
                <?php endif; ?>
                <?php if (!empty($errors['lotacao_id'])): ?><div class="form-hint" style="color:var(--danger)"><?= View::e($errors['lotacao_id']) ?></div><?php endif; ?>
            </div>
            <?php else: ?>
            <div class="form-field" style="grid-column:1/-1;">
                <div class="alert alert-warning" style="margin:0;">
                    Lotação não se altera nesta tela. Use <strong>Transferir lotação</strong> na ficha do colaborador.
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div class="stack-row">
            <a class="btn btn-secondary" href="<?= View::e($cancelHref) ?>">Cancelar</a>
            <button class="btn btn-primary" type="submit" <?= $dbOk ? '' : 'disabled' ?>>
                <?= $isEdit ? 'Salvar alterações' : 'Salvar colaborador' ?>
            </button>
        </div>
    </form>
</section>
