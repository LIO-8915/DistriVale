mod licensing;

// NOTA: este crate todavía no está inicializado como proyecto Tauri real
// (falta `tauri.conf.json`, iconos, `cargo tauri init`). Ver ARQUITECTURA_TAURI.md.
// Cuando se inicialice, el `main()` generado deberá registrar los comandos:
//   tauri::Builder::default()
//       .invoke_handler(tauri::generate_handler![
//           licensing::activate_license,
//           licensing::check_saved_license,
//       ])
//       .run(tauri::generate_context!())
//       .expect("error running DistriVale");

fn main() {
    println!("Hello, world!");
}
