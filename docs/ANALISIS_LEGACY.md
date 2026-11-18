# Análisis del proyecto original y mapa de recodificación

## Resumen ejecutivo

`banca_page` es el portal de autoservicio de clientes de Sistema 14. No es una aplicación aislada: depende de archivos ubicados un nivel arriba (`config.php`, `funciones.php`), de módulos bajo `../plus`, de la base compartida `iohanes_14` y de varios servicios remotos. La directiva de Apache fuerza PHP 5.6, aunque gran parte del núcleo usa la API `mysql_*`, retirada desde PHP 7; otras piezas ya usan `mysqli`. Esa mezcla explica que el despliegue dependa de una plataforma antigua o de una capa de compatibilidad no contenida de forma coherente en la carpeta.

La aplicación gira alrededor de dos libros:

- `pagos`: libro de saldo del cliente. El saldo disponible se calcula como `SUM(saldo_acreditable)` por `nombre` (identificador de cliente).
- `pre_ejecuta_plantilla`: órdenes de retiro/SPEI, sus estados STP/Banxico, folio, devolución y CEP.

El portal original duplica lógica de negocio entre páginas, clases y endpoints. Hay versiones `old`, `v2`, `dev`, `app` y `token` del mismo flujo, lo que dificulta saber cuál es canónica.

## Módulos originales

| Módulo | Archivos principales | Qué hace | Datos/servicios usados |
|---|---|---|---|
| Acceso | `login.php`, `api/adminLogin.php`, `loginOld.php`, `sso/*` | Valida correo, contraseña con hash heredado, TOTP, límite de intentos y crea variables de sesión. Existe un SSO paralelo, pero el `guard` está comentado en `index.php`. | `usuarios`, `logsesiones`, GoogleAuthenticator, SSO remoto. |
| Inicio | `index.php` | Presenta saldo, cinco movimientos, último retiro, strikes, QR y accesos rápidos. | `pagos`, `pre_ejecuta_plantilla`, `clientes`, `strikes`. |
| Retiro SPEI | `retiro.php`, `envia_retiro_fun.php` y variantes | Captura hasta seis beneficiarios, detecta banco, valida 2FA/saldo, elige una banca de salida, llama servicios externos y registra orden, cargo y captura bancaria. | `pre_ejecuta_plantilla`, `pagos`, `captura_edo_cta`, `stp_saldos*`, `cuentas_esp`, `configuracion`, APIs remotas. |
| Traspaso interno | `traspasos.php`, `api/reboteTraspaso.php`, `api/apiPagosT.php` | Mueve saldo entre dos clientes creando un abono y un cargo. El portal hace rebote hacia `ultra.sistema14.com` con una cookie fija. | Dos inserciones en `pagos`, `clientes`, `mailer_movimientos`. |
| Contactos | `contactos.php`, `api/adminClientesFav.php`, `api/apiClientesFav.php` | Alta, edición, consulta y baja de beneficiarios frecuentes. | `cuentas_clientes_frecuentes`. |
| Movimientos | `movimientos.php`, `api/adminPagos.php` | Lista hasta 1,000 movimientos del libro y delega el PDF de estado de cuenta al sitio antiguo. | `pagos`, `captura`, `bancos`, `empresas`; PDF remoto. |
| Comprobantes | `comprobantes.php`, `disp.php`, `gen_cep_pdf-directo.php` | Lista retiros, muestra estado/devolución y abre CEP o PDF. | `pre_ejecuta_plantilla`; Banxico y sitio antiguo. |
| Lotes | `plantilla.php`, `plantilla.xls`, `PHPExcel`, `excel_reader2.php` | Lee una plantilla Excel, prepara varios retiros y los procesa. | Mismas tablas y flujo que retiro; librerías Excel copiadas. |
| Seguridad de cuenta | `contra.php`, `2fa.php`, `api/apiUsuariosBanca.php` | Cambia contraseña y secreto TOTP; el alta 2FA incluye correo/notificación. | `usuarios`, GoogleAuthenticator, notificador. |
| Conciliación/API auxiliar | `api/actualizaMovimientos*.php`, `apiPagosT.php`, `apiNotificaciones.php` | Consulta múltiples bancas/STP, actualiza estados, envía correos/WhatsApp y soporta funciones del sistema administrativo. | Ocho tablas `stp_saldos*`, endpoints por IP, Meta/WhatsApp, Mandrill. |
| Facturación compartida | `funciones.php` y el archivo superior homónimo | Timbrado CFDI, complementos, correo, imágenes, catálogos y utilidades HTML. Es funcionalidad ajena al portal bancario, pero está acoplada al include general. | Tablas fiscales, PHPMailer, servicios de timbrado. |

## Flujo de datos observado

### Inicio de sesión

1. El navegador manda correo, contraseña y TOTP a `api/adminLogin.php`.
2. Se buscan usuario y secreto 2FA mediante SQL concatenado.
3. La contraseña se compara con `sha1(md5(sha1(email + password)))`.
4. Se cuentan errores de la última hora en `logsesiones` y se intenta detectar otra sesión por hora, no por un token robusto.
5. Se crean múltiples claves de sesión con el prefijo `clientes_sistema_14_`.

### Saldo y movimientos

