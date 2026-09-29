<?php

namespace App\Http\Controllers;

use App\Support\Quincena;
use App\Support\Wireframe\Store;
use Illuminate\Support\Collection;

class DashboardController extends Controller
{
    public function index()
    {
        $clientes = Store::clientes();
        $vales = Store::vales();
        $financieras = Store::financieras();

        $totalClientes = $clientes->where('activo', true)->count();
        $totalFinancieras = $financieras->where('activo', true)->count();
        $valesActivos = $vales->where('estado', 'ACTIVO')->count();
        $valesEnMora = $vales->where('estado', 'EN_MORA')->count();
        $totalAdministrado = (float) $vales->sum('monto_original');
        $totalPendiente = (float) $vales->where('estado', '!=', 'LIQUIDADO')->sum('saldo_pendiente');

        $porFinanciera = $financieras;

        $ultimaQuincena = Store::liquidaciones()->sortByDesc('periodo_quincena')->pluck('periodo_quincena')->first();

        $quincena = Quincena::actual();
        $avanceQuincena = $this->calcularAvanceQuincena($quincena, $vales);
        $actividadReciente = $this->actividadReciente($vales, $financieras);

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

    private function calcularAvanceQuincena(array $quincena, Collection $vales): array
    {
        $total = (float) $vales->whereIn('estado', ['ACTIVO', 'EN_MORA'])->sum('cuota_quincenal');

        $cobrado = (float) Store::recibos()
            ->where('periodo_quincena', $quincena['periodo_quincena'])
            ->flatMap(fn ($r) => $r->detalles)
            ->sum('monto_pago');

        $pendiente = (float) $vales->where('estado', 'EN_MORA')->sum('cuota_quincenal');
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

    private function actividadReciente(Collection $vales, Collection $financieras): Collection
    {
        $pagos = Store::recibos()
            ->flatMap(fn ($r) => $r->detalles)
            ->sortByDesc('created_at')->take(8)
            ->map(fn ($d) => [
                'icono' => 'icono-vale-check.webp',
                'titulo' => 'Pago recibido', 'sub' => $d->recibo->cliente->nombre_completo ?? '—',
                'monto' => (float) $d->monto_pago, 'positivo' => true, 'fecha' => $d->created_at,
            ]);

        $creditos = $vales->sortByDesc('created_at')->take(8)
            ->map(fn ($v) => [
                'icono' => 'icono-vale-check.webp',
                'titulo' => 'Crédito aprobado', 'sub' => 'Vale '.$v->folio_vale,
                'monto' => (float) $v->monto_original, 'positivo' => true, 'fecha' => $v->created_at,
            ]);

        $depositos = Store::liquidaciones()->sortByDesc('created_at')->take(8)
            ->map(fn ($l) => [
                'icono' => 'icono-vale-actualizado.webp',
                'titulo' => 'Depósito registrado', 'sub' => $l->financiera->nombre ?? '—',
                'monto' => (float) $l->monto_depositar, 'positivo' => true, 'fecha' => $l->created_at,
            ]);

        $vencidos = $vales->where('estado', 'EN_MORA')->sortByDesc('updated_at')->take(8)
            ->map(fn ($v) => [
                'icono' => 'icono-vale-atrasado.webp',
                'titulo' => 'Pago vencido', 'sub' => $v->cliente->nombre_completo ?? '—',
                'monto' => (float) $v->cuota_quincenal, 'positivo' => false, 'fecha' => $v->updated_at,
            ]);

        return $pagos->concat($creditos)->concat($depositos)->concat($vencidos)
            ->sortByDesc('fecha')
            ->take(6)
            ->values();
    }
}
