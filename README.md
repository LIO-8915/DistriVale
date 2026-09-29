# DistriVale

Aplicación de escritorio (Tauri + Laravel) para administrar clientes, créditos, cobranza y liquidaciones de un negocio de préstamos.

## v0.2.3 — wireframe de avance (esta rama)

Esta rama NO es una continuación de la línea de versiones normal (viene después de `v0.9.6` en el árbol de git, pero el número `0.2.3` es a propósito: es un avance de diseño temprano, no un release). Es solo la app de Tauri con el wireframe casi terminado del diseño — vistas, controladores y la reactividad/CSS de cada pantalla — sin nada de lo que se construyó después:

- **Sin base de datos real.** Los 6 controladores (`FinancieraController`, `ClienteController`, `ValeController`, `LiquidacionController`, `ReciboController`, `DashboardController`) usan `App\Support\Wireframe\Store` en vez de Eloquent/SQLite: datos de ejemplo generados en `App\Support\Wireframe\Sample` y guardados en la sesión de Laravel (`SESSION_DRIVER=file`, nunca en disco de verdad). Crear/editar/eliminar/confirmar pago sí se refleja en pantalla mientras la app sigue abierta, pero se reinicia solo en cada arranque — "nunca guarda".
- **Sin respaldo a Google Drive** — `GoogleDriveController`/`GoogleDriveService`/`GoogleDriveToken` y la pantalla de Respaldo no existen en esta rama.
- **Sin licenciamiento ni cuentas** — nada de Lemon Squeezy ni Supabase; `DistriVale/src/main.rs` arranca directo a la app, sin pantalla de activación.

Ver `supabase-licencias-lemonsqueezy.sql` y las notas de `v0.9.1`/`v0.9.5`/`v0.9.6` (en sus propias ramas) para el sistema de licencias real, que sigue siendo el plan para producción — esta rama es solo para mostrar/probar el diseño.

## Versión actual (línea de producción): v0.9.6

Corrige que al abrir DistriVale apareciera una ventana de consola de fondo (con su propio ícono en la barra de tareas) además de la ventana de la app — el binario se linkeaba como app de consola por no tener seteado `windows_subsystem`. Se agrega `#![cfg_attr(not(debug_assertions), windows_subsystem = "windows")]` al inicio de `DistriVale/src/main.rs`: desaparece en el `.exe` de release (lo que corre el cliente), se conserva en `cargo run` para poder ver `println!`/errores mientras se desarrolla.

## Versión anterior: v0.9.5

Cambia el licenciamiento: la activación ya no es correo + código de un solo uso, es la license key de Lemon Squeezy que el cliente recibe al comprar. Lemon Squeezy pasa a ser la única fuente de verdad de si la licencia sigue activa y cuántos equipos puede tener activados (`activation_limit`); Supabase deja de decidir eso y pasa a ser la capa de auditoría: guarda un espejo de cuenta/dispositivo, junta las anomalías que detecta el nuevo "módulo de monitoreo local" (reloj manipulado, huella de equipo que cambió) en `logs_actividad`, y genera filas en `alertas` cuando algo amerita revisión desde AdminDistriVale. Supabase conserva un veto manual rápido: suspender una cuenta o revocar un dispositivo ahí sigue bloqueando el acceso en el siguiente arranque, aunque Lemon Squeezy diga que la licencia es válida.

Ver `DistriVale/src/licensing.rs` (orquesta Lemon Squeezy + Supabase), `DistriVale/src/supabase_licensing.rs` y `DistriVale/src/monitoreo_local.rs`, y el esquema nuevo en `supabase-licencias-lemonsqueezy.sql` (falta aplicarlo en el proyecto real de Supabase — incluye un ajuste pendiente al esquema existente, ver los comentarios del archivo).

El sistema anterior de correo + código de un solo uso (tabla `activaciones`, función `activar_dispositivo`) queda deprecado: no se borró nada, pero el escritorio ya no lo usa.

## Versión anterior: v0.8.1

Esta versión deja terminado y funcionando de punta a punta el respaldo de la base de datos a Google Drive (pantalla "Respaldo"): conectar una cuenta, subir/restaurar la base, y deshacer una restauración reciente.

Cómo funciona:

- **Login**: se abre en el navegador del sistema (no en el WebView embebido, porque Google bloquea el login OAuth ahí) usando un flujo OAuth 2.0 con PKCE contra un cliente Google tipo "Desktop app".
- **Vuelta del login**: como el login corre en un navegador aparte del WebView, no comparten sesión — el estado de la autorización (`state`/`code_verifier` de PKCE) se guarda en caché (tabla `cache`, no en la sesión) usando el propio `state` como llave, y el callback de Google vuelve a la raíz de la app (`http://127.0.0.1:PUERTO`), que detecta los parámetros que trae y los procesa sin interferir con la navegación normal.
- **Tokens**: se guardan cifrados en SQLite (cast `encrypted` de Laravel).
- **Respaldo/Restauración**: `VACUUM INTO` para una copia consistente, subida/descarga solo del archivo propio de la app en Drive (scope `drive.file`, no ve el resto del Drive del usuario), swap atómico al restaurar y un snapshot para poder deshacer una restauración.
- **Pantalla**: mientras se espera a que termine el login en el navegador del sistema, la pantalla de Respaldo se actualiza sola (sondeo cada 3s) en cuanto detecta la cuenta conectada.

Dos bugs de plataforma (no de la app) que costó encontrar y que quedan documentados en el código por si vuelven a aparecer en otra instalación:

- En Windows, `escapeshellarg()` reemplaza cada `%` por un espacio al abrir el navegador del sistema, lo que corrompía cualquier URL con partes codificadas (rompía todo el login). Se abre el navegador con las comillas puestas a mano en vez de `escapeshellarg()`.
- Un antivirus con inspección de tráfico HTTPS (AVG "Web Shield") puede hacer que el `curl` de PHP en Windows falle al validar el certificado de Google (`schannel: the certificate chain is incomplete`) aunque el resto del sistema sí valide bien la conexión — hay que excluir la app de ese escaneo.

Ver `ARQUITECTURA_TAURI.md` §7 para los pasos de cómo crear las credenciales de Google necesarias para activar esta función.