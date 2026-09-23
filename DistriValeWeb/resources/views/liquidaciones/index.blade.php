@extends('layouts.app')

@section('title', 'Liquidación quincenal')
@section('actions')
    <a href="{{ route('liquidaciones.create') }}" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> Registrar liquidación</a>
@endsection

@section('content')
<div class="card p-3 mb-3">
    <form method="GET" class="row g-2">
        <div class="col-md-4">
            <select name="periodo" class="form-select form-select-sm" onchange="this.form.submit()">
                @forelse ($periodos as $p)
                    <option value="{{ $p }}" @selected($periodo == $p)>{{ $p }}</option>
                @empty
                    <option value="">Sin quincenas registradas</option>
                @endforelse
            </select>
        </div>
    </form>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead>
                <tr><th>Financiera</th><th class="text-end">Cobrar</th><th class="text-end">Poner</th><th class="text-end">Depositar</th><th class="text-end">Ganancias</th></tr>
            </thead>
            <tbody>
                @forelse ($liquidaciones as $l)
                    <tr>
                        <td>{{ $l->financiera->nombre }}</td>
                        <td class="text-end">${{ number_format($l->monto_cobrar, 2) }}</td>
                        <td class="text-end {{ $l->monto_poner < 0 ? 'text-danger' : '' }}">${{ number_format($l->monto_poner, 2) }}</td>
                        <td class="text-end">${{ number_format($l->monto_depositar, 2) }}</td>
                        <td class="text-end">${{ number_format($l->monto_ganancias, 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-muted py-4">No hay liquidaciones registradas para esta quincena.</td></tr>
                @endforelse
            </tbody>
            @if ($liquidaciones->isNotEmpty())
                <tfoot>
                    <tr class="table-light fw-bold">
                        <td>TOTALES</td>
                        <td class="text-end">${{ number_format($totales['cobrar'], 2) }}</td>
                        <td class="text-end">${{ number_format($totales['poner'], 2) }}</td>
                        <td class="text-end">${{ number_format($totales['depositar'], 2) }}</td>
                        <td class="text-end">${{ number_format($totales['ganancias'], 2) }}</td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
</div>
@endsection
