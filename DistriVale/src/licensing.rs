//! Motor de licencias (Lemon Squeezy) para DistriVale.
//!
//! Lemon Squeezy es la ÚNICA fuente de verdad de si la licencia de un
//! cliente sigue activa y de cuántos equipos puede tener activados al
//! mismo tiempo (`activation_limit`). Solo se usan sus endpoints
//! PÚBLICOS (activate/validate), que reciben nada más la `license_key`
//! que el usuario final ingresa — la API key de administración de la
//! cuenta Lemon Squeezy nunca se usa ni se guarda acá.
//!
//! Supabase (`supabase_licensing.rs`) no decide nada de esto: guarda un
//! espejo de cuenta/dispositivo para auditoría y detección de anomalías,
//! y sigue teniendo un veto manual rápido para el admin (suspender una
//! cuenta o revocar un dispositivo ahí bloquea el acceso en el siguiente
//! arranque, sin depender de Lemon Squeezy) — ver el `match` de
//! `check_saved_account` más abajo para cómo se combinan los dos.
//!
//! La licencia activada se guarda en `%APPDATA%\DistriVale\license.json`
//! (vía `app_data_dir()`), excluido del repo por `.gitignore`.

use serde::{Deserialize, Serialize};
use std::fs;
use std::path::PathBuf;
use std::time::{SystemTime, UNIX_EPOCH};
use tauri::AppHandle;
use tauri::Manager;

use crate::monitoreo_local;
use crate::supabase_licensing::{self, Latido};

const API_ACTIVATE: &str = "https://api.lemonsqueezy.com/v1/licenses/activate";
const API_VALIDATE: &str = "https://api.lemonsqueezy.com/v1/licenses/validate";

/// Días que la app puede seguir funcionando sin conexión a internet
/// antes de exigir una revalidación exitosa contra el servidor.
const OFFLINE_GRACE_DAYS: u64 = 7;

/// Tolerancia sobre el reloj del sistema: un margen chico (relojes que se
/// desincronizan unos minutos por NTP/cambios de huso horario es normal),
/// pero si el reloj aparece más atrás que esto respecto a la última vez
/// que la app corrió, se trata como manipulación deliberada del reloj
/// para estirar la gracia offline, y se fuerza a revalidar en línea.
const CLOCK_TAMPER_TOLERANCE_SECS: i64 = 10 * 60;

/// Datos de licencia guardados localmente en el equipo del usuario.
#[derive(Serialize, Deserialize, Debug, Clone)]
pub struct LocalLicense {
    pub license_key: String,
    pub instance_id: String,
    pub cuenta_id: String,
    pub huella: String,
    pub limite_equipos: i64,
    /// Timestamp (segundos desde epoch) de la última validación exitosa contra Lemon Squeezy.
    pub last_verified_at: u64,
    /// Marca de más alto valor vista del reloj del sistema en cualquier
    /// arranque anterior — un "high-water mark", nunca retrocede sola.
    pub last_seen_system_time: u64,
}

#[derive(Deserialize)]
struct LemonInstance {
    id: String,
}

#[derive(Deserialize)]
struct LemonLicenseKeyInfo {
    activation_limit: Option<i64>,
}

#[derive(Deserialize)]
struct LemonLicenseData {
    activated: Option<bool>,
    valid: Option<bool>,
    error: Option<String>,
    instance: Option<LemonInstance>,
    license_key: Option<LemonLicenseKeyInfo>,
}

/// Resultado que se expone al frontend.
#[derive(Serialize)]
pub struct AccountStatus {
    pub valid: bool,
    /// true si la validación fue local (sin conexión) dentro del periodo de gracia.
    pub offline: bool,
    pub reason: Option<String>,
}

