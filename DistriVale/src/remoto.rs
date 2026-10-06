//! Acceso remoto por red local (iPad, etc.) — lado del shell de escritorio.
//!
//! Laravel decide QUIÉN entra (códigos de emparejamiento, ver
//! `App\Support\AccesoRemoto`); este módulo decide SI el puerto está abierto
//! a la red y hace todo lo que solo un proceso nativo puede hacer: cambiar el
//! `bind` de Caddy, crear la regla de firewall, evitar la suspensión de
//! Windows y avisar en la PC (ventana al frente, parpadeo y sonido).
//!
//! Se hablan por archivos en `<php>/tmp/control` (Laravel recibe la ruta en
//! la variable de entorno `DV_CONTROL_DIR`), sin puertos ni IPC extra:
//!
//! | archivo              | quién escribe | contenido                                   |
//! |----------------------|---------------|---------------------------------------------|
//! | `boot_id`            | Rust, al arrancar | id de ESTA ejecución de la app          |
//! | `remoto.json`        | Laravel       | `{"habilitado": bool, "puerto": u16}`       |
//! | `remoto-estado.json` | Rust          | estado real (activo, ip, puerto, firewall…) |
//! | `accion.json`        | Laravel       | `{"id", "accion"}` (firewall)               |
//! | `alerta.json`        | Laravel       | `{"contador"}`: llegó una solicitud nueva   |
//!
//! Todo arranca APAGADO en cada ejecución: abrir la app nunca expone la red
//! por sí solo, hay que encender el interruptor.

use std::fs;
use std::net::{Ipv4Addr, SocketAddr, TcpListener, UdpSocket};
use std::path::{Path, PathBuf};
use std::sync::{Arc, Mutex};
use std::time::{Duration, Instant, SystemTime, UNIX_EPOCH};

use serde_json::{Value, json};
use sha2::{Digest, Sha256};
use tauri::{AppHandle, Manager, UserAttentionType};

#[cfg(target_os = "windows")]
use std::os::windows::process::CommandExt;
#[cfg(target_os = "windows")]
const CREATE_NO_WINDOW: u32 = 0x0800_0000;

const PUERTO_POR_DEFECTO: u16 = 8712;
/// Cuántos puertos seguidos se prueban si el fijo ya está ocupado.
const PUERTOS_DE_RESPALDO: u16 = 10;
/// Con el acceso encendido, cada cuánto se vuelve a mirar si la IP de la PC cambió (DHCP).
const REVISAR_IP_CADA: Duration = Duration::from_secs(10);
/// Tras un fallo al abrir el puerto, cuánto se espera antes de reintentar.
const REINTENTO_TRAS_FALLO: Duration = Duration::from_secs(8);
/// Se reescribe el estado al menos así de seguido: Laravel descarta uno más viejo que 15 s.
const LATIDO: Duration = Duration::from_secs(5);

#[cfg(target_os = "windows")]
#[link(name = "kernel32")]
unsafe extern "system" {
    fn SetThreadExecutionState(es_flags: u32) -> u32;
}
#[cfg(target_os = "windows")]
#[link(name = "user32")]
unsafe extern "system" {
    fn MessageBeep(tipo: u32) -> i32;
}

/// Acción del sistema que Laravel pidió y su resultado (se refleja en `remoto-estado.json`).
#[derive(Clone)]
struct Accion {
    id: String,
    estado: &'static str, // "ejecutando" | "ok" | "error"
    mensaje: String,
}

/// Lo que escriben los hilos de fondo (firewall) y lee el supervisor.
#[derive(Default)]
struct Compartido {
    firewall: String, // "ok" | "falta" | "desconocido"
    perfil_red: Option<String>,
    accion: Option<Accion>,
}

pub struct Remoto {
    dir: PathBuf,
    caddy_exe: PathBuf,
    activo: Option<(Ipv4Addr, u16)>,
    error: Option<String>,
    reintentar_en: Option<Instant>,
    alerta_visto: u64,
    accion_vista: String,
    ultima_revision_ip: Instant,
    ultima_escritura: Instant,
    ultimo_json: String,
    keep_awake: bool,
    compartido: Arc<Mutex<Compartido>>,
}

impl Remoto {
    pub fn new(dir: PathBuf, caddy_exe: PathBuf) -> Self {
        let compartido = Compartido {
            firewall: "desconocido".to_string(),
            ..Default::default()
        };
        Remoto {
            dir,
            caddy_exe,
            activo: None,
            error: None,
            reintentar_en: None,
            alerta_visto: 0,
            accion_vista: String::new(),
            ultima_revision_ip: Instant::now(),
            ultima_escritura: Instant::now() - LATIDO * 2,
            ultimo_json: String::new(),
            keep_awake: false,
            compartido: Arc::new(Mutex::new(compartido)),
        }
    }

