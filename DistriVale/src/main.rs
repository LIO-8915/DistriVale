// Sin esto, el binario se linkea como app de consola: al abrir DistriVale
// aparece de fondo una ventana de terminal (y su propio ícono en la
// barra de tareas) además de la ventana de la app. Solo se desactiva en
// release — en debug (`cargo run`) conviene conservarla para ver
// println!/errores de arranque mientras se desarrolla.
#![cfg_attr(not(debug_assertions), windows_subsystem = "windows")]

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
mod monitoreo_local;
mod supabase_licensing;

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

/// Arranca `php artisan serve` y navega el WebView ahí — la secuencia
/// normal de arranque, movida a su propia función porque ahora hay dos
/// caminos que llegan a ella: el arranque normal (cuenta ya válida) y el
/// botón "continuar" de la pantalla de activación (cuenta recién
/// activada).
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

/// Reemplaza la pantalla de carga con un formulario simple de activación
/// (la license key de Lemon Squeezy que el cliente recibió por correo al
/// comprar) cuando no hay ninguna licencia local válida — inyectado
/// directo sobre el splash en vez de navegar a un archivo aparte, para no
/// tener que lidiar con volver del origen http://127.0.0.1:<puerto> de
/// vuelta al de los assets empaquetados.
fn show_activation_screen(app: &AppHandle, mensaje_inicial: Option<&str>) {
    let Some(window) = app.get_webview_window("main") else { return };

    let aviso = mensaje_inicial
        .map(|m| m.replace('\\', "\\\\").replace('`', "\\`"))
        .unwrap_or_default();

    let script = format!(
        r#"
        // Sin esto, WebView2 aplica su propio tema nativo oscuro por
        // encima de los controles de formulario (appearance: auto por
        // defecto en <input>/<button>) y "lava" los colores puestos acá
        // abajo, haciendo que se vean planos/deshabilitados aunque
        // funcionen — declarar el color-scheme evita que el navegador
        // intente reinterpretar los colores por su cuenta.
        document.documentElement.style.colorScheme = 'dark';

        document.body.innerHTML = `
          <style>
            #dv-license-key, #dv-activar-btn {{
              appearance: none;
              -webkit-appearance: none;
              font: inherit;
            }}
            #dv-license-key:focus {{
              outline: none;
              border-color: #4f7cff;
              box-shadow: 0 0 0 3px rgba(79,124,255,.35);
            }}
            #dv-activar-btn:hover:not(:disabled) {{ background: #3d69eb; }}
            #dv-activar-btn:active:not(:disabled) {{ background: #3359d6; }}
            #dv-activar-btn:disabled {{ opacity: .6; cursor: not-allowed; }}
          </style>
          <div style="font-family:-apple-system,'Segoe UI',Inter,system-ui,sans-serif;color:#e6e9f0;
                      max-width:380px;margin:3rem auto;padding:0 1.5rem;text-align:center">
            <h2 style="color:#fff;font-size:1.3rem;margin-bottom:.3rem">Activar DistriVale</h2>
            <p style="color:#aab4c6;font-size:.9rem;margin-bottom:1.5rem">
              Ingresa la clave de licencia que recibiste por correo al comprar.
            </p>
            <div id="dv-activacion-error" style="color:#ff8fa3;font-size:.85rem;min-height:1.2rem;margin-bottom:.5rem"></div>
            <input id="dv-license-key" type="text" placeholder="XXXXXXXX-XXXX-XXXX-XXXX-XXXXXXXXXXXX" autocomplete="off"
                   style="width:100%;box-sizing:border-box;padding:.6rem .8rem;margin-bottom:1rem;
                          border-radius:8px;border:1px solid #3a4258;background:#0d1119;color:#fff;
                          letter-spacing:.03em;transition:border-color .15s,box-shadow .15s">
            <button id="dv-activar-btn"
                    style="width:100%;padding:.65rem;border:0;border-radius:8px;
                           background:#4f7cff;color:#fff;font-weight:600;cursor:pointer;
                           transition:background .15s">
              Activar
            </button>
          </div>`;
        document.getElementById('dv-activacion-error').textContent = `{aviso}`;

        var btn = document.getElementById('dv-activar-btn');
        btn.addEventListener('click', function () {{
          var licenseKey = document.getElementById('dv-license-key').value.trim();
          var err = document.getElementById('dv-activacion-error');
          err.textContent = '';

          if (!licenseKey) {{
            err.textContent = 'Ingresa la clave de licencia.';
            return;
          }}

          btn.disabled = true;
          btn.textContent = 'Activando…';

          window.__TAURI__.core.invoke('activar_cuenta', {{ licenseKey: licenseKey }})
            .then(function () {{
              return window.__TAURI__.core.invoke('continuar_arranque');
            }})
            .catch(function (e) {{
              err.textContent = typeof e === 'string' ? e : 'No se pudo activar la licencia.';
              btn.disabled = false;
              btn.textContent = 'Activar';
            }});
        }});
        "#
    );
    let _ = window.eval(&script);
}

