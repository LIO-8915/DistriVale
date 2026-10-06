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

`DistriVale/` ya tiene `tauri.conf.json`, `build.rs`, íconos (`icons/`), una pantalla de carga (`dist/index.html`) y un `main.rs` funcional que arranca `php artisan serve` apuntando a `DistriValeWeb/`, espera a que el puerto responda y navega la ventana hacia él — ver el paso 1 y 3 de abajo, ya resueltos. Para llegar a un instalador distribuible falta:

1. ~~Inicializar Tauri de verdad~~ — hecho: `tauri.conf.json`, `build.rs` (`tauri_build::build()`), `icons/`.

2. ~~PHP portable embebido~~ — hecho, sin depender del mecanismo de sidecar de Tauri (que espera un solo binario, no una instalación completa de PHP con su carpeta `ext/`): `DistriVale/php-portable/` (gitignorada — ver más abajo cómo generarla) trae PHP 8.4.x NTS x64 completo, descargado de windows.php.net. `resolve_php_dir()` en `main.rs` lo ubica con la misma estrategia de 3 pasos que `resolve_webapp_dir()` (empaquetado vía `resource_dir()` → copia portable hermana del `.exe` → carpeta de desarrollo), y `write_php_ini()` regenera `php.ini` en cada arranque con `extension_dir`/`sys_temp_dir` absolutos calculados en el momento — no se pueden dejar fijos en el archivo porque dependen de dónde termine instalado el `.exe` en la máquina del cliente. `tauri.conf.json` ya declara `bundle.resources: {"php-portable": "php"}` para que `cargo tauri build` lo incluya.

   Para regenerar `php-portable/` (por ejemplo, para subir a otra versión de PHP): descargar el zip NTS x64 correspondiente de `https://windows.php.net/downloads/releases/`, extraerlo completo en `DistriVale/php-portable/` — no hace falta tocar nada más, `php.ini` se escribe solo en cada arranque. Usar la MISMA versión mayor.menor que `vendor/composer/platform_check.php` exige en `DistriValeWeb` (ahí quedó registrada la versión real usada al instalar los paquetes de Composer, que puede ser más nueva que el `"php": "^8.3"` de `composer.json` si alguna dependencia transitiva pide más) — mezclar versiones hace que la app truene al arrancar con "Composer detected issues in your platform".

   Dos bugs de plataforma (Windows) que costó encontrar al probar esto, documentados en el código por si vuelven a aparecer:
   - `php artisan serve` en Windows NO hereda la mayoría de las variables de entorno al proceso hijo que de verdad atiende las peticiones (Laravel solo deja pasar una lista fija: `PATH`, `SYSTEMROOT`, etc. — ver `Illuminate\Foundation\Console\ServeCommand::$passthroughVariables`). Sin `sys_temp_dir` fijado a mano en `php.ini`, `sys_get_temp_dir()` en ese proceso hijo cae a algo que puede no ser escribible, y dompdf (que necesita un directorio temporal para generar cada PDF) truena con `ValueError: Path must not be empty`.
   - `current_exe()`/`resource_dir()` a veces devuelven el path con el prefijo extendido de Windows (`\\?\C:\...`) — que Rust maneja bien, pero que Symfony Process (usado por `php artisan serve` para armar sus propios archivos temporales) no tolera: fallaba con "No such file or directory" para un path que sí existía. `quitar_prefijo_extendido()` en `main.rs` lo saca a mano antes de usar cualquiera de estos paths.

3. ~~Escribir el `main.rs`~~ — hecho: `setup()` lanza el proceso PHP y guarda el `Child` en el estado de Tauri; `on_window_event(CloseRequested)` lo mata. Pendiente (opcional): preparar `%APPDATA%\DistriVale` con `database.sqlite` y `.env` propios (hoy usa directamente el `.env`/`database.sqlite` de `DistriValeWeb/`), y comandos Tauri para respaldo/restauración de la base.

