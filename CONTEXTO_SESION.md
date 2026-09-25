# DistriVale — Contexto de sesión

> Resumen denso de una sesión larga de trabajo, pensado para cargar contexto rápido en una conversación nueva (pegarlo, o simplemente decir "leé CONTEXTO_SESION.md"). Contiene decisiones y bugs reales encontrados — no repetirlos.

## Qué es el proyecto

App de escritorio Windows para gestión de créditos/distribución (clientes, vales/créditos, financieras, cobranza/recibos, liquidaciones quincenales). Arquitectura: **Tauri (Rust) como shell nativo + Laravel 13/PHP 8.5 (toda la lógica de negocio) + SQLite**. Tauri arranca `php artisan serve` como subproceso y navega una ventana WebView2 hacia él — Tauri no reemplaza a Laravel, solo lo empaqueta. Documento de arquitectura completo y actualizado: `ARQUITECTURA_TAURI.md`. Dos carpetas: `DistriVale/` (crate Rust/Tauri) y `DistriValeWeb/` (app Laravel).

## Shell Tauri (`DistriVale/`)

- Proyecto Tauri real y funcional (antes era un `cargo new` vacío): `tauri.conf.json`, `build.rs`, `icons/`, ventana construida **en código** dentro de `src/main.rs` (`WebviewWindowBuilder::from_config(...)`, no declarativa vía config) — necesario para poder engancharle `.on_download(...)`.
- `main.rs`: arranca PHP en un puerto libre, espera a que responda, navega la ventana; mata el proceso PHP al cerrar; si algo falla en el arranque, reemplaza la pantalla de carga con un mensaje de error legible en vez de dejar la ventana en blanco.
- **Descargas (PDFs) piden "Guardar como"**: hook `on_download` de Tauri + crate `rfd` (diálogo nativo de Windows). Cancelar el diálogo cancela la descarga entera.
- **Splash screens** (`DistriVale/dist/index.html` y `#dv-preloader` en `layouts/app.blade.php`, deben mantenerse visualmente sincronizados): estilo Disney+/Netflix — fondo oscuro + resplandor + ícono SVG inline (círculo + "$") + wordmark "DISTRIVALE". Animación: fade+scale de entrada, luego pulso de brillo lento continuo, respeta `prefers-reduced-motion`.
  - **Bug real, confirmado 2 veces en la app empaquetada de verdad (no solo en pruebas)**: `radial-gradient()` en WebView2/Chromium deja una costura vertical visible — pasa incluso con todos los stops 100% opacos, no tiene que ver con transparencia. **Evitar `radial-gradient` para viñetas/resplandores en este proyecto.** Solución que sí funciona: fondo sólido + `filter: blur(140px)` sobre un `div`/`::before` circular.
- Ventana: `minWidth: 860, minHeight: 600` (bajado desde 1024×700 una vez que el sidebar quedó colapsable).

## Frontend Laravel: navegación "SPA-lite" sin recargar la página completa

- `public/js/dv-nav.js`: intercepta clics en `<a>` internos, hace `fetch()` de la página completa del destino, y reemplaza **solo** `#dv-view` (contenido central), `.dv-title`/`.dv-subtitle`, `#dv-topbar-actions` y la clase `.active` del sidebar — deja intacto `<head>` (CSS/fuentes/íconos ya cargados), Bootstrap JS y Chart.js. NO intercepta: `<form>` (submits normales), links a otro origen, `target=_blank`, ni descargas (pdf/xlsx/csv/zip/docx — se detectan por extensión y se dejan navegar normal).
- Eventos custom propios: `dv:nav-start` (antes de tocar el DOM, para que widgets como tooltips se limpien) y `dv:nav-swapped` (después del swap, para reinicializarlos).
- `public/js/dv-ui.js`: inicializa/destruye tooltips de Bootstrap (`[data-bs-toggle=tooltip]`) enganchado a esos eventos.
- **Bug real corregido**: la primera versión de `dv-nav.js` reemplazaba **todo** el innerHTML del sidebar en cada navegación (para mover la clase `.active`) → destruía los `<a>` que tenían una tooltip de Bootstrap activa → la burbuja de la tooltip (que Bootstrap cuelga como hijo de `<body>`, no del trigger) quedaba huérfana en pantalla para siempre, acumulándose en cada navegación. **Fix**: nunca reemplazar el innerHTML del sidebar — solo mover la clase `.active` entre los `<a>` que ya existen, comparando por `href`.
- Barra de progreso fina (`#dv-progress`) siempre visible durante el fetch; spinner+dim sobre `#dv-view` (`.dv-nav-slow`) que solo aparece si la carga tarda **más de 250ms**, para que un clic normal nunca parpadee ningún estado de carga.
- Placeholders tipo esqueleto (`.placeholder-glow` de Bootstrap) en la búsqueda en vivo de la tabla de clientes, mismo umbral de 250ms.

