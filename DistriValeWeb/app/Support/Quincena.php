<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * The business operates in fixed half-month "quincena" periods: the 1st–15th,
 * and the 16th–end of month. This centralizes how that period (and its
 * display label) is computed from a date, so Dashboard, Recibos and
 * Liquidación all agree on what "the current quincena" means.
 */
class Quincena
{
    public static function actual(): array
    {
        return static::paraFecha(now());
    }

    public static function paraFecha(Carbon $fecha): array
    {
        if ($fecha->day <= 15) {
            $inicio = $fecha->copy()->startOfMonth();
            $fin = $fecha->copy()->startOfMonth()->addDays(14);
        } else {
            $inicio = $fecha->copy()->startOfMonth()->addDays(15);
            $fin = $fecha->copy()->endOfMonth()->startOfDay();
        }

        return [
            'inicio' => $inicio,
            'fin' => $fin,
            'label' => $inicio->translatedFormat('d')." - ".$fin->translatedFormat('d \d\e M Y'),
            'periodo_quincena' => mb_strtoupper($inicio->translatedFormat('d')." AL ".$fin->translatedFormat('d \d\e F Y')),
        ];
    }
}
