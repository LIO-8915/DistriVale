@extends('layouts.app')

@section('title', 'Inicio')
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
{{-- Siempre 2 columnas × 2 filas (nunca 1 fila de 4), a cualquier ancho, para
     que las 4 tarjetas guarden simetría y el texto/monto nunca se corte. --}}
<div class="row g-3 mb-3 dv-stat-row">
    <div class="col-6">
        <div class="card stat-card p-3">
            <div class="d-flex align-items-center gap-3">
                <span class="stat-icon icon-blue"><i class="bi bi-people-fill"></i></span>
                <div class="stat-info">
                    <div class="text-muted small">Clientes activos</div>
                    <div class="stat-value">{{ $totalClientes }}</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6">
        <div class="card stat-card p-3">
            <div class="d-flex align-items-center gap-3">
                <span class="stat-icon icon-purple"><i class="bi bi-bank2"></i></span>
                <div class="stat-info">
                    <div class="text-muted small">Financieras activas</div>
                    <div class="stat-value">{{ $totalFinancieras }}</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6">
        <div class="card stat-card p-3">
            <div class="d-flex align-items-center gap-3">
                <span class="stat-icon icon-orange"><i class="bi bi-exclamation-triangle-fill"></i></span>
                <div class="stat-info">
                    <div class="text-muted small">Activos / Mora</div>
                    <div class="stat-value">{{ $valesActivos }} <span class="text-danger fs-6">/ {{ $valesEnMora }}</span></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6">
        <div class="card stat-card p-3">
            <div class="d-flex align-items-center gap-3">
                <span class="stat-icon icon-green"><i class="bi bi-cash-stack"></i></span>
                <div class="stat-info">
                    <div class="text-muted small">Saldo pendiente total</div>
                    <div class="stat-value">${{ number_format($totalPendiente, 2) }}</div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Ancho suficiente: Avance, Actividad reciente y Accesos rápidos comparten
     fila. Angosto: se apilan en ese mismo orden. --}}
