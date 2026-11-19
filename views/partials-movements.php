<?php use Banca\Core\View; ?>
<div class="table-wrap"><table><thead><tr><th>Fecha</th><th>Detalle</th><th>Tipo</th><th class="number">Importe</th></tr></thead><tbody>
<?php foreach ($movements as $movement): $amount = (float) $movement['saldo_acreditable']; ?>
<tr><td><?= View::e(View::date($movement['fecha_asignacion'] ?: $movement['fecha_operacion'])) ?></td><td><strong><?= View::e($movement['descripcion'] ?: 'Movimiento') ?></strong><small><?= View::e($movement['detalle'] ?? '') ?></small></td><td><span class="badge <?= $amount < 0 ? 'out' : 'in' ?>"><?= View::e($movement['tipo_movimiento']) ?></span></td><td class="number <?= $amount < 0 ? 'negative' : 'positive' ?>"><?= $amount >= 0 ? '+' : '−' ?>$<?= number_format(abs($amount), 2) ?></td></tr>
<?php endforeach; ?>
<?php if ($movements === []): ?><tr><td colspan="4" class="empty">Aún no hay movimientos.</td></tr><?php endif; ?>
</tbody></table></div>

