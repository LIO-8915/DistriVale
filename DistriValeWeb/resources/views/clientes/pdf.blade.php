<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    @include('pdf._theme')
</head>
<body>
    <div class="pdf-title-bar">
        <h1>{{ $cliente->nombre_completo }}</h1>
        <div class="pdf-subtitle">Generado el {{ now()->format('d/m/Y H:i') }}</div>
    </div>

    <div class="pdf-card">
        <table class="pdf-meta">
            <tr>
                <td>
                    <span class="pdf-label">Cliente</span>
                    <span class="pdf-value">C-{{ str_pad($cliente->id_cliente, 3, '0', STR_PAD_LEFT) }}</span>
                </td>
                <td>
                    <span class="pdf-label">Teléfono</span>
                    <span class="pdf-value">{{ $cliente->telefono ?: '—' }}</span>
                </td>
            </tr>
        </table>
    </div>

    <div class="pdf-table-card">
        <table class="pdf-table pdf-compact">
            <thead>
                <tr>
                    <th>Folio</th>
                    <th class="pdf-num">Monto original</th>
                    <th class="pdf-num">Cuota quincenal</th>
                    <th class="pdf-num">Saldo pendiente</th>
                    <th># Pago</th>
                    <th>Estado</th>
                </tr>
            </thead>
            {{-- Una sección por financiera: encabezado, sus vales y su subtotal
                 justo debajo; el total general va al final en el tfoot. --}}
            @forelse ($cliente->vales->groupBy('financiera.nombre') as $nombre => $grupo)
                <tbody>
                    <tr class="pdf-group-row">
                        <td colspan="6">{{ $nombre }}</td>
                    </tr>
                    @foreach ($grupo as $vale)
                        <tr>
                            <td>{{ $vale->folio_vale }}</td>
                            <td class="pdf-num">${{ number_format($vale->monto_original, 2) }}</td>
                            <td class="pdf-num">${{ number_format($vale->cuota_quincenal, 2) }}</td>
                            <td class="pdf-num">${{ number_format($vale->saldo_pendiente, 2) }}</td>
                            <td style="white-space: nowrap;">{{ $vale->numeroPagoTexto() }}</td>
                            <td>
                                @if ($vale->esUltimoPago())
                                    <span class="pdf-badge pdf-badge-ULTIMO">Último pago</span>
                                @else
                                    <span class="pdf-badge pdf-badge-{{ $vale->estado }}">{{ $vale->estadoLegible() }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    <tr class="pdf-subtotal-row">
                        <td>Subtotal {{ $nombre }} &middot; {{ $grupo->count() }} {{ $grupo->count() === 1 ? 'vale' : 'vales' }}</td>
                        <td class="pdf-num">${{ number_format($grupo->sum('monto_original'), 2) }}</td>
                        <td class="pdf-num">${{ number_format($grupo->sum('cuota_quincenal'), 2) }}</td>
                        <td class="pdf-num">${{ number_format($grupo->sum('saldo_pendiente'), 2) }}</td>
                        <td colspan="2"></td>
                    </tr>
                </tbody>
            @empty
                <tbody>
                    <tr><td colspan="6" class="pdf-muted">Sin créditos activos o en mora.</td></tr>
                </tbody>
            @endforelse
            @if ($cliente->vales->isNotEmpty())
                <tfoot>
                    <tr class="pdf-total-row">
                        <td>TOTAL GENERAL &middot; {{ $cliente->vales->count() }} {{ $cliente->vales->count() === 1 ? 'vale' : 'vales' }}</td>
                        <td class="pdf-num">${{ number_format($cliente->vales->sum('monto_original'), 2) }}</td>
                        <td class="pdf-num">${{ number_format($cliente->vales->sum('cuota_quincenal'), 2) }}</td>
                        <td class="pdf-num">${{ number_format($cliente->vales->sum('saldo_pendiente'), 2) }}</td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>

    {{-- Detalle individual por vale: su propio historial de pagos tabulado
         (sin fechas, igual que la tabla de arriba). --}}
    @foreach ($cliente->vales as $vale)
        @if ($vale->detallesRecibo->isNotEmpty())
            <div class="pdf-table-card">
                <table class="pdf-table pdf-compact">
                    <thead>
                        <tr><th colspan="3">Vale {{ $vale->folio_vale }} &middot; {{ $vale->financiera->nombre }}</th></tr>
                        <tr>
                            <th># Pago</th>
                            <th class="pdf-num">Monto pago</th>
                            <th class="pdf-num">Nuevo saldo</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($vale->detallesRecibo as $detalle)
                            <tr>
                                <td>{{ $detalle->numero_pago_texto }}</td>
                                <td class="pdf-num">${{ number_format($detalle->monto_pago, 2) }}</td>
                                <td class="pdf-num">${{ number_format($detalle->nuevo_saldo, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @endforeach

    <div class="pdf-footer-note">
        DistriVale &middot; Reporte de créditos / vales
    </div>
</body>
</html>
