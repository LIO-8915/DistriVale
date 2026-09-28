//! Control de acceso a DistriVale contra Supabase: activación por código
//! de un solo uso, identificación de dispositivo, y revalidación periódica
//! con tolerancia offline de 7 días — ver `supabase-verificar-dispositivo.sql`
//! y el `supabase-setup.sql` del repo AdminDistriVale para el esquema
//! completo (tablas `perfiles`/`dispositivos`/`activaciones`).
//!
//! Deliberadamente sin contraseña de Supabase Auth en el escritorio: el
//! comprador solo ingresa su correo y el código que le dio el admin. Por
//! eso las llamadas usan `verificar_dispositivo(cuenta_id, huella)` (recibe
//! los ids como parámetros) en vez de `verificar_licencia()` (que exige una
//! sesión de Auth autenticada vía `auth.uid()`).
//!
//! Las llamadas a Supabase se hacen invocando `curl.exe` como subproceso en
//! vez de usar el cliente HTTP de `reqwest` (ya usado en `licensing.rs`):
//! en pruebas reales, `reqwest` mostró un patrón reproducible de "éxito
//! fantasma" contra este endpoint puntual — el servidor procesaba y
//! confirmaba la llamada (el código quedaba marcado como usado), pero la
//! respuesta que el proceso Rust terminaba viendo era la de un segundo
//! intento contra un código ya consumido, incluso con un cliente nuevo,
//! sin *connection pooling*, forzando HTTP/1.1 y en un binario aislado sin
//! Tauri de por medio. `curl.exe` contra el mismo endpoint, mismos
//! parámetros, nunca mostró el problema — de ahí la decisión de usarlo acá
//! (mismo patrón que ya usa este proyecto para `reg.exe`/`php.exe`).
//!
//! Este módulo es independiente de `licensing.rs` (Lemon Squeezy) — ese es
//! el plan real de licenciamiento para cuando llegue la etapa de
//! distribución; este es el control de acceso inmediato mientras tanto.

use serde::{Deserialize, Serialize};
use serde_json::Value;
use std::fs;
use std::path::PathBuf;
use std::process::Command;
use std::time::{SystemTime, UNIX_EPOCH};
use tauri::AppHandle;
use tauri::Manager;

#[cfg(target_os = "windows")]
use std::os::windows::process::CommandExt;
#[cfg(target_os = "windows")]
const CREATE_NO_WINDOW: u32 = 0x0800_0000;

const SUPABASE_URL: &str = "https://ibjfpaleqyvabrfmujme.supabase.co";
// Llave "anon": segura de exponer, las políticas RLS + las funciones
// security definer limitan exactamente lo que se puede hacer con ella.
// La llave "service_role" (administrador, sin restricciones) NUNCA va acá
// — esa vive solo en AdminDistriVale.
const SUPABASE_ANON_KEY: &str = "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6ImliamZwYWxlcXl2YWJyZm11am1lIiwicm9sZSI6ImFub24iLCJpYXQiOjE3NzQyMjMwOTMsImV4cCI6MjA4OTc5OTA5M30.OeplX4ogGmqzJs7a5-M5j2Y56aWTqGDDX3iCJeMQon4";

/// Días que la app puede seguir funcionando sin conexión a internet antes
/// de exigir una revalidación exitosa contra Supabase.
const OFFLINE_GRACE_DAYS: u64 = 7;

/// Tolerancia sobre el reloj del sistema: un margen chico (relojes que se
/// desincronizan unos minutos por NTP/cambios de huso horario es normal),
/// pero si el reloj aparece más atrás que esto respecto a la última vez
/// que la app corrió, se trata como manipulación deliberada del reloj
/// para estirar la gracia offline, y se fuerza a revalidar en línea.
const CLOCK_TAMPER_TOLERANCE_SECS: i64 = 10 * 60;

#[derive(Serialize, Deserialize, Debug, Clone)]
pub struct LocalAccount {
    pub correo: String,
    pub cuenta_id: String,
    pub huella: String,
    /// Última vez (epoch, segundos) que Supabase confirmó la cuenta vigente.
    pub last_verified_at: u64,
    /// Marca de más alto valor vista del reloj del sistema en cualquier
    /// arranque anterior — un "high-water mark", nunca retrocede sola.
    pub last_seen_system_time: u64,
}