4. **Script de build** (`package.json` o `build.rs`) que:
   - Corre `composer install --no-dev --optimize-autoloader` dentro de `DistriValeWeb/`.
   - Corre `php artisan config:cache` y `php artisan view:cache` contra una base de datos SQLite "plantilla" ya migrada.
   - Copia `DistriValeWeb/` (sin `.env`, sin `node_modules`, sin `database/database.sqlite` de desarrollo) a `DistriVale/resources/app/`.
   - `DistriVale/php-portable/` (ver paso 2 de arriba) ya no requiere este paso — solo tiene que existir antes de correr `cargo tauri build`, `tauri.conf.json` la empaqueta sola.

5. **`cargo tauri build`** (requiere `cargo install tauri-cli`, no instalado todavía en este entorno) genera el instalador `.msi`/`.exe` final para Windows.

## 6. Desarrollo local (sin empaquetar)

Durante el desarrollo no es necesario tocar Tauri en absoluto:

```powershell
cd DistriValeWeb
php artisan serve
```

y abrir `http://127.0.0.1:8000` en el navegador. Todo el trabajo de módulos (clientes, vales, financieras, recibos, liquidaciones) se prueba así. Tauri solo entra en juego al momento de empaquetar la app final para la usuaria.

## 6.1. Servidor local: Caddy + varios `php-cgi` (reemplaza a `php artisan serve`)

**Por qué.** El servidor embebido de PHP (`php -S`, lo que usa `artisan serve`) se queda colgado ~19 s en Windows cuando llegan peticiones simultáneas — y una pantalla las dispara (CSS, JS, fuentes). Se reprodujo con `php -S` a secas (PHP 8.4 y 8.5), con archivos estáticos, sin Laravel ni SQLite de por medio; un servidor Node con la misma carga no lo tuvo. `PHP_CLI_SERVER_WORKERS` no existe en Windows (se ignora). Medido con Edge real, 48 cargas de página:

| | `artisan serve` | Caddy + 4 `php-cgi` | + OPcache |
|---|---|---|---|
| media | 18,484 ms | 778 ms | **350 ms** |
| máximo | 35,000 ms | 1,501 ms | 956 ms |
| cargas > 3 s | 41 de 48 | 0 | 0 |

**Cómo funciona** (`iniciar_stack` en `main.rs`):
1. Se reservan 4 puertos libres distintos y se lanzan 4 `php-cgi.exe -b 127.0.0.1:<p>` (FastCGI, `PHP_FCGI_MAX_REQUESTS=0`).
2. Se espera a que los 4 acepten conexiones **antes** de arrancar Caddy (si no, la primera petición daría 502 y WebView2 no reintenta la navegación).
3. Se genera `php/tmp/Caddyfile` y se lanza `caddy.exe run`: `bind 127.0.0.1`, `php_fastcgi` con `lb_policy least_conn`, `file_server` para estáticos.
4. `vigilar_servidor` (hilo) relanza en el mismo puerto cualquier `php-cgi` o Caddy que muera.
5. Al cerrar la ventana, `detener_servidor` mata todos los árboles con `taskkill /T`.

**Respaldo.** Si falta `caddy.exe` o `php-cgi.exe`, o el stack no responde en 20 s, `iniciar_app_principal` cae solo a `php artisan serve` (probado quitando Caddy: arranca y responde 200).

**Trampas que ya mordieron** (no repetirlas):
- **`bind 127.0.0.1` es obligatorio.** Con solo `http://127.0.0.1:puerto` de dirección de sitio, Caddy igual escucha en `0.0.0.0` y `[::]` — la app quedaba abierta a toda la red local.
- Las rutas en el Caddyfile van **entre comillas** y con `/`; con espacios sin comillas Caddy rechaza la configuración ("too many arguments").
- **OPcache** (`zend_extension=opcache` en el `php.ini` que genera `write_php_ini`) solo rinde con `php-cgi`, que es de larga vida; en el SAPI CLI queda apagado (`enable_cli=0`).
- `php-portable/ext/php_opcache.dll` y `php-cgi.exe` vienen en el zip NTS de windows.php.net; no hay que añadir nada más.

