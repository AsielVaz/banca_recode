<?php use Banca\Core\Csrf; use Banca\Core\Http; use Banca\Core\View; ?>
<?php if (!$canTransfer): ?>
<div class="alert warning">Sólo una cartera líder asignada a un grupo puede realizar traspasos.</div>
<?php else: ?>
<section class="narrow panel"><div class="step">Traspaso interno</div><h2>Mover saldo a otra cartera</h2><p>El cargo y el abono se registran juntos en una sola transacción.</p>
<form method="post" action="<?= Http::url('/transfer') ?>" class="stack" data-lock-submit>
<?= Csrf::field() ?>
<label>Cartera destino<select name="destination" required><option value="">Selecciona una cartera del grupo</option><?php foreach ($clients as $client): ?><option value="<?= (int) $client['id'] ?>"><?= View::e($client['nombre']) ?></option><?php endforeach; ?></select></label>
<label>Monto MXN<input name="amount" inputmode="decimal" placeholder="0.00" required></label>
<label>Detalle<textarea name="detail" maxlength="500" rows="3" placeholder="Motivo del traspaso"></textarea></label>
<div class="summary-line"><span>Disponible</span><strong>$<?= number_format((float) $balance, 2) ?></strong></div>
<button class="primary wide">Confirmar traspaso</button>
</form></section>
<?php endif; ?>

