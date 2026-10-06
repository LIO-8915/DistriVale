@extends('layouts.app')

@section('title', 'Vale ' . $vale->folio_vale)
@section('subtitle', $vale->cliente->nombre_completo . ' · ' . $vale->financiera->nombre)
@section('actions')
    @if ($vale->estado === 'LIQUIDADO')
        <form method="GET" action="{{ route('vales.edit', $vale) }}" class="d-inline" data-confirm="Este crédito ya está liquidado. ¿Seguro que quieres modificarlo?">
            <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i> Editar</button>
        </form>
    @else
        <a href="{{ route('vales.edit', $vale) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i> Editar</a>
    @endif
    <a href="{{ route('vales.index') }}" class="btn btn-sm btn-outline-secondary" aria-label="Volver a los créditos" title="Volver a los créditos"><i class="bi bi-arrow-left"></i></a>
@endsection

@section('content')
@if ($vale->estado === 'LIQUIDADO')
    <div class="alert alert-secondary d-flex align-items-center gap-2 mb-3">
        <i class="bi bi-check-circle-fill text-success"></i>
        Este crédito ya está liquidado — esto es su historial, no hace falta modificarlo.
    </div>
@endif

<div class="row g-3 mb-3">
    <div class="col-lg-5">
        <div class="card p-3 h-100">
            <div class="d-flex justify-content-between align-items-start mb-2">
                <div>
                    <div class="fw-bold fs-5">{{ $vale->folio_vale }}</div>
                    <a href="{{ route('clientes.show', $vale->cliente) }}" class="text-decoration-none">{{ $vale->cliente->nombre_completo }}</a>
                    <div class="text-muted small">{{ $vale->financiera->nombre }}</div>
                </div>
                <span class="badge badge-estado-{{ $vale->estado }}">{{ $vale->estadoLegible() }}</span>
            </div>
            <div class="text-muted small">
                Dispuesto {{ $vale->fecha_disposicion?->format('d/m/Y') ?? '—' }}
                @if ($vale->fecha_ultimo_pago)
                    · Último pago {{ $vale->fecha_ultimo_pago->format('d/m/Y') }}
                @endif
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card p-3 h-100">
            <div class="row text-center h-100 align-items-center g-2">
                <div class="col-6 col-md-3 border-end">
                    <div class="text-muted small">Monto original</div>
                    <div class="fw-bold">${{ number_format($vale->monto_original, 2) }}</div>
                </div>
                <div class="col-6 col-md-3 border-end">
                    <div class="text-muted small">Saldo pendiente</div>
                    <div class="fw-bold">${{ number_format($vale->saldo_pendiente, 2) }}</div>
                </div>
                <div class="col-6 col-md-3 border-end">
                    <div class="text-muted small">Pago</div>
                    <div class="fw-bold">{{ $vale->numeroPagoTexto() }}</div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="text-muted small">Próximo pago</div>
                    <div class="fw-bold {{ $vale->recargo_acumulado > 0 ? 'text-danger' : '' }}">${{ number_format($vale->montoProximoPago(), 2) }}</div>
                </div>
            </div>
        </div>
    </div>
</div>

@if ($vale->recargo_acumulado > 0)
    <div class="alert alert-danger d-flex align-items-center gap-2 mb-3">
        <i class="bi bi-exclamation-triangle-fill"></i>
        Este vale trae ${{ number_format($vale->recargo_acumulado, 2) }} de recargo acumulado por cuotas incompletas o no pagadas
        ({{ (float) $vale->financiera->recargo_porcentaje }}% de {{ $vale->financiera->nombre }}) — ya está sumado al próximo pago y al saldo total de arriba.
    </div>
@endif

<div class="card p-3">
    <h6 class="mb-3">Historial de pagos</h6>
    <table class="table table-sm">
        <thead><tr><th># Pago</th><th class="text-end">Monto</th><th class="text-end">Nuevo saldo</th><th>Fecha</th><th class="text-end"></th></tr></thead>
        <tbody>
            @forelse ($vale->detallesRecibo as $detalle)
                <tr>
                    <td>{{ $detalle->numero_pago_texto }}</td>
                    <td class="text-end">${{ number_format($detalle->monto_pago, 2) }}</td>
                    <td class="text-end">${{ number_format($detalle->nuevo_saldo, 2) }}</td>
                    <td>{{ $detalle->created_at->format('d/m/Y') }}</td>
                    <td class="text-end">
                        <a href="{{ route('recibos.show', $detalle->recibo) }}" class="btn btn-sm btn-outline-secondary">Ver recibo</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-center text-muted py-4">Sin pagos registrados todavía.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
