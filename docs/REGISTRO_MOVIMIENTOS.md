# Registro seguro de retiros

## Las tres tablas operativas

1. `pre_ejecuta_plantilla`: orden del retiro, beneficiario, cuenta destino, banco, estado del proveedor, folio y referencias hacia el pago y la captura.
2. `pagos`: cargo del cliente. `monto` conserva el importe positivo y `saldo_acreditable` registra el importe negativo; también guarda el saldo posterior.
3. `captura_edo_cta`: movimiento de salida de la cuenta bancaria operativa y su referencia.

`mailer_movimientos` funciona como cola de notificación y se escribe dentro de la misma transacción, enlazada con el ID real de `pagos`.

## Riesgos encontrados en el sistema anterior

- Las tablas se insertaban por separado y sin transacción.
- Los enlaces se calculaban con `SELECT MAX(id)`, por lo que dos solicitudes simultáneas podían relacionar registros de operaciones diferentes.
- Un fallo intermedio podía descontar saldo sin crear la orden, o crear una captura sin retiro asociado.
- La descripción del pago podía almacenar tarjeta o CLABE completas.
- Un reintento generaba otro folio y podía volver a descontar el saldo.
- La cuenta bancaria de salida se aceptaba sin comprobar que siguiera activa y perteneciera a la empresa esperada.

## Flujo recodificado

1. CSRF, token de operación, 2FA, cuenta destino, banco y monto se validan antes de escribir.
2. El token genera una referencia HMAC estable de 19 caracteres. Cada fila de una plantilla obtiene una referencia distinta.
3. Un bloqueo asesor de MySQL serializa las operaciones de un mismo cliente entre procesos, servidores y ubicaciones que compartan la base. Clientes diferentes no comparten bloqueo y operan simultáneamente.
4. Antes de contactar al proveedor se comprueba la cuenta bancaria de salida mediante una lista permitida y el catálogo `bancos`.
5. El proveedor recibe la misma referencia como `Idempotency-Key`.
6. La llamada al proveedor se realiza sin una transacción de base abierta. Después, dentro de una transacción InnoDB breve, se bloquea únicamente el último movimiento del cliente, se recalcula el saldo y se insertan la orden, la captura, el pago y la notificación.
7. Los enlaces usan `PDO::lastInsertId()` de cada `INSERT`; nunca `MAX(id)`.
8. Cualquier excepción ejecuta `ROLLBACK`. Los reintentos devuelven el retiro existente sin volver a enviar ni descontar.
9. Los errores transitorios `1205`, `1213` y `SQLSTATE 40001` se reintentan con espera aleatoria y un máximo acotado; otros errores fallan inmediatamente.

Las descripciones sólo contienen el folio; no incluyen tarjeta ni CLABE completas.

Los bloqueos son por fila o por nombre de cliente, nunca por tabla. Para que la coordinación sea efectiva, todas las instancias que registren retiros deben utilizar este servicio y la misma base MySQL. Un sistema externo que escriba directamente en las tablas sin respetar el flujo puede romper las garantías de saldo e idempotencia.

## Disponibilidad de la base

La aplicación expone `GET /health`, que devuelve `200` cuando la base acepta consultas y `503` cuando no está disponible. Puede utilizarse desde un balanceador para retirar una instancia con problemas.

La revisión del entorno actual encontró MariaDB `12.3.2`, `server_id = 1`, binlog deshabilitado y ninguna réplica conectada. Por tanto, el servidor actual sigue siendo un punto único de fallo: ningún cambio de PHP puede garantizar disponibilidad permanente si ese proceso, equipo o disco deja de responder.

Para alta disponibilidad real se requiere, fuera del código de la aplicación:

- un clúster MariaDB/Galera o primario con réplicas y failover probado;
- un proxy estable de base de datos para que todas las sedes usen el mismo punto de conexión;
- binlog/GTID o el mecanismo de replicación correspondiente habilitado;
- monitoreo del endpoint de salud, conexiones, latencia, bloqueos y espacio en disco;
- copias de seguridad verificadas y simulacros de restauración.

No se configuró automáticamente una réplica ni un clúster porque se necesitan servidores, direcciones y una política operativa que no existen en este entorno local.

## Cuenta bancaria de salida

La selección mantiene el mapa operativo del código anterior, pero ahora falla de forma segura si `bancos.status_banco` no es `AC` o si la cuenta no pertenece a la empresa esperada. El flujo original vigente asigna directamente `desicion_salida = 7`; no utiliza el valor obsoleto `configuracion.banca_salida = 4`. Por ello, el recode usa `WITHDRAWAL_SOURCE=7`, que corresponde a la cuenta `166` de la empresa `106`, marcada como activa y con actividad reciente. Cambiar la salida requiere modificar explícitamente esta variable por otro valor permitido; el sistema nunca elige una cuenta distinta por su cuenta.

## Índices

`database/recommended_indexes.sql` incluye los índices de consulta y relación para folio, pago y captura. Deben aplicarse en una ventana de mantenimiento después de revisar duplicados y ejecutar `EXPLAIN`; las tablas actuales contienen cientos de miles de registros.