// --- COMANDO 1: activar con la license key de Lemon Squeezy ---
#[tauri::command]
pub async fn activar_cuenta(app: AppHandle, license_key: String) -> Result<bool, String> {
    let license_key = license_key.trim().to_string();
    let huella = supabase_licensing::device_fingerprint();
    let nombre = supabase_licensing::device_name();

    let client = reqwest::Client::new();
    let res = client
        .post(API_ACTIVATE)
        .header("Accept", "application/json")
        .form(&[
            ("license_key", license_key.as_str()),
            ("instance_name", huella.as_str()),
        ])
        .send()
        .await
        .map_err(|e| format!("Error de red: {e}"))?;

    let json: LemonLicenseData = res
        .json()
        .await
        .map_err(|e| format!("Error en respuesta del servidor: {e}"))?;

    if !json.activated.unwrap_or(false) {
        return Err(json
            .error
            .unwrap_or_else(|| "Clave de licencia inválida o límite de equipos alcanzado.".into()));
    }

    let instance_id = json
        .instance
        .map(|i| i.id)
        .ok_or("No se recibió ID de instancia de Lemon Squeezy")?;
    let limite_equipos = json
        .license_key
        .and_then(|k| k.activation_limit)
        .unwrap_or(1);

    let registro = supabase_licensing::registrar_cuenta_licencia(
        &license_key,
        &huella,
        &instance_id,
        &nombre,
        limite_equipos,
    )
    .ok_or(
        "La licencia se activó en Lemon Squeezy, pero no se pudo registrar en Supabase. \
         Revisa tu conexión a internet e intenta de nuevo.",
    )?;

    if !registro.ok {
        return Err(registro
            .error
            .unwrap_or_else(|| "No se pudo completar el registro de la cuenta.".into()));
    }

    let cuenta_id = registro.cuenta_id.unwrap_or_default();
    save_local_license(
        &app,
        &LocalLicense {
            license_key,
            instance_id,
            cuenta_id,
            huella,
            limite_equipos,
            last_verified_at: now_secs(),
            last_seen_system_time: now_secs(),
        },
    )?;

    Ok(true)
}

