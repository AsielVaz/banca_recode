<?php
use Banca\Core\Csrf;
use Banca\Core\Http;
?>
<?php if ($speiMode === 'disabled'): ?>
<div class="alert warning">Los retiros SPEI están deshabilitados en esta instalación. Puedes cargar y validar la plantilla, pero el envío quedará en modo simulado.</div>
<?php endif; ?>

<section class="panel batch-panel">
    <div class="batch-intro">
        <div>
            <p class="step">Retiro por plantilla</p>
            <h2>Carga la plantilla oficial</h2>
            <p>Al seleccionar el archivo se leerán los retiros y se determinará automáticamente el banco. Para tarjetas de 16 dígitos se consulta el servicio original de Sistema 14.</p>
        </div>
        <a class="secondary button template-download" href="<?= Http::url('/batch/template') ?>" download="plantilla.xls">Descargar plantilla XLS</a>
    </div>

    <form method="post" action="<?= Http::url('/batch') ?>" enctype="multipart/form-data" class="batch-form" data-batch-form data-preview-url="<?= Http::url('/batch/preview') ?>" data-lock-submit>
        <?= Csrf::field() ?>
        <input type="hidden" name="operation" value="<?= htmlspecialchars($operationToken, ENT_QUOTES, 'UTF-8') ?>">
        <label class="file-drop" for="batch-file">
            <span class="file-drop-title">Seleccionar plantilla.xls</span>
            <span class="file-drop-copy" data-file-label>Archivo XLS original · máximo 50 retiros</span>
            <input id="batch-file" type="file" name="file" accept=".xls,application/vnd.ms-excel" required>
        </label>

        <div class="batch-status" data-batch-status aria-live="polite">Selecciona el archivo para cargar su información.</div>

        <div class="batch-preview" data-batch-preview hidden>
            <div class="batch-summary" aria-label="Resumen de la plantilla">
                <article><span>Saldo previo</span><strong data-summary-balance>$0.00</strong></article>
                <article><span>Saldo posterior</span><strong data-summary-after>$0.00</strong></article>
                <article><span>Total a retirar</span><strong data-summary-total>$0.00</strong></article>
                <article><span>Retiros cargados</span><strong data-summary-count>0</strong></article>
            </div>

            <div class="table-wrap batch-table-wrap">
                <table>
                    <thead><tr><th>#</th><th>Beneficiario</th><th>Tarjeta / CLABE</th><th>Banco</th><th>Teléfono</th><th class="number">Monto</th><th>Validación</th></tr></thead>
                    <tbody data-batch-rows></tbody>
                </table>
            </div>
        </div>

        <div class="batch-authorize">
            <label>Código 2FA
                <input name="totp" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required disabled data-batch-totp>
            </label>
            <button class="primary" type="submit" disabled data-batch-submit>Procesar retiros</button>
        </div>
    </form>
</section>

