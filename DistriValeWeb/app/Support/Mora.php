<?php

namespace App\Support;

use App\Models\LiquidacionQuincena;
use App\Models\Vale;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Mora automática: un vale ACTIVO pasa a EN_MORA cuando ya pasó la fecha
 * límite de pago del corte vigente de su financiera y no tiene ningún pago
 * registrado desde ese corte. Si después se le registra el pago, vuelve a
 * ACTIVO. Los LIQUIDADO nunca se tocan.
 *
 * El "corte vigente" de cada financiera es su liquidación más reciente con
 * fecha de corte ya ocurrida y fecha límite capturada.
 */
class Mora
{
    // La app guarda en UTC, pero el negocio opera en La Paz, B.C.S. (UTC−7):
    // la fecha límite vence a medianoche local, no a las 17:00 del día.
    public const ZONA_HORARIA = 'America/Mazatlan';

    /**
     * Corre la revisión como mucho una vez al día. La app de escritorio no
     * tiene un scheduler corriendo, así que se dispara con la primera
     * petición del día (ver middleware en bootstrap/app.php).
     */
    public static function actualizarSiToca(): void
    {
        $hoy = now(self::ZONA_HORARIA)->toDateString();

        if (Cache::add('mora:revisada:'.$hoy, true, now()->addDays(2))) {
            static::actualizar();
        }
    }

    /**
     * @return array{en_mora: int, regularizados: int}
     */
    public static function actualizar(?Carbon $hoy = null): array
    {
        $hoy = ($hoy ?? now(self::ZONA_HORARIA))->copy()->startOfDay();
        $resultado = ['en_mora' => 0, 'regularizados' => 0];

        $cortes = LiquidacionQuincena::query()
            ->whereNotNull('fecha_corte')
            ->whereNotNull('fecha_limite_pago')
            ->whereDate('fecha_corte', '<=', $hoy->toDateString())
            ->orderByDesc('fecha_corte')
            ->get()
            ->unique('id_financiera');

        DB::transaction(function () use ($cortes, $hoy, &$resultado) {
            foreach ($cortes as $corte) {
                $fechaCorte = $corte->fecha_corte->toDateString();

                // Pagaron después de caer en mora: se regularizan.
                $resultado['regularizados'] += Vale::where('id_financiera', $corte->id_financiera)
                    ->where('estado', 'EN_MORA')
                    ->where('fecha_ultimo_pago', '>=', $fechaCorte)
                    ->update(['estado' => 'ACTIVO', 'updated_at' => now()]);

                if ($corte->fecha_limite_pago->toDateString() >= $hoy->toDateString()) {
                    continue;
                }

                $resultado['en_mora'] += Vale::where('id_financiera', $corte->id_financiera)
                    ->where('estado', 'ACTIVO')
                    // Vales dados de alta después del corte todavía no le debían nada.
                    ->where(fn ($q) => $q->whereNull('fecha_disposicion')->orWhereDate('fecha_disposicion', '<=', $fechaCorte))
                    ->where(fn ($q) => $q->whereNull('fecha_ultimo_pago')->orWhere('fecha_ultimo_pago', '<', $fechaCorte))
                    ->update(['estado' => 'EN_MORA', 'updated_at' => now()]);
            }
        });

        return $resultado;
    }
}
