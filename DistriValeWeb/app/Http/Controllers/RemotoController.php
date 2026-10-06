<?php

namespace App\Http\Controllers;

use App\Models\DispositivoRemoto;
use App\Support\AccesoRemoto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Lado "dispositivo" del acceso remoto (iPad, etc.): pedir un código, que la
 * PC lo muestre, escribirlo aquí y quedar autorizado. Todas estas rutas
 * pasan por ControlAcceso (el acceso remoto tiene que estar encendido) pero
 * NO exigen estar autorizado — para eso son. Los límites de abajo son lo que
 * evita que alguien en la red llene la pantalla de la PC de solicitudes o
 * adivine un código:
 *   - un código sirve para 5 intentos y vence a los 5 minutos;
 *   - pedir/regenerar códigos tiene tope por minuto y por IP;
 *   - demasiados intentos fallidos desde una misma IP la frenan un rato;
 *   - nunca hay más de MAX_PENDIENTES solicitudes vivas a la vez.
 */
class RemotoController extends Controller
{
    private const LIM_SOLICITAR = 5;     // por IP, cada 10 min
    private const LIM_REGENERAR = 3;     // por IP, cada minuto
    private const LIM_FALLOS = 15;       // intentos fallidos por IP, cada 10 min

    public function acceso(Request $request)
    {
        if (AccesoRemoto::dispositivoDe($request)) {
            return redirect('/');
        }

        return view('remoto.acceso', [
            'solicitud' => $this->solicitudActual($request),
            'nombreSugerido' => AccesoRemoto::nombreSugerido($request->userAgent()),
            'maxIntentos' => AccesoRemoto::MAX_INTENTOS,
        ]);
    }

    public function solicitar(Request $request)
    {
        $datos = $request->validate(['nombre' => 'nullable|string|max:40']);

        // Si ya hay una solicitud viva en este dispositivo no se crea otra
        // (ni se vuelve a molestar a la PC): para un código nuevo está "regenerar".
        $actual = $this->solicitudActual($request);
        if ($actual && $actual->estado === 'pendiente') {
            return redirect()->route('remoto.acceso');
        }

        $clave = 'remoto-solicitar:'.$request->ip();
        if (RateLimiter::tooManyAttempts($clave, self::LIM_SOLICITAR)) {
            return $this->esperar($clave);
        }

        AccesoRemoto::limpiarSiToca();
        if (DispositivoRemoto::pendientesVigentes()->count() >= AccesoRemoto::MAX_PENDIENTES) {
            return back()->withErrors(['remoto' => 'Hay demasiadas solicitudes pendientes en la computadora. Espera unos minutos y vuelve a intentar.']);
        }

        RateLimiter::hit($clave, 600);

        $nombre = trim((string) ($datos['nombre'] ?? '')) ?: AccesoRemoto::nombreSugerido($request->userAgent());
        $dispositivo = DispositivoRemoto::create([
            'nombre' => $nombre,
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
            'ip' => (string) $request->ip(),
            'estado' => 'pendiente',
            'codigo' => AccesoRemoto::generarCodigo(),
            'intentos' => 0,
            'codigo_expira_en' => now()->addMinutes(AccesoRemoto::VIGENCIA_CODIGO_MIN),
        ]);

        $request->session()->put('remoto_solicitud_id', $dispositivo->id);
        AccesoRemoto::alertar();

        return redirect()->route('remoto.acceso');
    }

