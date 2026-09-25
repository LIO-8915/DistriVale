@extends('layouts.app')

@section('title', 'Liquidación')
@section('subtitle', 'Reporte de cobranza y liquidación de la quincena seleccionada.')
@section('actions')
    <a href="{{ route('liquidaciones.pdf', ['periodo' => $periodo]) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-file-earmark-pdf"></i> Descargar PDF</a>
    <a href="{{ route('liquidaciones.create') }}" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg"></i> Registrar liquidación</a>
@endsection

@section('content')
<div class="card p-3 mb-3">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-5">
            <label class="form-label small text-muted mb-1">Quincena</label>
            <select name="periodo" class="form-select" onchange="this.form.submit()">
                @foreach ($periodos as $p)
                    <option value="{{ $p }}" @selected($periodo == $p)>{{ $p }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-7">
            <div class="text-muted small">Periodo seleccionado</div>
            <div class="fw-semibold">{{ $periodo }}</div>
        </div>
    </form>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card">
            <div class="p-3 pb-0"><h6 class="mb-0">Clientes por financiera — Cuota / Pago / Saldo</h6></div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th rowspan="2" class="align-middle">Cliente</th>
                            @foreach ($financieras as $f)
                                <th colspan="3" class="text-center">{{ $f->nombre }}</th>
                            @endforeach
                            <th rowspan="2" class="text-end align-middle">Total</th>
                            <th rowspan="2" class="align-middle">Estado</th>
                        </tr>
                        <tr>
                            @foreach ($financieras as $f)
                                <th class="text-end">Cuota</th><th class="text-end">Pago</th><th class="text-end">Saldo</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($matriz as $fila)
                            <tr>
                                <td class="fw-semibold">{{ $fila['cliente']->nombre_completo }}</td>
                                @foreach ($financieras as $f)
                                    @php $d = $fila['financieras'][$f->id_financiera]; @endphp
                                    @if ($d)
                                        <td class="text-end">${{ number_format($d['cuota'], 0) }}</td>
                                        <td class="text-end">${{ number_format($d['pago'], 0) }}</td>
                                        <td class="text-end">${{ number_format($d['saldo'], 0) }}</td>
                                    @else
                                        <td class="text-end text-muted">—</td><td class="text-end text-muted">—</td><td class="text-end text-muted">—</td>
                                    @endif
                                @endforeach
                                <td class="text-end fw-bold">${{ number_format($fila['total_saldo'], 0) }}</td>
                                <td>
                                    <span class="badge {{ $fila['oportuno'] ? 'bg-success' : '' }}" @if(!$fila['oportuno']) style="background:rgba(255,159,67,.16);color:#c97316;" @endif>
                                        {{ $fila['oportuno'] ? 'Oportuno' : 'Extemporáneo' }}
                                    </span>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="{{ count($financieras) * 3 + 3 }}" class="text-center text-muted py-4">Sin clientes con créditos activos.</td></tr>
                        @endforelse
                    </tbody>
                    @if (count($matriz))
                        <tfoot>
                            <tr class="table-light fw-bold">
                                <td>TOTALES</td>
                                @foreach ($financieras as $f)
                                    <td class="text-end">${{ number_format($totales['por_financiera'][$f->id_financiera]['cuota'], 0) }}</td>
                                    <td class="text-end">${{ number_format($totales['por_financiera'][$f->id_financiera]['pago'], 0) }}</td>
                                    <td class="text-end">${{ number_format($totales['por_financiera'][$f->id_financiera]['saldo'], 0) }}</td>
                                @endforeach
                                <td class="text-end">${{ number_format($totales['saldo'], 0) }}</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card p-3">
            <h6 class="mb-3">Distribución de saldo por financiera</h6>
            <canvas id="chartSaldoFinanciera" style="max-height:220px;"></canvas>
            <div class="d-flex flex-column gap-1 mt-3">
                @php $totalSaldo = $saldoPorFinanciera->sum('saldo'); @endphp
                @foreach ($saldoPorFinanciera as $f)
                    <div class="d-flex justify-content-between small">
                        <span>{{ $f->nombre }}</span>
                        <span class="text-muted">{{ $totalSaldo > 0 ? round(($f->saldo ?? 0) / $totalSaldo * 100) : 0 }}%</span>
                        <span class="fw-semibold">${{ number_format($f->saldo ?? 0, 2) }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mt-1">
    <div class="col-12">
        <div class="card">
            <div class="p-3 pb-0 d-flex justify-content-between align-items-center">
                <h6 class="mb-0">Liquidación con financieras — Cobrar / Poner / Depositar / Ganancias</h6>
            </div>
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
                            <tr><td colspan="5" class="text-center text-muted py-4">No hay liquidación capturada para esta quincena. <a href="{{ route('liquidaciones.create') }}">Registrar ahora</a>.</td></tr>
                        @endforelse
                    </tbody>
                    @if ($liquidaciones->isNotEmpty())
                        <tfoot>
                            <tr class="table-light fw-bold">
                                <td>TOTALES</td>
                                <td class="text-end">${{ number_format($totalesCaptura['cobrar'], 2) }}</td>
                                <td class="text-end">${{ number_format($totalesCaptura['poner'], 2) }}</td>
                                <td class="text-end">${{ number_format($totalesCaptura['depositar'], 2) }}</td>
                                <td class="text-end">${{ number_format($totalesCaptura['ganancias'], 2) }}</td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script src="{{ asset('vendor/chartjs/chart.umd.min.js') }}"></script>
<script>
    new Chart(document.getElementById('chartSaldoFinanciera'), {
        type: 'doughnut',
        data: {
            labels: [@foreach ($saldoPorFinanciera as $f) '{{ $f->nombre }}', @endforeach],
            datasets: [{
                data: [@foreach ($saldoPorFinanciera as $f) {{ $f->saldo ?? 0 }}, @endforeach],
                backgroundColor: ['#4f7cff', '#8b6bff', '#ff9f43', '#2bc48a', '#ff5c72'],
                borderWidth: 0,
            }]
        },
        options: { cutout: '68%', plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } } } }
    });
</script>
@endpush
@endsection