    /// Un ciclo del supervisor (cada ~500 ms): atiende lo que Laravel dejó en
    /// los archivos de control y mantiene `remoto-estado.json` al día.
    ///
    /// `aplicar(Some((ip, puerto)))` debe dejar Caddy escuchando también en
    /// esa IP/puerto; `aplicar(None)`, solo en 127.0.0.1.
    pub fn paso(
        &mut self,
        app: &AppHandle,
        aplicar: &mut dyn FnMut(Option<(Ipv4Addr, u16)>) -> Result<(), String>,
    ) {
        self.atender_accion();
        self.atender_alerta(app);
        self.reconciliar(aplicar);
        self.sincronizar_suspension();
        self.escribir_estado();
    }

    /// El Caddy de la red murió por su cuenta: se da por no activo para que el
    /// siguiente ciclo lo reabra (si el acceso sigue encendido).
    pub fn caddy_murio(&mut self) {
        self.activo = None;
        self.error = Some("El servidor de la red local se detuvo; reabriéndolo…".to_string());
    }

    /// Al cerrar la app: suelta el bloqueo de suspensión y retira el estado.
    pub fn cerrar(&mut self) {
        poner_suspension(false);
        let _ = fs::remove_file(self.dir.join("remoto-estado.json"));
    }

    // --- Encender / apagar ---

    fn reconciliar(&mut self, aplicar: &mut dyn FnMut(Option<(Ipv4Addr, u16)>) -> Result<(), String>) {
        let (habilitado, puerto_pedido) = self.leer_deseado();

        if !habilitado {
            self.reintentar_en = None;
            if self.activo.is_some() {
                // Aunque falle el reinicio de Caddy, se da por apagado: lo
                // importante es no seguir ofreciendo un puerto que el usuario cerró.
                self.error = aplicar(None).err().map(|e| format!("No se pudo cerrar el puerto limpiamente: {e}"));
                self.activo = None;
            } else {
                self.error = None;
            }
            return;
        }

        // Ya encendido: solo se vuelve a mirar si la IP de la PC cambió (DHCP),
        // cada REVISAR_IP_CADA — no en cada ciclo de 500 ms.
        if let Some((ip_actual, _)) = self.activo {
            if self.ultima_revision_ip.elapsed() < REVISAR_IP_CADA {
                return;
            }
            self.ultima_revision_ip = Instant::now();
            if lan_ip() == Some(ip_actual) {
                self.error = None;
                return;
            }
            let _ = aplicar(None);
            self.activo = None;
        }

        // Tras un fallo se espera antes de reintentar: sin esto, un puerto que
        // no abre reiniciaría Caddy en bucle cada 500 ms.
        if self.reintentar_en.is_some_and(|t| Instant::now() < t) {
            return;
        }

        let Some(ip) = lan_ip() else {
            self.error = Some("No se encontró una dirección de red local. ¿La computadora está conectada a una red WiFi o de cable?".to_string());
            self.reintentar_en = Some(Instant::now() + REINTENTO_TRAS_FALLO);
            return;
        };
        let Some(puerto) = puerto_libre(ip, puerto_pedido) else {
            self.error = Some(format!("Los puertos {puerto_pedido}–{} están ocupados en esta computadora.", puerto_pedido + PUERTOS_DE_RESPALDO - 1));
            self.reintentar_en = Some(Instant::now() + REINTENTO_TRAS_FALLO);
            return;
        };

        match aplicar(Some((ip, puerto))) {
            Ok(()) => {
                self.activo = Some((ip, puerto));
                self.error = None;
                self.reintentar_en = None;
                self.ultima_revision_ip = Instant::now();
                // Con el puerto ya abierto se revisa si el firewall lo deja pasar.
                self.lanzar_verificacion_firewall();
            }
            Err(e) => {
                self.error = Some(format!("No se pudo abrir el puerto en {ip}:{puerto}: {e}"));
                self.reintentar_en = Some(Instant::now() + REINTENTO_TRAS_FALLO);
                // Se vuelve a un Caddy solo-local para no dejar la app sin servidor.
                let _ = aplicar(None);
            }
        }
    }

