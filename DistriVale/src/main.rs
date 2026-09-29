// Sin esto, el binario se linkea como app de consola: al abrir DistriVale
// aparece de fondo una ventana de terminal (y su propio ícono en la
// barra de tareas) además de la ventana de la app. Solo se desactiva en
// release — en debug (`cargo run`) conviene conservarla para ver
// println!/errores de arranque mientras se desarrolla.
#![cfg_attr(not(debug_assertions), windows_subsystem = "windows")]

// Versión wireframe (v0.2.3): sin licenciamiento ni conexión a base de
// datos real. Este shell:
//   1. Arranca `php artisan serve` sobre DistriValeWeb/ en un puerto local libre.
//   2. Espera a que el servidor responda.
//   3. Navega la ventana principal (que arranca en dist/index.html, una
//      pantalla de carga) hacia http://127.0.0.1:<puerto>.
//   4. Al cerrar la ventana, mata el proceso de PHP.
//
// DistriValeWeb usa datos de ejemplo guardados en sesión (ver
// App\Support\Wireframe\Store del lado PHP) en vez de SQLite — nunca
// persiste nada en disco, se reinicia solo en cada arranque.

use std::net::TcpStream;
use std::path::PathBuf;
use std::process::{Child, Command, Stdio};
use std::sync::Mutex;
use std::time::{Duration, Instant};

use tauri::webview::DownloadEvent;
use tauri::{AppHandle, Manager, Url, WebviewWindowBuilder, WindowEvent};

#[cfg(target_os = "windows")]
use std::os::windows::process::CommandExt;
#[cfg(target_os = "windows")]
const CREATE_NO_WINDOW: u32 = 0x0800_0000;

/// Proceso de `php artisan serve` en ejecución, para poder matarlo al
/// cerrar la ventana. `None` hasta que el servidor arranca con éxito.
struct PhpServer(Mutex<Option<Child>>);

fn find_free_port() -> u16 {
    std::net::TcpListener::bind("127.0.0.1:0")
        .and_then(|listener| listener.local_addr())
        .map(|addr| addr.port())
        .unwrap_or(8712)
}

/// Ubica la app Laravel probando, en orden: (1) un build empaquetado de
/// verdad, donde vive junto al ejecutable como `resources/app` (pendiente:
/// sidecar de PHP portable, ver ARQUITECTURA_TAURI.md §5); (2) una copia
/// portable — el .exe con una carpeta `DistriValeWeb/` hermana, sin importar
/// en qué carpeta se haya copiado el par — resuelta en tiempo de EJECUCIÓN
/// vía `current_exe()`, a diferencia de (3); (3) la carpeta hermana del
/// repositorio en desarrollo, resuelta vía `CARGO_MANIFEST_DIR` en tiempo de
/// COMPILACIÓN — solo funciona en esta máquina, en esta ruta exacta.
fn resolve_webapp_dir(app: &AppHandle) -> Option<PathBuf> {
    if let Ok(resource_dir) = app.path().resource_dir() {
        let packaged = resource_dir.join("app");
        if packaged.join("artisan").exists() {
            return Some(packaged);
        }
    }

    if let Ok(exe_path) = std::env::current_exe() {
        if let Some(exe_dir) = exe_path.parent() {
            let portable = exe_dir.join("DistriValeWeb");
            if portable.join("artisan").exists() {
                return Some(portable);
            }
        }
    }

    let dev_dir = PathBuf::from(env!("CARGO_MANIFEST_DIR"))
        .join("..")
        .join("DistriValeWeb");
    if dev_dir.join("artisan").exists() {
        return Some(dev_dir);
    }

    None
}

