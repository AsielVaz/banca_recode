<?php
use Banca\Core\Csrf;
use Banca\Core\Flash;
use Banca\Core\Http;
use Banca\Core\View;
$flash = Flash::pull();
?>
<!doctype html>
<html lang="es">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#080808"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-status-bar-style" content="black-translucent"><title>Acceso · Banca 14</title><link rel="icon" href="assets/banca14-logo.png" type="image/png"><link rel="stylesheet" href="assets/app.css?v=<?= (int) filemtime(dirname(__DIR__) . '/public/assets/app.css') ?>"></head>
<body class="login-page">
<a class="skip-link" href="#login-form">Saltar al formulario</a>
<main class="login-shell">
    <section class="login-card" id="login-form">
        <img class="login-logo" src="assets/banca14-logo.png" alt="Banca 14">
        <div class="login-heading"><p>Acceso seguro</p><h1>Inicia sesión</h1><span>Ingresa las credenciales de tu cuenta.</span></div>
        <?php if ($flash): ?><div class="alert <?= View::e($flash['type']) ?>"><?= View::e($flash['message']) ?></div><?php endif; ?>
        <form method="post" action="<?= Http::url('/login') ?>" class="stack">
            <?= Csrf::field() ?>
            <label>Correo electrónico<span class="field-shell"><svg class="icon" aria-hidden="true"><use href="assets/icons.svg#mail"></use></svg><input type="email" name="email" autocomplete="username" required autofocus></span></label>
            <label>Contraseña<span class="field-shell"><svg class="icon" aria-hidden="true"><use href="assets/icons.svg#key"></use></svg><input type="password" name="password" autocomplete="current-password" required></span></label>
            <label>Código 2FA <span class="optional">Sólo si está configurado</span><span class="field-shell"><svg class="icon" aria-hidden="true"><use href="assets/icons.svg#shield"></use></svg><input type="text" name="totp" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code"></span></label>
            <button class="primary wide">Iniciar sesión</button>
            <button class="passkey-button wide" type="button" disabled title="Configura una Passkey desde tu cuenta para utilizar esta opción"><svg class="icon" aria-hidden="true"><use href="assets/icons.svg#key"></use></svg>Usar Passkey</button>
        </form>
        <p class="login-help">El acceso está protegido con autenticación de dos factores.</p>
    </section>
</main>
</body></html>