    fn leer_deseado(&self) -> (bool, u16) {
        match leer_json(&self.dir.join("remoto.json")) {
            Some(v) => (
                v.get("habilitado").and_then(Value::as_bool).unwrap_or(false),
                v.get("puerto").and_then(Value::as_u64).map(|p| p as u16).unwrap_or(PUERTO_POR_DEFECTO),
            ),
            None => (false, PUERTO_POR_DEFECTO),
        }
    }

    // --- Suspensión de Windows ---

    fn sincronizar_suspension(&mut self) {
        let debe = self.activo.is_some();
        if debe != self.keep_awake {
            poner_suspension(debe);
            self.keep_awake = debe;
        }
    }

    // --- Alerta de solicitud nueva ---

    fn atender_alerta(&mut self, app: &AppHandle) {
        let Some(v) = leer_json(&self.dir.join("alerta.json")) else { return };
        let contador = v.get("contador").and_then(Value::as_u64).unwrap_or(0);
        if contador != 0 && contador != self.alerta_visto {
            self.alerta_visto = contador;
            avisar(app);
        }
    }

    // --- Acciones del sistema (firewall) ---

    fn atender_accion(&mut self) {
        let ruta = self.dir.join("accion.json");
        let Some(v) = leer_json(&ruta) else { return };
        let id = v.get("id").and_then(Value::as_str).unwrap_or("").to_string();
        let accion = v.get("accion").and_then(Value::as_str).unwrap_or("").to_string();
        if id.is_empty() || id == self.accion_vista {
            return;
        }
        self.accion_vista = id.clone();
        let _ = fs::remove_file(&ruta);

        let compartido = Arc::clone(&self.compartido);
        let caddy = self.caddy_exe.clone();

        match accion.as_str() {
            "crear_regla_firewall" => {
                set_accion(&compartido, &id, "ejecutando", "");
                std::thread::spawn(move || {
                    // Se queda esperando a que el usuario conteste la ventana de permisos (UAC).
                    let resultado = crear_regla_firewall(&caddy);
                    let (regla, perfil) = verificar_firewall(&caddy);
                    aplicar_verificacion(&compartido, regla, perfil);
                    match (resultado, regla) {
                        (_, true) => set_accion(&compartido, &id, "ok", "Regla de firewall creada."),
                        (Err(e), false) => set_accion(&compartido, &id, "error", &e),
                        (Ok(()), false) => set_accion(&compartido, &id, "error", "Windows no dejó creada la regla (¿se canceló el permiso de administrador?)."),
                    }
                });
            }
            "verificar_firewall" => {
                set_accion(&compartido, &id, "ejecutando", "");
                std::thread::spawn(move || {
                    let (regla, perfil) = verificar_firewall(&caddy);
                    aplicar_verificacion(&compartido, regla, perfil);
                    set_accion(&compartido, &id, "ok", "");
                });
            }
            otra => set_accion(&compartido, &id, "error", &format!("Acción desconocida: {otra}")),
        }
    }

    fn lanzar_verificacion_firewall(&self) {
        let compartido = Arc::clone(&self.compartido);
        let caddy = self.caddy_exe.clone();
        std::thread::spawn(move || {
            let (regla, perfil) = verificar_firewall(&caddy);
            aplicar_verificacion(&compartido, regla, perfil);
        });
    }

    // --- Estado hacia Laravel ---

    fn escribir_estado(&mut self) {
        let (firewall, perfil_red, accion) = {
            let c = self.compartido.lock().unwrap();
            (c.firewall.clone(), c.perfil_red.clone(), c.accion.clone())
        };

        let json = json!({
            "stack": true,
            "activo": self.activo.is_some(),
            "ip": self.activo.map(|(ip, _)| ip.to_string()),
            "puerto": self.activo.map(|(_, p)| p),
            "url": self.activo.map(|(ip, p)| format!("http://{ip}:{p}")),
            "firewall": firewall,
            "perfil_red": perfil_red,
            "error": self.error,
            "accion": accion.map(|a| json!({"id": a.id, "estado": a.estado, "mensaje": a.mensaje})),
        })
        .to_string();

        // Solo se reescribe si cambió, o como latido (Laravel descarta un estado viejo).
        if json != self.ultimo_json || self.ultima_escritura.elapsed() >= LATIDO {
            if escribir_atomico(&self.dir.join("remoto-estado.json"), &json) {
                self.ultimo_json = json;
                self.ultima_escritura = Instant::now();
            }
        }
    }
}

// --- Preparación (al arrancar el stack) ---

