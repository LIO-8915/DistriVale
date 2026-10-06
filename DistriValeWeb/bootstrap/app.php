<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // ControlAcceso va antes de ActualizarMora: una petición remota sin
        // autorizar no debe disparar trabajo de la app.
        $middleware->web(append: [
            \App\Http\Middleware\ControlAcceso::class,
            \App\Http\Middleware\ActualizarMora::class,
        ]);
        $middleware->alias(['solo.local' => \App\Http\Middleware\SoloLocal::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
