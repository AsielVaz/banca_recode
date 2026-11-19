<?php use Banca\Core\Csrf; use Banca\Core\Http; use Banca\Core\View; ?>
<section class="split">
<div class="panel"><h2>Cambiar contraseña</h2><p>Usa al menos 12 caracteres con mayúscula, minúscula y número.</p><form method="post" action="<?= Http::url('/security/password') ?>" class="stack" data-lock-submit><?= Csrf::field() ?><label>Contraseña actual<input type="password" name="current_password" required autocomplete="current-password"></label><label>Nueva contraseña<input type="password" name="new_password" minlength="12" required autocomplete="new-password"></label><button class="primary">Actualizar contraseña</button></form></div>
<div class="panel"><h2>Autenticación 2FA</h2><p>Estado: <span class="badge <?= $hasTotp ? 'in' : 'out' ?>"><?= $hasTotp ? 'Activa' : 'Pendiente' ?></span></p>
<?php if (!$pendingSecret): ?><form method="post" action="<?= Http::url('/security/totp/start') ?>"><?= Csrf::field() ?><button class="secondary">Generar nueva clave 2FA</button></form><?php else: ?><div class="secret-box"><p>Agrega esta clave en tu aplicación TOTP:</p><code><?= View::e($pendingSecret) ?></code><small><?= View::e($pendingUri) ?></small></div><form method="post" action="<?= Http::url('/security/totp/enable') ?>" class="stack"><?= Csrf::field() ?><label>Código de confirmación<input name="totp" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required></label><button class="primary">Confirmar y activar</button></form><?php endif; ?>
</div></section>

<?php if ($webauthnEnabled): ?>
<section class="panel passkey-manager" data-passkey-manager data-csrf-token="<?= View::e(Csrf::token()) ?>" data-webauthn-endpoint="<?= View::e(Http::url('/')) ?>">
<div class="panel-head"><div><h2>Passkeys</h2><p>Accede con la biometría, PIN o bloqueo de pantalla de tus dispositivos.</p></div><button class="primary" type="button" data-passkey-register>Registrar Passkey</button></div>
<p class="passkey-status" data-passkey-status hidden aria-live="polite"></p>
<div class="passkey-list">
<?php foreach ($passkeys as $passkey): ?>
<article class="passkey-card" data-passkey-card>
<div class="passkey-icon" aria-hidden="true"><svg class="icon"><use href="assets/icons.svg#key"></use></svg></div>
<div class="passkey-copy"><strong><?= View::e($passkey['name']) ?></strong><span>Creada <?= View::e(View::date($passkey['created_at'])) ?></span><span>Último uso: <?= $passkey['last_used_at'] ? View::e(View::date($passkey['last_used_at'])) : 'Nunca' ?></span><span><?= $passkey['backup_state'] ? 'Respaldada y sincronizada' : ($passkey['backup_eligible'] ? 'Admite respaldo' : 'Ligada al dispositivo') ?></span></div>
<div class="passkey-actions"><button class="secondary" type="button" data-passkey-rename="<?= (int) $passkey['id'] ?>" data-current-name="<?= View::e($passkey['name']) ?>">Cambiar nombre</button><button class="secondary danger" type="button" data-passkey-delete="<?= (int) $passkey['id'] ?>">Eliminar</button></div>
</article>
<?php endforeach; ?>
<?php if ($passkeys === []): ?><p class="empty">Aún no has registrado una Passkey.</p><?php endif; ?>
</div>
</section>
<?php endif; ?>

