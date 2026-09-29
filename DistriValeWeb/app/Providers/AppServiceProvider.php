<?php

namespace App\Providers;

use App\Models\PerfilUsuario;
use App\Models\Vale;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Laravel pagina con plantillas de Tailwind por defecto; esta app solo
        // carga Bootstrap, así que sin esto las flechas SVG de ->links() se
        // dibujan a tamaño completo y tapan las tablas.
        Paginator::useBootstrapFive();

        // Sin subsetting, dompdf incrusta DejaVu Sans completa (~850 KB por
        // PDF). El servidor integrado de PHP en Windows (php artisan serve,
        // el que arranca Tauri) a veces trunca respuestas de ese tamaño y el
        // PDF llega corrupto; con solo los glifos usados pesa unas decenas de KB.
        config(['dompdf.options.enable_font_subsetting' => true]);

        // The notification bell lives in the shared layout, so every page
        // needs the auto-calculated overdue-payment list for its modal.
        // El avatar/nombre de la barra superior también vive ahí — misma
        // razón para compartirlo vía composer en vez de repetirlo por
        // controlador.
        View::composer('layouts.app', function ($view) {
            $view->with([
                'vencimientos' => Vale::with('cliente', 'financiera')->where('estado', 'EN_MORA')->latest('updated_at')->get(),
                'perfilUsuario' => PerfilUsuario::actual(),
            ]);
        });
    }
}
