<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Financiera;
use App\Models\ReciboConsolidado;
use App\Models\Vale;
use App\Support\Mora;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * El recibo consolidado debe llevar todo lo que lleva el recibo impreso de la
 * financiera (folio, núm. de pago, importe, nuevo saldo, totales, pena) y los
 * números deben cuadrar: subtotales por financiera = total general.
 */
class ReciboConsolidadoTest extends TestCase
{
    use RefreshDatabase;

    private Cliente $cliente;
    private Financiera $capta;
    private Financiera $dportenis;

    protected function setUp(): void
    {
        parent::setUp();

        // La mora se recalcula por calendario con la primera petición del día; aquí los vales
        // se crean con el estado exacto que cada prueba necesita y no deben moverse solos.
        Cache::put('mora:revisada:'.now(Mora::ZONA_HORARIA)->toDateString(), true, now()->addDay());

        $this->cliente = Cliente::create(['nombre_completo' => 'RAMON ARTURO BURGOIN GUERRERO', 'telefono' => '6121234567', 'activo' => true]);
        $this->capta = Financiera::create(['nombre' => 'CaptaVale', 'comision_porcentaje' => 20, 'recargo_porcentaje' => 20, 'activo' => true]);
        $this->dportenis = Financiera::create(['nombre' => 'Dportenis', 'comision_porcentaje' => 15, 'recargo_porcentaje' => 0, 'activo' => true]);
    }

    private function vale(Financiera $f, string $folio, float $cuota, float $saldo, string $estado = 'ACTIVO', float $recargo = 0, int $actual = 1, int $total = 10): Vale
    {
        return Vale::create([
            'id_cliente' => $this->cliente->id_cliente,
            'id_financiera' => $f->id_financiera,
            'folio_vale' => $folio,
            'fecha_disposicion' => now()->toDateString(),
            'monto_original' => $cuota * $total,
            'cuota_quincenal' => $cuota,
            'total_quincenas' => $total,
            'quincena_actual' => $actual,
            'saldo_pendiente' => $saldo,
            'recargo_acumulado' => $recargo,
            'quincenas_vencidas' => 0,
            'estado' => $estado,
        ]);
    }

    /**
     * Genera el recibo como lo hace la pantalla: con los créditos elegidos y su monto. Sin
     * argumento se eligen TODOS los pendientes del cliente con el monto sugerido.
     */
    private function generar(?array $abonos = null): ReciboConsolidado
    {
        $abonos ??= $this->cliente->vales()->where('estado', '!=', 'LIQUIDADO')->get()
            ->mapWithKeys(fn (Vale $v) => [$v->id_vale => $v->montoProximoPago()])->all();

        $this->post(route('recibos.store'), [
            'id_cliente' => $this->cliente->id_cliente,
            'nombre_distribuidora' => 'ELIA MARIA VELIZ MURILLO',
            'fecha_corte' => '2026-09-30',
            'abonos' => $abonos,
        ])->assertRedirect();

        return ReciboConsolidado::with('detalles.vale.financiera', 'cliente')->latest('id_recibo')->firstOrFail();
    }

    public function test_incluye_todos_los_vales_activos_y_en_mora_y_ninguno_liquidado(): void
    {
        $this->vale($this->capta, 'D1000001', 1110.00, 11100.00);
        $this->vale($this->capta, 'D1000002', 879.00, 8790.00, 'EN_MORA', recargo: 100.00);
        $this->vale($this->dportenis, 'WFHUHPZHQW', 745.00, 3725.00);
        $this->vale($this->capta, 'D1000099', 500.00, 0.00, 'LIQUIDADO');

        $recibo = $this->generar();

        $this->assertSame(3, $recibo->detalles->count(), 'deben entrar los 3 vales no liquidados');
        $this->assertEqualsCanonicalizing(
            ['D1000001', 'D1000002', 'WFHUHPZHQW'],
            $recibo->detalles->map(fn ($d) => $d->vale->folio_vale)->all()
        );
        // El vale en mora cobra cuota + recargo acumulado
        $mora = $recibo->detalles->first(fn ($d) => $d->vale->folio_vale === 'D1000002');
        $this->assertEqualsWithDelta(979.00, (float) $mora->monto_pago, 0.001, 'cuota 879 + recargo acumulado 100');
    }

