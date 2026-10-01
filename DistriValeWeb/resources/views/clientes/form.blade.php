@extends('layouts.app')

@section('title', $cliente->exists ? 'Editar cliente' : 'Nuevo cliente')

@section('content')
<div class="row justify-content-center">
<div class="col-xxl-6 col-xl-7 col-lg-8 col-md-9">
<div class="card p-4">
    <form action="{{ $cliente->exists ? route('clientes.update', $cliente) : route('clientes.store') }}" method="POST">
        @csrf
        @if ($cliente->exists) @method('PUT') @endif

        <div class="mb-3">
            <label class="form-label">Nombre completo</label>
            <input type="text" name="nombre_completo" class="form-control" value="{{ old('nombre_completo', $cliente->nombre_completo) }}" required maxlength="150">
        </div>

        <div class="mb-3">
            <label class="form-label">Teléfono</label>
            <input type="text" name="telefono" class="form-control" value="{{ old('telefono', $cliente->telefono) }}" maxlength="20">
        </div>

        <div class="mb-3">
            <label class="form-label">Dirección</label>
            <textarea name="direccion" class="form-control" rows="2">{{ old('direccion', $cliente->direccion) }}</textarea>
        </div>

        <div class="form-check mb-3">
            <input type="checkbox" name="activo" value="1" class="form-check-input" id="activo" {{ old('activo', $cliente->activo ?? true) ? 'checked' : '' }}>
            <label class="form-check-label" for="activo">Cliente activo</label>
        </div>

        <div class="d-flex gap-2">
            <button class="btn btn-primary">Guardar</button>
            <a href="{{ route('clientes.index') }}" class="btn btn-outline-secondary dv-btn-cancelar">Cancelar</a>
        </div>
    </form>
</div>
</div>
</div>
@endsection
