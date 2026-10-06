<?php

namespace App\Http\Middleware;

use App\Support\AccesoRemoto;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * El candado del acceso remoto. La PC donde corre DistriVale (127.0.0.1)
 * pasa directo; cualquier otro equipo solo entra si el acceso remoto está
 * encendido Y trae la cookie de un dispositivo emparejado con código (ver
 * App\Support\AccesoRemoto y RemotoController).
 */
class ControlAcceso
{
    public function handle(Request $request, Closure $next): Response
    {
        if (AccesoRemoto::esLocal($request)) {
            return $next($request);
        }

        // Desde otro equipo: nada pasa si el acceso remoto no está encendido
        // de verdad (o si no hay shell de escritorio que lo gobierne).
        if (! AccesoRemoto::activo()) {
            abort(403, 'El acceso remoto está desactivado.');
        }

        // Las pantallas del propio emparejamiento tienen que poder abrirse
        // sin estar autorizado — cada una valida lo suyo (código, límites).
        if ($request->routeIs('remoto.*')) {
            return $next($request);
        }

        $dispositivo = AccesoRemoto::dispositivoDe($request);
        if ($dispositivo) {
            AccesoRemoto::tocarUso($dispositivo);
            $request->attributes->set('dispositivo_remoto', $dispositivo);

            return $next($request);
        }

        // La navegación interna de la app (dv-nav.js) hace fetch y, si recibe
        // un error, recarga la página completa — ahí sí llega al redirect.
        if ($request->hasHeader('X-DV-Nav') || $request->ajax() || $request->expectsJson()) {
            abort(401, 'Este dispositivo no está autorizado.');
        }

        return redirect()->route('remoto.acceso');
    }
}
