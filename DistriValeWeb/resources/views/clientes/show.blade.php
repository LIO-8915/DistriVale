@extends('layouts.app')

@section('title', $cliente->nombre_completo)
@section('actions')
    <a href="{{ route('recibos.create') }}" class="btn btn-sm btn-primary"><i class="bi bi-receipt"></i> Generar recibo</a>
    <a href="{{ route('clientes.edit', $cliente) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i> Editar</a>
@endsection

@section('content')
<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="card p-3">
            <div class="text-muted small">Teléfono</div>
            <div class="fw-semibold">{{ $cliente->telefono ?: '—' }}</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card p-3">
            <div class="text-muted small">Vales activos</div>
            <div class="fw-semibold">{{ $cliente->vales->whereIn('estado', ['ACTIVO', 'EN_MORA'])->count() }}</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card p-3">
            <div class="text-muted small">Saldo global adeudado</div>
            <div class="fw-semibold">${{ number_format($cliente->saldoTotal(), 2) }}</div>
        </div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header bg-white"><strong>Vales por financiera</strong></div>
    <div class="table-responsive">
        <table class="table mb-0 align-middle">
            <thead>
                <tr>
                    <th>Financiera</th><th>Folio</th><th>Monto</th><th>Cuota quincenal</th>
                    <th># Pago</th><th>Saldo</th><th>Estado</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($cliente->vales as $vale)
                    <tr>
                        <td>{{ $vale->financiera->nombre }}</td>
                        <td>{{ $vale->folio_vale }}</td>
                        <td>${{ number_format($vale->monto_original, 2) }}</td>
                        <td>${{ number_format($vale->cuota_quincenal, 2) }}</td>
                        <td>{{ $vale->numeroPagoTexto() }}</td>
                        <td>${{ number_format($vale->saldo_pendiente, 2) }}</td>
                        <td><span class="badge badge-estado-{{ $vale->estado }}">{{ $vale->estado }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center text-muted py-3">Sin vales registrados.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-header bg-white"><strong>Historial de recibos consolidados</strong></div>
    <div class="table-responsive">
        <table class="table mb-0 align-middle">
            <thead>
                <tr><th>Fecha de corte</th><th>Total oportuno</th><th>Total extemporáneo</th><th></th></tr>
            </thead>
            <tbody>
                @forelse ($cliente->recibos as $recibo)
                    <tr>
                        <td>{{ $recibo->fecha_corte->format('d/m/Y') }}</td>
                        <td>${{ number_format($recibo->total_oportuno, 2) }}</td>
                        <td>${{ number_format($recibo->total_extemporaneo, 2) }}</td>
                        <td class="text-end"><a href="{{ route('recibos.show', $recibo) }}" class="btn btn-sm btn-outline-secondary">Ver</a></td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="text-center text-muted py-3">Sin recibos emitidos.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
