<?php
use Banca\Core\Http;
use Banca\Core\View;
?>
<?php if ($securityNotice): ?><div class="alert warning"><?= View::e($securityNotice) ?> <a href="<?= Http::url('/security') ?>">Configurar ahora</a></div><?php endif; ?>
<section class="action-grid">
    <a class="action-card accent" href="<?= Http::url('/withdraw') ?>"><span class="action-icon"><svg class="icon" aria-hidden="true"><use href="assets/icons.svg#send"></use></svg></span><h2>Nuevo retiro</h2><p>Envía una transferencia SPEI con validación 2FA.</p></a>
    <a class="action-card" href="<?= Http::url('/transfer') ?>"><span class="action-icon"><svg class="icon" aria-hidden="true"><use href="assets/icons.svg#transfer"></use></svg></span><h2>Traspaso interno</h2><p>Mueve saldo entre carteras sin salir del sistema.</p></a>
    <a class="action-card" href="<?= Http::url('/batch') ?>"><span class="action-icon"><svg class="icon" aria-hidden="true"><use href="assets/icons.svg#batch"></use></svg></span><h2>Retiro por plantilla</h2><p>Carga y valida automáticamente la plantilla XLS oficial.</p></a>
</section>
<section class="split">
    <div class="panel"><div class="panel-head"><h2>Actividad reciente</h2><a href="<?= Http::url('/movements') ?>">Ver todos</a></div><?php require __DIR__ . '/partials-movements.php'; ?></div>
    <div class="panel"><div class="panel-head"><h2>Últimos retiros</h2><a href="<?= Http::url('/receipts') ?>">Ver todos</a></div><?php require __DIR__ . '/partials-withdrawals.php'; ?></div>
</section>

