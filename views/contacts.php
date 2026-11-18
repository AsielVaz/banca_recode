<?php
use Banca\Core\Csrf;
use Banca\Core\Http;
use Banca\Core\View;
?>
<section class="split contacts-layout">
<div class="panel"><h2><?= $edit ? 'Editar contacto' : 'Nuevo contacto' ?></h2><p>Guarda beneficiarios frecuentes para capturar retiros más rápido.</p>
<form method="post" action="<?= Http::url('/contacts/save') ?>" class="form-grid">
<?= Csrf::field() ?><input type="hidden" name="id" value="<?= View::e($edit['id'] ?? '') ?>">
<label class="full">Nombre<input name="nombre" maxlength="100" required value="<?= View::e($edit['nombre'] ?? '') ?>"></label>
<label class="full">Tarjeta o CLABE<input name="numero" inputmode="numeric" minlength="16" maxlength="18" required value="<?= View::e($edit['numero'] ?? '') ?>"></label>
<label>Correo<input type="email" name="correo" value="<?= View::e($edit['correo'] ?? '') ?>"></label>
<label>Teléfono<input name="telefono" maxlength="100" value="<?= View::e($edit['telefono'] ?? '') ?>"></label>
<label class="full">Concepto predeterminado<input name="concepto" maxlength="100" value="<?= View::e($edit['concepto_pre'] ?? '') ?>"></label>
<div class="form-actions full"><button class="primary">Guardar contacto</button><?php if ($edit): ?><a class="secondary button" href="<?= Http::url('/contacts') ?>">Cancelar</a><?php endif; ?></div>
</form></div>
<div class="panel"><div class="panel-head"><h2>Mis contactos</h2><span><?= count($contacts) ?> guardados</span></div><div class="contact-list">
<?php foreach ($contacts as $contact): ?><article class="contact"><div class="avatar"><?= View::e(mb_strtoupper(mb_substr((string) $contact['nombre'], 0, 1))) ?></div><div><strong><?= View::e($contact['nombre']) ?></strong><small>•••• <?= View::e(substr((string) $contact['numero'], -4)) ?> · <?= View::e($contact['correo']) ?></small></div><div class="contact-actions"><a href="<?= Http::url('/contacts') ?>&id=<?= (int) $contact['id'] ?>">Editar</a><form method="post" action="<?= Http::url('/contacts/delete') ?>" data-confirm="¿Eliminar este contacto?"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $contact['id'] ?>"><button class="link-button danger">Eliminar</button></form></div></article><?php endforeach; ?>
<?php if ($contacts === []): ?><p class="empty">No tienes contactos guardados.</p><?php endif; ?>
</div></div></section>

