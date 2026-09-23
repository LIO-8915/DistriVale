<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: sans-serif; font-size: 12px; color: #222; }
        h1 { font-size: 18px; margin-bottom: 0; }
        .muted { color: #666; font-size: 11px; }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        th, td { border-bottom: 1px solid #ddd; padding: 6px 8px; text-align: left; }
        th { background: #f2f4f8; text-transform: uppercase; font-size: 10px; }
        .estado-EN_MORA { color: #c0392b; font-weight: bold; }
        .estado-ACTIVO { color: #1a9c6c; font-weight: bold; }
    </style>
</head>
<body>
    <h1>{{ $cliente->nombre_completo }}</h1>
    <div class="muted">ID: C-{{ str_pad($cliente->id_cliente, 3, '0', STR_PAD_LEFT) }} · Teléfono: {{ $cliente->telefono ?: '—' }}</div>
    <div class="muted">Créditos / vales activos o en mora — generado el {{ now()->format('d/m/Y H:i') }}</div>

    <table>
        <thead>
            <tr>
                <th>Financiera</th>
                <th>Folio</th>
                <th>Monto original</th>
                <th>Cuota quincenal</th>
                <th>Saldo pendiente</th>
                <th># Pago</th>
                <th>Estado</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($cliente->vales as $vale)
                <tr>
                    <td>{{ $vale->financiera->nombre }}</td>
                    <td>{{ $vale->folio_vale }}</td>
                    <td>${{ number_format($vale->monto_original, 2) }}</td>
                    <td>${{ number_format($vale->cuota_quincenal, 2) }}</td>
                    <td>${{ number_format($vale->saldo_pendiente, 2) }}</td>
                    <td>{{ $vale->numeroPagoTexto() }}</td>
                    <td class="estado-{{ $vale->estado }}">{{ $vale->estado }}</td>
                </tr>
            @empty
                <tr><td colspan="7">Sin créditos activos o en mora.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