/// Crea la carpeta de control, la limpia de restos de una ejecución anterior y
/// escribe el `boot_id` de ESTA ejecución (las autorizaciones de dispositivos
/// quedan atadas a él: al reiniciar la app dejan de valer).
pub fn preparar_control(php_dir: &Path) -> std::io::Result<PathBuf> {
    let dir = php_dir.join("tmp").join("control");
    fs::create_dir_all(&dir)?;

    for entrada in fs::read_dir(&dir)?.flatten() {
        let _ = fs::remove_file(entrada.path());
    }

    let nanos = SystemTime::now().duration_since(UNIX_EPOCH).map(|d| d.as_nanos()).unwrap_or(0);
    fs::write(dir.join("boot_id"), format!("{:x}-{:x}", nanos, std::process::id()))?;
    Ok(dir)
}

// --- Red ---

/// IP de esta PC en la red local: la de la interfaz que Windows usaría para
/// salir. Conectar un socket UDP no envía nada; solo hace que el sistema elija
/// la ruta. Se prueban varios destinos por si la red no tiene salida a internet.
pub fn lan_ip() -> Option<Ipv4Addr> {
    for destino in ["8.8.8.8:80", "192.168.1.1:80", "192.168.0.1:80", "10.0.0.1:80", "172.16.0.1:80"] {
        let Ok(sock) = UdpSocket::bind("0.0.0.0:0") else { continue };
        if sock.connect(destino).is_err() {
            continue;
        }
        if let Ok(SocketAddr::V4(a)) = sock.local_addr() {
            if es_red_local(*a.ip()) {
                return Some(*a.ip());
            }
        }
    }
    None
}

fn es_red_local(ip: Ipv4Addr) -> bool {
    ip.is_private() && !ip.is_link_local() && !ip.is_loopback()
}

/// Primer puerto libre en `ip` desde `desde` (el fijo; los siguientes son respaldo).
fn puerto_libre(ip: Ipv4Addr, desde: u16) -> Option<u16> {
    (desde..desde.saturating_add(PUERTOS_DE_RESPALDO)).find(|&p| TcpListener::bind((ip, p)).is_ok())
}

// --- Firewall (netsh, sin PowerShell) ---
//
// Antes esto usaba `powershell -EncodedCommand`. Se quitó a propósito: un .exe
// sin firmar que lanza PowerShell con un comando en base64 (y, peor, pidiendo
// elevación con la ventana oculta) es EXACTAMENTE el patrón que los antivirus
// marcan como malware — AVG puso la app en cuarentena (IDP.HELU.PSE92) a los
// pocos segundos de encender el acceso remoto. `netsh` es una herramienta
// firmada de Windows y las llamadas son de texto plano.

/// Nombre de la regla, atado a la ruta de caddy.exe: cada carpeta de la app
/// (cada versión exportada, cada instalación) necesita su propia regla porque
/// la regla es POR PROGRAMA — así no importa en qué puerto quedó escuchando.
fn nombre_regla(caddy: &Path) -> String {
    let h = Sha256::digest(caddy.to_string_lossy().to_lowercase().as_bytes());
    format!("DistriVale acceso remoto ({:02x}{:02x}{:02x}{:02x})", h[0], h[1], h[2], h[3])
}

fn netsh(args: &[String]) -> std::io::Result<std::process::Output> {
    let mut cmd = std::process::Command::new("netsh");
    cmd.args(args);
    #[cfg(target_os = "windows")]
    cmd.creation_flags(CREATE_NO_WINDOW);
    cmd.output()
}

/// ¿Existe la regla de entrada de ESTE caddy.exe? `netsh ... show rule` sale
/// con código 0 si existe y 1 si "ninguna regla coincide" — el código no
/// depende del idioma de Windows (el texto sí). Y, aparte, el perfil de red
/// activo. Ninguna de las dos necesita permisos de administrador.
fn verificar_firewall(caddy: &Path) -> (bool, Option<String>) {
    let regla = netsh(&[
        "advfirewall".into(),
        "firewall".into(),
        "show".into(),
        "rule".into(),
        format!("name={}", nombre_regla(caddy)),
    ])
    .map(|o| o.status.success())
    .unwrap_or(false);

    let perfil = netsh(&["advfirewall".into(), "show".into(), "currentprofile".into()])
        .ok()
        .and_then(|o| clasificar_perfil(&String::from_utf8_lossy(&o.stdout)));

    (regla, perfil)
}

