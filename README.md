# Banca 14 · recode

Reimplementación del portal `banca_page` para PHP 8.5. Usa el esquema existente de `iohanes_14`, pero elimina el acoplamiento con PHP 5, consultas concatenadas, credenciales embebidas y dependencias copiadas dentro del repositorio.

## Requisitos

- PHP 8.5 con `pdo_mysql`, `mbstring`, `json`, `curl` y `openssl`.
- MySQL/MariaDB con el esquema actual de Sistema 14.
- Apache con `mod_rewrite`, Nginx equivalente, o el servidor integrado de PHP para desarrollo.

## Inicio local

1. Ajusta `.env`. El archivo local ya contiene la conexión solicitada y está excluido de Git. `APP_URL` vacío detecta automáticamente la carpeta pública; sólo fíjalo cuando exista un proxy inverso.
2. Cambia `APP_KEY` por una cadena aleatoria antes de producción.
3. `SPEI_MODE=local_queue` conserva la operación del portal anterior: registra el retiro con `status=0` para el procesador SPEI compartido. Usa `disabled` para bloquear retiros o `api` sólo con un proveedor documentado.
4. Configura `BANK_LOOKUP_KEY` para identificar tarjetas mediante el servicio original de Sistema 14.
5. `WITHDRAWAL_SOURCE=7` conserva la salida usada explícitamente por el sistema original. Sólo cámbiala después de validar la cuenta y empresa correspondientes.
6. Ejecuta:

   ```powershell
   composer install
   composer test
   php -S 127.0.0.1:8085 -t public
   ```

7. Abre `http://127.0.0.1:8085`.

En Laragon puede abrirse directamente como `http://localhost/banca/recode/`; el punto de entrada de la raíz redirige internamente a `public`. Para producción sigue siendo preferible configurar el `DocumentRoot` directamente en `C:\laragon\www\banca\recode\public`.

El endpoint `GET /health` comprueba la conexión a la base y responde `200` o `503` sin revelar credenciales ni detalles internos.

## Modos SPEI

- `disabled`: bloquea retiros antes de escribir en base de datos. Es el valor seguro inicial.
- `local_queue`: registra retiro, cargo y notificación en las tablas existentes con el estado inicial histórico (`status=0` y datos del proveedor vacíos). El envío depende del procesador SPEI externo que vigila esa base de datos.
- `api`: envía JSON a `SPEI_API_URL` con Bearer token opcional e `Idempotency-Key`. Sólo registra el movimiento cuando el proveedor confirma aceptación.

La variante `api` requiere adaptar el contrato de `src/Service/SpeiGateway.php` al proveedor autorizado. El código anterior no contiene URL ni token de envío SPEI: encolaba en `pre_ejecuta_plantilla`, por eso `SPEI_API_URL` y `SPEI_API_TOKEN` permanecen vacíos en `local_queue`.

## Seguridad y compatibilidad

- Inicia sesión con hashes modernos y también reconoce el hash heredado; después de un acceso válido lo migra automáticamente con `password_hash()`.
- TOTP es compatible con las claves Base32 guardadas en `usuarios.2fa`.
- Todos los POST requieren CSRF y todas las consultas variables son preparadas.
- Los traspasos bloquean las filas relevantes y crean cargo/abono dentro de una transacción.
- El retiro por plantilla usa el archivo XLS original, carga automáticamente su vista previa y admite hasta 50 filas. Las tarjetas de 16 dígitos se identifican enviando únicamente su BIN de seis dígitos a `old.sistema14.com`; las CLABE se resuelven con el catálogo local.
- Con un proveedor externo, las operaciones confirmadas antes de un fallo no pueden revertirse; el proveedor debe soportar idempotencia y conciliación.

Consulta [docs/ANALISIS_LEGACY.md](docs/ANALISIS_LEGACY.md) para el mapa funcional y las decisiones de migración.
El diseño transaccional de las tres tablas de retiros se documenta en [docs/REGISTRO_MOVIMIENTOS.md](docs/REGISTRO_MOVIMIENTOS.md).

Los índices recomendados están en `database/recommended_indexes.sql`. Deben aplicarse en una ventana de mantenimiento después de medirlos con `EXPLAIN`; la instalación no modifica automáticamente tablas de producción.