El saldo no se guarda como una cuenta única: se deriva sumando `pagos.saldo_acreditable`. Cada depósito suma y cada retiro resta. La columna `saldo` es un saldo posterior informativo y puede divergir si dos operaciones se insertan a la vez sin bloqueo.

### Traspaso

El original consulta ambos saldos y después inserta dos registros independientes: depósito al destino y retiro al origen. No hay transacción; un fallo intermedio puede dejar el libro desbalanceado. La versión recodificada bloquea la cartera, revalida saldo y confirma ambas inserciones juntas.

### Retiro

El flujo original decide entre varias bancas de salida usando `configuracion.banca_salida`, cuentas especiales y saldos `stp_saldos*`; hace llamadas HTTP y después inserta una orden en `pre_ejecuta_plantilla`, un cargo en `pagos`, una captura y una notificación. Hay muchas ramas casi idénticas según banca. La versión recodificada concentra el contrato externo en `SpeiGateway`, usa una referencia idempotente y registra el cargo sólo después de una aceptación del adaptador.

## Dependencias y deuda técnica encontradas

- Credenciales de MySQL y secretos de terceros escritos en archivos PHP.
- SQL formado con interpolación en prácticamente todos los módulos, incluso después de validaciones caseras.
- API `mysql_*`, `mysqli` y clases conectoras coexistiendo.
- Ausencia de transacciones en operaciones de doble partida.
- Identificadores “cifrados” usados como autorización, sin comprobar siempre propiedad del registro.
- Cookies de sesión remotas codificadas dentro de `reboteTraspaso.php`.
- CORS abierto en endpoints de aplicación móvil.
- Variantes de producción, desarrollo y respaldo accesibles bajo el mismo DocumentRoot.
- Logs, ZIP de la API, librerías completas, demos y archivos de respaldo publicados.
- Límites implementados en JavaScript que pueden omitirse llamando el endpoint directamente.
- Contraseña con hash rápido sin costo adaptativo.
- Zona horaria inconsistente: Mazatlán y Ciudad de México.
- Consultas de hasta 10,000 registros y tablas sin índices suficientes para filtros frecuentes.
- Servicios externos por HTTP sin TLS y llamadas sin tiempo límite en algunos casos.
- PDF, correo, WhatsApp, facturación y banca mezclados en archivos de miles de líneas.
- Manejo de errores mediante `die()`, mensajes SQL al usuario y supresión con `@`.

## Correspondencia en `recode`

| Original | Nueva implementación |
|---|---|
| Includes globales | `src/bootstrap.php` y autocarga PSR-4. |
| Conector por consulta | Una conexión PDO tipada en `Core/Database`. |
| SQL concatenado | Repositorio y servicios con sentencias preparadas. |
| Sesiones dispersas | `Core/Auth`, cookie HttpOnly/SameSite y regeneración de ID. |
| Hash heredado | Compatibilidad de lectura y rehash automático con `PASSWORD_DEFAULT`. |
| GoogleAuthenticator copiado | `TotpService` pequeño, auditable y sin dependencia de PHP 5. |
| Traspaso remoto con cookie | `TransferService` local y transaccional. |
| Ramas SPEI duplicadas | `SpeiGateway` configurable: disabled, local_queue o api. |
| XLS heredado | Plantilla XLS original leída con una librería compatible con PHP 8.5, vista previa automática y máximo 50 filas. |
| Plantillas PHP repetidas | Layout y parciales compartidos. |
| Endpoints por acción | Front controller con rutas, métodos y CSRF. |

## Decisiones que requieren coordinación externa

1. **Contrato SPEI real.** Debe documentarse URL, autenticación, firma, catálogo de errores, idempotencia y conciliación. No es seguro copiar las llamadas y cookies antiguas.
2. **SSO.** La versión recodificada usa acceso local compatible. Si el SSO corporativo es obligatorio, debe integrarse con un contrato OAuth/OIDC o con el flujo firmado existente después de auditarlo.
3. **Comprobantes PDF.** Se muestra el CEP almacenado. El generador del sitio antiguo no se copió; puede sustituirse por PDF local una vez definida la plantilla legal.
4. **Notificaciones.** Se conserva la cola `mailer_movimientos`; el worker de correo/WhatsApp permanece fuera del portal.
5. **Índices.** Conviene agregar índices compuestos tras medir producción, especialmente en `pagos(nombre, fecha_asignacion, id)`, `pre_ejecuta_plantilla(cliente, id)` y `logsesiones(usuario, fecha, hora_i)`. No se alteró el esquema automáticamente para evitar bloquear tablas grandes.

## Alcance implementado

Quedan funcionales sobre el esquema actual: acceso y migración de hash, resumen, saldo, movimientos, comprobantes, contactos, traspasos internos, configuración/cambio de 2FA, cambio de contraseña, retiro individual y retiro por plantilla XLS a través del adaptador SPEI. La detección de tarjetas conserva el origen del sistema anterior (`old.sistema14.com`) y sólo transmite el BIN de seis dígitos. Las funciones administrativas de conciliación de ocho bancas, facturación CFDI y notificación multicanal se documentan como sistemas externos, pues no pertenecen al portal de cliente y migrarlas sin contratos ampliaría el riesgo operativo.

