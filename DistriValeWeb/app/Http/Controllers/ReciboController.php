<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\DetalleReciboVale;
use App\Models\ReciboConsolidado;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReciboController extends Controller
{
    public function index()
    {
        $recibos = ReciboConsolidado::with('cliente')->latest('fecha_corte')->paginate(15);

        return view('recibos.index', compact('recibos'));
    }

    public function create()
    {
        $clientes = Cliente::where('activo', true)->orderBy('nombre_completo')->get();

        return view('recibos.create', compact('clientes'));
    }

    /**
     * Genera el recibo consolidado quincenal a partir de los vales ACTIVOS del cliente.
     */
    public function store(Request $request)
    {
        $request->validate([
            'id_cliente' => 'required|exists:clientes,id_cliente',
            'fecha_corte' => 'required|date',
            'nombre_distribuidora' => 'required|string|max:150',
        ]);

        $cliente = Cliente::with(['vales' => fn ($q) => $q->where('estado', '!=', 'LIQUIDADO')->with('financiera')])
            ->findOrFail($request->id_cliente);

        if ($cliente->vales->isEmpty()) {
            return back()->withErrors(['id_cliente' => 'Este cliente no tiene vales activos para consolidar.']);
        }

        $recibo = DB::transaction(function () use ($request, $cliente) {
            $totalOportuno = 0;
            $totalExtemporaneo = 0;

            $recibo = ReciboConsolidado::create([
                'id_cliente' => $cliente->id_cliente,
                'nombre_distribuidora' => $request->nombre_distribuidora,
                'fecha_corte' => $request->fecha_corte,
                'total_oportuno' => 0,
                'total_extemporaneo' => 0,
                'fecha_emision' => now(),
            ]);

            foreach ($cliente->vales as $vale) {
                $cuota = (float) $vale->cuota_quincenal;
                $recargoPct = (float) ($vale->financiera->recargo_porcentaje ?? 0);
                $cuotaExtemporanea = round($cuota * (1 + $recargoPct / 100), 2);
                $nuevoSaldo = max(0, (float) $vale->saldo_pendiente - $cuota);

                DetalleReciboVale::create([
                    'id_recibo' => $recibo->id_recibo,
                    'id_vale' => $vale->id_vale,
                    'monto_pago' => $cuota,
                    'numero_pago_texto' => $vale->numeroPagoTexto(),
                    'nuevo_saldo' => $nuevoSaldo,
                ]);

                $totalOportuno += $cuota;
                $totalExtemporaneo += $cuotaExtemporanea;
            }

            $recibo->update([
                'total_oportuno' => $totalOportuno,
                'total_extemporaneo' => $totalExtemporaneo,
            ]);

            return $recibo;
        });

        return redirect()->route('recibos.show', $recibo)->with('success', 'Recibo consolidado generado.');
    }

    public function show(ReciboConsolidado $recibo)
    {
        $recibo->load(['cliente', 'detalles.vale.financiera']);

        return view('recibos.show', compact('recibo'));
    }

    /**
     * Marca el recibo como pagado: aplica el abono a cada vale (avanza quincena y saldo).
     */
    public function confirmarPago(ReciboConsolidado $recibo)
    {
        $recibo->load('detalles.vale');

        DB::transaction(function () use ($recibo) {
            foreach ($recibo->detalles as $detalle) {
                $detalle->vale->registrarPago((float) $detalle->monto_pago);
            }
        });

        return redirect()->route('recibos.show', $recibo)->with('success', 'Pago aplicado a los vales del cliente.');
    }
}
