<?php use App\Helpers\View; /** @var array $escala */ /** @var list<array> $ciclos */ ?>
<?php if (!empty($flash)): ?><div class="alert alert-success">Ciclo salvo.</div><?php endif; ?>
<?php if (!empty($error)): ?><div class="alert alert-danger"><?= View::e((string)$error) ?></div><?php endif; ?>

<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title"><?= View::e((string)$escala['nome']) ?></h2>
            <p class="card-subtitle">Tipos: 5x2, 6x1, 12x36, seg-sex, seg-sab, ciclo, personalizada</p>
        </div>
        <div style="display:flex;gap:.5rem;">
            <span class="pill pill-brand"><?= View::e((string)$escala['tipo']) ?></span>
            <a class="btn btn-secondary btn-sm" href="/rh/escalas">Voltar</a>
        </div>
    </div>
</section>

<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Escala por ciclo</h2>
            <p class="card-subtitle">Dia 1 Trabalho · Dia 2 Trabalho · Dia 3 Folga · …</p>
        </div>
    </div>
    <form method="post" action="/rh/escalas/<?= (int)$escala['id'] ?>/ciclo" class="card-body section-stack" id="cicloForm">
        <div id="cicloRows" class="section-stack">
            <?php
            $rows = $ciclos !== [] ? $ciclos : [
                ['dia_ciclo' => 1, 'tipo_dia' => 'trabalho'],
                ['dia_ciclo' => 2, 'tipo_dia' => 'trabalho'],
                ['dia_ciclo' => 3, 'tipo_dia' => 'folga'],
                ['dia_ciclo' => 4, 'tipo_dia' => 'trabalho'],
            ];
            foreach ($rows as $c):
            ?>
            <div class="form-grid cols-3 ciclo-row">
                <div class="form-field">
                    <label class="form-label">Dia do ciclo</label>
                    <input class="form-control" type="number" min="1" name="dia_ciclo[]" value="<?= (int)$c['dia_ciclo'] ?>" required>
                </div>
                <div class="form-field">
                    <label class="form-label">Tipo</label>
                    <select class="form-control" name="tipo_dia[]">
                        <option value="trabalho" <?= ($c['tipo_dia']??'')==='trabalho'?'selected':'' ?>>Trabalho</option>
                        <option value="folga" <?= ($c['tipo_dia']??'')==='folga'?'selected':'' ?>>Folga</option>
                    </select>
                </div>
                <div class="form-field">
                    <label class="form-label">&nbsp;</label>
                    <button class="btn btn-ghost btn-sm" type="button" onclick="this.closest('.ciclo-row').remove()">Remover</button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="stack-row">
            <button class="btn btn-secondary" type="button" id="addCicloDia">+ Dia</button>
            <button class="btn btn-primary" type="submit">Salvar ciclo</button>
        </div>
    </form>
</section>
<script>
document.getElementById('addCicloDia')?.addEventListener('click', () => {
  const wrap = document.getElementById('cicloRows');
  const n = wrap.querySelectorAll('.ciclo-row').length + 1;
  const div = document.createElement('div');
  div.className = 'form-grid cols-3 ciclo-row';
  div.innerHTML = `
    <div class="form-field"><label class="form-label">Dia do ciclo</label>
      <input class="form-control" type="number" min="1" name="dia_ciclo[]" value="${n}" required></div>
    <div class="form-field"><label class="form-label">Tipo</label>
      <select class="form-control" name="tipo_dia[]">
        <option value="trabalho">Trabalho</option>
        <option value="folga">Folga</option>
      </select></div>
    <div class="form-field"><label class="form-label">&nbsp;</label>
      <button class="btn btn-ghost btn-sm" type="button" onclick="this.closest('.ciclo-row').remove()">Remover</button></div>`;
  wrap.appendChild(div);
});
</script>
