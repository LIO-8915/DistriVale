@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card stat-card p-3">
            <div class="text-muted small">Clientes activos</div>
            <div class="stat-value">{{ $totalClientes }}</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card p-3">
            <div class="text-muted small">Financieras activas</div>
            <div class="stat-value">{{ $totalFinancieras }}</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card p-3">
            <div class="text-muted small">Vales activos / en mora</div>
            <div class="stat-value">{{ $valesActivos }} <span class="text-danger fs-6">/ {{ $valesEnMora }}</span></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card p-3">
            <div class="text-muted small">Saldo pendiente total</div>
            <div class="stat-value">${{ number_format($totalPendiente, 2) }}</div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-md-8">
        <div class="card p-3">
            <h6 class="mb-3">Saldo pendiente por financiera</h6>
            <table class="table table-sm">
                <thead>
                    <tr><th>Financiera</th><th># Vales</th><th class="text-end">Saldo pendiente</th></tr>
                </thead>
                <tbody>
                    @forelse ($porFinanciera as $f)
                        <tr>
                            <td>{{ $f->nombre }}</td>
                            <td>{{ $f->vales_count }}</td>
                            <td class="text-end">${{ number_format($f->saldo_financiera ?? 0, 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="text-center text-muted py-3">Sin financieras registradas.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card p-3">
            <h6 class="mb-3">Accesos rápidos</h6>
            <div class="d-grid gap-2">
                <a href="{{ route('clientes.create') }}" class="btn btn-outline-primary btn-sm"><i class="bi bi-person-plus"></i> Nuevo cliente</a>
                <a href="{{ route('vales.create') }}" class="btn btn-outline-primary btn-sm"><i class="bi bi-ticket"></i> Nuevo vale</a>
                <a href="{{ route('recibos.create') }}" class="btn btn-outline-primary btn-sm"><i class="bi bi-receipt"></i> Generar recibo</a>
                <a href="{{ route('liquidaciones.create') }}" class="btn btn-outline-primary btn-sm"><i class="bi bi-calculator"></i> Registrar liquidación</a>
            </div>
            @if ($ultimaQuincena)
                <hr>
                <div class="small text-muted">Última quincena registrada:</div>
                <div class="fw-semibold">{{ $ultimaQuincena }}</div>
                <a href="{{ route('liquidaciones.index', ['periodo' => $ultimaQuincena]) }}" class="small">Ver liquidación →</a>
            @endif
        </div>
    </div>
</div>
@endsection
