<?php

namespace App\Support;

use App\Models\DispositivoRemoto;
use Illuminate\Http\Request;

/**
 * Acceso remoto por red local (p. ej. desde un iPad con el navegador).
 *
 * Quién hace qué:
 *  - Laravel decide QUIÉN entra: la PC (127.0.0.1) entra directo; cualquier
 *    otro equipo debe emparejarse con un código de 6 dígitos que solo se
 *    muestra en la pantalla de la PC (ver App\Http\Middleware\ControlAcceso).
 *  - El shell de Tauri (main.rs) decide SI el puerto está abierto a la red:
 *    es el dueño de Caddy, así que él cambia su `bind`, crea la regla de
 *    firewall, evita la suspensión y hace sonar/parpadear la ventana.
 *
 * Se hablan por archivos en DV_CONTROL_DIR (que main.rs crea y le pasa a los
 * php-cgi como variable de entorno) — sin puertos extra ni IPC:
 *   boot_id            (Rust → Laravel) id de esta ejecución de la app.
 *   remoto.json        (Laravel → Rust) {"habilitado": bool, "puerto": int}.
 *   remoto-estado.json (Rust → Laravel) estado real: activo, ip, puerto, firewall…
 *   accion.json        (Laravel → Rust) {"id", "accion"} — acciones del sistema.
 *   alerta.json        (Laravel → Rust) {"contador"} — llegó una solicitud nueva.
 *
 * Sin DV_CONTROL_DIR (p. ej. `php artisan serve` a mano, o el camino de
 * respaldo del shell) el acceso remoto simplemente no está disponible y todo
 * lo que no venga de la propia PC se rechaza.
 */
class AccesoRemoto
{
    public const MAX_INTENTOS = 5;
    public const VIGENCIA_CODIGO_MIN = 5;
    public const MAX_PENDIENTES = 5;
    public const PUERTO_POR_DEFECTO = 8712;
    public const COOKIE = 'dv_dispositivo';

    /** main.rs reescribe el estado al menos cada ~5 s; más viejo que esto = Rust ya no está. */
    private const ESTADO_FRESCO_SEG = 15;

    /**
     * ¿La petición viene de la propia PC? Se mira SOLO la IP del socket
     * (REMOTE_ADDR): las cabeceras tipo X-Forwarded-For las controla quien
     * hace la petición, así que no sirven para decidir esto.
     */
    public static function esLocal(Request $request): bool
    {
        $ip = (string) $request->server('REMOTE_ADDR', '');

        return $ip === '::1' || str_starts_with($ip, '127.') || str_starts_with($ip, '::ffff:127.');
    }

    public static function controlDir(): ?string
    {
        $dir = getenv('DV_CONTROL_DIR') ?: ($_SERVER['DV_CONTROL_DIR'] ?? null);

        return is_string($dir) && $dir !== '' && is_dir($dir) ? rtrim($dir, '\\/') : null;
    }

    public static function bootId(): string
    {
        $dir = self::controlDir();
        $archivo = $dir ? $dir.DIRECTORY_SEPARATOR.'boot_id' : null;

        return ($archivo && is_file($archivo)) ? trim((string) @file_get_contents($archivo)) : 'dev';
    }

    /**
     * Estado REAL del acceso remoto según el shell (Rust). Si Rust no está
     * (sin control dir, camino de respaldo, o dejó de actualizar el archivo)
     * `disponible` es false y nada remoto debe pasar.
     *
     * @return array{disponible: bool, activo: bool, ip: ?string, puerto: ?int, url: ?string, firewall: string, perfil_red: ?string, error: ?string, accion: ?array}
     */
    public static function estado(): array
    {
        $base = [
            'disponible' => false, 'activo' => false, 'ip' => null, 'puerto' => null, 'url' => null,
            'firewall' => 'desconocido', 'perfil_red' => null, 'error' => null, 'accion' => null,
        ];

        $dir = self::controlDir();
        $archivo = $dir ? $dir.DIRECTORY_SEPARATOR.'remoto-estado.json' : null;
        if (! $archivo || ! is_file($archivo) || (time() - (int) @filemtime($archivo)) > self::ESTADO_FRESCO_SEG) {
            return $base;
        }

        $json = json_decode((string) @file_get_contents($archivo), true);
        if (! is_array($json) || ! ($json['stack'] ?? false)) {
            return $base;
        }

        return array_merge($base, array_intersect_key($json, $base), ['disponible' => true]);
    }

    public static function activo(): bool
    {
        $estado = self::estado();

        return $estado['disponible'] && $estado['activo'];
    }

    /** Lo que pidió el usuario (puede no estar aplicado todavía: Rust lo toma en ~1 s). */
    public static function deseado(): bool
    {
        $dir = self::controlDir();
        $archivo = $dir ? $dir.DIRECTORY_SEPARATOR.'remoto.json' : null;
        $json = ($archivo && is_file($archivo)) ? json_decode((string) @file_get_contents($archivo), true) : null;

        return is_array($json) && ($json['habilitado'] ?? false) === true;
    }

