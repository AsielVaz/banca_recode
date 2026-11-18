-- Ejecutar durante una ventana de mantenimiento y después de revisar
-- EXPLAIN en una copia de producción. No se aplica automáticamente porque
-- pagos y pre_ejecuta_plantilla contienen cientos de miles de registros.

ALTER TABLE pagos
    ADD INDEX idx_pagos_cliente_fecha_id (nombre, fecha_asignacion, id);

ALTER TABLE pre_ejecuta_plantilla
    ADD INDEX idx_retiros_cliente_id (cliente, id),
    ADD INDEX idx_retiros_cliente_folio (cliente, folio),
    ADD INDEX idx_retiros_pago (id_pago),
    ADD INDEX idx_retiros_captura (id_captura_edo),
    ADD INDEX idx_retiros_cuenta_fecha (clabe, fecha_creacion),
    ADD INDEX idx_retiros_tarjeta_fecha (tarjeta, fecha_creacion);

ALTER TABLE pagos
    ADD INDEX idx_pagos_captura_edo (id_captura_edo_cta);

ALTER TABLE captura_edo_cta
    ADD INDEX idx_captura_referencia (referencia(64));

ALTER TABLE cuentas_clientes_frecuentes
    ADD INDEX idx_contactos_cliente_nombre (cliente, nombre);

ALTER TABLE logsesiones
    ADD INDEX idx_login_usuario_fecha_hora (usuario, fecha, hora_i, tipo);

