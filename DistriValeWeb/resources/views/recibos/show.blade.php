@extends('layouts.app')

@section('title', 'Recibo consolidado')
@section('actions')
    <button class="btn btn-sm btn-outline-secondary" onclick="copiarRecibo()"><i class="bi bi-clipboard"></i> Copiar texto</button>
    <button type="submit" form="form-confirmar-pago" class="btn btn-sm btn-success"><i class="bi bi-check2-circle"></i> Confirmar pago</button>
    <a href="{{ route('recibos.index') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
@endsection

@section('content')
<div class="card p-4 mx-auto" style="max-width: 560px;" id="recibo-card">
    <div class="text-center mb-3">
        <div class="fw-bold fs-5">{{ $recibo->nombre_distribuidora }}</div>
        <div class="text-muted">Recibo consolidado — {{ $recibo->fecha_corte->format('d/m/Y') }}</div>
    </div>

    <div class="mb-2"><strong>Cliente:</strong> {{ $recibo->cliente->nombre_completo }}</div>
    @if ($recibo->cliente->telefono)
        <div class="mb-3"><strong>Teléfono:</strong> {{ $recibo->cliente->telefono }}</div>
    @endif

    {{-- Fecha de pago y monto por vale son editables: lo que calcula el
         sistema al generar el recibo es solo una sugerencia, por si el
         cliente termina pagando otro día o un monto distinto. Al confirmar,
         estos son los valores que se aplican de verdad a cada vale. --}}
    <form id="form-confirmar-pago" action="{{ route('recibos.confirmar-pago', $recibo) }}" method="POST" data-confirm="¿Confirmar que este recibo fue pagado? Esto actualizará el saldo y la quincena de cada vale con la fecha y los montos capturados aquí.">
        @csrf

        <div class="mb-3">
            <label class="form-label">Fecha de pago</label>
            <input type="date" name="fecha_pago" class="form-control" value="{{ old('fecha_pago', optional($recibo->fecha_pago ?? $recibo->fecha_corte)->format('Y-m-d')) }}" required>
        </div>

        <table class="table table-sm">
            <thead>
                <tr><th>Financiera</th><th>Folio</th><th># Pago</th><th class="text-end">Monto</th><th class="text-end">Nuevo saldo</th></tr>
            </thead>
            <tbody>
                @foreach ($recibo->detalles as $detalle)
                    <tr>
                        <td>{{ $detalle->vale->financiera->nombre }}</td>
                        <td>{{ $detalle->vale->folio_vale }}</td>
                        <td>{{ $detalle->numero_pago_texto }}</td>
                        <td class="text-end" style="max-width: 130px;">
                            <input type="number" step="0.01" min="0" name="montos[{{ $detalle->id_detalle }}]" class="form-control form-control-sm text-end" value="{{ old('montos.'.$detalle->id_detalle, $detalle->monto_pago) }}">
                        </td>
                        <td class="text-end">${{ number_format($detalle->nuevo_saldo, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </form>

    <div class="d-flex justify-content-between border-top pt-2">
        <span>Total pago oportuno</span>
        <strong>${{ number_format($recibo->total_oportuno, 2) }}</strong>
    </div>
    <div class="d-flex justify-content-between text-danger">
        <span>Pago después del {{ $recibo->fecha_corte->format('d/m/Y') }}</span>
        <strong>${{ number_format($recibo->total_extemporaneo, 2) }}</strong>
    </div>
</div>

<textarea id="recibo-texto" class="d-none">{{ $recibo->nombre_distribuidora }}
Recibo consolidado - {{ $recibo->fecha_corte->format('d/m/Y') }}
Cliente: {{ $recibo->cliente->nombre_completo }}
@foreach ($recibo->detalles as $detalle)
{{ $detalle->vale->financiera->nombre }} | Folio {{ $detalle->vale->folio_vale }} | Pago {{ $detalle->numero_pago_texto }} | ${{ number_format($detalle->monto_pago, 2) }} | Saldo: ${{ number_format($detalle->nuevo_saldo, 2) }}
@endforeach
Total pago oportuno: ${{ number_format($recibo->total_oportuno, 2) }}
Pago después del {{ $recibo->fecha_corte->format('d/m/Y') }}: ${{ number_format($recibo->total_extemporaneo, 2) }}</textarea>

<script>
function copiarRecibo() {
    const texto = document.getElementById('recibo-texto').value;
    navigator.clipboard.writeText(texto).then(() => alert('Recibo copiado al portapapeles.'));
}
</script>
@endsection