    public function test_la_pantalla_muestra_todos_los_datos_del_recibo(): void
    {
        $this->vale($this->capta, 'D1000001', 1110.00, 11100.00);
        $this->vale($this->dportenis, 'WFHUHPZHQW', 745.00, 3725.00);
        $recibo = $this->generar();
        $texto = $recibo->detalles->first()->numero_pago_texto;

        $r = $this->get(route('recibos.show', $recibo))->assertOk();

        $r->assertSee('ELIA MARIA VELIZ MURILLO');
        $r->assertSee('corte 30/09/2026', false);
        $r->assertSee('RAMON ARTURO BURGOIN GUERRERO');
        $r->assertSee($recibo->codigoCliente());                     // C-001
        $r->assertSee('6121234567');                                  // teléfono
        $r->assertSee('Pendiente de pago');                           // estado
        foreach (['D1000001', 'WFHUHPZHQW', 'CaptaVale', 'Dportenis'] as $dato) {
            $r->assertSee($dato);
        }
        $r->assertSee($texto);                                        // núm. de pago (x/y)
        foreach (['$1,110.00', '$745.00', '$9,990.00', '$2,980.00'] as $monto) {
            $r->assertSee($monto);                                    // subtotal y nuevo saldo (saldo - pago), con centavos
        }
        $r->assertSee('Resumen por financiera');
        $r->assertSee('Total pago oportuno');
        $r->assertSee('Pago después del 30/09/2026', false);
        $r->assertSee('se carga pena', false);                       // leyenda de la pena
        $r->assertSee('CaptaVale 20%', false);
    }

    public function test_los_subtotales_por_financiera_cuadran_con_el_total_general(): void
    {
        $this->vale($this->capta, 'D1000001', 1110.00, 11100.00);
        $this->vale($this->capta, 'D1000002', 879.00, 8790.00, 'EN_MORA', recargo: 100.00);
        $this->vale($this->dportenis, 'WFHUHPZHQW', 745.00, 3725.00);
        $recibo = $this->generar();

        $resumen = $recibo->resumenPorFinanciera();

        $this->assertCount(2, $resumen);
        $this->assertEqualsWithDelta((float) $recibo->total_oportuno, array_sum(array_column($resumen, 'monto')), 0.001);
        $this->assertEqualsWithDelta((float) $recibo->total_extemporaneo, array_sum(array_column($resumen, 'tardio')), 0.001);
        $this->assertSame(3, array_sum(array_column($resumen, 'vales')));
    }

    public function test_el_texto_para_copiar_lleva_todo_y_con_subtotales(): void
    {
        $this->vale($this->capta, 'D1000001', 1110.00, 11100.00);
        $this->vale($this->capta, 'D1000002', 879.00, 8790.00);
        $this->vale($this->dportenis, 'WFHUHPZHQW', 745.00, 3725.00);
        $recibo = $this->generar();

        $r = $this->get(route('recibos.show', $recibo))->assertOk();

        foreach ([
            'Teléfono: 6121234567',
            'Pendiente de pago',
            'CaptaVale (pena 20%)',
            'Folio D1000001 | Pago ',
            'Subtotal CaptaVale (2 vales): $1,989.00',
            'Subtotal Dportenis (1 vale): $745.00',
            'Total pago oportuno: $2,734.00',
        ] as $linea) {
            $r->assertSee($linea, false);
        }
    }

    public function test_confirmar_el_pago_marca_el_recibo_como_pagado_y_lo_muestra(): void
    {
        $v = $this->vale($this->capta, 'D1000001', 1110.00, 11100.00);
        $recibo = $this->generar();
        $detalle = $recibo->detalles->first();

        $this->post(route('recibos.confirmar-pago', $recibo), [
            'fecha_pago' => '2026-10-05',
            'montos' => [$detalle->id_detalle => 1110.00],
        ])->assertRedirect(route('recibos.show', $recibo));

        $this->assertEqualsWithDelta(9990.00, (float) $v->fresh()->saldo_pendiente, 0.001);

        $r = $this->get(route('recibos.show', $recibo))->assertOk();
        $r->assertSee('Pagado el 05/10/2026');
        $r->assertSee('Total pagado');
        $r->assertSee('Aplicar pago otra vez');
        $r->assertSee('DE NUEVO', false);                             // advertencia reforzada al confirmar otra vez

        $this->get(route('recibos.index'))->assertOk()->assertSee('05/10/2026');
    }