**Obtener Caddy.** `DistriVale/caddy-portable/` está gitignorada (~53 MB). Bajar `caddy_<versión>_windows_amd64.zip` de https://github.com/caddyserver/caddy/releases y extraer **solo** `caddy.exe` ahí (probado con v2.11.7). `tauri.conf.json` la empaqueta como `resources/caddy`; el build falla si la carpeta `caddy-portable` no existe (igual que `php-portable`). Licencia Apache 2.0.

## 6.2. SQLite en modo WAL (varios dispositivos a la vez)

Con acceso remoto (§6.3) la PC y un iPad escriben en la misma `database.sqlite`, y ya había 4 `php-cgi` concurrentes. `config/database.php` fija `journal_mode = wal` y `busy_timeout = 5000` ms (`DB_JOURNAL_MODE` / `DB_BUSY_TIMEOUT` en `.env` lo cambian).

**Qué se ganó realmente** (medido con carga mixta lectura/escritura): ~5× de rendimiento de escritura y ~5× menos latencia p99 de lectura, porque los lectores ya no bloquean al escritor. **No** elimina los errores "database is locked": PDO ya esperaba 60 s por defecto; el timeout de 5 s los hace fallar antes, y la escritura más pesada (`Mora::actualizar`, ≈1.1 s) cabe de sobra.

**Trampas**:
- Aparecen `database.sqlite-wal` y `-shm` junto al archivo. **Copiar solo `database.sqlite` con la app abierta pierde lo que aún está en el `-wal`.** Cualquier copia manual (exportar a "Revisiones de prueba", respaldos a mano) debe hacerse con la app cerrada, o ejecutar antes `PRAGMA wal_checkpoint(TRUNCATE)`, o copiar los tres archivos juntos.
- `GoogleDriveController::reemplazarBase()` (restaurar / deshacer) hace el checkpoint antes de reemplazar y **aborta** con "Hay otro dispositivo usando la base de datos…" si no puede (otra conexión activa); luego `DB::disconnect()`, borra `-wal`/`-shm` y renombra con 10 reintentos × 300 ms (Windows).
- El archivo debe estar en un disco local; WAL no funciona sobre carpetas de red.

## 6.3. Acceso remoto desde otro dispositivo de la red local (iPad)

**Idea.** La app de escritorio debe seguir abierta en la PC; el iPad abre `http://<ip-de-la-PC>:8712` en Safari. Por defecto está **apagado** y nada escucha fuera de la PC. Se enciende con un interruptor en la pantalla "Acceso remoto" (solo visible desde la PC).

**Dos Caddy independientes sobre los mismos `php-cgi`** (`vigilar_servidor` / `remoto.rs`):
- Caddy principal: `127.0.0.1:<aleatorio>`, el de siempre, **nunca se reinicia** por esto.
- Caddy LAN: existe solo mientras el acceso remoto está encendido; `bind <IP privada de la PC>` + puerto fijo **8712** (si está ocupado prueba los 10 siguientes), Caddyfile `Caddyfile-remoto` y datos propios `caddy-data-remoto`. `bind <ip>` es obligatorio (sin él Caddy escucha en todas las interfaces). La IP se detecta con el truco de UDP-connect y solo se acepta si es RFC1918 (no 169.254.x.x).

**Canal Laravel ↔ Rust**: archivos en `<php>/tmp/control` (Laravel lo recibe en `DV_CONTROL_DIR`), escritos de forma atómica (tmp + rename):