## Theming / CSS

Todo vive en el único bloque `<style>` de `resources/views/layouts/app.blade.php` (no hay build step de CSS para esta parte de la app).

- **Todo vendorizado localmente en `public/vendor/`** (Bootstrap, Bootstrap Icons + fuentes woff2, Inter variable font, Chart.js) — **cero CDN**, porque la app corre offline dentro de Tauri y un CDN roto deja la UI sin estilos.
- Chart.js se carga **una sola vez** en el shell persistente (no por página) — si se cargara por página, cada navegación in-place lo re-ejecutaría innecesariamente.
- Sidebar responsivo puro-CSS vía la custom property `--dv-sidebar-w`: ≥1200px completo con etiquetas; <1200px colapsa a riel de íconos (72px, con tooltips reemplazando las etiquetas); <860px además oculta nombre/rol del usuario en el topbar.
- Tipografía fluida con `clamp()` en vez de saltos de tamaño fijos entre breakpoints.
- Animación "Liquid Glass" en botones (`.btn`, sidebar, chips de vidrio): `:active` aplica `transform: scale()`, `border-radius` mayor, `backdrop-filter` más intenso/oscuro; easing `cubic-bezier(.34,1.56,.64,1)` (con rebote/overshoot) en ambas direcciones; respeta `prefers-reduced-motion`.
- Texto: todo en negro (`#000`) excepto verde/rojo de saldos (`.text-success`/`.text-danger` nativos de Bootstrap) — pedido explícito de contraste del usuario.
- **Bug de contraste real corregido**: `.form-select-sm` tenía `padding: Y X` (shorthand simétrico) que pisaba el padding-derecho que Bootstrap reserva para la flechita del `<select>` (normalmente 2.25rem) → el texto largo se superponía con la flecha. Solo afectaba a la pantalla de Vales (única que usaba esa variante `-sm`). Fix: regla separada para `.form-select-sm` con `padding-right: 2.25rem` explícito.
- Tabla matriz de Liquidación (la más ancha del sistema): primera columna "Cliente"/"TOTALES" con `position: sticky` para que no se pierda de vista al hacer scroll horizontal.

## Optimización backend (Laravel)

- **N+1 corregido** en `LiquidacionController::construirMatriz()`: antes, una query `whereHas`+`sum` por cada combinación cliente×financiera dentro de loops anidados. Ahora: una sola query agregada (`GROUP BY id_vale`) ejecutada antes de los loops, resultado indexado en un array. Verificado sembrando 30 clientes×5 financieras en una transacción de prueba (con rollback): 161 queries → 11.
- Reportes PDF (dompdf) restylizados con tema visual compartido: `resources/views/pdf/_theme.blade.php` (parcial reusable, clases `.pdf-card`, `.pdf-title-bar`, `.pdf-table-card`, `.pdf-badge-*`) — **reusar este parcial para cualquier PDF nuevo** en vez de estilos ad-hoc.

## Feature: Respaldo a Google Drive (código completo, falta configurar credenciales)

Pantalla "Respaldo" en el sidebar (`/drive`). Modelo manual (no sincronización en tiempo real ni edición simultánea) — pensado como "guardar en la nube" / "traer de la nube" a demanda.

