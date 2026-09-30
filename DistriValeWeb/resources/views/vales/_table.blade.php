@include('partials.tabla-controles', ['paginador' => $vales, 'porPagina' => $porPagina])
<div class="table-responsive">
    {{-- Columnas que se ocultan al angostar la ventana, en este orden:
         Folio (<1400px), Cuota (<1200px), Fecha (<992px). --}}
    <table class="table align-middle mb-0">
        <thead>
            <tr>
                <th class="dv-col-cliente">Cliente</th><th>Financiera</th><th class="d-none d-xxl-table-cell">Folio</th><th class="d-none d-lg-table-cell">Fecha</th><th>Monto</th>
                <th class="d-none d-xl-table-cell">Cuota</th><th># Pago</th><th>Saldo</th><th>Estado</th><th class="text-end">Acciones</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($vales as $vale)
                <tr>
                    <td><a href="{{ route('clientes.show', $vale->cliente) }}" class="text-decoration-none dv-nombre-cliente" title="{{ $vale->cliente->nombre_completo }}">{{ $vale->cliente->nombre_completo }}</a></td>
                    <td>{{ $vale->financiera->nombre }}</td>
                    <td class="d-none d-xxl-table-cell">{{ $vale->folio_vale }}</td>
                    <td class="text-nowrap d-none d-lg-table-cell">{{ $vale->fecha_disposicion?->format('d/m/Y') ?? '—' }}</td>
                    <td>${{ number_format($vale->monto_original, 2) }}</td>
                    <td class="d-none d-xl-table-cell">${{ number_format($vale->cuota_quincenal, 2) }}</td>
                    <td>{{ $vale->numeroPagoTexto() }}</td>
                    <td>
                        ${{ number_format($vale->saldo_pendiente, 2) }}
                        @if ($vale->recargo_acumulado > 0)
                            <div class="text-danger small">+${{ number_format($vale->recargo_acumulado, 2) }} recargo</div>
                        @endif
                    </td>
                    <td><span class="badge badge-estado-{{ $vale->estado }}">{{ $vale->estadoLegible() }}</span></td>
                    <td class="text-end">
                        <a href="{{ route('vales.show', $vale) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i></a>
                        @if ($vale->estado === 'LIQUIDADO')
                            <form method="GET" action="{{ route('vales.edit', $vale) }}" class="d-inline" data-confirm="Este crédito ya está liquidado. ¿Seguro que quieres modificarlo?">
                                <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></button>
                            </form>
                        @else
                            <a href="{{ route('vales.edit', $vale) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a>
                        @endif
                        <form action="{{ route('vales.destroy', $vale) }}" method="POST" class="d-inline" data-confirm="¿Eliminar este crédito?">
                            @csrf @method('DELETE')
                            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="10" class="text-center text-muted py-4">No hay vales registrados.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
