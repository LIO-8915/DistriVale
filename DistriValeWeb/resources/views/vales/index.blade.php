@extends('layouts.app')

@section('title', 'Créditos')
@section('subtitle', 'Cada fila es un crédito — entra a uno para ver su historial de pagos quincenales (vales).')
@section('actions')
    <a href="{{ route('vales.create') }}" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> Nuevo crédito</a>
@endsection

@section('content')
<div class="card p-3 mb-3">
    <button type="button" class="btn btn-outline-secondary btn-sm d-md-none mb-2 w-100" data-bs-toggle="collapse" data-bs-target="#valesFiltros">
        <i class="bi bi-sliders"></i> Filtros
    </button>
    <div class="collapse d-md-block" id="valesFiltros">
        <div class="row g-2">
            <div class="col-md-3">
                <select id="valesFinanciera" class="form-select form-select-sm">
                    <option value="">Todas las financieras</option>
                    @foreach ($financieras as $f)
                        <option value="{{ $f->id_financiera }}" @selected(request('id_financiera') == $f->id_financiera)>{{ $f->nombre }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <select id="valesEstado" class="form-select form-select-sm">
                    <option value="">Todos los estados</option>
                    <option value="ACTIVO" @selected(request('estado') == 'ACTIVO')>Activo</option>
                    <option value="EN_MORA" @selected(request('estado') == 'EN_MORA')>Mora</option>
                    <option value="LIQUIDADO" @selected(request('estado') == 'LIQUIDADO')>Liquidado</option>
                </select>
            </div>
        </div>
    </div>
</div>

<div class="card" id="valesTableWrap">
    @include('vales._table')
</div>

@push('scripts')
<script>
(function () {
    var selFinanciera = document.getElementById('valesFinanciera');
    var selEstado = document.getElementById('valesEstado');
    var wrap = document.getElementById('valesTableWrap');
    var baseUrl = '{{ route('vales.index') }}';
    // Filas por página elegidas en esta visita; filtrar las conserva.
    var porPagina = '{{ $porPagina }}';

    function reload(page) {
        var params = new URLSearchParams();
        if (selFinanciera.value) params.set('id_financiera', selFinanciera.value);
        if (selEstado.value) params.set('estado', selEstado.value);
        if (porPagina !== '{{ \App\Support\PorPagina::DEFECTO }}') params.set('por_pagina', porPagina);
        if (page) params.set('page', page);

        fetch(baseUrl + '?' + params.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.text(); })
            .then(function (html) {
                wrap.innerHTML = html;
                history.replaceState(null, '', baseUrl + '?' + params.toString());
            });
    }

    selFinanciera.addEventListener('change', function () { reload(); });
    selEstado.addEventListener('change', function () { reload(); });

    wrap.addEventListener('click', function (e) {
        var opcion = e.target.closest('.dv-por-pagina a');
        if (opcion) {
            e.preventDefault();
            porPagina = opcion.dataset.valor;
            reload();
            return;
        }
        var link = e.target.closest('.pagination a');
        if (link) {
            e.preventDefault();
            var url = new URL(link.href);
            reload(url.searchParams.get('page'));
        }
    });
})();
</script>
@endpush
@endsection
