//! Puente hacia Supabase para todo lo que NO es "¿esta licencia sigue
//! siendo válida?" — esa pregunta la resuelve Lemon Squeezy directo (ver
//! `licensing.rs`), con sus propios endpoints públicos activate/validate.
//! Este módulo se ocupa de la auditoría alrededor de esa licencia: dejar
//! un espejo de cuenta/dispositivo en Supabase (`registrar_cuenta_licencia`,
//! llamada una vez al activar), un latido en cada arranque
//! (`latir_dispositivo`, que reusa `verificar_dispositivo` — ver
//! `supabase-verificar-dispositivo.sql`), y subir lo que detecte el
//! módulo de monitoreo local (`monitoreo_local.rs`).
//!
//! Supabase puede seguir vetando el acceso aunque Lemon Squeezy diga que
//! la licencia es válida: si `perfiles.estado` deja de ser `'aprobado'`
//! o el dispositivo queda `'revocado'` (herramientas manuales que ya
//! tiene AdminDistriVale), `latir_dispositivo` devuelve un rechazo
//! explícito y `licensing::check_saved_account` lo trata como inválido
//! — es el kill-switch rápido del admin, sin depender de que Lemon
//! Squeezy también desactive la instancia. Solo cuando Supabase no
//! responde (sin internet) se ignora, porque ahí Lemon Squeezy ya dio
//! su propio veredicto y hay gracia offline de sobra en `licensing.rs`.
//!
//! Las llamadas a Supabase se hacen invocando `curl.exe` como subproceso
//! en vez de usar `reqwest` (que sí se usa para Lemon Squeezy en
//! `licensing.rs`): en pruebas reales, `reqwest` mostró un patrón
//! reproducible de "éxito fantasma" contra el REST/RPC de Supabase — el
//! servidor procesaba y confirmaba la llamada, pero la respuesta que el
//! proceso Rust terminaba viendo era la de un segundo intento contra un
//! recurso ya consumido, incluso con un cliente nuevo, sin *connection
//! pooling*, forzando HTTP/1.1 y en un binario aislado sin Tauri de por
//! medio. `curl.exe` contra el mismo endpoint, mismos parámetros, nunca
//! mostró el problema.

use serde::Serialize;
use serde_json::Value;
use sha2::{Digest, Sha256};
use std::process::Command;

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

#[derive(Serialize)]
pub struct RegistroCuenta {
    pub ok: bool,
    pub cuenta_id: Option<String>,
    pub error: Option<String>,
}

/// Resultado de un latido (`verificar_dispositivo`) contra Supabase.
pub enum Latido {
    /// Supabase confirmó que la cuenta y el dispositivo siguen bien.
    Ok,
    /// Supabase respondió, pero rechazó explícitamente (cuenta no
    /// aprobada / dispositivo revocado) — esto SÍ debe bloquear el
    /// acceso aunque Lemon Squeezy diga que la licencia es válida.
    Rechazado(String),
    /// No se pudo conectar — no implica nada, Lemon Squeezy ya dio su
    /// veredicto y es quien manda cuando no hay red.
    SinConexion,
}

/// Llama una función RPC de Supabase vía `curl.exe` y devuelve el JSON ya
/// parseado. `None` significa "no se pudo conectar" (sin internet, DNS,
/// timeout) — se distingue de un error de la propia función (que sí
/// devuelve JSON con `ok:false`).
pub(crate) fn call_rpc(function: &str, body: &Value) -> Option<Value> {
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

/// Se llama una sola vez, justo después de que Lemon Squeezy confirmó
/// `activated: true` para la license key. Deja el espejo de cuenta +
/// dispositivo en Supabase (autoaprobado: la validez real ya la dio
/// Lemon Squeezy) — ver `registrar_cuenta_licencia()` en
/// `supabase-licencias-lemonsqueezy.sql`.
pub fn registrar_cuenta_licencia(
    license_key: &str,
    huella: &str,
    instance_id: &str,
    nombre: &str,
    limite_equipos: i64,
) -> Option<RegistroCuenta> {
    let hash = hash_license_key(license_key);

    let json = call_rpc(
        "registrar_cuenta_licencia",
        &serde_json::json!({
            "p_license_key_hash": hash,
            "p_huella": huella,
            "p_instance_id": instance_id,
            "p_nombre": nombre,
            "p_limite_equipos": limite_equipos,
        }),
    )?;

    let ok = json.get("ok").and_then(Value::as_bool).unwrap_or(false);
    Some(RegistroCuenta {
        ok,
        cuenta_id: json
            .get("cuenta_id")
            .and_then(Value::as_str)
            .map(String::from),
        error: json.get("error").and_then(Value::as_str).map(String::from),
    })
}

/// Latido de cada arranque: confirma contra Supabase que la cuenta sigue
/// aprobada y el dispositivo sigue activo, y actualiza `ultima_vez`.
/// Reusa `verificar_dispositivo()`, ya existente desde el flujo anterior
/// de correo + código — el contrato (cuenta_id + huella) es el mismo.
pub fn latir_dispositivo(cuenta_id: &str, huella: &str) -> Latido {
    match call_rpc(
        "verificar_dispositivo",
        &serde_json::json!({
            "p_cuenta_id": cuenta_id,
            "p_huella": huella,
        }),
    ) {
        Some(json) if json.get("ok").and_then(Value::as_bool).unwrap_or(false) => Latido::Ok,
        Some(json) => Latido::Rechazado(
            json.get("error")
                .and_then(Value::as_str)
                .unwrap_or("rechazado")
                .to_string(),
        ),
        None => Latido::SinConexion,
    }
}

fn hash_license_key(license_key: &str) -> String {
    let mut hasher = Sha256::new();
    hasher.update(license_key.trim().as_bytes());
    format!("{:x}", hasher.finalize())
}

/// Huella del equipo: GUID de máquina de Windows (estable entre arranques,
/// cambia si se reinstala Windows) + nombre de usuario del SO, resumidos en
/// un hash. No es infalsificable — alcanza para distinguir "esta parece ser
/// la misma máquina" al nivel que le importa a este sistema (evitar que
/// copiar la carpeta completa a otra PC siga funcionando sin activar de
/// nuevo), no para seguridad criptográfica.
pub(crate) fn device_fingerprint() -> String {
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

pub(crate) fn device_name() -> String {
    std::env::var("COMPUTERNAME").unwrap_or_else(|_| "PC-sin-nombre".to_string())
}
