# Arquitectura de ejecución: DistriVale (Tauri + Laravel + SQLite)

Este documento describe cómo se integran los dos proyectos del repositorio:

- **[DistriValeWeb/](DistriValeWeb/)** — Aplicación Laravel 13 (PHP 8.5) que contiene toda la lógica de negocio, modelos, controladores, vistas y la base de datos SQLite.
- **[DistriVale/](DistriVale/)** — Shell de escritorio en Tauri (Rust) que empaqueta Laravel como un programa nativo de Windows usando WebView2.

> **Estado actual:** `DistriVale/` ya es un proyecto Tauri real y usable para desarrollo: tiene `tauri.conf.json`, `build.rs`, iconos y un `main.rs` que arranca `php artisan serve` sobre `DistriValeWeb/` en un puerto libre, espera a que responda y navega la ventana principal hacia él (matando el proceso de PHP al cerrar). Ejecutar con `cargo run` desde `DistriVale/` (requiere `php` en el `PATH` y `DistriValeWeb/` con `composer install` ya corrido). Lo que falta es el **empaquetado final** para distribuir un instalador a usuarias sin PHP instalado — ver la sección 5, pasos 2 y 4 (sidecar de PHP portable, copia de `DistriValeWeb/` a `resources/app` sin `.env`/`vendor` de dev).

## 1. Flujo conceptual

```
Usuario (Windows)
      │
      ▼
Ejecutable .exe (Tauri)
      │
      ▼
Ventana nativa → WebView2 (renderiza HTML/CSS/JS)
      │  navega a http://127.0.0.1:<puerto>
      ▼
Servidor HTTP local (PHP built-in server / PHP Portable)
      │  ejecuta index.php
      ▼
Laravel (rutas, controladores, modelos, Blade)
      │  Eloquent ORM
      ▼
SQLite (archivo database.sqlite)
```

Tauri **no reemplaza** a Laravel: solo provee la ventana nativa y arranca/detiene el servidor PHP como un subproceso. Toda la lógica de negocio (clientes, vales, financieras, recibos, liquidaciones) vive en `DistriValeWeb/`.

## 2. Qué hace cada capa

| Capa | Tecnología | Responsabilidad |
|---|---|---|
| Shell de escritorio | Tauri (Rust) | Crear la ventana, lanzar/matar el proceso PHP, exponer diálogos nativos (guardar respaldo, abrir carpeta) |
| Renderizado UI | WebView2 (motor nativo de Windows) | Mostrar las vistas Blade servidas por Laravel |
| Backend / lógica de negocio | Laravel 13 + PHP 8.5 | Rutas, controladores, validaciones, cálculos de saldos/liquidación, generación de recibos |
| Runtime | PHP Portable (CLI, x64, NTS) embebido en el paquete | Ejecutar `php artisan serve` (o un servidor equivalente) sin requerir PHP instalado en el equipo del usuario |
| Persistencia | SQLite (`database.sqlite`) | Un único archivo de base de datos, sin servidor externo |

## 3. Estructura de carpetas propuesta (build final)

```
DistriVale\                              (raíz de instalación, p. ej. C:\Program Files\DistriVale\)
│
├── DistriVale.exe                       Ejecutable Tauri
├── resources\
│   ├── php\                             PHP Portable (php.exe + extensiones)
│   └── app\                             Copia de DistriValeWeb (sin .env, sin database.sqlite)
│       ├── app\
│       ├── routes\
│       ├── resources\views\
│       ├── vendor\                      Dependencias de Composer (instaladas en build time)
│       └── artisan
└── WebView2Loader.dll

%APPDATA%\DistriVale\                    Datos del usuario (fuera de la carpeta de instalación)
│
├── database.sqlite                      Base de datos SQLite activa
├── .env                                 Configuración generada en primer arranque
├── backups\                             Respaldos manuales/automáticos (.sqlite)
├── exports\                             PDFs / textos de recibos exportados
└── storage\                             logs de Laravel, cache de vistas compiladas
```

La separación entre **binarios de la app** (reinstalables) y **datos del usuario** (persistentes) es la misma que describe el anteproyecto: una actualización del `.exe` nunca debe sobrescribir `database.sqlite`.

## 4. Flujo de arranque de la aplicación

