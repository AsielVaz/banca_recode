<?php use Banca\Core\View; ?>
<div class="table-wrap"><table><thead><tr><th>Folio</th><th>Beneficiario</th><th>Estado</th><th class="number">Monto</th></tr></thead><tbody>
<?php foreach ($withdrawals as $withdrawal): ?>
<tr><td><code><?= View::e($withdrawal['folio'] ?: $withdrawal['id']) ?></code><small><?= View::e($withdrawal['fecha_creacion']) ?></small></td><td><strong><?= View::e($withdrawal['nombre']) ?></strong><small><?= View::e($withdrawal['banco'] ?: $withdrawal['institucion']) ?></small></td><td><span class="badge neutral"><?= View::e($withdrawal['estado'] ?: $withdrawal['status']) ?></span></td><td class="number negative">−$<?= number_format((float) $withdrawal['monto'], 2) ?></td></tr>
<?php endforeach; ?>
<?php if ($withdrawals === []): ?><tr><td colspan="4" class="empty">Aún no hay retiros.</td></tr><?php endif; ?>
</tbody></table></div>

