{{--
    Barra superior de las tablas paginadas: a la izquierda "Mostrando X a Y de
    Z resultados" y los números de página; a la derecha el selector de filas.
    Uso: @include('partials.tabla-controles', ['paginador' => $vales, 'porPagina' => $porPagina])
--}}
<div class="px-3 pt-3 pb-2 d-flex align-items-center gap-2 flex-wrap dv-tabla-controles">
    <div class="flex-grow-1 dv-pagination">
        @if ($paginador->hasPages())
            {{ $paginador->onEachSide(1)->links() }}
        @elseif ($paginador->total() > 0)
            {{-- Sin páginas que navegar, Laravel no pinta nada: igual se muestra el conteo. --}}
            <div class="small text-muted">Mostrando {{ $paginador->total() }} {{ $paginador->total() === 1 ? 'resultado' : 'resultados' }}</div>
        @endif
    </div>
    @include('partials.por-pagina', ['porPagina' => $porPagina])
</div>
