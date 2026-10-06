<?php

namespace App\Support;

use App\Models\Vale;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Mora automática por calendario: ya no depende de que se capture una
 * liquidación con fecha de corte/límite por financiera cada quincena (ese
 * diseño anterior dejaba sin detectar cualquier vale atrasado de un
 * periodo que no se llegó a capturar, que es justo lo que pasaba antes de
 * esto — ver el historial de esta clase si hace falta el porqué exacto).
 *
 * Las quincenas ya no se cuentan como "cada 15 días desde que se dispuso"
 * (eso era una ventana rotativa, no un calendario real) — se usan los
 * mismos cortes fijos que App\Support\Quincena ya usa en Dashboard,
 * Recibos y Liquidación: día 15 y último día del mes (28/29 en febrero, 30
 * o 31 según el mes). El recargo por pago fuera de tiempo se activa el día
 * inmediato siguiente a cada corte — el 16 del mes para la primera mitad,
 * el día 1 del mes siguiente para la segunda.
 *
 * Para cada vale no liquidado se cuenta cuántos de esos cortes ya deberían
 * haber pasado desde que se dispuso, contra cuántas quincenas se han
 * pagado de verdad (quincena_actual - 1). Si va atrasado, se marca
 * EN_MORA — y cada quincena completa que se le vence SIN NINGÚN pago (ni
 * siquiera parcial: eso ya lo resuelve Vale::registrarPago() al momento
 * del pago) genera un recargo nuevo sobre lo que se le debía ese periodo,
 * que se acumula para el siguiente — el mismo mecanismo, solo que
 * disparado por el calendario en vez de por un pago real.
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
     * @return array{en_mora: int, regularizados: int, recargos_nuevos: int}
     */
    public static function actualizar(?Carbon $hoy = null): array
    {
        $hoy = ($hoy ?? now(self::ZONA_HORARIA))->copy()->startOfDay();
        $resultado = ['en_mora' => 0, 'regularizados' => 0, 'recargos_nuevos' => 0];

        DB::transaction(function () use ($hoy, &$resultado) {
            Vale::where('estado', '!=', 'LIQUIDADO')
                ->with('financiera')
                ->chunkById(200, function ($vales) use ($hoy, &$resultado) {
                    foreach ($vales as $vale) {
                        $resultado = static::revisarVale($vale, $hoy, $resultado);
                    }
                }, 'id_vale');
        });

        return $resultado;
    }

    private static function revisarVale(Vale $vale, Carbon $hoy, array $resultado): array
    {
        $inicio = $vale->fecha_disposicion ?? $vale->created_at;
        if (! $inicio) {
            return $resultado;
        }

        // fecha_disposicion llega con la zona horaria por defecto de la app
        // (UTC), no la de negocio (self::ZONA_HORARIA) — compararla tal cual
        // contra $hoy podía adelantar o atrasar el corte un día. Se vuelve a
        // anclar solo la FECHA (sin su hora/zona original) a la zona de
        // negocio antes de ubicarla en el calendario de quincenas.
        $inicio = Carbon::parse($inicio->toDateString(), self::ZONA_HORARIA)->startOfDay();
        $quincenasEsperadas = static::cortesVencidos($inicio, $hoy);
        $quincenasPagadas = max(0, $vale->quincena_actual - 1);
        $vencidas = max(0, $quincenasEsperadas - $quincenasPagadas);

        if ($vencidas <= 0) {
            if ($vale->estado === 'EN_MORA') {
                $vale->estado = 'ACTIVO';
                $resultado['regularizados']++;
            }
            if ($vale->quincenas_vencidas !== 0) {
                $vale->quincenas_vencidas = 0;
            }
            if ($vale->isDirty()) {
                $vale->save();
            }

            return $resultado;
        }

        $nuevosVencidos = $vencidas - $vale->quincenas_vencidas;
        if ($nuevosVencidos > 0) {
            $recargoPct = (float) ($vale->financiera?->recargo_porcentaje ?? 0);

            for ($i = 0; $i < $nuevosVencidos; $i++) {
                $montoEsperado = $vale->montoProximoPago();
                $recargo = round($montoEsperado * $recargoPct / 100, 2);

                $vale->recargo_acumulado = round($montoEsperado + $recargo, 2);
                $vale->saldo_pendiente = round((float) $vale->saldo_pendiente + $recargo, 2);
                $resultado['recargos_nuevos']++;
            }

            $vale->quincenas_vencidas = $vencidas;
        }

        if ($vale->estado !== 'EN_MORA') {
            $vale->estado = 'EN_MORA';
            $resultado['en_mora']++;
        }

        $vale->save();

        return $resultado;
    }

    /**
     * Cuenta, desde la quincena calendario en la que cayó $inicio, cuántos
     * cortes (día 15 o último día del mes) ya tienen su recargo activo —
     * es decir, cuántas quincenas completas ya debieron pagarse — a más
     * tardar $hoy. Usa App\Support\Quincena para ubicar cada corte, así que
     * automáticamente respeta los días reales de cada mes (28/29 en
     * febrero, 30, 31) sin lógica de calendario propia.
     */
    private static function cortesVencidos(Carbon $inicio, Carbon $hoy): int
    {
        $vencidas = 0;
        $cursor = $inicio->copy();

        while (true) {
            $periodo = Quincena::paraFecha($cursor);
            // El recargo por fuera de tiempo se activa el día inmediato
            // siguiente al corte: el 16 si el corte fue el 15, o el día 1
            // del mes siguiente si el corte fue el último día del mes.
            $diaRecargo = $periodo['fin']->copy()->addDay()->startOfDay();

            if ($diaRecargo->gt($hoy)) {
                break;
            }

            $vencidas++;
            $cursor = $periodo['fin']->copy()->addDay();
        }

        return $vencidas;
    }
}
