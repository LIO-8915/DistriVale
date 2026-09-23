@extends('layouts.app')

@section('title', $vale->exists ? 'Editar vale' : 'Nuevo vale')

@section('content')
<div class="card p-4" style="max-width: 640px;">
    <form action="{{ $vale->exists ? route('vales.update', $vale) : route('vales.store') }}" method="POST">
        @csrf
        @if ($vale->exists) @method('PUT') @endif

        <div class="row">
            <div class="col-6 mb-3">
                <label class="form-label">Cliente</label>
                <select name="id_cliente" class="form-select" required>
                    <option value="">Seleccione...</option>
                    @foreach ($clientes as $c)
                        <option value="{{ $c->id_cliente }}" @selected(old('id_cliente', $vale->id_cliente) == $c->id_cliente)>{{ $c->nombre_completo }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 mb-3">
                <label class="form-label">Financiera</label>
                <select name="id_financiera" class="form-select" required>
                    <option value="">Seleccione...</option>
                    @foreach ($financieras as $f)
                        <option value="{{ $f->id_financiera }}" @selected(old('id_financiera', $vale->id_financiera) == $f->id_financiera)>{{ $f->nombre }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="mb-3">
            <label class="form-label">Folio del vale</label>
            <input type="text" name="folio_vale" class="form-control" value="{{ old('folio_vale', $vale->folio_vale) }}" required maxlength="50">
        </div>

        <div class="row">
            <div class="col-6 mb-3">
                <label class="form-label">Monto original</label>
                <input type="number" step="0.01" name="monto_original" class="form-control" value="{{ old('monto_original', $vale->monto_original) }}" required>
            </div>
            <div class="col-6 mb-3">
                <label class="form-label">Cuota quincenal</label>
                <input type="number" step="0.01" name="cuota_quincenal" class="form-control" value="{{ old('cuota_quincenal', $vale->cuota_quincenal) }}" required>
            </div>
        </div>

        <div class="row">
            <div class="col-4 mb-3">
                <label class="form-label">Total quincenas</label>
                <input type="number" name="total_quincenas" class="form-control" value="{{ old('total_quincenas', $vale->total_quincenas ?? 12) }}" required min="1">
            </div>
            <div class="col-4 mb-3">
                <label class="form-label">Quincena actual</label>
                <input type="number" name="quincena_actual" class="form-control" value="{{ old('quincena_actual', $vale->quincena_actual ?? 1) }}" required min="1">
            </div>
            <div class="col-4 mb-3">
                <label class="form-label">Estado</label>
                <select name="estado" class="form-select" required>
                    @foreach (['ACTIVO', 'EN_MORA', 'LIQUIDADO'] as $estado)
                        <option value="{{ $estado }}" @selected(old('estado', $vale->estado ?? 'ACTIVO') == $estado)>{{ $estado }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        @if ($vale->exists)
            <div class="mb-3">
                <label class="form-label">Saldo pendiente</label>
                <input type="number" step="0.01" name="saldo_pendiente" class="form-control" value="{{ old('saldo_pendiente', $vale->saldo_pendiente) }}" required>
            </div>
        @else
            <p class="text-muted small">El saldo pendiente inicial se establece automáticamente igual al monto original.</p>
        @endif

        <div class="d-flex gap-2">
            <button class="btn btn-primary">Guardar</button>
            <a href="{{ route('vales.index') }}" class="btn btn-outline-secondary">Cancelar</a>
        </div>
    </form>
</div>
@endsection
