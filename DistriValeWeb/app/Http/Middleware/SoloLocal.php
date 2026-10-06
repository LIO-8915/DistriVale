<?php

namespace App\Http\Middleware;

use App\Support\AccesoRemoto;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Acciones que solo tiene sentido (o solo es seguro) hacer desde la propia
 * PC aunque un dispositivo remoto ya esté autorizado: administrar el acceso
 * remoto, conectar Google Drive (el login se abre en la PC), restaurar la base.
 */
class SoloLocal
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! AccesoRemoto::esLocal($request)) {
            abort(403, 'Esto solo se puede hacer desde la computadora donde corre DistriVale.');
        }

        return $next($request);
    }
}
