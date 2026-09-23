//! Motor de licencias (Lemon Squeezy) para DistriVale.
//!
//! Solo usa los endpoints PÚBLICOS de Lemon Squeezy (activate/validate),
//! que reciben únicamente la `license_key` que el usuario final ingresa.
//! La API key de administración de la cuenta Lemon Squeezy NUNCA se usa
//! ni se almacena aquí: no es necesaria para este flujo.
//!
//! Dónde va la licencia del usuario final:
//! - El usuario la ingresa en el formulario de activación (ver frontend).
//! - Una vez activada, se guarda cifrada... en realidad en texto plano pero
//!   local, en `%APPDATA%\DistriVale\license.json` (vía `app_data_dir()`),
//!   junto con el `instance_id` que devuelve Lemon Squeezy. Ese archivo
//!   está excluido del repo por `.gitignore` (`*license*.json`).

use serde::{Deserialize, Serialize};
use std::fs;
use std::path::PathBuf;
use std::time::{SystemTime, UNIX_EPOCH};
use tauri::AppHandle;
use tauri::Manager;

const API_ACTIVATE: &str = "https://api.lemonsqueezy.com/v1/licenses/activate";
const API_VALIDATE: &str = "https://api.lemonsqueezy.com/v1/licenses/validate";

/// Días que la app puede seguir funcionando sin conexión a internet
/// antes de exigir una revalidación exitosa contra el servidor.
const OFFLINE_GRACE_DAYS: u64 = 7;

/// Datos de licencia guardados localmente en el equipo del usuario.
#[derive(Serialize, Deserialize, Debug, Clone)]
pub struct LocalLicense {
    pub license_key: String,
    pub instance_id: String,
    /// Timestamp (segundos desde epoch) de la última validación exitosa contra el servidor.
    pub last_validated_at: u64,
}

#[derive(Deserialize)]
struct LemonInstance {
    id: String,
}

#[derive(Deserialize)]
struct LemonLicenseData {
    activated: Option<bool>,
    valid: Option<bool>,
    error: Option<String>,
    instance: Option<LemonInstance>,
}

/// Resultado que se expone al frontend.
#[derive(Serialize)]
pub struct LicenseStatus {
    pub valid: bool,
    /// true si la validación fue local (sin conexión) dentro del periodo de gracia.
    pub offline: bool,
}

// --- COMANDO 1: Activar licencia por primera vez ---
#[tauri::command]
pub async fn activate_license(app: AppHandle, license_key: String) -> Result<bool, String> {
    let client = reqwest::Client::new();

    let res = client
        .post(API_ACTIVATE)
        .header("Accept", "application/json")
        .form(&[
            ("license_key", license_key.as_str()),
            ("instance_name", "DistriVale Desktop"),
        ])
        .send()
        .await
        .map_err(|e| format!("Error de red: {e}"))?;

    let json: LemonLicenseData = res
        .json()
        .await
        .map_err(|e| format!("Error en respuesta del servidor: {e}"))?;

    if json.activated.unwrap_or(false) {
        let instance_id = json
            .instance
            .map(|i| i.id)
            .ok_or("No se recibió ID de instancia")?;

        save_license_data(&app, &license_key, &instance_id)?;
        Ok(true)
    } else {
        Err(json
            .error
            .unwrap_or_else(|| "Clave de licencia inválida o límite de activaciones excedido.".into()))
    }
}

// --- COMANDO 2: Validar en cada arranque de la app (con soporte offline) ---
#[tauri::command]
pub async fn check_saved_license(app: AppHandle) -> Result<LicenseStatus, String> {
    let mut local_data = match load_license_data(&app) {
        Ok(data) => data,
        Err(_) => return Ok(LicenseStatus { valid: false, offline: false }),
    };

    let client = reqwest::Client::new();

    let res = client
        .post(API_VALIDATE)
        .header("Accept", "application/json")
        .form(&[
            ("license_key", local_data.license_key.as_str()),
            ("instance_id", local_data.instance_id.as_str()),
        ])
        .send()
        .await;

    match res {
        Ok(response) => match response.json::<LemonLicenseData>().await {
            Ok(json) if json.valid.unwrap_or(false) => {
                local_data.last_validated_at = now_secs();
                let _ = save_license_data(&app, &local_data.license_key, &local_data.instance_id);
                Ok(LicenseStatus { valid: true, offline: false })
            }
            _ => {
                // El servidor respondió pero la licencia ya no es válida (revocada/reembolsada).
                let _ = delete_license_data(&app);
                Ok(LicenseStatus { valid: false, offline: false })
            }
        },
        Err(_) => {
            // Sin conexión: permitir uso si estamos dentro del periodo de gracia.
            let elapsed = now_secs().saturating_sub(local_data.last_validated_at);
            let grace_seconds = OFFLINE_GRACE_DAYS * 24 * 60 * 60;
            if elapsed <= grace_seconds {
                Ok(LicenseStatus { valid: true, offline: true })
            } else {
                Ok(LicenseStatus { valid: false, offline: true })
            }
        }
    }
}

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

fn save_license_data(app: &AppHandle, key: &str, instance_id: &str) -> Result<(), String> {
    let path = get_license_file_path(app)?;
    let data = LocalLicense {
        license_key: key.to_string(),
        instance_id: instance_id.to_string(),
        last_validated_at: now_secs(),
    };
    let json = serde_json::to_string(&data).map_err(|e| e.to_string())?;
    fs::write(path, json).map_err(|e| e.to_string())?;
    Ok(())
}

fn load_license_data(app: &AppHandle) -> Result<LocalLicense, String> {
    let path = get_license_file_path(app)?;
    let content = fs::read_to_string(path).map_err(|e| e.to_string())?;
    let data: LocalLicense = serde_json::from_str(&content).map_err(|e| e.to_string())?;
    Ok(data)
}

fn delete_license_data(app: &AppHandle) -> Result<(), String> {
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
