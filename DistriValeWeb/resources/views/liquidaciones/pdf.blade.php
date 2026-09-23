<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: sans-serif; font-size: 10px; color: #222; }
        h1 { font-size: 16px; margin-bottom: 2px; }
        .muted { color: #666; font-size: 10px; margin-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #ddd; padding: 4px 6px; text-align: right; }
        th { background: #f2f4f8; text-transform: uppercase; font-size: 8px; }
        td:first-child, th:first-child { text-align: left; }
        tfoot td { font-weight: bold; background: #f8f9fb; }
    </style>
</head>
<body>
    <h1>Liquidación quincenal</h1>
    <div class="muted">Periodo: {{ $periodo }} — generado el {{ now()->format('d/m/Y H:i') }}</div>

    <table>
        <thead>
            <tr>
                <th>Cliente</th>
                @foreach ($financieras as $f)
                    <th colspan="3">{{ $f->nombre }}</th>
                @endforeach
                <th>Total</th>
                <th>Estado</th>
            </tr>
            <tr>
                <th></th>
                @foreach ($financieras as $f)
                    <th>Cuota</th><th>Pago</th><th>Saldo</th>
                @endforeach
                <th></th><th></th>
            </tr>
        </thead>
        <tbody>
            @foreach ($matriz as $fila)
                <tr>
                    <td>{{ $fila['cliente']->nombre_completo }}</td>
                    @foreach ($financieras as $f)
                        @php $d = $fila['financieras'][$f->id_financiera]; @endphp
                        <td>{{ $d ? '$'.number_format($d['cuota'], 0) : '—' }}</td>
                        <td>{{ $d ? '$'.number_format($d['pago'], 0) : '—' }}</td>
                        <td>{{ $d ? '$'.number_format($d['saldo'], 0) : '—' }}</td>
                    @endforeach
                    <td>${{ number_format($fila['total_saldo'], 0) }}</td>
                    <td>{{ $fila['oportuno'] ? 'Oportuno' : 'Extemporáneo' }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td>TOTALES</td>
                @foreach ($financieras as $f)
                    <td>${{ number_format($totales['por_financiera'][$f->id_financiera]['cuota'], 0) }}</td>
                    <td>${{ number_format($totales['por_financiera'][$f->id_financiera]['pago'], 0) }}</td>
                    <td>${{ number_format($totales['por_financiera'][$f->id_financiera]['saldo'], 0) }}</td>
                @endforeach
                <td>${{ number_format($totales['saldo'], 0) }}</td>
                <td></td>
            </tr>
        </tfoot>
    </table>
</body>
</html>
