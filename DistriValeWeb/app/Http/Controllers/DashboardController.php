<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\DetalleReciboVale;
use App\Models\Financiera;
use App\Models\LiquidacionQuincena;
use App\Models\Vale;
use App\Support\Quincena;
use Illuminate\Support\Collection;

class DashboardController extends Controller
{
    public function index()
    {
        $totalClientes = Cliente::where('activo', true)->count();
        $totalFinancieras = Financiera::where('activo', true)->count();
        $valesActivos = Vale::where('estado', 'ACTIVO')->count();
        $valesEnMora = Vale::where('estado', 'EN_MORA')->count();
        $totalAdministrado = Vale::sum('monto_original');
        $totalPendiente = Vale::where('estado', '!=', 'LIQUIDADO')->sum('saldo_pendiente');

        $porFinanciera = Financiera::withCount('vales')
            ->withSum(['vales as saldo_financiera' => fn ($q) => $q->where('estado', '!=', 'LIQUIDADO')], 'saldo_pendiente')
            ->orderBy('nombre')
            ->get();

        $ultimaQuincena = LiquidacionQuincena::select('periodo_quincena')
            ->orderByDesc('periodo_quincena')
            ->value('periodo_quincena');

        $quincena = Quincena::actual();
        $avanceQuincena = $this->calcularAvanceQuincena($quincena);
        $actividadReciente = $this->actividadReciente();

        return view('dashboard', compact(
            'totalClientes',
            'totalFinancieras',
            'valesActivos',
            'valesEnMora',
            'totalAdministrado',
            'totalPendiente',
            'porFinanciera',
            'ultimaQuincena',
            'quincena',
            'avanceQuincena',
            'actividadReciente',
        ));
    }

    private function calcularAvanceQuincena(array $quincena): array
    {
        $total = (float) Vale::whereIn('estado', ['ACTIVO', 'EN_MORA'])->sum('cuota_quincenal');

        $cobrado = (float) DetalleReciboVale::whereHas(
            'recibo',
            fn ($q) => $q->where('periodo_quincena', $quincena['periodo_quincena'])
        )->sum('monto_pago');

        $pendiente = (float) Vale::where('estado', 'EN_MORA')->sum('cuota_quincenal');
        $porCobrar = max(0, $total - $cobrado - $pendiente);

        $pct = fn (float $parte) => $total > 0 ? round($parte / $total * 100) : 0;

        return [
            'total' => $total,
            'cobrado' => $cobrado,
            'por_cobrar' => $porCobrar,
            'pendiente' => $pendiente,
            'pct_cobrado' => $pct($cobrado),
            'pct_por_cobrar' => $pct($porCobrar),
            'pct_pendiente' => $pct($pendiente),
        ];
    }

    private function actividadReciente(): Collection
    {
        $pagos = DetalleReciboVale::with('recibo.cliente')
            ->latest('created_at')->take(8)->get()
            ->map(fn ($d) => [
                'icono' => 'bi-cash-coin', 'color' => 'green',
                'titulo' => 'Pago recibido', 'sub' => $d->recibo->cliente->nombre_completo ?? '—',
                'monto' => (float) $d->monto_pago, 'positivo' => true, 'fecha' => $d->created_at,
            ]);

        $creditos = Vale::with('cliente')->latest('created_at')->take(8)->get()
            ->map(fn ($v) => [
                'icono' => 'bi-ticket-perforated', 'color' => 'green',
                'titulo' => 'Crédito aprobado', 'sub' => 'Vale '.$v->folio_vale,
                'monto' => (float) $v->monto_original, 'positivo' => true, 'fecha' => $v->created_at,
            ]);

        $depositos = LiquidacionQuincena::with('financiera')->latest('created_at')->take(8)->get()
            ->map(fn ($l) => [
                'icono' => 'bi-bank2', 'color' => 'green',
                'titulo' => 'Depósito registrado', 'sub' => $l->financiera->nombre ?? '—',
                'monto' => (float) $l->monto_depositar, 'positivo' => true, 'fecha' => $l->created_at,
            ]);

        $vencidos = Vale::with('cliente')->where('estado', 'EN_MORA')->latest('updated_at')->take(8)->get()
            ->map(fn ($v) => [
                'icono' => 'bi-exclamation-triangle', 'color' => 'red',
                'titulo' => 'Pago vencido', 'sub' => $v->cliente->nombre_completo ?? '—',
                'monto' => (float) $v->cuota_quincenal, 'positivo' => false, 'fecha' => $v->updated_at,
            ]);

        return $pagos->concat($creditos)->concat($depositos)->concat($vencidos)
            ->sortByDesc('fecha')
            ->take(6)
            ->values();
    }
}