- **Conectar**: OAuth2 + PKCE, scope mínimo `https://www.googleapis.com/auth/drive.file` (la app solo ve archivos que ella misma crea, nunca el resto del Drive del usuario). Abre el **navegador del sistema** vía `exec('start "" url')` — nunca la ventana embebida, Google bloquea el login OAuth dentro de un WebView (`disallowed_useragent`). El callback es una ruta propia de la misma app Laravel (`/drive/callback`); Google permite automáticamente cualquier puerto en `127.0.0.1`/`localhost` para clientes tipo "Desktop app", así que el puerto dinámico de `php artisan serve` no es problema.
- **Respaldar**: `VACUUM INTO` de SQLite (snapshot consistente sin copiar el archivo "en caliente") → sube reemplazando siempre el mismo archivo en Drive (aprovecha gratis el historial de versiones nativo de Drive en vez de acumular archivos sueltos).
- **Restaurar**: descarga a archivo temporal primero → valida integridad (header real de SQLite + `PRAGMA integrity_check`) antes de tocar nada → guarda snapshot de seguridad de la base **actual** (fuera de `database/`, para que sobreviva al propio swap) → `rename()` atómico sobre `database.sqlite`.
- **"Devolver cambios de la db" (rollback)**: un solo nivel de deshacer — restaura el snapshot pre-restauración guardado en el paso anterior, con confirmación explícita (avisa que se pierden cambios post-restauración).
- Tokens (access/refresh) **encriptados en la BD** vía el cast `encrypted` de Laravel (AES-256 con `APP_KEY`).
- Archivos: `app/Services/GoogleDriveService.php` (llamadas REST puras a Drive v3 vía `Http` facade, sin SDK de Google), `app/Http/Controllers/GoogleDriveController.php`, `app/Models/GoogleDriveToken.php`, migración `2026_09_25_090420_create_google_drive_tokens_table`, vistas `resources/views/respaldo/`.
- **Pendiente real para el usuario** (documentado en `ARQUITECTURA_TAURI.md` §7): crear un cliente OAuth gratis tipo "Desktop app" en Google Cloud Console (Drive API habilitada, consent screen en modo Testing, agregarse como test user) y pegar `GOOGLE_DRIVE_CLIENT_ID`/`GOOGLE_DRIVE_CLIENT_SECRET` en `.env`. Sin esas variables, la pantalla muestra un aviso claro y el botón queda deshabilitado — el resto de la app funciona igual.
- **Pendiente documentado** (`ARQUITECTURA_TAURI.md` §7.1): antes de distribuir a más de ~100 negocios, publicar la pantalla de consentimiento OAuth a **"In production"** — gratis, no dispara la revisión de seguridad de Google porque `drive.file` es un scope "no sensible"; solo pide nombre/logo/correo de soporte/URL de política de privacidad (alcanza una página estática gratuita tipo GitHub Pages).

## Gotchas del entorno de pruebas (no son bugs de la app)

- `php artisan serve` es de un solo hilo — bajo pruebas automatizadas rápidas (Playwright headless lanzando varias páginas seguidas) se traba o tarda mucho; reiniciar el servidor lo resuelve. No confundir con un bug real (pasó varias veces en esta sesión y generó falsas alarmas).
- Capturar la ventana real de Tauri por PID vía PowerShell+Win32 (`SetForegroundWindow`+`CopyFromScreen`) es poco confiable en este sandbox — a veces termina capturando otra ventana superpuesta (VS Code, el Explorador) en vez de la app. Cuando la verificación visual importa de verdad, mejor abrir la misma vista Laravel con Playwright+Edge contra `php artisan serve` — mismo motor Chromium que WebView2, mucho más confiable de automatizar acá. Para bugs específicos de WebView2 (como el del `radial-gradient`), sí hace falta confirmar en la app empaquetada real tarde o temprano.

## Convenciones establecidas en esta sesión

- Todo en español: código de negocio, comentarios, UI, mensajes de commit.
- Comentarios solo para el "por qué" no obvio (decisiones, workarounds, bugs evitados) — nunca describiendo qué hace el código.
- Cero dependencias nuevas de JS/CDN — todo vendorizado en `public/vendor/` o JS vanilla propio en `public/js/`.
- Verificar cambios visuales con Playwright+Edge real cuando es posible — no confiar solo en lectura de código para cosas de layout/render.
- Sidebar: para agregar una pantalla nueva, replicar el patrón de los `<a>` existentes en `dv-sidebar-nav` (icono + `<span class="dv-label">` + `title=` + `data-bs-toggle="tooltip"`).
