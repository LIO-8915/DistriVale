<?php

namespace App\Http\Middleware;

use App\Support\Mora;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ActualizarMora
{
    public function handle(Request $request, Closure $next): Response
    {
        Mora::actualizarSiToca();

        return $next($request);
    }
}