/// Llamado desde la pantalla de activación (JS) justo después de que
/// `activar_cuenta` confirma éxito — arranca la secuencia normal de PHP +
/// navegación, la misma que corre en el arranque cuando ya había una
/// cuenta válida guardada.
#[tauri::command]
async fn continuar_arranque(app: AppHandle) {
    std::thread::spawn(move || {
        iniciar_app_principal(app);
    });
}

fn main() {
    tauri::Builder::default()
        .manage(PhpServer(Mutex::new(None)))
        .invoke_handler(tauri::generate_handler![
            licensing::activar_cuenta,
            licensing::check_saved_account,
            continuar_arranque,
        ])
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
                // inicial antes de intentar reemplazarlo — sin esto, en el
                // caso "sin cuenta activada" (que resuelve casi al
                // instante, sin red) el eval() puede correr contra una
                // página que todavía no existe y quedarse sin efecto.
                std::thread::sleep(Duration::from_millis(400));

                // Solo existe en builds de debug — ver el comentario en
                // licensing.rs. En release esta función siempre da false.
                if licensing::dev_saltar_activacion() {
                    iniciar_app_principal(app_handle);
                    return;
                }

                let status = tauri::async_runtime::block_on(
                    licensing::check_saved_account(app_handle.clone()),
                );

                let valido = matches!(status, Ok(ref s) if s.valid);

                if valido {
                    iniciar_app_principal(app_handle);
                } else {
                    let motivo = match status {
                        Ok(s) => match s.reason.as_deref() {
                            Some("sin_activar") | None => None,
                            Some("dispositivo_no_activo") | Some("cuenta_no_aprobada") => {
                                Some("Esta cuenta o este equipo ya no tienen acceso. Contacta al administrador.".to_string())
                            }
                            Some("licencia_invalida") => Some(
                                "Esta licencia ya no es válida (cancelada o reembolsada). Contacta al administrador.".to_string(),
                            ),
                            Some("gracia_offline_vencida") => Some(
                                "Pasó más de una semana sin poder confirmar tu licencia. Conéctate a internet e intenta de nuevo, o vuelve a activar.".to_string(),
                            ),
                            Some("reloj_manipulado") => Some(
                                "El reloj de este equipo no coincide con el esperado. Conectate a internet para revalidar.".to_string(),
                            ),
                            Some(_) => Some("No se pudo confirmar tu licencia. Intenta de nuevo.".to_string()),
                        },
                        Err(_) => Some("No se pudo confirmar tu licencia. Intenta de nuevo.".to_string()),
                    };

                    let show_handle = app_handle.clone();
                    let _ = app_handle.run_on_main_thread(move || {
                        show_activation_screen(&show_handle, motivo.as_deref());
                    });
                }
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
                licensing::dev_limpiar_licencia_al_salir(&window.app_handle());
            }
        })
        .run(tauri::generate_context!())
        .expect("error running DistriVale");
}