<div class="row g-3">
    <div class="col-lg-3">
        <div class="card p-3 h-100">
            <h6 class="mb-3">Avance de quincena</h6>
            <div class="position-relative mx-auto dv-avance-donut-wrap">
                <canvas id="chartAvance"></canvas>
                <div class="position-absolute top-50 start-50 translate-middle text-center">
                    <div class="fw-bold fs-4">{{ $avanceQuincena['pct_cobrado'] }}%</div>
                    <div class="text-muted" style="font-size:.72rem;">${{ number_format($avanceQuincena['cobrado'], 0) }}</div>
                </div>
            </div>
            <div class="text-center text-muted small mt-2">de ${{ number_format($avanceQuincena['total'], 2) }} programado esta quincena</div>
            {{-- Nombre (en su píldora de vidrio) y, debajo, porcentaje +
                 monto centrados en el mismo eje — se mantiene así sin
                 importar qué tan largo sea el nombre o cuántos dígitos
                 tenga el monto. --}}
            <div class="d-flex flex-column gap-3 mt-3">
                <div class="dv-avance-item">
                    <span class="dv-avance-label"><span style="color:#2bc48a">●</span> Cobrado</span>
                    <div class="dv-avance-values">
                        <span class="text-muted">{{ $avanceQuincena['pct_cobrado'] }}%</span>
                        <span class="fw-semibold">${{ number_format($avanceQuincena['cobrado'], 2) }}</span>
                    </div>
                </div>
                <div class="dv-avance-item">
                    <span class="dv-avance-label"><span style="color:#4f7cff">●</span> Por cobrar</span>
                    <div class="dv-avance-values">
                        <span class="text-muted">{{ $avanceQuincena['pct_por_cobrar'] }}%</span>
                        <span class="fw-semibold">${{ number_format($avanceQuincena['por_cobrar'], 2) }}</span>
                    </div>
                </div>
                <div class="dv-avance-item">
                    <span class="dv-avance-label"><span style="color:#ff5c72">●</span> Pendiente</span>
                    <div class="dv-avance-values">
                        <span class="text-muted">{{ $avanceQuincena['pct_pendiente'] }}%</span>
                        <span class="fw-semibold">${{ number_format($avanceQuincena['pendiente'], 2) }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card p-3 h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="mb-0">Actividad reciente</h6>
            </div>
            <div class="d-flex flex-column gap-1">
                @forelse ($actividadReciente as $item)
                    <div class="d-flex justify-content-between align-items-center py-2 {{ !$loop->last ? 'border-bottom' : '' }}">
                        <div class="d-flex align-items-center gap-2">
                            <span class="dv-activity-icon">
                                <img src="{{ asset('images/'.$item['icono']) }}" alt="">
                            </span>
                            <div>
                                <div class="fw-semibold" style="font-size:.92rem;">{{ $item['titulo'] }} · {{ $item['sub'] }}</div>
                                <div class="text-muted" style="font-size:.78rem;">{{ $item['fecha']->diffForHumans() }}</div>
                            </div>
                        </div>
                        <div class="dv-activity-amount {{ $item['positivo'] ? 'text-success' : 'text-danger' }}">
                            {{ $item['positivo'] ? '+' : '-' }}${{ number_format($item['monto'], 2) }}
                        </div>
                    </div>
                @empty
                    <p class="text-muted text-center py-4 mb-0">Sin movimientos registrados todavía.</p>
                @endforelse
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card p-3 h-100">
            <h6 class="mb-3">Accesos rápidos</h6>
            <div class="d-flex flex-column gap-2">
                <a href="{{ route('clientes.create') }}" class="dv-glass-chip glass-quick-btn glass-fill-blue"><i class="bi bi-person-plus"></i><span>Nuevo cliente</span></a>
                <a href="{{ route('vales.create') }}" class="dv-glass-chip glass-quick-btn glass-fill-purple"><i class="bi bi-ticket"></i><span>Nuevo vale</span></a>
                <a href="{{ route('recibos.create') }}" class="dv-glass-chip glass-quick-btn glass-fill-green"><i class="bi bi-receipt"></i><span>Generar recibo</span></a>
                <a href="{{ route('liquidaciones.create') }}" class="dv-glass-chip glass-quick-btn glass-fill-orange"><i class="bi bi-calculator"></i><span>Registrar liquidación</span></a>
            </div>
            @if ($ultimaQuincena)
                <hr>
                <div class="small text-muted">Última quincena registrada:</div>
                <div class="fw-semibold">{{ $ultimaQuincena }}</div>
                <a href="{{ route('liquidaciones.index', ['periodo' => $ultimaQuincena]) }}" class="small" style="color:#000;">Ver liquidación →</a>
            @endif
        </div>
    </div>
</div>

{{-- Ancho suficiente: Saldo pendiente y Distribución comparten fila, con
     más espacio para Distribución (7 de 12). Angosto: se apilan, siempre en
     ese orden. La gráfica de Distribución siempre es alta (ver
     #chartFinanciera-wrap): a lo ancho completo se veía demasiado corta
     para distinguir a las financieras con montos chicos frente a CaptaVale. --}}
<div class="row g-3 mt-1">
    <div class="col-lg-5">
        <div class="card p-3 h-100">
            <h6 class="mb-3">Saldo pendiente por financiera</h6>
            {{-- Sin .table-responsive esta tabla no tenía scroll propio: al
                 angostar la ventana la columna de saldo se cortaba contra el
                 borde redondeado de la tarjeta (que recorta con
                 overflow:hidden) en vez de poder desplazarse. --}}
            <div class="table-responsive">
            <table class="table table-sm mb-0">
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
    <div class="col-lg-7">
        <div class="card p-3 h-100">
            <h6 class="mb-3">Distribución de saldo por financiera</h6>
            <div id="chartFinanciera-wrap" style="height: 360px;">
                <canvas id="chartFinanciera"></canvas>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
    // Todo el script va en un IIFE a propósito: dv-nav.js reinyecta este
    // <script> cada vez que se vuelve a Inicio (clona el nodo y lo vuelve a
    // insertar, sin recargar la página). Un `const`/`let` declarado suelto
    // en el script vive en el ámbito léxico GLOBAL de la página, y ese
    // ámbito no se limpia solo porque el <script> anterior se haya quitado
    // del DOM — la segunda vez que este bloque se ejecuta, el motor lanza
    // "Identifier 'finCtx' has already been declared" y aborta TODO el
    // script en silencio (nunca se ve en pantalla, solo en la consola).
    // Esa era la causa real de que la gráfica de Distribución por
    // financiera dejara de dibujarse al volver a Inicio — no era Chart.js
    // por sí solo. El IIFE le da a esas variables un ámbito de función
    // nuevo en cada ejecución, así puede volver a correr las veces que
    // haga falta.
    (function () {
        // Ambas gráficas reusan el mismo id de <canvas> en cada carga; si el
        // Chart anterior no se destruye, Chart.js lo sigue teniendo
        // registrado contra ese id. Chart.getChart() localiza la instancia
        // previa (si sigue viva) para destruirla antes de crear la nueva.
        [Chart.getChart('chartAvance'), Chart.getChart('chartFinanciera')]
            .filter(Boolean)
            .forEach(chart => chart.destroy());

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
    // CaptaVale suele ser varias veces más grande que el resto: en una
    // escala lineal las financieras chicas quedan como una raya de un par
    // de píxeles, ilegible aunque la gráfica sea alta. El monto encima de
    // cada barra la hace legible de todas formas, sin depender de su alto.
    const valorEncimaBarra = {
        id: 'valorEncimaBarra',
        afterDatasetsDraw(chart) {
            const { ctx } = chart;
            chart.getDatasetMeta(0).data.forEach((barra, i) => {
                const valor = chart.data.datasets[0].data[i];
                ctx.save();
                ctx.fillStyle = '#1c2733';
                ctx.font = '600 11px sans-serif';
                ctx.textAlign = 'center';
                ctx.fillText('$' + Math.round(valor).toLocaleString('es-MX'), barra.x, barra.y - 8);
                ctx.restore();
            });
        }
    };

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
        plugins: [valorEncimaBarra],
        options: {
            responsive: true,
            maintainAspectRatio: false,
            layout: { padding: { top: 24 } },
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,.05)' } }, x: { grid: { display: false } } }
        }
    });
    })();
</script>
@endpush
@endsection