#[derive(Serialize)]
pub struct AccountStatus {
    pub valid: bool,
    pub offline: bool,
    pub reason: Option<String>,
}

/// Llama una función RPC de Supabase vía `curl.exe` y devuelve el JSON ya
/// parseado. `None` significa "no se pudo conectar" (sin internet, DNS,
/// timeout) — se distingue de un error de la propia función (que sí
/// devuelve JSON con `ok:false`) para poder aplicar la gracia offline solo
/// en el primer caso.
fn call_rpc(function: &str, body: &Value) -> Option<Value> {
    let url = format!("{SUPABASE_URL}/rest/v1/rpc/{function}");
    let body_str = serde_json::to_string(body).ok()?;

    let mut cmd = Command::new("curl");
    cmd.args([
        "-s",
        "-X",
        "POST",
        &url,
        "-H",
        &format!("apikey: {SUPABASE_ANON_KEY}"),
        "-H",
        &format!("Authorization: Bearer {SUPABASE_ANON_KEY}"),
        "-H",
        "Content-Type: application/json",
        "--max-time",
        "10",
        "-d",
        &body_str,
    ]);
    #[cfg(target_os = "windows")]
    cmd.creation_flags(CREATE_NO_WINDOW);

    let output = cmd.output().ok()?;
    if !output.status.success() {
        return None;
    }

    let text = String::from_utf8_lossy(&output.stdout);
    serde_json::from_str(&text).ok()
}

// --- COMANDO 1: activar con correo + código de un solo uso ---
#[tauri::command]
pub async fn activar_cuenta(app: AppHandle, correo: String, codigo: String) -> Result<bool, String> {
    let huella = device_fingerprint();
    let nombre = device_name();

    let json = call_rpc(
        "activar_dispositivo",
        &serde_json::json!({
            "p_codigo": codigo.trim(),
            "p_huella": huella,
            "p_nombre": nombre,
        }),
    )
    .ok_or("No se pudo conectar para activar la cuenta. Revisá tu conexión a internet.")?;

    let ok = json.get("ok").and_then(Value::as_bool).unwrap_or(false);

    if ok {
        let cuenta_id = json
            .get("cuenta_id")
            .and_then(Value::as_str)
            .ok_or("Respuesta sin id de cuenta")?;
        save_local_account(&app, &correo, cuenta_id, &huella)?;
        Ok(true)
    } else {
        let error = json.get("error").and_then(Value::as_str);
        Err(match error {
            Some("codigo_invalido") => {
                "Ese código no es válido o ya se usó. Pedí uno nuevo.".to_string()
            }
            Some(other) => other.to_string(),
            None => "No se pudo activar la cuenta.".to_string(),
        })
    }
}

// --- COMANDO 2: revalidar en cada arranque (con tolerancia offline) ---
#[tauri::command]
pub async fn check_saved_account(app: AppHandle) -> Result<AccountStatus, String> {
    let mut local = match load_local_account(&app) {
        Ok(data) => data,
        Err(_) => {
            return Ok(AccountStatus {
                valid: false,
                offline: false,
                reason: Some("sin_activar".into()),
            })
        }
    };

    let now = now_secs();

    // Reloj retrocedido respecto al high-water mark guardado: no se
    // confía en la resta de fechas para la gracia offline, hay que
    // revalidar en línea sí o sí.
    let clock_tampered =
        (local.last_seen_system_time as i64) - (now as i64) > CLOCK_TAMPER_TOLERANCE_SECS;

    local.last_seen_system_time = local.last_seen_system_time.max(now);
    let _ = save_local_account_raw(&app, &local);

    match call_rpc(
        "verificar_dispositivo",
        &serde_json::json!({
            "p_cuenta_id": local.cuenta_id,
            "p_huella": local.huella,
        }),
    ) {
        Some(json) if json.get("ok").and_then(Value::as_bool).unwrap_or(false) => {
            local.last_verified_at = now;
            let _ = save_local_account_raw(&app, &local);
            Ok(AccountStatus { valid: true, offline: false, reason: None })
        }
        Some(json) => {
            let _ = delete_local_account(&app);
            Ok(AccountStatus {
                valid: false,
                offline: false,
                reason: json
                    .get("error")
                    .and_then(Value::as_str)
                    .map(String::from)
                    .or(Some("rechazado".into())),
            })
        }
        None => {
            // Sin conexión: permitir uso solo si el reloj no fue
            // manipulado y seguimos dentro de la semana de gracia.
            if clock_tampered {
                return Ok(AccountStatus {
                    valid: false,
                    offline: true,
                    reason: Some("reloj_manipulado".into()),
                });
            }

            let elapsed = now.saturating_sub(local.last_verified_at);
            let grace_seconds = OFFLINE_GRACE_DAYS * 24 * 60 * 60;
            if elapsed <= grace_seconds {
                Ok(AccountStatus { valid: true, offline: true, reason: None })
            } else {
                Ok(AccountStatus {
                    valid: false,
                    offline: true,
                    reason: Some("gracia_offline_vencida".into()),
                })
            }
        }
    }
}

