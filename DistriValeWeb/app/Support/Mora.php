<?php

namespace App\Support;

use App\Support\Wireframe\Store;
use Illuminate\Support\Facades\Cache;

/**
 * Mora automática: ver App\Support\Wireframe\Store::actualizarMora() para
 * el cálculo real (mismo criterio que la versión con base de datos: un
 * vale ACTIVO pasa a EN_MORA cuando ya venció la fecha límite de pago del
 * corte vigente de su financiera y no tiene pago registrado desde ese
 * corte; vuelve a ACTIVO si después se le registra el pago).
 */
class Mora
{
    // La app opera en La Paz, B.C.S. (UTC−7): la fecha límite vence a
    // medianoche local, no a las 17:00 del día.
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
    public static function actualizar(): array
    {
        return Store::actualizarMora();
    }
}