// --- COMANDO 2: revalidar en cada arranque (con soporte offline) ---
#[tauri::command]
pub async fn check_saved_account(app: AppHandle) -> Result<AccountStatus, String> {
    let mut local = match load_local_license(&app) {
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

    if clock_tampered {
        monitoreo_local::registrar_evento(
            &app,
            &local.cuenta_id,
            &local.huella,
            "reloj_manipulado",
            &format!(
                "high-water mark {} > hora actual {}",
                local.last_seen_system_time, now
            ),
        );
    }

    local.last_seen_system_time = local.last_seen_system_time.max(now);
    let _ = save_local_license(&app, &local);

    let huella_actual = supabase_licensing::device_fingerprint();
    if huella_actual != local.huella {
        monitoreo_local::registrar_evento(
            &app,
            &local.cuenta_id,
            &local.huella,
            "huella_cambiada",
            &format!("guardada {}, detectada ahora {}", local.huella, huella_actual),
        );
    }

    let client = reqwest::Client::new();
    let res = client
        .post(API_VALIDATE)
        .header("Accept", "application/json")
        .form(&[
            ("license_key", local.license_key.as_str()),
            ("instance_id", local.instance_id.as_str()),
        ])
        .send()
        .await;

    match res {
        Ok(response) => match response.json::<LemonLicenseData>().await {
            Ok(json) if json.valid.unwrap_or(false) => {
                // Lemon Squeezy dice que la licencia es válida. Supabase
                // todavía puede vetar el acceso (kill-switch manual del
                // admin) — pero solo si respondió; sin conexión, Lemon
                // Squeezy ya dio su veredicto y manda.
                match supabase_licensing::latir_dispositivo(&local.cuenta_id, &local.huella) {
                    Latido::Rechazado(motivo) => {
                        let _ = delete_local_license(&app);
                        return Ok(AccountStatus {
                            valid: false,
                            offline: false,
                            reason: Some(motivo),
                        });
                    }
                    Latido::Ok | Latido::SinConexion => {}
                }

                local.last_verified_at = now;
                let _ = save_local_license(&app, &local);
                monitoreo_local::subir_pendientes(&app, &local.cuenta_id, &local.huella);

                Ok(AccountStatus {
                    valid: true,
                    offline: false,
                    reason: None,
                })
            }
            _ => {
                // El servidor respondió pero la licencia ya no es válida (revocada/cancelada).
                let _ = delete_local_license(&app);
                Ok(AccountStatus {
                    valid: false,
                    offline: false,
                    reason: Some("licencia_invalida".into()),
                })
            }
        },
        Err(_) => {
            // Sin conexión a Lemon Squeezy: permitir uso si estamos
            // dentro del periodo de gracia y el reloj no fue manipulado.
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
                Ok(AccountStatus {
                    valid: true,
                    offline: true,
                    reason: None,
                })
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

// --- SOLO DESARROLLO: saltarse la activación / limpiar al salir ---
//
// Todo lo de acá abajo existe únicamente en builds de debug
// (`cargo run` / `cargo build` sin `--release`) — en release, que es lo
// que se compila para el cliente, este código ni siquiera se incluye en
// el binario (no es solo "desactivado", literalmente no existe: no hay
// forma de activarlo por accidente en el .exe que se distribuye, ni de
// encontrar el nombre de estas variables haciendo `strings` sobre él).
//
// Nada de esto se guarda en el repo: son variables de entorno que cada
// quien setea localmente antes de correr `cargo run`, nunca un valor
// hardcodeado en el código ni en un archivo versionado.
//
//   - DISTRIVALE_DEV_SKIP_LICENSE=1
//       Salta por completo la pantalla de activación (no llama a Lemon
//       Squeezy ni a Supabase) y arranca directo. Para cuando estás
//       trabajando en cualquier otra parte de la app y la licencia no
//       importa.
//   - DISTRIVALE_DEV_CLEAR_LICENSE_ON_EXIT=1
//       Borra license.json al cerrar la ventana, así cada arranque
//       vuelve a pedir activación desde cero. Para cuando sí estás
//       probando el flujo de activación en sí con una license key de
//       prueba de Lemon Squeezy, sin tener que borrar el archivo a mano
//       cada vez.

#[cfg(debug_assertions)]
pub fn dev_saltar_activacion() -> bool {
    std::env::var("DISTRIVALE_DEV_SKIP_LICENSE").is_ok()
}
#[cfg(not(debug_assertions))]
pub fn dev_saltar_activacion() -> bool {
    false
}

#[cfg(debug_assertions)]
pub fn dev_limpiar_licencia_al_salir(app: &AppHandle) {
    if std::env::var("DISTRIVALE_DEV_CLEAR_LICENSE_ON_EXIT").is_ok() {
        let _ = delete_local_license(app);
    }
}
#[cfg(not(debug_assertions))]
pub fn dev_limpiar_licencia_al_salir(_app: &AppHandle) {}

// --- MÉTODOS AUXILIARES: Guardar / Cargar / Borrar en disco ---

fn get_license_file_path(app: &AppHandle) -> Result<PathBuf, String> {
    let mut path = app
        .path()
        .app_data_dir()
        .map_err(|e| format!("Error obteniendo app_data_dir: {e}"))?;

    if !path.exists() {
        fs::create_dir_all(&path).map_err(|e| e.to_string())?;
    }

    path.push("license.json");
    Ok(path)
}

fn save_local_license(app: &AppHandle, data: &LocalLicense) -> Result<(), String> {
    let path = get_license_file_path(app)?;
    let json = serde_json::to_string(data).map_err(|e| e.to_string())?;
    fs::write(path, json).map_err(|e| e.to_string())?;
    Ok(())
}

fn load_local_license(app: &AppHandle) -> Result<LocalLicense, String> {
    let path = get_license_file_path(app)?;
    let content = fs::read_to_string(path).map_err(|e| e.to_string())?;
    let data: LocalLicense = serde_json::from_str(&content).map_err(|e| e.to_string())?;
    Ok(data)
}

fn delete_local_license(app: &AppHandle) -> Result<(), String> {
    let path = get_license_file_path(app)?;
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
