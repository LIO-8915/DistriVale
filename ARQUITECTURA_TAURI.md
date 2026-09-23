# Arquitectura de ejecución: DistriVale (Tauri + Laravel + SQLite)

Este documento describe cómo se integran los dos proyectos del repositorio:

- **[DistriValeWeb/](DistriValeWeb/)** — Aplicación Laravel 13 (PHP 8.5) que contiene toda la lógica de negocio, modelos, controladores, vistas y la base de datos SQLite.
- **[DistriVale/](DistriVale/)** — Shell de escritorio en Tauri (Rust) que empaqueta Laravel como un programa nativo de Windows usando WebView2.

> **Estado actual:** `DistriVale/` es todavía un crate de Rust base (`cargo new`), sin inicializar como proyecto Tauri real (falta `src-tauri/tauri.conf.json`, `Cargo.toml` con dependencias de Tauri, y el frontend). La sección 5 describe los pasos para completar esa inicialización.

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

## 5. Pasos pendientes para inicializar Tauri correctamente

El crate actual en `DistriVale/` solo tiene `Cargo.toml` y un `main.rs` con "Hello, world!". Para llegar al flujo descrito arriba:

1. **Inicializar Tauri de verdad** (desde `DistriVale/`):
   ```powershell
   npm create tauri-app@latest .   # o cargo install tauri-cli; cargo tauri init
   ```
   Esto genera `src-tauri/tauri.conf.json`, `src-tauri/Cargo.toml` con las dependencias de Tauri, y el `identifier` de la app.

2. **Configurar el sidecar de PHP** en `tauri.conf.json`:
   ```json
   {
     "bundle": {
       "externalBin": ["binaries/php"],
       "resources": ["resources/app/**/*"]
     }
   }
   ```
   `binaries/php-x86_64-pc-windows-msvc.exe` sería el PHP Portable, renombrado según convención de sidecars de Tauri.

3. **Escribir el `main.rs`** con:
   - Hook `setup()` que prepara `%APPDATA%\DistriVale`, lanza el sidecar PHP y guarda el `Child` handle en el estado de Tauri.
   - Handler de `on_window_event(CloseRequested)` que mata el proceso PHP antes de salir.
   - (Opcional) comandos Tauri (`#[tauri::command]`) para diálogos nativos de respaldo/restauración de `database.sqlite`.

4. **Script de build** (`package.json` o `build.rs`) que:
   - Corre `composer install --no-dev --optimize-autoloader` dentro de `DistriValeWeb/`.
   - Corre `php artisan config:cache` y `php artisan view:cache` contra una base de datos SQLite "plantilla" ya migrada.
   - Copia `DistriValeWeb/` (sin `.env`, sin `node_modules`, sin `database/database.sqlite` de desarrollo) a `DistriVale/resources/app/`.
   - Descarga/incluye el binario de PHP Portable x64 NTS en `DistriVale/binaries/`.

5. **`cargo tauri build`** genera el instalador `.msi`/`.exe` final para Windows.

## 6. Desarrollo local (sin empaquetar)

Durante el desarrollo no es necesario tocar Tauri en absoluto:

```powershell
cd DistriValeWeb
php artisan serve
```

y abrir `http://127.0.0.1:8000` en el navegador. Todo el trabajo de módulos (clientes, vales, financieras, recibos, liquidaciones) se prueba así. Tauri solo entra en juego al momento de empaquetar la app final para la usuaria.

## 7. Resumen de responsabilidades por carpeta

| Carpeta | Contiene | Se toca durante... |
|---|---|---|
| `DistriValeWeb/app` | Modelos, controladores | Desarrollo de features |
| `DistriValeWeb/routes/web.php` | Rutas | Desarrollo de features |
| `DistriValeWeb/resources/views` | Blade (wireframe/UI) | Desarrollo de features |
| `DistriValeWeb/database/migrations` | Esquema SQLite | Cambios de modelo de datos |
| `DistriVale/src` (futuro `src-tauri`) | Arranque del sidecar PHP, ventana nativa | Empaquetado / integración de escritorio |
| `%APPDATA%\DistriVale` | Datos reales de la usuaria | Nunca se toca en desarrollo; solo en runtime |
