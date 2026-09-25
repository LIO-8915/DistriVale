<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('vales:actualizar-mora', function () {
    $r = \App\Support\Mora::actualizar();
    $this->info("Vales que pasaron a EN_MORA: {$r['en_mora']} · regularizados (pagaron): {$r['regularizados']}");
})->purpose('Marca EN_MORA los vales con fecha límite vencida y sin pago desde el corte');

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