    public function test_el_ultimo_pago_de_un_vale_se_marca_como_liquida(): void
    {
        $this->vale($this->capta, 'D1000001', 1110.00, 1110.00, actual: 10, total: 10);
        $recibo = $this->generar();

        $this->get(route('recibos.show', $recibo))->assertOk()->assertSee('Liquida');
    }

    public function test_el_listado_muestra_si_cada_recibo_esta_pendiente_o_pagado(): void
    {
        $this->vale($this->capta, 'D1000001', 1110.00, 11100.00);
        $this->generar();

        $this->get(route('recibos.index'))->assertOk()->assertSee('Pendiente');
    }

    // ------------------------------------------------------------------ Generar recibo: elegir cliente → créditos → monto

    private function post_store(array $abonos, ?Cliente $cliente = null)
    {
        return $this->post(route('recibos.store'), [
            'id_cliente' => ($cliente ?? $this->cliente)->id_cliente,
            'nombre_distribuidora' => 'ELIA MARIA VELIZ MURILLO',
            'fecha_corte' => '2026-09-30',
            'abonos' => $abonos,
        ]);
    }

    public function test_la_lista_de_clientes_solo_trae_a_quienes_tienen_algo_pendiente(): void
    {
        $this->vale($this->capta, 'D1000001', 1110.00, 11100.00);                      // RAMON: pendiente
        $liquidado = Cliente::create(['nombre_completo' => 'CLIENTE SOLO LIQUIDADO', 'activo' => true]);
        Vale::create(['id_cliente' => $liquidado->id_cliente, 'id_financiera' => $this->capta->id_financiera, 'folio_vale' => 'L1', 'fecha_disposicion' => now()->toDateString(),
            'monto_original' => 100, 'cuota_quincenal' => 100, 'total_quincenas' => 1, 'quincena_actual' => 2, 'saldo_pendiente' => 0, 'recargo_acumulado' => 0, 'quincenas_vencidas' => 0, 'estado' => 'LIQUIDADO']);
        Cliente::create(['nombre_completo' => 'CLIENTE SIN CREDITOS', 'activo' => true]);
        $inactivo = Cliente::create(['nombre_completo' => 'CLIENTE INACTIVO CON DEUDA', 'activo' => false]);
        Vale::create(['id_cliente' => $inactivo->id_cliente, 'id_financiera' => $this->capta->id_financiera, 'folio_vale' => 'I1', 'fecha_disposicion' => now()->toDateString(),
            'monto_original' => 500, 'cuota_quincenal' => 50, 'total_quincenas' => 10, 'quincena_actual' => 1, 'saldo_pendiente' => 500, 'recargo_acumulado' => 0, 'quincenas_vencidas' => 0, 'estado' => 'ACTIVO']);

        $r = $this->get(route('recibos.create'))->assertOk();

        $r->assertSee('RAMON ARTURO BURGOIN GUERRERO');
        $r->assertDontSee('CLIENTE SOLO LIQUIDADO');
        $r->assertDontSee('CLIENTE SIN CREDITOS');
        $r->assertDontSee('CLIENTE INACTIVO CON DEUDA');
        $r->assertSee('Créditos a abonar');
        $r->assertSee('Abono', false);
    }

    public function test_el_selector_de_creditos_solo_ofrece_los_activos_o_en_mora(): void
    {
        $this->vale($this->capta, 'D1000001', 1110.00, 11100.00);
        $this->vale($this->capta, 'D1000002', 879.00, 8790.00, 'EN_MORA', recargo: 100.00);
        $this->vale($this->capta, 'D1000099', 500.00, 0.00, 'LIQUIDADO');

        $json = $this->getJson(route('recibos.vales-cliente', $this->cliente))->assertOk()->json();

        $this->assertSame(['D1000001', 'D1000002'], array_column($json, 'folio'), 'el liquidado no se ofrece');
        $mora = $json[1];
        $this->assertSame('EN_MORA', $mora['estado']);
        $this->assertEqualsWithDelta(979.00, $mora['sugerido'], 0.001);          // cuota + recargo acumulado
        $this->assertEqualsWithDelta(8790.00, $mora['saldo'], 0.001);
        $this->assertGreaterThanOrEqual($mora['sugerido'], $mora['tope']);
        $this->assertArrayHasKey('pago', $mora);
        $this->assertSame('CaptaVale', $mora['financiera']);
    }

