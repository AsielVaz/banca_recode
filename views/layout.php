<?php
use Banca\Core\Auth;
use Banca\Core\Csrf;
use Banca\Core\Flash;
use Banca\Core\Http;
use Banca\Core\View;
$flash = Flash::pull();
$currentUser = Auth::user();
$currentRoute = Http::route();
$pageMeta = [
    '/' => ['Resumen de cartera', 'Consulta el saldo y la actividad más reciente.'],
    '/withdraw' => ['Operaciones', 'Registra una transferencia SPEI con validación de seguridad.'],
    '/transfer' => ['Operaciones', 'Mueve saldo entre carteras internas.'],
    '/contacts' => ['Gestión', 'Administra los beneficiarios que utilizas con frecuencia.'],
    '/movements' => ['Consulta', 'Revisa cargos, depósitos y saldos de la cartera.'],
    '/receipts' => ['Consulta', 'Da seguimiento a retiros y comprobantes CEP.'],
    '/batch' => ['Operaciones', 'Procesa retiros múltiples desde una plantilla validada.'],
    '/security' => ['Cuenta', 'Actualiza tu contraseña y autenticación de dos factores.'],
];
$meta = $pageMeta[$currentRoute] ?? ['Sistema 14', 'Opera tu cartera de forma segura.'];
$initials = '';
foreach (array_slice(array_filter(explode(' ', (string) ($currentUser['name'] ?? 'Usuario'))), 0, 2) as $namePart) {
    $initials .= mb_strtoupper(mb_substr($namePart, 0, 1));
}
$icon = static fn (string $name, string $class = ''): string => '<svg class="icon ' . View::e($class) . '" aria-hidden="true"><use href="assets/icons.svg#' . View::e($name) . '"></use></svg>';
$navItem = static function (string $route, string $label, string $iconName) use ($currentRoute, $icon): string {
    $active = $currentRoute === $route;
    return '<a class="nav-item' . ($active ? ' active' : '') . '" href="' . Http::url($route) . '"' . ($active ? ' aria-current="page"' : '') . '>' . $icon($iconName) . '<span>' . View::e($label) . '</span></a>';
};
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="color-scheme" content="light">
    <title><?= View::e($title ?? 'Banca 14') ?> · Banca 14</title>
    <link rel="icon" href="assets/banca14-logo.png" type="image/png">
    <link rel="stylesheet" href="assets/app.css?v=<?= (int) filemtime(dirname(__DIR__) . '/public/assets/app.css') ?>">
</head>
<body class="app-body">
<a class="skip-link" href="#main-content">Saltar al contenido</a>
<header class="mobile-topbar">
    <a href="<?= Http::url('/') ?>" aria-label="Banca 14, inicio"><img src="assets/banca14-logo.png" alt="Banca 14"></a>
    <button class="menu-toggle" type="button" aria-label="Abrir navegación" aria-controls="sidebar" aria-expanded="false"><?= $icon('menu') ?></button>
</header>
<aside class="sidebar" id="sidebar">
    <div class="sidebar-glow" aria-hidden="true"></div>
    <div class="sidebar-head">
        <a class="sidebar-logo" href="<?= Http::url('/') ?>"><img src="assets/banca14-logo.png" alt="Banca 14"></a>
        <button class="menu-close" type="button" aria-label="Cerrar navegación"><?= $icon('close') ?></button>
    </div>
    <nav class="side-nav" aria-label="Navegación principal">
        <p class="nav-label">Principal</p>
        <?= $navItem('/', 'Resumen', 'home') ?>
        <p class="nav-label">Operaciones</p>
        <div class="nav-group">
            <?= $navItem('/withdraw', 'Nuevo retiro', 'send') ?>
            <?= $navItem('/transfer', 'Traspaso interno', 'transfer') ?>
            <?= $navItem('/batch', 'Retiro por plantilla', 'batch') ?>
        </div>
        <p class="nav-label">Gestión</p>
        <div class="nav-group">
            <?= $navItem('/contacts', 'Contactos', 'contacts') ?>
            <?= $navItem('/movements', 'Movimientos', 'movements') ?>
            <?= $navItem('/receipts', 'Comprobantes', 'receipt') ?>
        </div>
    </nav>
    <div class="sidebar-user">
        <div class="user-avatar" aria-hidden="true"><?= View::e($initials ?: 'U') ?></div>
        <div class="user-copy"><strong><?= View::e($currentUser['name'] ?? 'Usuario') ?></strong><span><?= View::e($currentUser['email'] ?? '') ?></span></div>
        <a class="icon-button" href="<?= Http::url('/security') ?>" aria-label="Seguridad de la cuenta"><?= $icon('shield') ?></a>
        <form method="post" action="<?= Http::url('/logout') ?>"><?= Csrf::field() ?><button class="icon-button" aria-label="Cerrar sesión"><?= $icon('logout') ?></button></form>
    </div>
</aside>
<div class="sidebar-scrim" data-menu-close></div>
<div class="workspace">
<main class="shell" id="main-content" tabindex="-1">
    <?php if ($flash): ?><div class="alert <?= View::e($flash['type']) ?>" role="status"><?= View::e($flash['message']) ?></div><?php endif; ?>
    <div class="page-heading">
        <div class="page-title-wrap"><div class="context-line"><span aria-hidden="true"></span><p><?= View::e($meta[0]) ?></p></div><h1><?= View::e($title ?? '') ?></h1><p class="page-description"><?= View::e($meta[1]) ?></p></div>
        <?php if (isset($balance)): ?><div class="balance"><span>Saldo disponible</span><strong>$<?= number_format((float) $balance, 2) ?></strong><small>MXN</small></div><?php endif; ?>
    </div>
    <?= $content ?>
</main>
<footer>Operación protegida <span>·</span> Sistema 14 <span>·</span> America/Mexico_City</footer>
</div>
<script src="assets/app.js?v=<?= (int) filemtime(dirname(__DIR__) . '/public/assets/app.js') ?>" defer></script>
</body>
</html>

