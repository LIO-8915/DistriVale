{{-- Ancho: arriba "Liquidación con financieras" con la gráfica al lado y
     abajo la matriz de clientes a todo lo ancho. Angosto: se apilan en ese
     mismo orden (liquidación, gráfica, clientes). --}}
<div class="row g-3">
    <div class="col-lg-8">
        <div class="card h-100">
            <div class="p-3 pb-0 d-flex justify-content-between align-items-center">
                <h6 class="mb-0">Liquidación con financieras — Cobrar / Poner / Depositar / Ganancias</h6>
            </div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr><th>Financiera</th><th>Corte</th><th>Límite de pago</th><th class="text-end">Cobrar</th><th class="text-end">Poner</th><th class="text-end">Depositar</th><th class="text-end">Ganancias</th></tr>
                    </thead>
                    <tbody>
                        @forelse ($liquidaciones as $l)
                            <tr>
                                <td>{{ $l->financiera->nombre }}</td>
                                <td>{{ $l->fecha_corte?->format('d/m/Y') ?? '—' }}</td>
                                <td>{{ $l->fecha_limite_pago?->format('d/m/Y') ?? '—' }}</td>
                                <td class="text-end">${{ number_format($l->monto_cobrar, 2) }}</td>
                                <td class="text-end {{ $l->monto_poner < 0 ? 'text-danger' : '' }}">${{ number_format($l->monto_poner, 2) }}</td>
                                <td class="text-end">${{ number_format($l->monto_depositar, 2) }}</td>
                                <td class="text-end">${{ number_format($l->monto_ganancias, 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="text-center text-muted py-4">No hay liquidación capturada para esta quincena. <a href="{{ route('liquidaciones.create') }}">Registrar ahora</a>.</td></tr>
                        @endforelse
                    </tbody>
                    @if ($liquidaciones->isNotEmpty())
                        <tfoot>
                            <tr class="table-light fw-bold">
                                <td colspan="3">TOTALES</td>
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

    <div class="col-lg-4">
        <div class="card p-3 h-100">
            <h6 class="mb-3">Distribución de saldo por financiera</h6>
            <canvas id="chartSaldoFinanciera" style="max-height:220px;"
                data-chart="{{ json_encode(['labels' => $saldoPorFinanciera->pluck('nombre'), 'data' => $saldoPorFinanciera->map(fn ($f) => $f->saldo ?? 0)]) }}"></canvas>
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

    <div class="col-12">
        <div class="card">
            <div class="px-3 pt-3"><h6 class="mb-0">Clientes por financiera — Cuota / Pago / Saldo</h6></div>
            @include('partials.tabla-controles', ['paginador' => $matrizPaginada, 'porPagina' => $porPagina])
            <div class="table-responsive dv-matrix-scroll">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th rowspan="2" class="align-middle dv-matrix-pin">Cliente</th>
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
                        @forelse ($matrizPaginada as $fila)
                            <tr>
                                <td class="fw-semibold dv-matrix-pin">
                                    <span class="dv-nombre-cliente" title="{{ $fila['cliente']->nombre_completo }}">{{ $fila['cliente']->nombre_completo }}</span>
                                </td>
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
                                <td class="dv-matrix-pin">TOTALES <span class="fw-normal small text-muted">({{ count($matriz) }} clientes)</span></td>
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
</div>
