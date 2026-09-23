<?php

namespace App\Providers;

use App\Models\Vale;
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
        // The notification bell lives in the shared layout, so every page
        // needs the auto-calculated overdue-payment list for its modal.
        View::composer('layouts.app', function ($view) {
            $view->with(
                'vencimientos',
                Vale::with('cliente', 'financiera')->where('estado', 'EN_MORA')->latest('updated_at')->get()
            );
        });
    }
}
