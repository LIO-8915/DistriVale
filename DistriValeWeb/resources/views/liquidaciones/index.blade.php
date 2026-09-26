@extends('layouts.app')

@section('title', 'Liquidación')
@section('subtitle', 'Reporte de cobranza y liquidación de la quincena seleccionada.')
@section('actions')
    <a href="{{ route('liquidaciones.pdf', ['periodo' => $periodo]) }}" id="liquidacionPdfLink" class="btn btn-sm btn-outline-primary"><i class="bi bi-file-earmark-pdf"></i> Descargar PDF</a>
    <a href="{{ route('liquidaciones.create') }}" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg"></i> Registrar liquidación</a>
@endsection

@section('content')
<div class="card p-3 mb-3">
    <div class="row g-2 align-items-end">
        <div class="col-md-5">
            <label class="form-label small text-muted mb-1">Quincena</label>
            <select id="liquidacionPeriodo" class="form-select">
                @foreach ($periodos as $p)
                    <option value="{{ $p }}" @selected($periodo == $p)>{{ $p }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-7">
            <div class="text-muted small">Periodo seleccionado</div>
            <div class="fw-semibold" id="liquidacionPeriodoLabel">{{ $periodo }}</div>
        </div>
    </div>
</div>

<div id="liquidacionContenido">
    @include('liquidaciones._contenido')
</div>

@push('scripts')
<script>
(function () {
    var wrap = document.getElementById('liquidacionContenido');
    var selPeriodo = document.getElementById('liquidacionPeriodo');
    var periodoLabel = document.getElementById('liquidacionPeriodoLabel');
    var baseUrl = '{{ route('liquidaciones.index') }}';
    // Filas por página elegidas en esta visita; cambiar de quincena o de
    // página las conserva.
    var porPagina = '{{ $porPagina }}';
    var chart = null;

    // Un solo gráfico reutilizable: cada recarga destruye la instancia
    // anterior antes de crear una nueva contra el <canvas> fresco que trae
    // el HTML recién insertado (Chart.js no permite reusar un canvas que
    // ya tiene una gráfica activa).
    function renderChart() {
        var canvas = document.getElementById('chartSaldoFinanciera');
        if (!canvas) return;
        var payload = JSON.parse(canvas.dataset.chart || '{"labels":[],"data":[]}');
        if (chart) chart.destroy();
        chart = new Chart(canvas, {
            type: 'doughnut',
            data: {
                labels: payload.labels,
                datasets: [{
                    data: payload.data,
                    backgroundColor: ['#4f7cff', '#8b6bff', '#ff9f43', '#2bc48a', '#ff5c72'],
                    borderWidth: 0,
                }]
            },
            options: { cutout: '68%', plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } } } }
        });
    }

    function reload(page) {
        var params = new URLSearchParams();
        params.set('periodo', selPeriodo.value);
        if (porPagina !== '{{ \App\Support\PorPagina::DEFECTO }}') params.set('por_pagina', porPagina);
        if (page) params.set('page', page);

        fetch(baseUrl + '?' + params.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.text(); })
            .then(function (html) {
                wrap.innerHTML = html;
                renderChart();
                history.replaceState(null, '', baseUrl + '?' + params.toString());
            });
    }

    var pdfLink = document.getElementById('liquidacionPdfLink');
    var pdfBaseUrl = pdfLink.href.split('?')[0];

    selPeriodo.addEventListener('change', function () {
        periodoLabel.textContent = selPeriodo.options[selPeriodo.selectedIndex].text;
        pdfLink.href = pdfBaseUrl + '?periodo=' + encodeURIComponent(selPeriodo.value);
        reload();
    });

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

    renderChart();
})();
</script>
@endpush
@endsection