/// Lee la(s) cabecera(s) de `netsh advfirewall show currentprofile`
/// ("Configuración de Perfil privado:", "Private Profile Settings:"…) y
/// devuelve "Private" si hay un perfil privado o de dominio activo (donde la
/// regla sí aplica), "Public" si el único activo es el público (la regla NO
/// aplica ahí), o `None` si no se reconoce (idioma raro): sin aviso falso.
fn clasificar_perfil(salida: &str) -> Option<String> {
    let (mut privado, mut publico) = (false, false);
    for linea in salida.lines().map(|l| l.trim().to_lowercase()) {
        if !linea.ends_with(':') {
            continue;
        }
        if ["private", "privad", "privé", "prive", "privat", "domain", "dominio", "domaine", "domäne"].iter().any(|k| linea.contains(k)) {
            privado = true;
        } else if ["public", "públic", "öffentl", "offentl"].iter().any(|k| linea.contains(k)) {
            publico = true;
        }
    }
    match (privado, publico) {
        (true, _) => Some("Private".to_string()),
        (false, true) => Some("Public".to_string()),
        _ => None,
    }
}

/// Argumentos de `netsh` para crear la regla de entrada de caddy.exe: solo
/// redes Privada/Dominio y solo la subred local — nunca se abre a internet.
fn argumentos_crear_regla(caddy: &Path) -> String {
    format!(
        "advfirewall firewall add rule name=\"{}\" dir=in action=allow protocol=TCP program=\"{}\" profile=private,domain remoteip=localsubnet enable=yes",
        nombre_regla(caddy),
        caddy.to_string_lossy()
    )
}

/// Crea la regla pidiendo permisos de administrador con la ventana de Windows
/// (UAC, vía el verbo "runas" de ShellExecute). Bloquea hasta que el usuario
/// contesta, por eso se llama siempre desde un hilo aparte. Si el usuario
/// cancela, `ejecutar_shell` devuelve error; si el proceso elevado falla por
/// dentro, devuelve su código de salida — y en ambos casos quien llama debe
/// confirmar con `verificar_firewall` si la regla quedó de verdad.
fn crear_regla_firewall(caddy: &Path) -> Result<(), String> {
    match ejecutar_shell("runas", "netsh.exe", &argumentos_crear_regla(caddy), Duration::from_secs(300)) {
        Ok(0) => Ok(()),
        Ok(codigo) => Err(format!("Windows rechazó crear la regla (código {codigo}).")),
        Err(e) => Err(e),
    }
}

/// `ShellExecuteExW` + espera + código de salida, para lanzar algo con un verbo
/// ("runas" = pedir elevación; "open" = normal) sin pasar por PowerShell ni cmd.
#[cfg(target_os = "windows")]
fn ejecutar_shell(verbo: &str, archivo: &str, parametros: &str, espera_max: Duration) -> Result<u32, String> {
    use std::ffi::c_void;

    #[repr(C)]
    struct ShellExecuteInfoW {
        cb_size: u32,
        f_mask: u32,
        hwnd: isize,
        lp_verb: *const u16,
        lp_file: *const u16,
        lp_parameters: *const u16,
        lp_directory: *const u16,
        n_show: i32,
        h_inst_app: isize,
        lp_id_list: *mut c_void,
        lp_class: *const u16,
        hkey_class: isize,
        dw_hot_key: u32,
        h_icon_o_monitor: isize,
        h_process: isize,
    }

    #[link(name = "shell32")]
    unsafe extern "system" {
        fn ShellExecuteExW(info: *mut ShellExecuteInfoW) -> i32;
    }
    #[link(name = "kernel32")]
    unsafe extern "system" {
        fn WaitForSingleObject(handle: isize, ms: u32) -> u32;
        fn GetExitCodeProcess(handle: isize, codigo: *mut u32) -> i32;
        fn CloseHandle(handle: isize) -> i32;
        fn GetLastError() -> u32;
    }

    const SEE_MASK_NOCLOSEPROCESS: u32 = 0x0000_0040;
    // Sin esto, si Windows no puede lanzar el programa muestra un cuadro de error
    // modal y la llamada se queda bloqueada hasta que alguien lo cierre. No afecta
    // al aviso de permisos (UAC) del verbo "runas": ese lo pinta el propio sistema.
    const SEE_MASK_FLAG_NO_UI: u32 = 0x0000_0400;
    const SW_HIDE: i32 = 0;
    const WAIT_OBJECT_0: u32 = 0;
    const ERROR_CANCELLED: u32 = 1223;

    let ancho = |s: &str| -> Vec<u16> { s.encode_utf16().chain(std::iter::once(0)).collect() };
    let (verbo, archivo, parametros) = (ancho(verbo), ancho(archivo), ancho(parametros));

    let mut info = ShellExecuteInfoW {
        cb_size: std::mem::size_of::<ShellExecuteInfoW>() as u32,
        f_mask: SEE_MASK_NOCLOSEPROCESS | SEE_MASK_FLAG_NO_UI,
        hwnd: 0,
        lp_verb: verbo.as_ptr(),
        lp_file: archivo.as_ptr(),
        lp_parameters: parametros.as_ptr(),
        lp_directory: std::ptr::null(),
        n_show: SW_HIDE,
        h_inst_app: 0,
        lp_id_list: std::ptr::null_mut(),
        lp_class: std::ptr::null(),
        hkey_class: 0,
        dw_hot_key: 0,
        h_icon_o_monitor: 0,
        h_process: 0,
    };

    // SAFETY: `info` y los buffers UTF-16 viven durante toda la llamada; el
    // handle de proceso que devuelve SEE_MASK_NOCLOSEPROCESS se cierra aquí.
    unsafe {
        if ShellExecuteExW(&mut info) == 0 {
            return Err(if GetLastError() == ERROR_CANCELLED {
                "Se canceló la ventana de permisos de administrador.".to_string()
            } else {
                "Windows no pudo lanzar la acción (¿este usuario no es administrador?).".to_string()
            });
        }
        if info.h_process == 0 {
            return Ok(0);
        }
        let ms = espera_max.as_millis().min(u32::MAX as u128 - 1) as u32;
        let esperado = WaitForSingleObject(info.h_process, ms);
        let mut codigo: u32 = 1;
        if esperado == WAIT_OBJECT_0 {
            GetExitCodeProcess(info.h_process, &mut codigo);
        }
        CloseHandle(info.h_process);
        if esperado == WAIT_OBJECT_0 { Ok(codigo) } else { Err("La acción tardó demasiado en terminar.".to_string()) }
    }
}