/// Huella del equipo: GUID de máquina de Windows (estable entre arranques,
/// cambia si se reinstala Windows) + nombre de usuario del SO, resumidos en
/// un hash. No es infalsificable — alcanza para distinguir "esta parece ser
/// la misma máquina" al nivel que le importa a este sistema (evitar que
/// copiar la carpeta completa a otra PC siga funcionando sin activar de
/// nuevo), no para seguridad criptográfica.
fn device_fingerprint() -> String {
    use std::collections::hash_map::DefaultHasher;
    use std::hash::{Hash, Hasher};

    let machine_guid = windows_machine_guid().unwrap_or_else(|| "sin-guid".to_string());
    let usuario = std::env::var("USERNAME").unwrap_or_else(|_| "sin-usuario".to_string());

    let mut hasher = DefaultHasher::new();
    machine_guid.hash(&mut hasher);
    usuario.hash(&mut hasher);
    format!("{:016x}", hasher.finish())
}

fn windows_machine_guid() -> Option<String> {
    let mut cmd = Command::new("reg");
    cmd.args([
        "query",
        r"HKLM\SOFTWARE\Microsoft\Cryptography",
        "/v",
        "MachineGuid",
    ]);
    #[cfg(target_os = "windows")]
    cmd.creation_flags(CREATE_NO_WINDOW);

    let output = cmd.output().ok()?;
    let text = String::from_utf8_lossy(&output.stdout);
    // Línea con forma: "    MachineGuid    REG_SZ    xxxxxxxx-xxxx-..."
    text.lines()
        .find(|l| l.contains("MachineGuid"))
        .and_then(|l| l.split_whitespace().last())
        .map(|s| s.to_string())
}

fn device_name() -> String {
    std::env::var("COMPUTERNAME").unwrap_or_else(|_| "PC-sin-nombre".to_string())
}

fn get_account_file_path(app: &AppHandle) -> Result<PathBuf, String> {
    let mut path = app
        .path()
        .app_data_dir()
        .map_err(|e| format!("Error obteniendo app_data_dir: {e}"))?;

    if !path.exists() {
        fs::create_dir_all(&path).map_err(|e| e.to_string())?;
    }

    path.push("account.json");
    Ok(path)
}

fn save_local_account(
    app: &AppHandle,
    correo: &str,
    cuenta_id: &str,
    huella: &str,
) -> Result<(), String> {
    let now = now_secs();
    save_local_account_raw(
        app,
        &LocalAccount {
            correo: correo.to_string(),
            cuenta_id: cuenta_id.to_string(),
            huella: huella.to_string(),
            last_verified_at: now,
            last_seen_system_time: now,
        },
    )
}

fn save_local_account_raw(app: &AppHandle, data: &LocalAccount) -> Result<(), String> {
    let path = get_account_file_path(app)?;
    let json = serde_json::to_string(data).map_err(|e| e.to_string())?;
    fs::write(path, json).map_err(|e| e.to_string())?;
    Ok(())
}

fn load_local_account(app: &AppHandle) -> Result<LocalAccount, String> {
    let path = get_account_file_path(app)?;
    let content = fs::read_to_string(path).map_err(|e| e.to_string())?;
    serde_json::from_str(&content).map_err(|e| e.to_string())
}

fn delete_local_account(app: &AppHandle) -> Result<(), String> {
    let path = get_account_file_path(app)?;
    if path.exists() {
        fs::remove_file(path).map_err(|e| e.to_string())?;
    }
    Ok(())
}

fn now_secs() -> u64 {
    SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .map(|d| d.as_secs())
        .unwrap_or(0)
}
