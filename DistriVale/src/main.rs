// Ver ARQUITECTURA_TAURI.md para el flujo completo. Este shell:
//   1. Arranca `php artisan serve` sobre DistriValeWeb/ en un puerto local libre.
//   2. Espera a que el servidor responda.
//   3. Navega la ventana principal (que arranca en dist/index.html, una
//      pantalla de carga) hacia http://127.0.0.1:<puerto>.
//   4. Al cerrar la ventana, mata el proceso de PHP.
//
// Empaquetado final (PHP portable embebido, copia de DistriValeWeb sin
// .env/vendor de dev, etc.) es trabajo pendiente — ver sección 5 del
// documento de arquitectura. Esta versión ya es utilizable para desarrollo
// y para correr la app como ventana nativa apuntando al PHP del sistema.

mod licensing;

use std::net::TcpStream;
use std::path::PathBuf;
use std::process::{Child, Command, Stdio};
use std::sync::Mutex;
use std::time::{Duration, Instant};

use tauri::{AppHandle, Manager, Url, WindowEvent};

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

/// Ubica la app Laravel: en un build empaquetado vive junto al ejecutable
/// como `resources/app`; en desarrollo es la carpeta hermana `DistriValeWeb/`
/// del repositorio (resuelta vía `CARGO_MANIFEST_DIR` en tiempo de compilación).
fn resolve_webapp_dir(app: &AppHandle) -> Option<PathBuf> {
    if let Ok(resource_dir) = app.path().resource_dir() {
        let packaged = resource_dir.join("app");
        if packaged.join("artisan").exists() {
            return Some(packaged);
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
        .stdout(Stdio::null())
        .stderr(Stdio::null());

    #[cfg(target_os = "windows")]
    cmd.creation_flags(CREATE_NO_WINDOW);

    cmd.spawn()
}

fn wait_for_server(port: u16, timeout: Duration) -> bool {
    let deadline = Instant::now() + timeout;
    while Instant::now() < deadline {
        if TcpStream::connect(("127.0.0.1", port)).is_ok() {
            return true;
        }
        std::thread::sleep(Duration::from_millis(200));
    }
    false
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

fn main() {
    tauri::Builder::default()
        .manage(PhpServer(Mutex::new(None)))
        .invoke_handler(tauri::generate_handler![
            licensing::activate_license,
            licensing::check_saved_license,
        ])
        .setup(|app| {
            let app_handle = app.handle().clone();

            std::thread::spawn(move || {
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
                    show_startup_error(
                        &app_handle,
                        "El servidor de Laravel no respondió a tiempo.",
                    );
                    return;
                }

                let url = format!("http://127.0.0.1:{port}");
                let navigate_handle = app_handle.clone();
                let _ = app_handle.run_on_main_thread(move || {
                    if let (Some(window), Ok(parsed)) = (
                        navigate_handle.get_webview_window("main"),
                        Url::parse(&url),
                    ) {
                        let _ = window.navigate(parsed);
                    }
                });
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
