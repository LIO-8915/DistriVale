@extends('layouts.app')

@section('title', 'Clientes')
@section('subtitle', 'Administra la información de tus clientes y sus créditos.')
@section('actions')
    <a href="{{ route('clientes.create') }}" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> Nuevo cliente</a>
@endsection

@section('content')
<div class="card p-3 mb-3">
    <button type="button" class="btn btn-outline-secondary btn-sm d-md-none mb-2 w-100" data-bs-toggle="collapse" data-bs-target="#clientesFiltros">
        <i class="bi bi-sliders"></i> Filtros
    </button>
    <div class="collapse d-md-block" id="clientesFiltros">
        <div class="row g-2">
            <div class="col-md-6">
                <input type="text" id="clientesSearch" value="{{ request('q') }}" class="form-control" placeholder="Buscar por nombre, teléfono o financiera...">
            </div>
            <div class="col-md-3">
                <select id="clientesFinanciera" class="form-select">
                    <option value="">Todas las financieras</option>
                    @foreach ($financieras as $f)
                        <option value="{{ $f->id_financiera }}" @selected(request('id_financiera') == $f->id_financiera)>{{ $f->nombre }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <select id="clientesEstado" class="form-select">
                    <option value="">Todos los estados</option>
                    <option value="activo" @selected(request('estado') === 'activo')>Activo</option>
                    <option value="inactivo" @selected(request('estado') === 'inactivo')>Inactivo</option>
                </select>
            </div>
        </div>
    </div>
</div>

{{-- Total + 5 financieras = 6 tarjetas: 6 / 3 / 2 / 1 por fila, siempre
     en filas parejas (nunca 4 + 2) al cambiar el ancho de la ventana. --}}
<div class="row g-3 mb-3">
    <div class="col-12 col-sm-6 col-lg-4 col-xxl-2">
        <div class="card stat-card p-3 h-100">
            <div class="d-flex align-items-center gap-2">
                <span class="stat-icon icon-blue" style="width:36px;height:36px;font-size:.9rem;"><i class="bi bi-people-fill"></i></span>
                <div>
                    <div class="text-muted" style="font-size:.75rem;">Total clientes</div>
                    <div class="fw-bold fs-5">{{ $totalClientes }}</div>
                </div>
            </div>
        </div>
    </div>
    @foreach ($porFinanciera as $f)
        <div class="col-12 col-sm-6 col-lg-4 col-xxl-2">
            <div class="card stat-card p-3 h-100">
                <div class="d-flex align-items-center gap-2">
                    <span class="stat-icon icon-purple" style="width:36px;height:36px;font-size:.9rem;"><i class="bi bi-bank"></i></span>
                    <div>
                        <div class="text-muted text-truncate" style="font-size:.75rem; max-width:110px;">{{ $f['nombre'] }}</div>
                        <div class="fw-bold fs-5">{{ $f['clientes'] }}</div>
                    </div>
                </div>
            </div>
        </div>
    @endforeach
</div>

<div class="card" id="clientesTableWrap">
    @include('clientes._table')
</div>

@push('scripts')
<script>
(function () {
    var input = document.getElementById('clientesSearch');
    var selFinanciera = document.getElementById('clientesFinanciera');
    var selEstado = document.getElementById('clientesEstado');
    var wrap = document.getElementById('clientesTableWrap');
    var baseUrl = '{{ route('clientes.index') }}';
    var timer = null;
    // Filas por página elegidas en esta visita; buscar o filtrar las conserva.
    var porPagina = '{{ $porPagina }}';

    // Skeleton rows (Bootstrap Placeholder), only shown if the fetch takes
    // a moment — mirrors the real table's columns so the swap-in doesn't
    // reflow the page, and never flashes on a fast local response.
    // Mismas clases de visibilidad que _table.blade.php, para que el
    // esqueleto tenga las mismas columnas que la tabla real a cada ancho.
    function skeletonRow() {
        return '<tr>' +
            '<td><span class="placeholder-glow"><span class="placeholder col-8"></span></span></td>' +
            '<td class="d-none d-xl-table-cell"><span class="placeholder-glow"><span class="placeholder col-6"></span></span></td>' +
            '<td><span class="placeholder-glow"><span class="placeholder col-7"></span></span></td>' +
            '<td class="d-none d-lg-table-cell"><span class="placeholder-glow"><span class="placeholder col-4"></span></span></td>' +
            '<td><span class="placeholder-glow"><span class="placeholder col-5"></span></span></td>' +
            '<td class="d-none d-md-table-cell"><span class="placeholder-glow"><span class="placeholder col-5"></span></span></td>' +
            '<td class="text-end"><span class="placeholder-glow"><span class="placeholder col-4"></span></span></td>' +
            '</tr>';
    }
    function skeletonHtml() {
        return '<div class="table-responsive"><table class="table align-middle mb-0">' +
            '<thead><tr><th>Nombre</th><th class="d-none d-xl-table-cell">Teléfono</th><th>Financiera</th><th class="d-none d-lg-table-cell"># Vales</th><th>Saldo global</th><th class="d-none d-md-table-cell">Estado</th><th class="text-end">Acciones</th></tr></thead>' +
            '<tbody>' + skeletonRow().repeat(5) + '</tbody></table></div>';
    }

    function reload(page) {
        var params = new URLSearchParams();
        if (input.value.trim() !== '') params.set('q', input.value.trim());
        if (selFinanciera.value) params.set('id_financiera', selFinanciera.value);
        if (selEstado.value) params.set('estado', selEstado.value);
        if (porPagina !== '{{ \App\Support\PorPagina::DEFECTO }}') params.set('por_pagina', porPagina);
        if (page) params.set('page', page);

        var skeletonTimer = setTimeout(function () { wrap.innerHTML = skeletonHtml(); }, 200);

        fetch(baseUrl + '?' + params.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.text(); })
            .then(function (html) {
                clearTimeout(skeletonTimer);
                wrap.innerHTML = html;
                history.replaceState(null, '', baseUrl + '?' + params.toString());
            });
    }

    input.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(function () { reload(); }, 300);
    });
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