| Archivo | Dirección | Contenido |
|---|---|---|
| `boot_id` | Rust → Laravel | Identificador de **esta** ejecución de la app; las autorizaciones de dispositivos quedan atadas a él |
| `remoto.json` | Laravel → Rust | `{habilitado, puerto}` — lo que pide el interruptor |
| `remoto-estado.json` | Rust → Laravel | Estado real (IP, puerto, firewall, error). Latido cada 5 s; más viejo de 15 s = "no disponible" |
| `accion.json` | Laravel → Rust | `{id, accion}`: `crear_regla_firewall` / `verificar_firewall` |
| `alerta.json` | Laravel → Rust | Contador de solicitudes nuevas → Rust parpadea la barra de tareas, trae la ventana al frente y suena |

Se limpia todo al arrancar: **cada vez que abres la app el acceso remoto vuelve a estar apagado** y nadie queda autorizado. Sin `DV_CONTROL_DIR` (p. ej. `php artisan serve` en desarrollo) un equipo remoto **nunca** pasa.

**Candado (emparejamiento con código)**
1. El iPad abre la URL → `/remoto/acceso`, escribe un nombre y pide acceso.
2. La PC muestra un **modal** (+ parpadeo, ventana al frente, sonido) con un código de **6 dígitos por dispositivo**.
3. Se teclea el código en el iPad. **5 intentos máximo por código**; se puede generar otro. El código caduca a los 5 min.
4. Si es correcto, el iPad recibe la cookie cifrada `dv_dispositivo` (token de 64 hex; en BD solo se guarda su sha256). Dura **mientras la app esté abierta**: otro `boot_id` la invalida.
5. La PC (`REMOTE_ADDR` 127.0.0.1) entra directo, sin código. **Solo se mira `REMOTE_ADDR`**; `X-Forwarded-For` se ignora (sería falsificable).

Límites (`RateLimiter`, por IP): solicitar acceso 5 / 10 min, regenerar código 3 / min, fallos de código 15 / 10 min; máximo 5 solicitudes pendientes a la vez. Dispositivos autorizados se listan y se pueden **revocar** (expulsa al instante); apagar el interruptor expulsa a todos.

Middleware: `ControlAcceso` (global, grupo web) deja pasar a la PC; a un remoto solo con la función activa **y** cookie válida (`remoto.*` queda accesible sin cookie; las peticiones `fetch` de navegación reciben 401 para forzar recarga completa). `SoloLocal` (`solo.local`) protege la administración del acceso remoto y las rutas peligrosas de Drive. Los nombres que escribe un dispositivo remoto se pintan siempre con `textContent` / `@json` (XSS almacenado).

**Respaldo (Drive) desde remoto: solo lectura.** Se permite "Respaldar ahora"; conectar/desconectar cuenta, restaurar y deshacer están bloqueados (403) y ocultos con aviso.

**Firewall.** Un botón en la pantalla crea la regla con permiso de administrador (UAC): `netsh advfirewall firewall add rule … program=<caddy.exe> profile=private,domain remoteip=localsubnet` (nombre con prefijo sha256 de la ruta de caddy). Nunca perfil público ni internet. Si la red de Windows está marcada como **Pública**, la regla no aplica y la pantalla lo avisa.

**LECCIÓN — antivirus.** Una versión de prueba lanzaba `powershell -EncodedCommand` (comprobar firewall) y AVG puso `DistriVale.exe` en **cuarentena** (`IDP.HELU.PSE92`) ~10 s después de encender el acceso. **Está prohibido usar PowerShell** en la app: la verificación usa `netsh … show rule` (código de salida 0 = existe; no depende del idioma) y `netsh advfirewall show currentprofile` (encabezado es/en); la elevación usa `ShellExecuteExW` con verbo `runas` sobre `netsh.exe`. Una prueba (`el_proyecto_ya_no_lanza_powershell`) falla si reaparece. El exe sin firmar seguirá siendo sospechoso para algunos antivirus: conviene firmarlo antes de repartirlo.

