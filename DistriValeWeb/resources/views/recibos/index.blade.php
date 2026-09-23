@extends('layouts.app')

@section('title', 'Recibos consolidados')
@section('actions')
    <a href="{{ route('recibos.create') }}" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> Generar recibo</a>
@endsection

@section('content')
<div class="card">
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead>
                <tr><th>Cliente</th><th>Fecha de corte</th><th>Total oportuno</th><th>Total extemporáneo</th><th class="text-end">Acciones</th></tr>
            </thead>
            <tbody>
                @forelse ($recibos as $recibo)
                    <tr>
                        <td>{{ $recibo->cliente->nombre_completo }}</td>
                        <td>{{ $recibo->fecha_corte->format('d/m/Y') }}</td>
                        <td>${{ number_format($recibo->total_oportuno, 2) }}</td>
                        <td>${{ number_format($recibo->total_extemporaneo, 2) }}</td>
                        <td class="text-end">
                            <a href="{{ route('recibos.show', $recibo) }}" class="btn btn-sm btn-outline-secondary">Ver recibo</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-muted py-4">No hay recibos generados.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="mt-3">{{ $recibos->links() }}</div>
@endsection
