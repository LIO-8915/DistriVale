<?php

namespace App\Support\Wireframe;

use App\Support\Quincena;
use Carbon\Carbon;

/**
 * Datos de ejemplo para la versión wireframe — se generan una sola vez por
 * sesión (ver Store::ensureSeeded()) y nunca tocan disco. Las fechas se
 * calculan relativas a "ahora" para que la demo se vea vigente sin
 * importar cuándo se corra. Deliberadamente no se le pone fecha_corte ni
 * fecha_limite_pago a ninguna liquidación de ejemplo (el formulario real
 * tampoco las captura) — así Store::actualizarMora() nunca las toca, y los
 * estados ACTIVO/EN_MORA/LIQUIDADO puestos acá abajo se quedan estables
 * mientras dure la sesión.
 */
class Sample
{
    public static function seed(): void
    {
        $ahora = now()->toDateTimeString();

        // --- Financieras ------------------------------------------------
        $financieras = [
            1 => ['id_financiera' => 1, 'nombre' => 'Financiera del Pacífico', 'comision_porcentaje' => 8.5, 'recargo_porcentaje' => 12, 'activo' => true, 'created_at' => $ahora, 'updated_at' => $ahora],
            2 => ['id_financiera' => 2, 'nombre' => 'Crediya', 'comision_porcentaje' => 6, 'recargo_porcentaje' => 10, 'activo' => true, 'created_at' => $ahora, 'updated_at' => $ahora],
            3 => ['id_financiera' => 3, 'nombre' => 'Préstamos La Paz', 'comision_porcentaje' => 7.25, 'recargo_porcentaje' => 15, 'activo' => true, 'created_at' => $ahora, 'updated_at' => $ahora],
            4 => ['id_financiera' => 4, 'nombre' => 'FinanSol', 'comision_porcentaje' => 5, 'recargo_porcentaje' => 10, 'activo' => false, 'created_at' => $ahora, 'updated_at' => $ahora],
        ];

        // --- Clientes -----------------------------------------------------
        $nombres = [
            'María Guadalupe Cota Higuera', 'José Luis Armenta Beltrán', 'Rosa Isela Murillo Cárdenas',
            'Francisco Javier Osuna Ramírez', 'Ana Karen Beltrán López', 'Juan Manuel Verdugo Castro',
            'Leticia Guadalupe Amador Ruiz', 'Pedro Alonso Green Camacho', 'Martha Elena Villavicencio Ibarra',
            'Ramón Alberto Salgado Peña', 'Guadalupe Concepción Rosas Meza', 'Carlos Eduardo Talamantes Ojeda',
        ];
        $clientes = [];
        foreach ($nombres as $i => $nombre) {
            $id = $i + 1;
            $clientes[$id] = [
                'id_cliente' => $id, 'nombre_completo' => $nombre,
                'telefono' => '612'.random_int(1000000, 9999999),
                'direccion' => 'Calle '.['Constitución', 'Revolución', 'Ignacio Allende', 'Isabel la Católica', 'Álvaro Obregón'][$i % 5].' #'.random_int(10, 950).', La Paz, BCS',
                'activo' => $id !== 11, // una inactiva, para mostrar el badge
                'created_at' => $ahora, 'updated_at' => $ahora,
            ];
        }

        // --- Vales --------------------------------------------------------
        // [id_cliente, id_financiera, monto, cuota, total_quincenas, quincena_actual, estado]
        $plan = [
            [1, 1, 15000, 1250, 12, 5, 'ACTIVO'], [1, 2, 8000, 800, 10, 3, 'ACTIVO'],
            [2, 1, 12000, 1000, 12, 1, 'EN_MORA'], [3, 3, 20000, 1666.67, 12, 8, 'ACTIVO'],
            [3, 3, 6000, 500, 12, 12, 'LIQUIDADO'], [4, 2, 9500, 950, 10, 4, 'ACTIVO'],
            [5, 1, 18000, 1500, 12, 2, 'EN_MORA'], [6, 3, 10000, 833.33, 12, 6, 'ACTIVO'],
            [7, 2, 7000, 700, 10, 10, 'LIQUIDADO'], [7, 1, 11000, 916.67, 12, 4, 'ACTIVO'],
            [8, 3, 25000, 2083.33, 12, 7, 'ACTIVO'], [9, 2, 5000, 500, 10, 2, 'ACTIVO'],
            [10, 1, 16000, 1333.33, 12, 9, 'ACTIVO'], [10, 3, 4000, 333.33, 12, 1, 'EN_MORA'],
            [11, 2, 8500, 850, 10, 5, 'ACTIVO'], [12, 1, 13500, 1125, 12, 3, 'ACTIVO'],
            [12, 3, 9000, 750, 12, 12, 'LIQUIDADO'], [2, 2, 6500, 650, 10, 6, 'ACTIVO'],
            [4, 3, 14000, 1166.67, 12, 2, 'ACTIVO'], [6, 1, 7500, 625, 12, 7, 'ACTIVO'],
        ];

        $vales = [];
        foreach ($plan as $i => [$idCliente, $idFinanciera, $monto, $cuota, $totalQuincenas, $quincenaActual, $estado]) {
            $id = $i + 1;
            $liquidado = $estado === 'LIQUIDADO';
            $saldo = $liquidado ? 0 : round($monto - ($cuota * ($quincenaActual - 1)), 2);
            $vales[$id] = [
                'id_vale' => $id, 'id_cliente' => $idCliente, 'id_financiera' => $idFinanciera,
                'folio_vale' => 'V-'.str_pad((string) $id, 5, '0', STR_PAD_LEFT),
                'fecha_disposicion' => now()->subDays(30 + $id * 3)->toDateString(),
                'monto_original' => (float) $monto, 'cuota_quincenal' => (float) $cuota,
                'total_quincenas' => $totalQuincenas, 'quincena_actual' => $quincenaActual,
                'saldo_pendiente' => max(0, $saldo), 'estado' => $estado,
                'fecha_ultimo_pago' => $quincenaActual > 1 ? now()->subDays(random_int(1, 14))->toDateTimeString() : null,
                'created_at' => $ahora, 'updated_at' => $ahora,
            ];
        }

        // --- Recibos + detalles --------------------------------------------
        $periodoActual = Quincena::actual()['periodo_quincena'];
        $recibos = [];
        $detalles = [];
        $idDetalle = 0;

        $planRecibos = [
            ['id_cliente' => 1, 'vales' => [1, 2], 'distribuidora' => 'ELIA MARIA VELIZ MURILLO', 'diasAtras' => 4],
            ['id_cliente' => 3, 'vales' => [4], 'distribuidora' => 'ELIA MARIA VELIZ MURILLO', 'diasAtras' => 2],
            ['id_cliente' => 8, 'vales' => [11], 'distribuidora' => 'ELIA MARIA VELIZ MURILLO', 'diasAtras' => 1],
        ];

        foreach ($planRecibos as $i => $r) {
            $idRecibo = $i + 1;
            $fechaCorte = now()->subDays($r['diasAtras']);
            $totalOportuno = 0;
            $totalExtemporaneo = 0;

            foreach ($r['vales'] as $idVale) {
                $vale = $vales[$idVale];
                $financiera = $financieras[$vale['id_financiera']];
                $cuota = $vale['cuota_quincenal'];
                $cuotaExtemporanea = round($cuota * (1 + $financiera['recargo_porcentaje'] / 100), 2);
                $totalOportuno += $cuota;
                $totalExtemporaneo += $cuotaExtemporanea;

                $idDetalle++;
                $detalles[$idDetalle] = [
                    'id_detalle' => $idDetalle, 'id_recibo' => $idRecibo, 'id_vale' => $idVale,
                    'monto_pago' => $cuota,
                    'numero_pago_texto' => $vale['quincena_actual'].' de '.$vale['total_quincenas'],
                    'nuevo_saldo' => max(0, round($vale['saldo_pendiente'] - $cuota, 2)),
                    'created_at' => $fechaCorte->toDateTimeString(), 'updated_at' => $fechaCorte->toDateTimeString(),
                ];
            }

            $recibos[$idRecibo] = [
                'id_recibo' => $idRecibo, 'id_cliente' => $r['id_cliente'],
                'nombre_distribuidora' => $r['distribuidora'], 'periodo_quincena' => $periodoActual,
                'fecha_corte' => $fechaCorte->toDateString(),
                'total_oportuno' => round($totalOportuno, 2), 'total_extemporaneo' => round($totalExtemporaneo, 2),
                'fecha_emision' => $fechaCorte->toDateTimeString(),
                'created_at' => $fechaCorte->toDateTimeString(), 'updated_at' => $fechaCorte->toDateTimeString(),
            ];
        }

        // --- Notas ----------------------------------------------------------
        $notas = [
            1 => ['id_nota' => 1, 'id_cliente' => 1, 'contenido' => 'Prefiere que le marquen después de las 5 pm.', 'created_at' => now()->subDays(10)->toDateTimeString(), 'updated_at' => now()->subDays(10)->toDateTimeString()],
            2 => ['id_nota' => 2, 'id_cliente' => 2, 'contenido' => 'Pidió prórroga de una semana para el pago de este mes.', 'created_at' => now()->subDays(5)->toDateTimeString(), 'updated_at' => now()->subDays(5)->toDateTimeString()],
            3 => ['id_nota' => 3, 'id_cliente' => 5, 'contenido' => 'Cambió de domicilio, actualizar dirección la próxima visita.', 'created_at' => now()->subDays(2)->toDateTimeString(), 'updated_at' => now()->subDays(2)->toDateTimeString()],
        ];

        // --- Liquidaciones capturadas (periodo actual) ----------------------
        $liquidaciones = [];
        foreach ($financieras as $id => $f) {
            if (! $f['activo']) {
                continue;
            }
            $cobrar = round(($f['saldo'] ?? 0) * 0 + random_int(15000, 45000), 2); // valor independiente del saldo, solo para verse realista
            $poner = round($cobrar * random_int(3, 9) / 100, 2);
            $ganancias = round($cobrar * $f['comision_porcentaje'] / 100, 2);
            $liquidaciones[$id] = [
                'id_liquidacion' => $id, 'periodo_quincena' => $periodoActual, 'id_financiera' => $id,
                'fecha_corte' => null, 'fecha_limite_pago' => null, 'fecha_deposito' => null,
                'monto_cobrar' => $cobrar, 'monto_poner' => $poner,
                'monto_depositar' => round($cobrar - $poner, 2), 'monto_ganancias' => $ganancias,
                'created_at' => $ahora, 'updated_at' => $ahora,
            ];
        }

        session([
            'wf.financieras' => $financieras,
            'wf.clientes' => $clientes,
            'wf.vales' => $vales,
            'wf.recibos' => $recibos,
            'wf.detalles' => $detalles,
            'wf.notas' => $notas,
            'wf.liquidaciones' => $liquidaciones,
            'wf.next' => [
                'financieras' => count($financieras), 'clientes' => count($clientes), 'vales' => count($vales),
                'recibos' => count($recibos), 'detalles' => count($detalles), 'notas' => count($notas),
                'liquidaciones' => max(array_keys($liquidaciones) ?: [0]),
            ],
        ]);
    }
}