**Suspensión.** Mientras el acceso está encendido, el supervisor llama a `SetThreadExecutionState(ES_CONTINUOUS | ES_SYSTEM_REQUIRED)` para que Windows no duerma la PC (la pantalla sí puede apagarse). Cerrar la tapa de una laptop sigue durmiéndola según la política de energía.

**HTTP plano.** Sin cifrado: dentro de la red local cualquiera con acceso al Wi-Fi podría ver el tráfico (los datos de clientes viajan en claro). Decisión consciente; usar solo en la red del negocio, nunca en Wi-Fi público.

**Pendiente.** Pasada de UI/táctil para el viewport del iPad (con el iPad real); validar el botón de firewall (UAC) y el parpadeo/sonido en una instalación real; probar con otros antivirus (McAfee/Defender).

## 6.4. Interfaz en celulares (< 600px) y "jalar para actualizar"

**Archivos:** `public/css/dv-mobile.css`, `public/js/dv-mobile.js` (ambos con `?v=filemtime` en el layout), la barra inferior + hoja "Más" en `layouts/app.blade.php`, y `DvNav.refresh()` en `dv-nav.js`. De 600px hacia arriba (iPad, PC) la app se ve **igual que antes** — verificado comparando píxel a píxel contra el commit anterior.

**Cuatro categorías** por ancho CSS en vertical (lo que reporta el navegador, no los píxeles físicos). `dv-mobile.js` pone `data-pantalla` en `<html>`:

| Categoría | Ancho CSS | Referencias | Margen | Toque mín. | Barra inf. | Texto |
|---|---|---|---|---|---|---|
| Compacto | ≤ 374 | Galaxy S25/S26 base 360, serie A, Redmi Note 360, plegables cerrados 344 | .7rem | 44px | 60px | 100% |
| Estándar | 375–399 | iPhone SE 375, iPhone 15/16 393 | .85rem | 44px | 62px | 100% |
| Grande | 400–429 | iPhone 16 Pro/17 402, Pixel 9/10 412, Galaxy S25/S26 Ultra 412, Redmi Turbo 4 Pro / POCO F7 ≈427 | 1rem | 46px | 64px | 103% |
| Extra grande | 430–599 | iPhone Plus/Pro Max 430–440, POCO F7 Pro 480 | 1.15rem | 48px | 68px | 106% |

La escala de texto va en **porcentaje** (no en px) para respetar el tamaño de fuente que la persona fijó en el teléfono. Los cortes de la tabla deben coincidir en el CSS y en `categoria()` del JS.

**Qué cambia en celular:**
- La sidebar se oculta; barra inferior flotante con Inicio · Clientes · Vales · Cobranza · **Más** (hoja inferior con Financieras, Liquidación, Respaldo y —solo en la PC— Acceso remoto; en remoto, "Desconectar"). `dv-nav.js` sincroniza el elemento activo y la hoja se cierra al navegar. Con el teclado abierto la barra se esconde (`html.dv-kb`).
- Encabezado: título + campana + avatar en una fila y los botones de acción debajo, a todo el ancho; ya no es `sticky`.
- **Las tablas se convierten en tarjetas** (una por fila): `dv-mobile.js` copia el texto de cada `<th>` a un `data-label` en cada `<td>`. **Toda tabla nueva debe tener `<thead>` con encabezados** o saldrá sin etiquetas. La primera columna es el título de la tarjeta si su encabezado dice Cliente/Nombre/Vale/Financiera; la última (Acciones o sin título) son los botones. La matriz de Liquidación (`.dv-matrix-scroll`) sigue con scroll lateral. Solo se etiqueta con ancho de celular: en PC/iPad el DOM de las tablas no se toca.
- Formularios: una columna (`form .row > [class*="col-"]`), porque las vistas usan `col-6`/`col-4` sin punto de quiebre y a 360px quedaban campos de ~150px.
- Inicio: las 4 cifras en renglones, una por línea (el saldo, p. ej. `$5,796,205.55`, no cabe en media tarjeta en ningún celular < 450px).
- Meta viewport **sin `user-scalable=no`** (se puede pellizcar para ampliar) y con `viewport-fit=cover`; `theme-color`; `text-size-adjust: 100%` (Samsung Internet y el navegador de Xiaomi inflan el texto si no).

