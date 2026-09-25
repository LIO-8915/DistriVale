@extends('layouts.app')

@section('title', 'Vales')
@section('actions')
    <a href="{{ route('vales.create') }}" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> Nuevo vale</a>
@endsection

@section('content')
<div class="card p-3 mb-3">
    <button type="button" class="btn btn-outline-secondary btn-sm d-md-none mb-2 w-100" data-bs-toggle="collapse" data-bs-target="#valesFiltros">
        <i class="bi bi-sliders"></i> Filtros
    </button>
    <div class="collapse d-md-block" id="valesFiltros">
        <form method="GET" class="row g-2">
            <div class="col-md-3">
                <select name="id_financiera" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">Todas las financieras</option>
                    @foreach ($financieras as $f)
                        <option value="{{ $f->id_financiera }}" @selected(request('id_financiera') == $f->id_financiera)>{{ $f->nombre }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <select name="estado" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">Todos los estados</option>
                    <option value="ACTIVO" @selected(request('estado') == 'ACTIVO')>Activo</option>
                    <option value="EN_MORA" @selected(request('estado') == 'EN_MORA')>En mora</option>
                    <option value="LIQUIDADO" @selected(request('estado') == 'LIQUIDADO')>Liquidado</option>
                </select>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead>
                <tr>
                    <th>Cliente</th><th>Financiera</th><th>Folio</th><th>Monto</th>
                    <th>Cuota</th><th># Pago</th><th>Saldo</th><th>Estado</th><th class="text-end">Acciones</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($vales as $vale)
                    <tr>
                        <td><a href="{{ route('clientes.show', $vale->cliente) }}" class="text-decoration-none">{{ $vale->cliente->nombre_completo }}</a></td>
                        <td>{{ $vale->financiera->nombre }}</td>
                        <td>{{ $vale->folio_vale }}</td>
                        <td>${{ number_format($vale->monto_original, 2) }}</td>
                        <td>${{ number_format($vale->cuota_quincenal, 2) }}</td>
                        <td>{{ $vale->numeroPagoTexto() }}</td>
                        <td>${{ number_format($vale->saldo_pendiente, 2) }}</td>
                        <td><span class="badge badge-estado-{{ $vale->estado }}">{{ $vale->estado }}</span></td>
                        <td class="text-end">
                            <a href="{{ route('vales.edit', $vale) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a>
                            <form action="{{ route('vales.destroy', $vale) }}" method="POST" class="d-inline" onsubmit="return confirm('¿Eliminar este vale?')">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="text-center text-muted py-4">No hay vales registrados.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="mt-3">{{ $vales->links() }}</div>
@endsection