```
1. Usuario ejecuta DistriVale.exe
2. Tauri (setup hook, src-tauri/src/main.rs):
   a. Verifica si %APPDATA%\DistriVale existe; si no, lo crea y copia
      database.sqlite "en blanco" (con migraciones ya aplicadas) desde resources\app\database\
   b. Genera/lee .env en %APPDATA%\DistriVale\.env apuntando
      DB_DATABASE=%APPDATA%\DistriVale\database.sqlite
   c. Busca un puerto TCP libre (ej. 127.0.0.1:8712)
   d. Lanza como subproceso:
        resources\php\php.exe -S 127.0.0.1:8712 -t resources\app\public resources\app\public\index.php
      (o `php artisan serve` con --port dinámico)
   e. Espera a que el puerto responda (retry loop corto)
3. Tauri abre la ventana WebView2 apuntando a http://127.0.0.1:8712
4. El usuario interactúa con la aplicación (Blade + formularios estándar, sin SPA)
5. Al cerrar la ventana:
   a. Tauri envía señal de terminación al proceso PHP (kill del child process)
   b. Se cierra la aplicación
```

Este patrón (arrancar un servidor PHP local como *sidecar* de Tauri) es el mecanismo estándar para empaquetar apps PHP/Laravel como aplicaciones de escritorio, y es compatible con `tauri.conf.json > bundle > externalBin`.

## 5. Pasos pendientes para el empaquetado final

`DistriVale/` ya tiene `tauri.conf.json`, `build.rs`, íconos (`icons/`), una pantalla de carga (`dist/index.html`) y un `main.rs` funcional que arranca `php artisan serve` apuntando a `DistriValeWeb/`, espera a que el puerto responda y navega la ventana hacia él — ver el paso 1 y 3 de abajo, ya resueltos. Esto es **usable en desarrollo** (`cargo run` desde `DistriVale/`, con PHP del sistema en el `PATH`), pero todavía depende de que la usuaria final tenga PHP instalado. Para llegar a un instalador distribuible sin esa dependencia falta:

1. ~~Inicializar Tauri de verdad~~ — hecho: `tauri.conf.json`, `build.rs` (`tauri_build::build()`), `icons/`.

2. **Configurar el sidecar de PHP portable** en `tauri.conf.json` (hoy `main.rs` invoca el `php` del `PATH`, no un binario embebido):
   ```json
   {
     "bundle": {
       "externalBin": ["binaries/php"],
       "resources": ["resources/app/**/*"]
     }
   }
   ```
   `binaries/php-x86_64-pc-windows-msvc.exe` sería el PHP Portable, renombrado según convención de sidecars de Tauri. `main.rs` debe cambiar de `Command::new("php")` a resolver el sidecar vía `tauri::process::Command::new_sidecar("php")`.

3. ~~Escribir el `main.rs`~~ — hecho: `setup()` lanza el proceso PHP y guarda el `Child` en el estado de Tauri; `on_window_event(CloseRequested)` lo mata. Pendiente (opcional): preparar `%APPDATA%\DistriVale` con `database.sqlite` y `.env` propios (hoy usa directamente el `.env`/`database.sqlite` de `DistriValeWeb/`), y comandos Tauri para respaldo/restauración de la base.

4. **Script de build** (`package.json` o `build.rs`) que:
   - Corre `composer install --no-dev --optimize-autoloader` dentro de `DistriValeWeb/`.
   - Corre `php artisan config:cache` y `php artisan view:cache` contra una base de datos SQLite "plantilla" ya migrada.
   - Copia `DistriValeWeb/` (sin `.env`, sin `node_modules`, sin `database/database.sqlite` de desarrollo) a `DistriVale/resources/app/`.
   - Descarga/incluye el binario de PHP Portable x64 NTS en `DistriVale/binaries/`.

5. **`cargo tauri build`** (requiere `cargo install tauri-cli`, no instalado todavía en este entorno) genera el instalador `.msi`/`.exe` final para Windows.

## 6. Desarrollo local (sin empaquetar)

Durante el desarrollo no es necesario tocar Tauri en absoluto:

```powershell
cd DistriValeWeb
php artisan serve
```

y abrir `http://127.0.0.1:8000` en el navegador. Todo el trabajo de módulos (clientes, vales, financieras, recibos, liquidaciones) se prueba así. Tauri solo entra en juego al momento de empaquetar la app final para la usuaria.

