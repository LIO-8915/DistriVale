//! Módulo de actividades de monitoreo local: detecta anomalías del lado
//! del dispositivo (reloj manipulado, huella de hardware que cambió,
//! fallos repetidos de validación) y las sube a Supabase
//! (`registrar_evento_monitoreo`, tabla `logs_actividad`, y `alertas`
//! cuando el tipo amerita atención) para que se puedan revisar desde
//! AdminDistriVale.
//!
//! Este módulo nunca decide si la app puede arrancar — eso lo resuelve
//! Lemon Squeezy (validez de la licencia) y, como kill-switch manual,
//! el latido contra Supabase (ver `supabase_licensing::latir_dispositivo`).
//! Es pura auditoría: ver algo acá no debería, por sí solo, tumbar el
//! acceso de nadie.
//!
//! Si no hay conexión al momento de detectar el evento, queda encolado
//! en disco (`eventos_monitoreo.json`, junto a `license.json`) y se
//! reintenta subir en el siguiente arranque, antes de descartarse.

use serde::{Deserialize, Serialize};
use std::fs;
use std::path::PathBuf;
use tauri::AppHandle;
use tauri::Manager;

use crate::supabase_licensing::call_rpc;

#[derive(Serialize, Deserialize, Debug, Clone)]
struct EventoPendiente {
    tipo: String,
    detalle: String,
}

/// Registra un evento local y trata de subirlo de inmediato junto con
/// cualquier otro que haya quedado pendiente de un arranque anterior.
pub fn registrar_evento(app: &AppHandle, cuenta_id: &str, huella: &str, tipo: &str, detalle: &str) {
    let mut pendientes = cargar_pendientes(app);
    pendientes.push(EventoPendiente {
        tipo: tipo.to_string(),
        detalle: detalle.to_string(),
    });
    guardar_pendientes(app, &pendientes);

    subir_pendientes(app, cuenta_id, huella);
}

/// Reintenta subir lo que haya quedado en la cola — se llama en cada
/// latido exitoso, no solo cuando se detecta un evento nuevo, para que
/// lo acumulado offline no espere a la próxima anomalía para subirse.
pub fn subir_pendientes(app: &AppHandle, cuenta_id: &str, huella: &str) {
    let mut pendientes = cargar_pendientes(app);
    if pendientes.is_empty() {
        return;
    }

    pendientes.retain(|ev| {
        let subido = call_rpc(
            "registrar_evento_monitoreo",
            &serde_json::json!({
                "p_cuenta_id": cuenta_id,
                "p_huella": huella,
                "p_tipo": ev.tipo,
                "p_detalle": ev.detalle,
            }),
        )
        .and_then(|j| j.get("ok").and_then(serde_json::Value::as_bool))
        .unwrap_or(false);
        !subido // se queda en la cola solo si no se pudo subir
    });

    guardar_pendientes(app, &pendientes);
}

fn cola_path(app: &AppHandle) -> Option<PathBuf> {
    let mut path = app.path().app_data_dir().ok()?;
    if !path.exists() {
        fs::create_dir_all(&path).ok()?;
    }
    path.push("eventos_monitoreo.json");
    Some(path)
}

fn cargar_pendientes(app: &AppHandle) -> Vec<EventoPendiente> {
    let Some(path) = cola_path(app) else { return Vec::new() };
    fs::read_to_string(path)
        .ok()
        .and_then(|s| serde_json::from_str(&s).ok())
        .unwrap_or_default()
}

fn guardar_pendientes(app: &AppHandle, pendientes: &[EventoPendiente]) {
    let Some(path) = cola_path(app) else { return };
    if pendientes.is_empty() {
        let _ = fs::remove_file(path);
        return;
    }
    if let Ok(json) = serde_json::to_string(pendientes) {
        let _ = fs::write(path, json);
    }
}