    public function verificar(Request $request)
    {
        $request->validate(['codigo' => ['required', 'digits:6']], [
            'codigo.required' => 'Escribe el código de 6 dígitos.',
            'codigo.digits' => 'El código tiene 6 dígitos.',
        ]);

        $claveFallos = 'remoto-fallos:'.$request->ip();
        if (RateLimiter::tooManyAttempts($claveFallos, self::LIM_FALLOS)) {
            return $this->esperar($claveFallos);
        }

        $dispositivo = $this->solicitudActual($request);
        if (! $dispositivo || $dispositivo->estado !== 'pendiente') {
            return redirect()->route('remoto.acceso')->withErrors(['remoto' => 'Primero pide un código.']);
        }

        if (! $dispositivo->codigo || $dispositivo->codigoVencido() || $dispositivo->intentos >= AccesoRemoto::MAX_INTENTOS) {
            return back()->withErrors(['remoto' => 'Ese código ya no sirve. Genera uno nuevo.']);
        }

        if (! hash_equals($dispositivo->codigo, (string) $request->input('codigo'))) {
            $dispositivo->increment('intentos');
            RateLimiter::hit($claveFallos, 600);

            $restantes = AccesoRemoto::MAX_INTENTOS - $dispositivo->intentos;
            if ($restantes <= 0) {
                // Se agotaron los 5 intentos: el código se invalida y hay que pedir otro.
                $dispositivo->update(['codigo' => null]);

                return back()->withErrors(['remoto' => 'Código incorrecto. Se agotaron los intentos: genera un código nuevo.']);
            }

            return back()->withErrors(['remoto' => "Código incorrecto. Te quedan {$restantes} ".($restantes === 1 ? 'intento' : 'intentos').'.']);
        }

        // Código correcto: el dispositivo recibe su propio secreto (en la base
        // solo queda su hash) y queda atado a esta ejecución de la app.
        $token = bin2hex(random_bytes(32));
        $dispositivo->update([
            'estado' => 'autorizado',
            'token_hash' => hash('sha256', $token),
            'boot_id' => AccesoRemoto::bootId(),
            'codigo' => null,
            'autorizado_en' => now(),
            'ultimo_uso_en' => now(),
        ]);

        $request->session()->forget('remoto_solicitud_id');
        $request->session()->regenerate();

        return redirect('/')->withCookie(cookie(AccesoRemoto::COOKIE, $token, 60 * 24 * 30, '/', null, false, true, false, 'lax'));
    }

    public function regenerar(Request $request)
    {
        $dispositivo = $this->solicitudActual($request);
        if (! $dispositivo || $dispositivo->estado !== 'pendiente') {
            return redirect()->route('remoto.acceso');
        }

        $clave = 'remoto-regenerar:'.$request->ip();
        if (RateLimiter::tooManyAttempts($clave, self::LIM_REGENERAR)) {
            return $this->esperar($clave);
        }
        RateLimiter::hit($clave, 60);

        $dispositivo->update([
            'codigo' => AccesoRemoto::generarCodigo(),
            'intentos' => 0,
            'codigo_expira_en' => now()->addMinutes(AccesoRemoto::VIGENCIA_CODIGO_MIN),
        ]);
        AccesoRemoto::alertar();

        return redirect()->route('remoto.acceso');
    }

    /** Lo sondea la propia pantalla del dispositivo para enterarse de rechazos/vencimientos sin recargar. */
    public function estado(Request $request): JsonResponse
    {
        if (AccesoRemoto::dispositivoDe($request)) {
            return response()->json(['estado' => 'autorizado']);
        }

        $dispositivo = $this->solicitudActual($request);
        if (! $dispositivo) {
            return response()->json(['estado' => 'sin_solicitud']);
        }

        $valido = $dispositivo->estado === 'pendiente' && $dispositivo->codigo
            && ! $dispositivo->codigoVencido() && $dispositivo->intentos < AccesoRemoto::MAX_INTENTOS;

        return response()->json([
            'estado' => $dispositivo->estado === 'pendiente' ? ($valido ? 'esperando' : 'codigo_invalido') : $dispositivo->estado,
            'intentos_restantes' => max(0, AccesoRemoto::MAX_INTENTOS - $dispositivo->intentos),
            'expira_en' => $dispositivo->codigo_expira_en?->timestamp,
        ]);
    }

    public function salir(Request $request)
    {
        AccesoRemoto::dispositivoDe($request)?->update(['estado' => 'revocado']);

        return redirect()->route('remoto.acceso')->withCookie(cookie()->forget(AccesoRemoto::COOKIE));
    }

    private function solicitudActual(Request $request): ?DispositivoRemoto
    {
        $id = $request->session()->get('remoto_solicitud_id');
        $dispositivo = $id ? DispositivoRemoto::find($id) : null;

        return ($dispositivo && in_array($dispositivo->estado, ['pendiente', 'rechazado'], true)) ? $dispositivo : null;
    }

    private function esperar(string $clave)
    {
        $min = max(1, (int) ceil(RateLimiter::availableIn($clave) / 60));

        return back()->withErrors(['remoto' => "Demasiados intentos. Espera {$min} ".($min === 1 ? 'minuto' : 'minutos').' e inténtalo de nuevo.']);
    }
}
