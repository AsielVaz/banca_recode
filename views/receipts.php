<?php use Banca\Core\View; ?>
<section class="panel"><div class="panel-head"><div><h2>Transferencias SPEI</h2><p>Seguimiento, estado y comprobante CEP.</p></div></div>
<div class="table-wrap"><table><thead><tr><th>Folio / fecha</th><th>Beneficiario / cuenta</th><th>Concepto</th><th>Estado</th><th class="number">Monto</th><th>CEP</th></tr></thead><tbody>
<?php foreach ($withdrawals as $withdrawal): $account = $withdrawal['clabe'] ?: $withdrawal['tarjeta']; ?>
<tr><td><code><?= View::e($withdrawal['folio'] ?: $withdrawal['id']) ?></code><small><?= View::e(View::date($withdrawal['fecha_creacion'])) ?></small></td><td><strong><?= View::e($withdrawal['nombre']) ?></strong><small>•••• <?= View::e(substr((string) $account, -4)) ?> · <?= View::e($withdrawal['banco'] ?: $withdrawal['institucion']) ?></small></td><td><?= View::e($withdrawal['concepto']) ?></td><td><span class="badge neutral"><?= View::e($withdrawal['estado'] ?: $withdrawal['status']) ?></span><?php if ($withdrawal['causa_devolucion']): ?><small class="negative"><?= View::e($withdrawal['causa_devolucion']) ?></small><?php endif; ?></td><td class="number negative">−$<?= number_format((float) $withdrawal['monto'], 2) ?></td><td><?php if ($withdrawal['cep']): ?><a href="<?= View::e($withdrawal['cep']) ?>" target="_blank" rel="noopener">Abrir CEP</a><?php else: ?>Pendiente<?php endif; ?></td></tr>
<?php endforeach; ?>
<?php if ($withdrawals === []): ?><tr><td colspan="6" class="empty">Aún no hay retiros.</td></tr><?php endif; ?>
</tbody></table></div></section>

