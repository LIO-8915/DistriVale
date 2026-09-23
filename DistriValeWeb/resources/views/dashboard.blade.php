@extends('layouts.app')

@section('title', 'Dashboard')
@section('subtitle', 'Resumen de tu cartera y operaciones')

@section('actions')
    <div class="card px-3 py-2 mb-0 d-flex flex-row align-items-center gap-2" style="box-shadow:none;">
        <i class="bi bi-calendar3 text-primary"></i>
        <div>
            <div class="text-muted" style="font-size:.72rem; line-height:1;">Quincena actual</div>
            <div class="fw-semibold" style="font-size:.88rem;">{{ $quincena['label'] }}</div>
        </div>
    </div>
@endsection

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
            <h6 class="mb-3">Avance de quincena</h6>
            <div class="position-relative mx-auto" style="max-width: 210px;">
                <canvas id="chartAvance"></canvas>
                <div class="position-absolute top-50 start-50 translate-middle text-center">
                    <div class="fw-bold fs-4">{{ $avanceQuincena['pct_cobrado'] }}%</div>
                    <div class="text-muted" style="font-size:.72rem;">${{ number_format($avanceQuincena['cobrado'], 0) }}</div>
                </div>
            </div>
            <div class="text-center text-muted small mt-2">de ${{ number_format($avanceQuincena['total'], 2) }} programado esta quincena</div>
            <div class="d-flex flex-column gap-2 mt-3">
                <div class="d-flex justify-content-between align-items-center">
                    <span><span style="color:#2bc48a">●</span> Cobrado</span>
                    <span class="text-muted">{{ $avanceQuincena['pct_cobrado'] }}%</span>
                    <span class="fw-semibold">${{ number_format($avanceQuincena['cobrado'], 2) }}</span>
                </div>
                <div class="d-flex justify-content-between align-items-center">
                    <span><span style="color:#4f7cff">●</span> Por cobrar</span>
                    <span class="text-muted">{{ $avanceQuincena['pct_por_cobrar'] }}%</span>
                    <span class="fw-semibold">${{ number_format($avanceQuincena['por_cobrar'], 2) }}</span>
                </div>
                <div class="d-flex justify-content-between align-items-center">
                    <span><span style="color:#ff5c72">●</span> Pendiente</span>
                    <span class="text-muted">{{ $avanceQuincena['pct_pendiente'] }}%</span>
                    <span class="fw-semibold">${{ number_format($avanceQuincena['pendiente'], 2) }}</span>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card p-3 h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="mb-0">Actividad reciente</h6>
            </div>
            <div class="d-flex flex-column gap-1">
                @forelse ($actividadReciente as $item)
                    <div class="d-flex justify-content-between align-items-center py-2 {{ !$loop->last ? 'border-bottom' : '' }}">
                        <div class="d-flex align-items-center gap-2">
                            <span class="stat-icon {{ $item['positivo'] ? 'icon-green' : 'icon-orange' }}" style="width:34px;height:34px;font-size:.9rem;">
                                <i class="bi {{ $item['icono'] }}"></i>
                            </span>
                            <div>
                                <div class="fw-semibold" style="font-size:.92rem;">{{ $item['titulo'] }} · {{ $item['sub'] }}</div>
                                <div class="text-muted" style="font-size:.78rem;">{{ $item['fecha']->diffForHumans() }}</div>
                            </div>
                        </div>
                        <div class="fw-bold {{ $item['positivo'] ? 'text-success' : 'text-danger' }}">
                            {{ $item['positivo'] ? '+' : '-' }}${{ number_format($item['monto'], 2) }}
                        </div>
                    </div>
                @empty
                    <p class="text-muted text-center py-4 mb-0">Sin movimientos registrados todavía.</p>
                @endforelse
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mt-1">
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
    <div class="col-lg-5">
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

<div class="row g-3 mt-1">
    <div class="col-12">
        <div class="card p-3">
            <h6 class="mb-3">Distribución de saldo por financiera</h6>
            <canvas id="chartFinanciera" height="70"></canvas>
        </div>
    </div>
</div>

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
    new Chart(document.getElementById('chartAvance'), {
        type: 'doughnut',
        data: {
            labels: ['Cobrado', 'Por cobrar', 'Pendiente'],
            datasets: [{
                data: [{{ $avanceQuincena['cobrado'] }}, {{ $avanceQuincena['por_cobrar'] }}, {{ $avanceQuincena['pendiente'] }}],
                backgroundColor: ['#2bc48a', '#4f7cff', '#ff5c72'],
                borderWidth: 0,
            }]
        },
        options: { cutout: '74%', plugins: { legend: { display: false } } }
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