#[cfg(not(target_os = "windows"))]
fn ejecutar_shell(_verbo: &str, _archivo: &str, _parametros: &str, _espera_max: Duration) -> Result<u32, String> {
    Err("Solo disponible en Windows.".to_string())
}

fn aplicar_verificacion(c: &Arc<Mutex<Compartido>>, regla: bool, perfil: Option<String>) {
    let mut c = c.lock().unwrap();
    c.firewall = if regla { "ok" } else { "falta" }.to_string();
    c.perfil_red = perfil;
}

fn set_accion(c: &Arc<Mutex<Compartido>>, id: &str, estado: &'static str, mensaje: &str) {
    c.lock().unwrap().accion = Some(Accion { id: id.to_string(), estado, mensaje: mensaje.to_string() });
}

// --- Aviso en la PC ---

/// Llegó una solicitud: ventana al frente, parpadeo en la barra de tareas hasta
/// que se atienda, y un sonido corto. (Windows puede negarse a robar el foco a
/// otra app; el parpadeo y el sonido avisan de todos modos.)
fn avisar(app: &AppHandle) {
    if let Some(w) = app.get_webview_window("main") {
        let _ = w.unminimize();
        let _ = w.show();
        // Subir y bajar "siempre encima" la trae al frente sin necesitar el foco.
        let _ = w.set_always_on_top(true);
        let _ = w.set_always_on_top(false);
        let _ = w.request_user_attention(Some(UserAttentionType::Critical));
        let _ = w.set_focus();
    }
    sonar();
}

fn sonar() {
    #[cfg(target_os = "windows")]
    unsafe {
        MessageBeep(0x40); // MB_ICONASTERISK: el sonido de notificación del sistema
    }
}

// --- Suspensión ---

/// Pide a Windows que no suspenda por inactividad mientras sea `true`
/// (ES_CONTINUOUS | ES_SYSTEM_REQUIRED). Vale para el hilo que llama y se
/// suelta solo al terminar; no necesita permisos de administrador. No impide
/// suspender a mano ni cerrar la tapa de una laptop.
fn poner_suspension(evitar: bool) {
    #[cfg(target_os = "windows")]
    unsafe {
        const ES_CONTINUOUS: u32 = 0x8000_0000;
        const ES_SYSTEM_REQUIRED: u32 = 0x0000_0001;
        SetThreadExecutionState(if evitar { ES_CONTINUOUS | ES_SYSTEM_REQUIRED } else { ES_CONTINUOUS });
    }
    #[cfg(not(target_os = "windows"))]
    let _ = evitar;
}

// --- Archivos ---

fn leer_json(ruta: &Path) -> Option<Value> {
    serde_json::from_str(&fs::read_to_string(ruta).ok()?).ok()
}

