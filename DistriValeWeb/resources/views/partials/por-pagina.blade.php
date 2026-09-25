{{--
    Selector de filas por página. Son links (no un <select>) para que
    dv-nav.js cambie la vista sin recargar. Conserva los filtros de la URL
    actual y vuelve a la página 1.
    Uso: @include('partials.por-pagina', ['porPagina' => $porPagina])
--}}
<div class="d-flex align-items-center gap-2 small dv-por-pagina" data-por-pagina="{{ $porPagina }}">
    <span class="text-muted">Mostrar</span>
    <div class="btn-group btn-group-sm" role="group" aria-label="Filas por página">
        @foreach (\App\Support\PorPagina::OPCIONES as $opcion)
            <a href="{{ request()->fullUrlWithQuery(['por_pagina' => $opcion, 'page' => null]) }}"
               data-valor="{{ $opcion }}"
               class="btn {{ $porPagina === $opcion ? 'btn-primary' : 'btn-outline-primary' }}">{{ $opcion ?: 'Todos' }}</a>
        @endforeach
    </div>
</div>
