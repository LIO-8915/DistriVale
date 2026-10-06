@extends('layouts.app')

@section('title', 'Detalle de cliente')
@section('actions')
    <a href="{{ route('clientes.pdf', $cliente) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-file-earmark-pdf"></i> Descargar PDF</a>
    <a href="{{ route('clientes.edit', $cliente) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i> Editar</a>
    <a href="{{ route('clientes.index') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
@endsection

@section('content')
<div class="row g-3 mb-3">
    <div class="col-lg-5">
        <div class="card p-3 h-100">
            <div class="d-flex align-items-start gap-3">
                <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0"
                     style="width:56px;height:56px;background:linear-gradient(135deg,#4f7cff,#7aa2ff);color:#fff;font-weight:700;font-size:1.1rem;">
                    {{ collect(explode(' ', $cliente->nombre_completo))->map(fn($p) => mb_substr($p,0,1))->take(2)->join('') }}
                </div>
                <div class="flex-grow-1">
                    <div class="d-flex justify-content-between align-items-start">
                        <div class="fw-bold fs-5">{{ $cliente->nombre_completo }}</div>
                        <span class="badge {{ $cliente->activo ? 'bg-success' : 'bg-secondary' }}">
                            {{ $cliente->activo ? 'Activo' : 'Inactivo' }}
                        </span>
                    </div>
                    <div class="text-muted small mb-2">ID: C-{{ str_pad($cliente->id_cliente, 3, '0', STR_PAD_LEFT) }} · Cliente desde {{ $cliente->created_at->format('m/Y') }}</div>
                    @if ($cliente->telefono)<div class="small"><i class="bi bi-telephone me-1"></i>{{ $cliente->telefono }}</div>@endif
                    @if ($cliente->direccion)<div class="small text-truncate"><i class="bi bi-geo-alt me-1"></i>{{ $cliente->direccion }}</div>@endif
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card p-3 h-100">
            <div class="row text-center h-100 align-items-center dv-resumen-fila">
                <div class="col-4 border-end">
                    <div class="text-muted small">Saldo global</div>
                    <div class="fw-bold fs-5">${{ number_format($cliente->saldoTotal(), 2) }}</div>
                </div>
                <div class="col-4 border-end">
                    <div class="text-muted small">Créditos / Vales</div>
                    <div class="fw-bold fs-5">{{ $cliente->vales->count() }}</div>
                </div>
                <div class="col-4">
                    <div class="text-muted small">Último pago</div>
                    <div class="fw-bold fs-5">{{ $ultimoPago?->created_at?->format('d/m/Y') ?? '—' }}</div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card p-3">
            <div class="dv-chips mb-3">
                <button type="button" class="dv-chip active" data-tab="resumen">Resumen</button>
                <button type="button" class="dv-chip" data-tab="vales">Vales / Créditos</button>
                <button type="button" class="dv-chip" data-tab="pagos">Pagos</button>
                <button type="button" class="dv-chip" data-tab="historial">Historial</button>
                <button type="button" class="dv-chip" data-tab="notas">Notas</button>
            </div>

            <div data-tab-panel="resumen">
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="small text-muted mb-1">Vales activos</div>
                        <div class="fw-semibold">{{ $cliente->vales->whereIn('estado', ['ACTIVO', 'EN_MORA'])->count() }}</div>
                    </div>
                    <div class="col-md-6">
                        <div class="small text-muted mb-1">Vales liquidados</div>
                        <div class="fw-semibold">{{ $cliente->vales->where('estado', 'LIQUIDADO')->count() }}</div>
                    </div>
                    <div class="col-md-6">
                        <div class="small text-muted mb-1">Financieras</div>
                        <div class="fw-semibold">{{ $cliente->financierasNombres() }}</div>
                    </div>
                    <div class="col-md-6">
                        <div class="small text-muted mb-1">Recibos emitidos</div>
                        <div class="fw-semibold">{{ $cliente->recibos->count() }}</div>
                    </div>
                </div>
            </div>

            <div data-tab-panel="vales" class="d-none">
                @forelse ($cliente->vales->groupBy('id_financiera') as $grupo)
                    <div class="border rounded-3 p-3 mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <div class="fw-semibold"><i class="bi bi-bank me-1"></i>{{ $grupo->first()->financiera->nombre }}</div>
                            <span class="text-muted small">{{ $grupo->count() }} vale(s)</span>
                        </div>
                        @foreach ($grupo as $vale)
                            @php $avance = $vale->monto_original > 0 ? round((1 - $vale->saldo_pendiente / $vale->monto_original) * 100) : 0; @endphp
                            <a href="{{ route('vales.show', $vale) }}" class="d-flex align-items-center justify-content-between py-2 text-decoration-none text-reset {{ !$loop->last ? 'border-bottom' : '' }}">
                                <div style="min-width:90px;"><span class="fw-semibold">{{ $vale->folio_vale }}</span></div>
                                <div style="min-width:100px;">${{ number_format($vale->monto_original, 2) }}</div>
                                <div style="min-width:100px;">${{ number_format($vale->saldo_pendiente, 2) }}</div>
                                <div class="flex-grow-1 mx-3">
                                    <div class="progress" style="height:6px;">
                                        <div class="progress-bar bg-success" style="width:{{ $avance }}%"></div>
                                    </div>
                                </div>
                                <div style="min-width:70px;" class="text-end"><span class="badge badge-estado-{{ $vale->estado }}">{{ $avance }}%</span></div>
                            </a>
                        @endforeach
                    </div>
                @empty
                    <p class="text-muted text-center py-4">Sin vales registrados.</p>
                @endforelse
            </div>

            <div data-tab-panel="pagos" class="d-none">
                <table class="table table-sm">
                    <thead><tr><th>Vale</th><th>Financiera</th><th># Pago</th><th class="text-end">Monto</th><th>Fecha</th></tr></thead>
                    <tbody>
                        @forelse ($cliente->vales->flatMap->detallesRecibo->sortByDesc('created_at') as $pago)
                            <tr>
                                <td><a href="{{ route('vales.show', $pago->vale) }}" class="text-decoration-none">{{ $pago->vale->folio_vale }}</a></td>
                                <td>{{ $pago->vale->financiera->nombre }}</td>
                                <td>{{ $pago->numero_pago_texto }}</td>
                                <td class="text-end text-success fw-semibold">${{ number_format($pago->monto_pago, 2) }}</td>
                                <td>{{ $pago->created_at->format('d/m/Y') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-center text-muted py-4">Sin pagos registrados.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div data-tab-panel="historial" class="d-none">
                <table class="table table-sm">
                    <thead><tr><th>Fecha de corte</th><th>Total oportuno</th><th>Total extemporáneo</th><th></th></tr></thead>
                    <tbody>
                        @forelse ($cliente->recibos as $recibo)
                            <tr>
                                <td>{{ $recibo->fecha_corte->format('d/m/Y') }}</td>
                                <td>${{ number_format($recibo->total_oportuno, 2) }}</td>
                                <td>${{ number_format($recibo->total_extemporaneo, 2) }}</td>
                                <td class="text-end"><a href="{{ route('recibos.show', $recibo) }}" class="btn btn-sm btn-outline-secondary">Ver</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-center text-muted py-4">Sin recibos emitidos.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div data-tab-panel="notas" class="d-none">
                @forelse ($cliente->notas as $nota)
                    <div class="border-bottom py-2">
                        <div>{{ $nota->contenido }}</div>
                        <div class="text-muted small">{{ $nota->created_at->format('d/m/Y H:i') }}</div>
                    </div>
                @empty
                    <p class="text-muted text-center py-4">Sin notas registradas.</p>
                @endforelse
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card p-3">
            <h6 class="mb-3"><i class="bi bi-sticky me-1"></i>Nueva nota</h6>
            <form action="{{ route('clientes.notas.store', $cliente) }}" method="POST">
                @csrf
                <textarea name="contenido" class="form-control mb-2" rows="5" placeholder="Escribe una nota sobre este cliente..." required></textarea>
                <button class="btn btn-primary btn-sm w-100">Guardar nota</button>
            </form>
        </div>
    </div>
</div>

<style>
    .dv-chips { display: flex; gap: .5rem; flex-wrap: wrap; }
    .dv-chip {
        border: 1px solid rgba(0,0,0,.1); background: rgba(255,255,255,.5); border-radius: 999px;
        padding: .45rem 1rem; font-size: .85rem; font-weight: 600; color: #000; cursor: pointer;
    }
    .dv-chip.active { background: linear-gradient(135deg, #4f7cff, #6f9bff); color: #fff; border-color: transparent; }
</style>

@push('scripts')
<script>
    document.querySelectorAll('.dv-chip').forEach(function (chip) {
        chip.addEventListener('click', function () {
            document.querySelectorAll('.dv-chip').forEach(c => c.classList.remove('active'));
            document.querySelectorAll('[data-tab-panel]').forEach(p => p.classList.add('d-none'));
            chip.classList.add('active');
            document.querySelector('[data-tab-panel="' + chip.dataset.tab + '"]').classList.remove('d-none');
        });
    });
</script>
@endpush
@endsection