fn spawn_php_server(webapp_dir: &PathBuf, port: u16) -> std::io::Result<Child> {
    let mut cmd = Command::new("php");
    cmd.arg("artisan")
        .arg("serve")
        .arg("--host=127.0.0.1")
        .arg(format!("--port={port}"))
        .current_dir(webapp_dir)
        // El servidor embebido de PHP es de un solo hilo por defecto: una
        // pantalla como el dashboard dispara varias peticiones a la vez
        // (CSS, iconos, bootstrap.js, chart.js...) y sin esto algunas se
        // cortan a medio cargar (ERR_CONNECTION_RESET), dejando por ejemplo
        // la gráfica sin dibujarse porque Chart.js nunca llegó a definirse.
        // Varios workers dejan atender peticiones en paralelo.
        .env("PHP_CLI_SERVER_WORKERS", "8")
        .stdout(Stdio::null())
        .stderr(Stdio::null());

    #[cfg(target_os = "windows")]
    cmd.creation_flags(CREATE_NO_WINDOW);

    cmd.spawn()
}

/// Antes esto sólo hacía un `TcpStream::connect` y daba el servidor por
/// listo en cuanto el socket aceptaba la conexión — pero el socket de PHP
/// queda escuchando un instante antes de que el propio intérprete esté listo
/// para atender una petición real. Ganar esa carrera dejaba a la primera
/// navegación (la única que hace esta ventana) recibiendo una respuesta
/// vacía o cortada, y como WebView2 no reintenta una navegación de nivel
/// superior, la ventana se quedaba en blanco para siempre. Ahora se manda un
/// GET real y sólo se da por listo el servidor cuando responde con una
/// línea de estado HTTP válida.
fn wait_for_server(port: u16, timeout: Duration) -> bool {
    use std::io::{Read, Write};

    let deadline = Instant::now() + timeout;
    while Instant::now() < deadline {
        if let Ok(mut stream) = TcpStream::connect(("127.0.0.1", port)) {
            let _ = stream.set_read_timeout(Some(Duration::from_secs(2)));
            let _ = stream.set_write_timeout(Some(Duration::from_secs(2)));

            let request = format!("GET / HTTP/1.1\r\nHost: 127.0.0.1:{port}\r\nConnection: close\r\n\r\n");
            if stream.write_all(request.as_bytes()).is_ok() {
                let mut buf = [0u8; 32];
                if let Ok(n) = stream.read(&mut buf) {
                    if buf[..n].starts_with(b"HTTP/") {
                        return true;
                    }
                }
            }
        }
        std::thread::sleep(Duration::from_millis(200));
    }
    false
}

/// Cada descarga (recibos, liquidaciones y clientes en PDF) pregunta dónde
/// guardar el archivo con el diálogo nativo "Guardar como" de Windows, en
/// vez de guardarlo automáticamente en la carpeta de Descargas por defecto.
/// Cancelar el diálogo cancela la descarga (WebView2 no intenta guardarla
/// en ningún lado).
fn handle_download(_webview: tauri::Webview, event: DownloadEvent) -> bool {
    match event {
        DownloadEvent::Requested { destination, .. } => {
            let suggested_name = destination
                .file_name()
                .map(|n| n.to_string_lossy().into_owned())
                .unwrap_or_else(|| "descarga".to_string());

            // Sin filtro, el diálogo de Windows guarda el nombre tal cual lo
            // escribe la usuaria: si lo cambia, el PDF queda sin extensión y
            // el Explorador lo muestra como "Archivo" en vez de documento PDF.
            let extension = std::path::Path::new(&suggested_name)
                .extension()
                .map(|e| e.to_string_lossy().to_lowercase());

            let mut dialog = rfd::FileDialog::new().set_file_name(&suggested_name);
            if let Some(ext) = &extension {
                let descripcion = match ext.as_str() {
                    "pdf" => "Documento PDF".to_string(),
                    otra => format!("Archivo {}", otra.to_uppercase()),
                };
                dialog = dialog.add_filter(descripcion, &[ext.as_str()]);
            }
            if let Some(dir) = destination.parent() {
                dialog = dialog.set_directory(dir);
            }

            match dialog.save_file() {
                Some(mut path) => {
                    if let Some(ext) = &extension {
                        let ya_la_tiene = path
                            .extension()
                            .is_some_and(|e| e.to_string_lossy().eq_ignore_ascii_case(ext));
                        if !ya_la_tiene {
                            // Se agrega en vez de reemplazar: un nombre como
                            // "reporte 15.09" no debe perder el ".09".
                            let mut nombre = path.file_name().unwrap_or_default().to_os_string();
                            nombre.push(format!(".{ext}"));
                            path.set_file_name(nombre);
                        }
                    }
                    *destination = path;
                    true
                }
                None => false, // el usuario canceló el diálogo: no se descarga nada
            }
        }
        _ => true,
    }
}

