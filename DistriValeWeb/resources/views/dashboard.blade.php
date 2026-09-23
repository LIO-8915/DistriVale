@extends('layouts.app')

@section('title', 'Dashboard')
@section('subtitle', 'Resumen de tu cartera y operaciones')

@section('content')
<div class="row g-3 mb-3">
    <div class="col-md-3">
        <div class="card stat-card p-3">
            <div class="d-flex align-items-center gap-3">
                <span class="stat-icon icon-blue"><i class="bi bi-people-fill"></i></span>
                <div>
                    <div class="text-muted small">Clientes activos</div>
                    <div class="stat-value">{{ $totalClientes }}</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card p-3">
            <div class="d-flex align-items-center gap-3">
                <span class="stat-icon icon-purple"><i class="bi bi-bank2"></i></span>
                <div>
                    <div class="text-muted small">Financieras activas</div>
                    <div class="stat-value">{{ $totalFinancieras }}</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card p-3">
            <div class="d-flex align-items-center gap-3">
                <span class="stat-icon icon-orange"><i class="bi bi-exclamation-triangle-fill"></i></span>
                <div>
                    <div class="text-muted small">Activos / En mora</div>
                    <div class="stat-value">{{ $valesActivos }} <span class="text-danger fs-6">/ {{ $valesEnMora }}</span></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card p-3">
            <div class="d-flex align-items-center gap-3">
                <span class="stat-icon icon-green"><i class="bi bi-cash-stack"></i></span>
                <div>
                    <div class="text-muted small">Saldo pendiente total</div>
                    <div class="stat-value">${{ number_format($totalPendiente, 2) }}</div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card p-3 h-100">
            <h6 class="mb-3">Cartera de créditos</h6>
            <div style="max-width: 230px; margin: 0 auto;">
                <canvas id="chartCartera"></canvas>
            </div>
            <div class="d-flex justify-content-around mt-3 small">
                <div><span class="badge" style="background: rgba(79,124,255,.14); color:#3f63d1;">● Al día</span></div>
                <div><span class="badge bg-danger">● En mora</span></div>
                <div><span class="badge bg-secondary">● Liquidado</span></div>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card p-3 h-100">
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
</div>

<div class="row g-3 mt-1">
    <div class="col-lg-8">
        <div class="card p-3 h-100">
            <h6 class="mb-3">Distribución de saldo por financiera</h6>
            <canvas id="chartFinanciera" height="90"></canvas>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card p-3 h-100">
            <h6 class="mb-3">Accesos rápidos</h6>
            <div class="d-flex flex-column gap-2">
                <div data-glass-button data-href="{{ route('clientes.create') }}" data-icon="bi-person-plus" data-color="blue">Nuevo cliente</div>
                <div data-glass-button data-href="{{ route('vales.create') }}" data-icon="bi-ticket" data-color="purple">Nuevo vale</div>
                <div data-glass-button data-href="{{ route('recibos.create') }}" data-icon="bi-receipt" data-color="green">Generar recibo</div>
                <div data-glass-button data-href="{{ route('liquidaciones.create') }}" data-icon="bi-calculator" data-color="orange">Registrar liquidación</div>
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

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
    const carteraCtx = document.getElementById('chartCartera');
    new Chart(carteraCtx, {
        type: 'doughnut',
        data: {
            labels: ['Al día', 'En mora', 'Liquidado'],
            datasets: [{
                data: [{{ $valesActivos }}, {{ $valesEnMora }}, {{ max(0, ($totalClientes ?: 0)) }}],
                backgroundColor: ['#4f7cff', '#ff5c72', '#9aa4b5'],
                borderWidth: 0,
            }]
        },
        options: { cutout: '72%', plugins: { legend: { display: false } } }
    });

    const finCtx = document.getElementById('chartFinanciera');
    new Chart(finCtx, {
        type: 'bar',
        data: {
            labels: [@foreach ($porFinanciera as $f) '{{ $f->nombre }}', @endforeach],
            datasets: [{
                label: 'Saldo pendiente',
                data: [@foreach ($porFinanciera as $f) {{ $f->saldo_financiera ?? 0 }}, @endforeach],
                backgroundColor: '#4f7cff',
                borderRadius: 8,
                maxBarThickness: 36,
            }]
        },
        options: {
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,.05)' } }, x: { grid: { display: false } } }
        }
    });
</script>
@endpush
@endsection