/// Escribe a un temporal y renombra, para que Laravel nunca lea un JSON a medias.
/// En Windows el rename falla si Laravel lo está leyendo justo ahora: se
/// devuelve `false` y el siguiente ciclo lo reintenta.
fn escribir_atomico(ruta: &Path, contenido: &str) -> bool {
    let tmp = ruta.with_extension("json.tmp");
    fs::write(&tmp, contenido).is_ok() && fs::rename(&tmp, ruta).is_ok()
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn solo_se_considera_red_local_una_ip_privada_real() {
        assert!(es_red_local(Ipv4Addr::new(192, 168, 1, 20)));
        assert!(es_red_local(Ipv4Addr::new(10, 0, 0, 7)));
        assert!(es_red_local(Ipv4Addr::new(172, 16, 5, 9)));
        assert!(!es_red_local(Ipv4Addr::new(169, 254, 3, 3))); // sin DHCP
        assert!(!es_red_local(Ipv4Addr::new(127, 0, 0, 1)));
        assert!(!es_red_local(Ipv4Addr::new(8, 8, 8, 8)));
    }

    #[test]
    fn la_regla_depende_de_la_ruta_del_programa() {
        let a = nombre_regla(Path::new(r"C:\App\caddy\caddy.exe"));
        let b = nombre_regla(Path::new(r"C:\Otra\caddy\caddy.exe"));
        assert_ne!(a, b);
        assert_eq!(a, nombre_regla(Path::new(r"c:\app\CADDY\caddy.exe")), "Windows no distingue mayúsculas en rutas");
        assert!(a.starts_with("DistriVale acceso remoto ("));
    }

    #[test]
    fn el_comando_de_netsh_limita_la_regla_a_red_privada_y_subred_local() {
        let args = argumentos_crear_regla(Path::new(r"D:\Mi App\caddy\caddy.exe"));
        assert!(args.contains(r#"program="D:\Mi App\caddy\caddy.exe""#), "la ruta con espacios va entre comillas");
        assert!(args.contains("dir=in") && args.contains("action=allow") && args.contains("protocol=TCP"));
        assert!(args.contains("profile=private,domain"), "nunca el perfil público");
        assert!(args.contains("remoteip=localsubnet"), "nunca internet");
        assert!(!args.contains("any"), "ningún comodín abierto");
    }

    #[test]
    fn el_perfil_de_red_se_reconoce_en_espanol_e_ingles() {
        assert_eq!(clasificar_perfil("Configuración de Perfil privado:\r\n----\r\nEstado ACTIVAR").as_deref(), Some("Private"));
        assert_eq!(clasificar_perfil("Private Profile Settings:\n------").as_deref(), Some("Private"));
        assert_eq!(clasificar_perfil("Configuración de Perfil público:\r\n------").as_deref(), Some("Public"));
        assert_eq!(clasificar_perfil("Public Profile Settings:\n------").as_deref(), Some("Public"));
        assert_eq!(clasificar_perfil("Configuración de Perfil de dominio:\n").as_deref(), Some("Private"));
        // Si hay uno privado Y uno público activos no se da aviso falso de "red pública".
        assert_eq!(clasificar_perfil("Perfil privado:\n---\nPerfil público:\n---").as_deref(), Some("Private"));
        assert_eq!(clasificar_perfil("texto en un idioma desconocido"), None);
        assert_eq!(clasificar_perfil(""), None);
    }

    #[test]
    fn el_puerto_fijo_ocupado_cae_al_siguiente() {
        let ip = Ipv4Addr::new(127, 0, 0, 1);
        let ocupado = TcpListener::bind((ip, 0)).unwrap();
        let p = ocupado.local_addr().unwrap().port();
        let elegido = puerto_libre(ip, p).expect("debe haber algún puerto libre cerca");
        assert_ne!(elegido, p);
        assert!(elegido > p && elegido < p + PUERTOS_DE_RESPALDO);
    }

    #[test]
    fn preparar_control_limpia_restos_y_cambia_el_boot_id() {
        let base = std::env::temp_dir().join(format!("dv-test-{}", std::process::id()));
        let _ = fs::remove_dir_all(&base);

        let dir = preparar_control(&base).unwrap();
        let primero = fs::read_to_string(dir.join("boot_id")).unwrap();
        fs::write(dir.join("remoto.json"), r#"{"habilitado":true}"#).unwrap();
        fs::write(dir.join("accion.json"), "{}").unwrap();

        std::thread::sleep(Duration::from_millis(2));
        let dir = preparar_control(&base).unwrap();
        assert!(!dir.join("remoto.json").exists(), "arranca apagado: se borra lo pedido en la ejecución anterior");
        assert!(!dir.join("accion.json").exists());
        assert_ne!(primero, fs::read_to_string(dir.join("boot_id")).unwrap());

        let _ = fs::remove_dir_all(&base);
    }

    #[test]
    fn json_atomico_se_lee_de_vuelta() {
        let base = std::env::temp_dir().join(format!("dv-test-json-{}", std::process::id()));
        fs::create_dir_all(&base).unwrap();
        let ruta = base.join("x.json");
        assert!(escribir_atomico(&ruta, r#"{"a":1}"#));
        assert_eq!(leer_json(&ruta).unwrap()["a"], 1);
        assert!(escribir_atomico(&ruta, r#"{"a":2}"#), "sobrescribe el anterior");
        assert_eq!(leer_json(&ruta).unwrap()["a"], 2);
        let _ = fs::remove_dir_all(&base);
    }

    // --- Windows de verdad (sin pedir permisos) ---

    #[cfg(target_os = "windows")]
    #[test]
    fn shell_execute_devuelve_el_codigo_de_salida_del_proceso() {
        // Prueba la capa FFI (layout del struct, espera, código) con el verbo "open": no hay UAC.
        assert_eq!(ejecutar_shell("open", "cmd.exe", "/c exit 7", Duration::from_secs(20)), Ok(7));
        assert_eq!(ejecutar_shell("open", "cmd.exe", "/c exit 0", Duration::from_secs(20)), Ok(0));
    }

    #[cfg(target_os = "windows")]
    #[test]
    fn evitar_la_suspension_queda_registrado_en_windows_y_se_puede_soltar() {
        const ES_CONTINUOUS: u32 = 0x8000_0000;
        const ES_SYSTEM_REQUIRED: u32 = 0x0000_0001;

        // SetThreadExecutionState devuelve el estado ANTERIOR del hilo: así se
        // lee, sin permisos de administrador, lo que Windows tiene registrado.
        // Cada prueba corre en su propio hilo, que empieza limpio.
        poner_suspension(true);
        let registrado = unsafe { SetThreadExecutionState(ES_CONTINUOUS | ES_SYSTEM_REQUIRED) };
        assert_eq!(registrado & ES_SYSTEM_REQUIRED, ES_SYSTEM_REQUIRED, "Windows debe tener la petición 'no suspender'");
        assert_eq!(registrado & ES_CONTINUOUS, ES_CONTINUOUS, "y debe ser continua, no de un solo uso");

        poner_suspension(false);
        let tras_soltar = unsafe { SetThreadExecutionState(ES_CONTINUOUS) };
        assert_eq!(tras_soltar & ES_SYSTEM_REQUIRED, 0, "al apagar el acceso remoto la petición debe soltarse");
    }

    #[cfg(target_os = "windows")]
    #[test]
    fn shell_execute_reporta_el_error_si_el_programa_no_existe() {
        assert!(ejecutar_shell("open", r"C:\no\existe\nada.exe", "", Duration::from_secs(5)).is_err());
    }

    #[cfg(target_os = "windows")]
    #[test]
    fn la_verificacion_real_corre_sin_admin_y_no_encuentra_una_regla_inexistente() {
        let (regla, _perfil) = verificar_firewall(Path::new(r"C:\no\existe\caddy.exe"));
        assert!(!regla);
    }

    #[cfg(target_os = "windows")]
    #[test]
    fn netsh_ve_una_regla_que_si_existe_en_el_sistema() {
        // Control positivo: que el "no existe" de arriba no sea simplemente un netsh que siempre falla.
        let salida = netsh(&["advfirewall".into(), "firewall".into(), "show".into(), "rule".into(), "name=all".into(), "dir=in".into()]).unwrap();
        assert!(salida.status.success(), "netsh debe poder listar reglas sin admin");
    }

    #[test]
    fn el_proyecto_ya_no_lanza_powershell() {
        // Candado contra regresiones: volver a PowerShell/-EncodedCommand hace que los
        // antivirus pongan la app en cuarentena (ver el comentario de la sección de firewall).
        let fuente = include_str!("remoto.rs");
        let prohibido = ["Command::new(\"powershell", "-EncodedCommand\"", "Start-Process"];
        for p in prohibido {
            // La propia lista de arriba contiene los textos: se cuentan solo apariciones fuera de este módulo de pruebas.
            let (codigo, _) = fuente.split_once("#[cfg(test)]\nmod tests").or_else(|| fuente.split_once("#[cfg(test)]\r\nmod tests")).unwrap();
            assert!(!codigo.contains(p), "no debe aparecer {p:?} en el código de remoto.rs");
        }
    }
}
