# DistriVale

Aplicación de escritorio (Tauri + Laravel) para administrar clientes, créditos, cobranza y liquidaciones de un negocio de préstamos.

## Versión actual: v0.9.9.1

Acceso remoto desde celulares/iPad, interfaz móvil y recibos consolidados por crédito. (Cargo y Tauri exigen versiones de 3 números, así que `Cargo.toml` y `tauri.conf.json` llevan `0.9.9-1`; la rama y este README la llaman v0.9.9.1.)

**Acceso remoto por red local**: un iPad o celular abre `http://<IP de la PC>:8712` y se empareja con un código de 6 dígitos que la PC muestra en un modal (con parpadeo y sonido); el modal aparece aunque el acceso remoto se encienda sin recargar la ventana. Por defecto está apagado, se apaga solo al cerrar la app y SQLite pasa a modo WAL para que varios dispositivos escriban a la vez. Ver `ARQUITECTURA_TAURI.md` §6.2 y §6.3.

**Interfaz para celulares**: barra inferior, tablas apiladas, "jalar para actualizar" y aviso de conexión perdida; en la vista remota, si la conexión se pierde ~10 s se tapa la app y al volver se regresa a la pantalla de código en lugar de dejar la vista congelada. El PDF "Relación de cobranza" ya no encima las tarjetas de cliente (dompdf no maneja bien columnas con `float`: ahora es una tabla). Ver §6.4 y §6.6.

**Recibo consolidado**: "Generar recibo" ahora es cliente → créditos activos o en mora → monto a abonar por crédito (los clientes sin nada pendiente no aparecen), con un botón "+ Agregar otro recibo" para armar hasta 8 recibos independientes en la misma pantalla (cada uno con su cliente, sin repetir créditos entre ellos, con "Separar por financiera", resumen fijo en celular y una página final con enlaces y "Copiar todo"). El detalle del recibo muestra financiera, folio, número de pago, nuevo saldo y totales por financiera. Ver §6.5.

**Tema contrastado opcional**: en el perfil se puede activar un tema de mayor contraste; viene apagado y el diseño original no cambia.

## Versión anterior: v0.9.8

Dos frentes: la lógica de cobranza/mora y el rendimiento del servidor local.

**Cobranza y mora**: la fecha de pago y el monto quedan editables al confirmar un recibo (antes eran 100% automáticos). `App\Support\Mora` deja de contar "cada 15 días desde que se dispuso" (ventana rotativa) y pasa a cortes de calendario fijos — día 15 y último día del mes, igual que `App\Support\Quincena` ya usa en el resto de la app — con el recargo activándose el día siguiente a cada corte (16, o el 1 del mes siguiente). En `cat_financieras` el recargo de financiera y el "recargo personal" (beneficio del distribuidor, mecánica aún sin definir) quedan en campos separados, y se agrega un % de ganancia quincenal por financiera. El PDF de cliente deja de mostrar fechas y el nuevo PDF "Relación de cobranza" (antes "Ganancias quincenales") exporta, por financiera o por todas, una tarjeta por cliente que no se corta entre hojas. La etiqueta "Demora" vuelve a "Mora" en toda la app.

**Servidor local más rápido**: `php artisan serve` (su servidor embebido, `php -S`) se quedaba colgado ~19 s en Windows con ráfagas de peticiones concurrentes — una sola pantalla dispara varias a la vez (CSS, JS, iconos). Se reemplaza por Caddy sirviendo de frente a 4 procesos `php-cgi` (FastCGI) con OPcache activado: de una media de 18.5 s (41 de 48 cargas por encima de 3 s) a 350 ms (0 de 48). Un hilo supervisor relanza cualquier `php-cgi`/Caddy que muera; si falta algún binario o el stack no arranca a tiempo, cae de vuelta al `php artisan serve` de antes. De paso se corrigió que cerrar la ventana dejaba huérfano el proceso que de verdad atendía las peticiones (Windows no mata hijos en cascada) — ahora se usa `taskkill /T` sobre el árbol completo. Ver `ARQUITECTURA_TAURI.md` §6.1.

## Versión anterior: v0.9.7

Empaqueta PHP portable (8.4.x NTS x64, descargado de windows.php.net) dentro de la propia app, para que el cliente que instale DistriVale no necesite tener PHP instalado por su cuenta. Vive en `DistriVale/php-portable/` — pesa ~90 MB, por eso está en `.gitignore` (ver `ARQUITECTURA_TAURI.md` §5 para cómo regenerarla) y no se sube al repo. `main.rs` lo ubica en tiempo de ejecución (`resolve_php_dir`) con la misma estrategia de 3 pasos que ya usaba para encontrar `DistriValeWeb/`, y reescribe `php.ini` en cada arranque (`write_php_ini`) con rutas absolutas — no se pueden fijar en el archivo porque dependen de dónde quede instalado el `.exe` en la máquina del cliente. En el camino aparecieron y se corrigieron dos bugs de plataforma en Windows (ver ARQUITECTURA_TAURI.md): `php artisan serve` no hereda la mayoría de las variables de entorno al proceso que de verdad atiende las peticiones (rompía la generación de PDFs), y `current_exe()`/`resource_dir()` a veces traen el prefijo extendido de rutas de Windows (`\\?\...`), que Symfony Process no tolera.

## Versión anterior: v0.9.6

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