## 7. Respaldo de la base de datos a Google Drive

`DistriValeWeb` tiene una pantalla ("Respaldo" en el sidebar) para subir/bajar manualmente `database.sqlite` a Google Drive — ver `app/Services/GoogleDriveService.php` y `app/Http/Controllers/GoogleDriveController.php`. No es sincronización en tiempo real: es "guardar en la nube" / "traer de la nube" a demanda, con un solo nivel de deshacer para la restauración.

Para que funcione hace falta un cliente OAuth propio (gratis, no requiere tarjeta):

1. Entrar a [Google Cloud Console](https://console.cloud.google.com/) con la cuenta de Google del negocio y crear un proyecto nuevo (cualquier nombre).
2. **APIs & Services > Library**: buscar "Google Drive API" y habilitarla.
3. **APIs & Services > OAuth consent screen**: tipo "External", dejarla en modo "Testing" (no hace falta publicarla ni pasar la revisión de Google porque el scope usado, `drive.file`, es de los que no la requieren) y agregar la cuenta de Gmail que va a usar la app como "Test user".
4. **APIs & Services > Credentials > Create Credentials > OAuth client ID**, tipo **"Desktop app"**. Copiar el Client ID y el Client Secret que genera.
5. Pegarlos en `DistriValeWeb/.env`:
   ```
   GOOGLE_DRIVE_CLIENT_ID=...
   GOOGLE_DRIVE_CLIENT_SECRET=...
   ```

No hace falta registrar la URL de redirección exacta: Google permite automáticamente cualquier puerto en `http://127.0.0.1:*`/`http://localhost:*` para clientes tipo "Desktop app" (la llamada "loopback exception"), lo cual encaja con que `php artisan serve` arranca en un puerto distinto cada vez.

Sin esas dos variables cargadas, la pantalla de Respaldo muestra un aviso y el botón de conectar queda deshabilitado — el resto de la app funciona igual.

### 7.1. Pendiente antes de distribuir a más de un puñado de negocios

El cliente OAuth queda embebido en la app y lo comparten todas las instalaciones distribuidas. Mientras la pantalla de consentimiento esté en modo **"Testing"** (el estado por defecto, el que se usa hoy en desarrollo), Google solo deja autorizar a las cuentas que se agreguen a mano como "test user" — **tope de 100 cuentas en total**. Las cuotas de la propia API de Drive no son el problema (son muy altas para este uso); el tope de 100 es el límite real.

**Antes de repartir la app a más de ~100 negocios**, pasar la pantalla de consentimiento OAuth a **"In production"** en Google Cloud Console (APIs & Services > OAuth consent screen > Publish App). Es **completamente gratis** — no tiene costo ni requiere tarjeta — y como el único scope pedido (`drive.file`) es "no sensible", no dispara el proceso de revisión manual de seguridad de Google (ese sí puede tardar semanas y es para apps que piden scopes más invasivos). Lo que sí pide para publicar:

- Nombre de la app, logo, correo de soporte (datos que ya se tienen).
- Una URL de política de privacidad — alcanza con una página estática simple (gratis en GitHub Pages, o incluso un Google Doc publicado como sitio), no hace falta un dominio propio.

Con eso publicado, cualquier cantidad de negocios puede conectar su Drive sin tope de usuarios. Puede quedar un cartel de "esta app no está verificada" con un clic extra la primera vez que cada negocio conecta su cuenta — desaparece solo si además se completa la verificación completa de la app (también gratis, opcional).

## 8. Resumen de responsabilidades por carpeta

| Carpeta | Contiene | Se toca durante... |
|---|---|---|
| `DistriValeWeb/app` | Modelos, controladores | Desarrollo de features |
| `DistriValeWeb/routes/web.php` | Rutas | Desarrollo de features |
| `DistriValeWeb/resources/views` | Blade (wireframe/UI) | Desarrollo de features |
| `DistriValeWeb/database/migrations` | Esquema SQLite | Cambios de modelo de datos |
| `DistriVale/src` (futuro `src-tauri`) | Arranque del sidecar PHP, ventana nativa | Empaquetado / integración de escritorio |
| `%APPDATA%\DistriVale` | Datos reales de la usuaria | Nunca se toca en desarrollo; solo en runtime |