/// Reemplaza la pantalla de carga con un mensaje de error legible cuando
/// el sidecar de PHP no pudo arrancar, en vez de dejar la ventana en blanco.
fn show_startup_error(app: &AppHandle, message: &str) {
    if let Some(window) = app.get_webview_window("main") {
        let escaped = message.replace('\\', "\\\\").replace('`', "\\`");
        let script = format!(
            "document.body.innerHTML = `<div style=\"font-family:sans-serif;color:#fff;padding:2rem;max-width:520px;margin:0 auto;text-align:center\">\
             <h2 style=\"color:#ff8fa3\">No se pudo iniciar DistriVale</h2><p>{escaped}</p></div>`;"
        );
        let _ = window.eval(&script);
    }
}

/// Arranca `php artisan serve` y navega el WebView ahí.
fn iniciar_app_principal(app_handle: AppHandle) {
    let Some(webapp_dir) = resolve_webapp_dir(&app_handle) else {
        show_startup_error(
            &app_handle,
            "No se encontró la aplicación Laravel (DistriValeWeb/artisan).",
        );
        return;
    };

    let port = find_free_port();

    let child = match spawn_php_server(&webapp_dir, port) {
        Ok(child) => child,
        Err(e) => {
            show_startup_error(
                &app_handle,
                &format!("No se pudo iniciar PHP ({e}). ¿Está PHP instalado y en el PATH?"),
            );
            return;
        }
    };

    if let Some(state) = app_handle.try_state::<PhpServer>() {
        *state.0.lock().unwrap() = Some(child);
    }

    if !wait_for_server(port, Duration::from_secs(15)) {
        show_startup_error(&app_handle, "El servidor de Laravel no respondió a tiempo.");
        return;
    }

    let url = format!("http://127.0.0.1:{port}");
    let navigate_handle = app_handle.clone();
    let _ = app_handle.run_on_main_thread(move || {
        if let (Some(window), Ok(parsed)) =
            (navigate_handle.get_webview_window("main"), Url::parse(&url))
        {
            let _ = window.navigate(parsed);
        }
    });
}

fn main() {
    tauri::Builder::default()
        .manage(PhpServer(Mutex::new(None)))
        .setup(|app| {
            // Construida acá en vez de dejar que tauri.conf.json la cree
            // sola (esa entrada ahora tiene "create": false, pero sus demás
            // propiedades — tamaño, título, etc. — se siguen usando tal
            // cual vía from_config) porque on_download solo se puede
            // engancharse al momento de construir la ventana.
            WebviewWindowBuilder::from_config(app.handle(), &app.config().app.windows[0])?
                .on_download(handle_download)
                .build()?;

            let app_handle = app.handle().clone();

            std::thread::spawn(move || {
                // Le da tiempo al WebView de terminar de cargar el splash
                // inicial antes de navegar — sin esto, la primera
                // navegación puede correr contra una página que todavía no
                // existe y quedarse sin efecto.
                std::thread::sleep(Duration::from_millis(400));
                iniciar_app_principal(app_handle);
            });

            Ok(())
        })
        .on_window_event(|window, event| {
            if let WindowEvent::CloseRequested { .. } = event {
                if let Some(state) = window.app_handle().try_state::<PhpServer>() {
                    if let Some(mut child) = state.0.lock().unwrap().take() {
                        let _ = child.kill();
                    }
                }
            }
        })
        .run(tauri::generate_context!())
        .expect("error running DistriVale");
}
