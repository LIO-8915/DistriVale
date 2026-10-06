@extends('layouts.app')

@section('title', 'Generar recibo consolidado')

@section('content')
<div class="row justify-content-center">
<div class="col-xxl-5 col-xl-6 col-lg-7 col-md-9">
<div class="card p-4">
    <p class="text-muted small">Se generará un recibo agrupando todos los vales activos o en mora del cliente seleccionado, calculando el pago oportuno y el pago con recargo por financiera.</p>

    <form action="{{ route('recibos.store') }}" method="POST">
        @csrf

        <div class="mb-3">
            <label class="form-label">Cliente</label>
            <select name="id_cliente" class="form-select" required>
                <option value="">Seleccione un cliente...</option>
                @foreach ($clientes as $c)
                    <option value="{{ $c->id_cliente }}" @selected(old('id_cliente') == $c->id_cliente)>{{ $c->nombre_completo }}</option>
                @endforeach
            </select>
        </div>

        <div class="mb-3">
            <label class="form-label">Nombre de la distribuidora</label>
            <input type="text" name="nombre_distribuidora" class="form-control" value="{{ old('nombre_distribuidora', 'ELIA MARIA VELIZ MURILLO') }}" required maxlength="150">
        </div>

        <div class="mb-3">
            <label class="form-label">Fecha de corte (quincena)</label>
            <input type="date" name="fecha_corte" class="form-control" value="{{ old('fecha_corte', now()->format('Y-m-d')) }}" required>
        </div>

        <div class="d-flex gap-2">
            <button class="btn btn-primary">Generar recibo</button>
            <a href="{{ route('recibos.index') }}" class="btn btn-outline-secondary dv-btn-cancelar">Cancelar</a>
        </div>
    </form>
</div>
</div>
</div>
@endsection
