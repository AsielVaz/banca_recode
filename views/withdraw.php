<?php use Banca\Core\Csrf; use Banca\Core\Http; use Banca\Core\View; ?>
<?php if ($speiMode === 'disabled'): ?><div class="alert warning">El adaptador SPEI está deshabilitado. La captura funciona, pero no se enviará hasta configurar <code>SPEI_MODE</code>.</div><?php endif; ?>
<section class="narrow panel"><div class="step">Transferencia SPEI</div><h2>Datos del retiro</h2><p>Verifica cuidadosamente la cuenta y el beneficiario; el servidor volverá a validar saldo y 2FA.</p>
<form method="post" action="<?= Http::url('/withdraw') ?>" class="form-grid" data-lock-submit data-bank-resolve-url="<?= Http::url('/bank/resolve') ?>">
<?= Csrf::field() ?>
<input type="hidden" name="operation" value="<?= View::e($operationToken) ?>">
<?php if ($contacts): ?><label class="full">Usar contacto<select id="contact-picker"><option value="">Capturar manualmente</option><?php foreach ($contacts as $contact): ?><option value="<?= (int) $contact['id'] ?>" data-name="<?= View::e($contact['nombre']) ?>" data-account="<?= View::e($contact['numero']) ?>" data-email="<?= View::e($contact['correo']) ?>" data-phone="<?= View::e($contact['telefono']) ?>" data-concept="<?= View::e($contact['concepto_pre']) ?>"><?= View::e($contact['nombre']) ?> · •••• <?= View::e(substr((string) $contact['numero'], -4)) ?></option><?php endforeach; ?></select></label><?php endif; ?>
<label class="full">Beneficiario<input name="name" maxlength="40" required></label>
<label class="full">Tarjeta (16) o CLABE (18)<input name="account" inputmode="numeric" minlength="16" maxlength="18" required></label>
<label>Banco<select name="bank" required><option value="">Se determinará automáticamente</option><?php foreach ($banks as $bank): ?><option value="<?= View::e($bank['cve_id']) ?>"><?= View::e($bank['nombre_corto']) ?></option><?php endforeach; ?></select><span class="field-status" data-bank-status aria-live="polite"></span></label>
<label>Monto MXN<input name="amount" inputmode="decimal" placeholder="0.00" required></label>
<label class="full">Concepto<input name="concept" maxlength="200" required></label>
<label>Correo de aviso<input type="email" name="email"></label><label>Teléfono de aviso<input name="phone" maxlength="20"></label>
<label class="full">Código 2FA<input name="totp" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required></label>
<div class="summary-line full"><span>Saldo máximo disponible</span><strong>$<?= number_format((float) $balance, 2) ?></strong></div>
<button class="primary wide full">Autorizar retiro</button>
</form></section>