    public static function pedirHabilitado(bool $habilitado): bool
    {
        $dir = self::controlDir();
        if (! $dir) {
            return false;
        }

        self::escribirJson($dir.DIRECTORY_SEPARATOR.'remoto.json', [
            'habilitado' => $habilitado,
            'puerto' => self::PUERTO_POR_DEFECTO,
        ]);

        return true;
    }

    /** @return string|null id de la acción pedida (para seguirla en estado()['accion']) */
    public static function pedirAccion(string $accion): ?string
    {
        $dir = self::controlDir();
        if (! $dir) {
            return null;
        }

        $id = bin2hex(random_bytes(6));
        self::escribirJson($dir.DIRECTORY_SEPARATOR.'accion.json', ['id' => $id, 'accion' => $accion]);

        return $id;
    }

    /** Avisa al shell que llegó una solicitud: ventana al frente, parpadeo y sonido. */
    public static function alertar(): void
    {
        $dir = self::controlDir();
        if ($dir) {
            // Milisegundos: siempre crece, así el shell solo compara "cambió" sin leer-antes-de-escribir.
            self::escribirJson($dir.DIRECTORY_SEPARATOR.'alerta.json', ['contador' => (int) (microtime(true) * 1000)]);
        }
    }

    // --- Dispositivos ---

    public static function generarCodigo(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /** Dispositivo autorizado dueño de la cookie de esta petición (o null). */
    public static function dispositivoDe(Request $request): ?DispositivoRemoto
    {
        $token = $request->cookie(self::COOKIE);
        if (! is_string($token) || strlen($token) !== 64 || ! ctype_xdigit($token)) {
            return null;
        }

        $dispositivo = DispositivoRemoto::where('token_hash', hash('sha256', $token))->first();

        // La autorización dura lo que dure ESTA ejecución de la app: otro
        // boot_id (app reiniciada) o un estado distinto (revocado) la anula.
        if (! $dispositivo || $dispositivo->estado !== 'autorizado'
            || ! hash_equals((string) $dispositivo->boot_id, self::bootId())) {
            return null;
        }

        return $dispositivo;
    }

    /** Marca actividad como mucho una vez por minuto (no un UPDATE por cada pantalla). */
    public static function tocarUso(DispositivoRemoto $dispositivo): void
    {
        if (! $dispositivo->ultimo_uso_en || now()->timestamp - $dispositivo->ultimo_uso_en->timestamp >= 60) {
            $dispositivo->forceFill(['ultimo_uso_en' => now()])->saveQuietly();
        }
    }

    /** Quita el acceso a todos (al apagar el acceso remoto) y vence las solicitudes en curso. */
    public static function revocarTodos(): void
    {
        DispositivoRemoto::where('estado', 'autorizado')->update(['estado' => 'revocado', 'updated_at' => now()]);
        DispositivoRemoto::where('estado', 'pendiente')->update(['estado' => 'rechazado', 'codigo' => null, 'updated_at' => now()]);
    }

    /** Autorizaciones de ejecuciones anteriores ya no valen; y se tira la basura vieja. */
    public static function limpiar(): void
    {
        DispositivoRemoto::where('estado', 'autorizado')->where('boot_id', '!=', self::bootId())
            ->update(['estado' => 'revocado', 'updated_at' => now()]);
        DispositivoRemoto::where('estado', 'pendiente')->where('codigo_expira_en', '<', now())
            ->update(['codigo' => null, 'updated_at' => now()]);
        DispositivoRemoto::whereIn('estado', ['pendiente', 'rechazado', 'revocado'])
            ->where('updated_at', '<', now()->subDays(7))->delete();
    }

    /** `limpiar()` como mucho una vez por minuto — las pantallas sondean cada pocos segundos. */
    public static function limpiarSiToca(): void
    {
        if (\Illuminate\Support\Facades\Cache::add('dv:remoto:limpieza', true, now()->addMinute())) {
            self::limpiar();
        }
    }

    /** Nombre sugerido para un dispositivo a partir de su navegador (el usuario lo puede editar). */
    public static function nombreSugerido(?string $userAgent): string
    {
        $ua = (string) $userAgent;

        return match (true) {
            str_contains($ua, 'iPad') => 'iPad',
            str_contains($ua, 'iPhone') => 'iPhone',
            str_contains($ua, 'Android') => 'Dispositivo Android',
            str_contains($ua, 'Windows') => 'Equipo Windows',
            str_contains($ua, 'Macintosh') => 'Equipo Mac / iPad',
            default => 'Dispositivo remoto',
        };
    }

    private static function escribirJson(string $ruta, array $datos): void
    {
        $contenido = json_encode($datos, JSON_UNESCAPED_SLASHES);
        $tmp = $ruta.'.'.getmypid().'.tmp';

        // Escribir a un temporal y renombrar: quien lea (Rust) nunca ve un JSON a medias.
        if (@file_put_contents($tmp, $contenido) !== false && @rename($tmp, $ruta)) {
            return;
        }

        @unlink($tmp);
        @file_put_contents($ruta, $contenido, LOCK_EX);
    }
}
