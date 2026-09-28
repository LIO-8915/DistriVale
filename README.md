# DistriVale

Aplicación de escritorio (Tauri + Laravel) para administrar clientes, créditos, cobranza y liquidaciones de un negocio de préstamos.

## Versión actual: v0.8.1

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