**Jalar para actualizar** (también en iPad; solo si hay pantalla táctil): `.dv-main` es el contenedor que scrollea (el `<body>` no), así que el navegador no lo ofrece solo; `overscroll-behavior-y: contain` evita además el de Chrome/Samsung. Reglas del gesto: solo si el scroll está arriba del todo, un solo dedo, dirección vertical hacia abajo (si es de lado, como en una tabla ancha, se ignora), no sobre modales/hojas/campos (`data-no-ptr` para excluir otros), no mientras ya actualiza; umbral ≈ 7.5% del alto de pantalla (56–72px) con resistencia 0.55; vibra al cruzar el umbral y al terminar (donde el navegador lo permita; iOS no). Refresca con `DvNav.refresh()` (misma ruta que un clic en un enlace, sin recarga completa; devuelve una promesa). **Si hay un formulario con cambios sin guardar** (`form[data-dv-sucio]`, marcado al escribir en formularios no-GET) **no refresca** y avisa.

**Aviso "Sin conexión con la computadora"** (`public/js/dv-conexion.js` + `public/dv-ping.txt`; solo en dispositivos remotos: `<body data-remoto="1">`, en la PC no hace nada). Un latido cada 10s a `dv-ping.txt` —un archivo estático que sirve el propio Caddy, así que si la PC cierra DistriVale deja de responder— con timeout de 4s; tras un latido perdido se confirma a los 2s y con dos seguidos se muestra el banner (~13s en el peor caso; el evento `offline` del sistema y los fallos de red de `dv-nav.js` lo muestran al instante). Mientras está caído: tocar una pestaña **no** manda a la página de error del navegador (se queda en la pantalla actual), **enviar un formulario se detiene y conserva lo escrito** (se vuelve a comprobar en ese momento por si la red ya regresó) y jalar para actualizar avisa en vez de decir "Actualizado". Al volver la conexión: banner verde y refresco silencioso (salvo formulario con cambios sin guardar). `DvNav.refresh()` devuelve `true`/`false`; los errores de red se marcan con `err.dvRed` para no confundirlos con un fallo al armar la pantalla (que sigue cayendo a la navegación completa). Límite: `dv-confirm.js` reenvía con `form.submit()` (no dispara el evento `submit`), así que si la red cae justo *entre* abrir el modal de confirmación y aceptarlo, ese envío no pasa por la protección (el primer `submit`, antes del modal, sí queda detenido cuando ya se sabe que no hay conexión).

**Pairing (`remoto/acceso`)**: el campo del código de 6 dígitos se recortaba a ≤ 360px; ahora el tamaño y el espaciado escalan con el ancho (`clamp`).

**Cómo se probó** (sin teléfono): Edge headless con `puppeteer-core` emulando viewport móvil + táctil (`isMobile`, `hasTouch`) y toques reales (`page.touchscreen`). No usar `php artisan serve` para eso: el bloqueo de `php -S` (§6.1) deja la página en la pantalla de bienvenida durante segundos; levantar Caddy + `php-cgi` como la app. Comparación de PC/iPad contra el commit anterior con `git worktree` + `pixelmatch`.

**No cubierto / limitaciones conocidas:** la barra de direcciones del navegador móvil **no se esconde** al hacer scroll porque scrollea `.dv-main` y no el documento (a cambio se conserva el diseño actual); pasar a scroll del documento en celular lo arreglaría pero toca `dv-motion`, el fondo `fixed` y el `sticky`. Tampoco hay manifest/PWA (por HTTP plano Android no ofrece "instalar"; iOS sí respeta el modo pantalla completa al "Añadir a inicio").

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
