<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    @include('pdf._theme')
</head>
<body>
    <div class="pdf-title-bar">
        <h1>Liquidación quincenal</h1>
        <div class="pdf-subtitle">Generado el {{ now()->format('d/m/Y H:i') }}</div>
    </div>

    <div class="pdf-card">
        <table class="pdf-meta">
            <tr>
                <td>
                    <span class="pdf-label">Periodo de quincena</span>
                    <span class="pdf-value">{{ $periodo }}</span>
                </td>
            </tr>
        </table>
    </div>

    <div class="pdf-table-card">
        <table class="pdf-table">
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
                        <td><strong>${{ number_format($fila['total_saldo'], 0) }}</strong></td>
                        <td>
                            <span class="pdf-badge {{ $fila['oportuno'] ? 'pdf-badge-ACTIVO' : 'pdf-badge-EN_MORA' }}">
                                {{ $fila['oportuno'] ? 'Oportuno' : 'Extemporáneo' }}
                            </span>
                        </td>
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
    </div>

    <div class="pdf-footer-note">
        DistriVale &middot; Reporte de liquidación quincenal
    </div>
</body>
</html>