    public function test_el_recibo_solo_lleva_los_creditos_elegidos_con_el_monto_capturado(): void
    {
        $a = $this->vale($this->capta, 'D1000001', 1110.00, 11100.00);
        $b = $this->vale($this->capta, 'D1000002', 879.00, 8790.00);
        $this->vale($this->dportenis, 'WFHUHPZHQW', 745.00, 3725.00);               // no se elige

        $recibo = $this->generar([$a->id_vale => 500.00, $b->id_vale => 879.00]);

        $this->assertSame(2, $recibo->detalles->count());
        $abonoA = $recibo->detalles->first(fn ($d) => $d->id_vale === $a->id_vale);
        $this->assertEqualsWithDelta(500.00, (float) $abonoA->monto_pago, 0.001, 'el monto capturado, no la cuota');
        $this->assertEqualsWithDelta(10600.00, (float) $abonoA->nuevo_saldo, 0.001, 'saldo - abono');
        $this->assertEqualsWithDelta(1379.00, (float) $recibo->total_oportuno, 0.001);
        // pago después del corte: abono × (1 + 20 %) de CaptaVale
        $this->assertEqualsWithDelta(round(500 * 1.2, 2) + round(879 * 1.2, 2), (float) $recibo->total_extemporaneo, 0.001);
        $this->assertEqualsWithDelta((float) $recibo->total_extemporaneo, array_sum(array_column($recibo->resumenPorFinanciera(), 'tardio')), 0.001);
    }

    public function test_no_se_genera_recibo_sin_elegir_ningun_credito(): void
    {
        $this->vale($this->capta, 'D1000001', 1110.00, 11100.00);

        $this->post_store([])->assertSessionHasErrors('abonos');
        $this->assertSame(0, ReciboConsolidado::count());
    }

    public function test_se_rechazan_montos_invalidos_y_creditos_que_no_son_del_cliente_o_estan_liquidados(): void
    {
        $a = $this->vale($this->capta, 'D1000001', 1110.00, 11100.00);
        $liq = $this->vale($this->capta, 'D1000099', 500.00, 0.00, 'LIQUIDADO');
        $otro = Cliente::create(['nombre_completo' => 'OTRO CLIENTE', 'activo' => true]);
        $ajeno = Vale::create(['id_cliente' => $otro->id_cliente, 'id_financiera' => $this->capta->id_financiera, 'folio_vale' => 'X1', 'fecha_disposicion' => now()->toDateString(),
            'monto_original' => 100, 'cuota_quincenal' => 10, 'total_quincenas' => 10, 'quincena_actual' => 1, 'saldo_pendiente' => 100, 'recargo_acumulado' => 0, 'quincenas_vencidas' => 0, 'estado' => 'ACTIVO']);

        $this->post_store([$a->id_vale => 0])->assertSessionHasErrors('abonos.'.$a->id_vale);                  // cero
        $this->post_store([$a->id_vale => -50])->assertSessionHasErrors('abonos.'.$a->id_vale);                // negativo
        $this->post_store([$a->id_vale => 'mucho'])->assertSessionHasErrors('abonos.'.$a->id_vale);            // no numérico
        $this->post_store([$a->id_vale => 99999.00])->assertSessionHasErrors('abonos.'.$a->id_vale);           // más de lo que debe
        $this->post_store([$liq->id_vale => 100.00])->assertSessionHasErrors('abonos');                        // liquidado
        $this->post_store([$ajeno->id_vale => 10.00])->assertSessionHasErrors('abonos');                       // de otro cliente

        $this->assertSame(0, ReciboConsolidado::count(), 'ninguno de los intentos inválidos crea recibo');
    }

    public function test_un_cliente_sin_creditos_pendientes_no_puede_generar_recibo(): void
    {
        $this->vale($this->capta, 'D1000099', 500.00, 0.00, 'LIQUIDADO');

        $this->post_store([1 => 100.00])->assertSessionHasErrors('id_cliente');
        $this->assertSame(0, ReciboConsolidado::count());
    }

    public function test_se_puede_abonar_el_saldo_completo_y_queda_en_cero(): void
    {
        $v = $this->vale($this->capta, 'D1000001', 1110.00, 2000.00);

        $recibo = $this->generar([$v->id_vale => 2000.00]);

        $this->assertEqualsWithDelta(0.00, (float) $recibo->detalles->first()->nuevo_saldo, 0.001);
        $this->get(route('recibos.show', $recibo))->assertSee('Liquida');
    }
}
