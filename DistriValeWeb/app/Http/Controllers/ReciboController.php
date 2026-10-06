<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\DetalleReciboVale;
use App\Models\ReciboConsolidado;
use App\Models\Vale;
use App\Support\Mora;
use App\Support\PorPagina;
use App\Support\Quincena;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReciboController extends Controller
{
    public function index(Request $request)
    {
        $porPagina = PorPagina::desde($request);
        $recibos = ReciboConsolidado::with('cliente')->latest('fecha_corte')
            ->paginate(PorPagina::tamano($porPagina))->withQueryString();

        return view('recibos.index', compact('recibos', 'porPagina'));
    }

    public function create()
    {
        // Solo clientes a los que de verdad se les puede cobrar: al menos un crédito que no
        // esté liquidado. Un cliente sin nada pendiente no aparece en la lista.
        $clientes = Cliente::where('activo', true)
            ->whereHas('vales', fn ($q) => $q->where('estado', '!=', 'LIQUIDADO'))
            ->withCount(['vales as vales_pendientes' => fn ($q) => $q->where('estado', '!=', 'LIQUIDADO')])
            ->orderBy('nombre_completo')
            ->get();

        return view('recibos.create', compact('clientes'));
    }

    /**
     * Créditos a los que se le puede abonar a un cliente (activos y en mora; los liquidados
     * no), ordenados como el recibo: por financiera y folio.
     */
    private function valesPendientes(Cliente $cliente)
    {
        return $cliente->vales()
            ->where('estado', '!=', 'LIQUIDADO')
            ->with('financiera')
            ->get()
            ->sortBy(fn (Vale $v) => [$v->financiera->nombre, $v->folio_vale])
            ->values();
    }

    /** Lo máximo que tiene sentido abonar a un vale: lo que debe, o la cuota si esta es mayor (último pago). */
    private function topeDeAbono(Vale $vale): float
    {
        return max((float) $vale->saldo_pendiente, $vale->montoProximoPago());
    }

    /** Máximo de recibos que se generan en una sola operación (cada uno es una tarjeta en pantalla). */
    public const MAX_RECIBOS = 8;

    /**
     * Alimenta el selector de créditos de "Generar recibo" al elegir un cliente. Cada crédito
     * avisa si ya está en un recibo SIN PAGAR (`recibo_pendiente`): confirmar los dos cobraría el
     * crédito dos veces.
     */
    public function valesDeCliente(Cliente $cliente): JsonResponse
    {
        $vales = $this->valesPendientes($cliente);

        $enReciboPendiente = DetalleReciboVale::whereIn('id_vale', $vales->pluck('id_vale'))
            ->whereHas('recibo', fn ($q) => $q->whereNull('fecha_pago'))
            ->with('recibo:id_recibo,fecha_corte')
            ->get()
            ->groupBy('id_vale')
            ->map(fn ($g) => $g->sortByDesc('id_recibo')->first());

        return response()->json($vales->map(function (Vale $v) use ($enReciboPendiente) {
            $pendiente = $enReciboPendiente->get($v->id_vale);

            return [
                'id' => $v->id_vale,
                'folio' => $v->folio_vale,
                'financiera' => $v->financiera->nombre,
                'id_financiera' => $v->id_financiera,
                'pena' => (float) ($v->financiera->recargo_porcentaje ?? 0),
                'estado' => $v->estado,
                'estado_texto' => $v->estadoLegible(),
                'pago' => $v->numeroPagoTexto(),
                'cuota' => (float) $v->cuota_quincenal,
                'recargo' => (float) $v->recargo_acumulado,
                'sugerido' => $v->montoProximoPago(),
                'saldo' => (float) $v->saldo_pendiente,
                'tope' => round($this->topeDeAbono($v), 2),
                'ultimo_pago' => $v->esUltimoPago(),
                'recibo_pendiente' => $pendiente ? [
                    'id' => $pendiente->id_recibo,
                    'fecha' => $pendiente->recibo->fecha_corte->format('d/m/Y'),
                    'url' => route('recibos.show', $pendiente->id_recibo),
                ] : null,
            ];
        })->all());
    }

    /**
     * Genera uno o varios recibos en una sola operación. Cada recibo trae su propio cliente,
     * distribuidora y fecha de corte, y los créditos elegidos con el monto a abonar de cada uno
     * (`recibos[i][abonos][id_vale]`). El monto sugerido es cuota + recargo acumulado, pero se
     * puede cambiar. Todo se guarda en una transacción: si algo falla no queda ningún recibo a medias.
     *
     * Un envío del formato anterior (un solo recibo: `id_cliente`, `abonos`… sin `recibos`)
     * sigue funcionando.
     */
    public function store(Request $request)
    {
        if (! $request->has('recibos')) {
            $request->merge(['recibos' => [[
                'id_cliente' => $request->input('id_cliente'),
                'nombre_distribuidora' => $request->input('nombre_distribuidora'),
                'fecha_corte' => $request->input('fecha_corte'),
                'abonos' => $request->input('abonos'),
            ]]]);
        }

        $request->validate([
            'recibos' => 'required|array|min:1|max:'.self::MAX_RECIBOS,
            'recibos.*.id_cliente' => 'required|exists:clientes,id_cliente',
            'recibos.*.fecha_corte' => 'required|date',
            'recibos.*.nombre_distribuidora' => 'required|string|max:150',
            'recibos.*.abonos' => 'required|array|min:1',
            'recibos.*.abonos.*' => 'required|numeric|min:0.01',
        ], [
            'recibos.required' => 'Agrega al menos un recibo.',
            'recibos.max' => 'Se pueden generar hasta '.self::MAX_RECIBOS.' recibos a la vez.',
            'recibos.*.id_cliente.required' => 'Recibo :position: elige el cliente.',
            'recibos.*.id_cliente.exists' => 'Recibo :position: el cliente elegido no existe.',
            'recibos.*.fecha_corte.required' => 'Recibo :position: captura la fecha de corte.',
            'recibos.*.fecha_corte.date' => 'Recibo :position: la fecha de corte no es válida.',
            'recibos.*.nombre_distribuidora.required' => 'Recibo :position: captura el nombre de la distribuidora.',
            'recibos.*.abonos.required' => 'Recibo :position: elige al menos un crédito al que abonar.',
            'recibos.*.abonos.min' => 'Recibo :position: elige al menos un crédito al que abonar.',
            'recibos.*.abonos.*.required' => 'Recibo :position: captura el monto a abonar de cada crédito elegido.',
            'recibos.*.abonos.*.numeric' => 'Recibo :position: el monto a abonar debe ser un número.',
            'recibos.*.abonos.*.min' => 'Recibo :position: el monto a abonar de cada crédito debe ser mayor a $0.00.',
        ]);

        $entrada = array_values($request->input('recibos'));
        $errores = [];

        // 1) Un crédito solo puede ir en UN recibo por envío (si no, se cobraría dos veces).
        $primeroEn = [];
        $repetidos = [];
        foreach ($entrada as $i => $r) {
            foreach (array_keys($r['abonos']) as $idVale) {
                $idVale = (int) $idVale;
                if (isset($primeroEn[$idVale])) {
                    $repetidos[$idVale] = [$primeroEn[$idVale], $i];
                } else {
                    $primeroEn[$idVale] = $i;
                }
            }
        }
        if ($repetidos) {
            $folios = Vale::whereIn('id_vale', array_keys($repetidos))->pluck('folio_vale', 'id_vale');
            foreach ($repetidos as $idVale => [$a, $b]) {
                $errores['recibos.'.$b.'.abonos.'.$idVale] = 'El crédito '.($folios[$idVale] ?? $idVale).' está en el recibo '.($a + 1).' y en el '.($b + 1).': un crédito solo puede ir en un recibo.';
            }
        }

        // 2) Cada recibo: créditos del cliente elegido, sin liquidar, y montos razonables.
        $pendientesPorCliente = [];
        $armados = [];
        foreach ($entrada as $i => $r) {
            $idCliente = (int) $r['id_cliente'];
            $pendientesPorCliente[$idCliente] ??= $this->valesPendientes(Cliente::findOrFail($idCliente))->keyBy('id_vale');
            $pendientes = $pendientesPorCliente[$idCliente];
            $n = $i + 1;

            if ($pendientes->isEmpty()) {
                $errores['recibos.'.$i.'.id_cliente'] = "Recibo {$n}: este cliente no tiene créditos pendientes de pago.";
                continue;
            }

            $elegidos = [];
            foreach ($r['abonos'] as $idVale => $monto) {
                $vale = $pendientes->get((int) $idVale);
                if (! $vale) {
                    $errores['recibos.'.$i.'.abonos'] = "Recibo {$n}: uno de los créditos elegidos ya no está disponible para este cliente (puede estar liquidado). Vuelve a elegir el cliente.";
                    continue;
                }
                $tope = $this->topeDeAbono($vale);
                if ((float) $monto > $tope + 0.01) {
                    $errores['recibos.'.$i.'.abonos.'.$idVale] = "Recibo {$n}: el abono del crédito {$vale->folio_vale} ($".number_format((float) $monto, 2).') es mayor a lo que debe ($'.number_format($tope, 2).').';
                    continue;
                }
                $elegidos[] = [$vale, round((float) $monto, 2)];
            }
            $armados[] = [$r, $elegidos];
        }

        if ($errores) {
            return back()->withInput()->withErrors($errores);
        }

        $ids = DB::transaction(fn () => array_map(fn ($a) => $this->crearRecibo($a[0], $a[1])->id_recibo, $armados));

        if (count($ids) === 1) {
            return redirect()->route('recibos.show', $ids[0])->with('success', 'Recibo consolidado generado.');
        }

        return redirect()->route('recibos.resumen', ['ids' => implode(',', $ids)])->with('success', count($ids).' recibos generados.');
    }

    /** Crea un recibo con sus renglones ya validados: [[Vale, monto], …]. */
    private function crearRecibo(array $datos, array $elegidos): ReciboConsolidado
    {
        $totalOportuno = 0;
        $totalExtemporaneo = 0;

        $recibo = ReciboConsolidado::create([
            'id_cliente' => $datos['id_cliente'],
            'nombre_distribuidora' => $datos['nombre_distribuidora'],
            'periodo_quincena' => Quincena::paraFecha(\Carbon\Carbon::parse($datos['fecha_corte']))['periodo_quincena'],
            'fecha_corte' => $datos['fecha_corte'],
            'total_oportuno' => 0,
            'total_extemporaneo' => 0,
            'fecha_emision' => now(),
        ]);

        foreach ($elegidos as [$vale, $monto]) {
            // El monto que se capturó para abonar a este crédito (por defecto, lo que toca
            // cobrar ahora: la cuota más el recargo acumulado — Vale::montoProximoPago()).
            $recargoPct = (float) ($vale->financiera->recargo_porcentaje ?? 0);
            // Si esto se paga después del corte, así de caro sale (monto + pena de la
            // financiera): lo que imprime el recibo de la financiera.
            $montoSiVuelveAFallar = round($monto * (1 + $recargoPct / 100), 2);
            $nuevoSaldo = max(0, (float) $vale->saldo_pendiente - $monto);

            DetalleReciboVale::create([
                'id_recibo' => $recibo->id_recibo,
                'id_vale' => $vale->id_vale,
                'monto_pago' => $monto,
                'numero_pago_texto' => $vale->numeroPagoTexto(),
                'nuevo_saldo' => $nuevoSaldo,
            ]);

            $totalOportuno += $monto;
            $totalExtemporaneo += $montoSiVuelveAFallar;
        }

        $recibo->update([
            'total_oportuno' => $totalOportuno,
            'total_extemporaneo' => $totalExtemporaneo,
        ]);

        return $recibo;
    }

    /** Pantalla que sigue a generar varios recibos a la vez: enlaces a cada uno y "copiar todo". */
    public function resumen(Request $request)
    {
        $ids = collect(explode(',', (string) $request->query('ids')))
            ->map(fn ($i) => (int) $i)->filter()->unique()->take(self::MAX_RECIBOS * 2)->values();

        $recibos = ReciboConsolidado::with(['cliente', 'detalles.vale.financiera'])
            ->whereIn('id_recibo', $ids)->orderBy('id_recibo')->get();
        abort_if($recibos->isEmpty(), 404);

        return view('recibos.resumen', [
            'recibos' => $recibos,
            'textoTodos' => $recibos->map(fn (ReciboConsolidado $r) => $r->textoParaCopiar())->implode("\n\n----------------\n\n"),
        ]);
    }

    public function show(ReciboConsolidado $recibo)
    {
        $recibo->load(['cliente', 'detalles.vale.financiera']);

        return view('recibos.show', compact('recibo'));
    }

    /**
     * Marca el recibo como pagado: aplica el abono a cada vale (avanza
     * quincena y saldo). La fecha de pago y el monto de cada vale son
     * editables desde recibos/show justo antes de confirmar — si el
     * cliente pagó otro día o un monto distinto al sugerido, se captura
     * aquí en vez de forzar el valor calculado al generar el recibo.
     */
    public function confirmarPago(Request $request, ReciboConsolidado $recibo)
    {
        $validated = $request->validate([
            'fecha_pago' => 'required|date',
            'montos' => 'required|array',
            'montos.*' => 'required|numeric|min:0',
        ]);

        $recibo->load('detalles.vale.financiera');
        $fechaPago = \Carbon\Carbon::parse($validated['fecha_pago']);

        DB::transaction(function () use ($recibo, $validated, $fechaPago) {
            foreach ($recibo->detalles as $detalle) {
                $monto = (float) ($validated['montos'][$detalle->id_detalle] ?? $detalle->monto_pago);
                $detalle->vale->registrarPago($monto, $fechaPago);

                $detalle->update([
                    'monto_pago' => $monto,
                    'nuevo_saldo' => $detalle->vale->saldo_pendiente,
                ]);
            }

            $recibo->update(['fecha_pago' => $fechaPago]);
        });

        // Un vale en mora que se acaba de pagar vuelve a ACTIVO en el acto,
        // sin esperar a la revisión diaria.
        Mora::actualizar();

        return redirect()->route('recibos.show', $recibo)->with('success', 'Pago aplicado a los vales del cliente.');
    }
}
