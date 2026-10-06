<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    @include('pdf._theme')
</head>
<body>
    <div class="pdf-title-bar">
        <h1>Relación de cobranza</h1>
        <div class="pdf-subtitle">{{ $periodo }} &middot; Generado el {{ now()->format('d/m/Y H:i') }}</div>
    </div>

    @forelse ($financieras as $financiera)
        @php
            $valesFinanciera = $porFinanciera->get($financiera->id_financiera, collect());
            $porCliente = $valesFinanciera->groupBy('id_cliente');
            $totalImporteFinanciera = $valesFinanciera->sum(fn ($v) => $v->montoProximoPago());
            $ganancia = $financiera->ganancia_quincenal_porcentaje !== null
                ? round($totalImporteFinanciera * (float) $financiera->ganancia_quincenal_porcentaje / 100, 2)
                : null;
        @endphp

        @if (! $loop->first)
            <div class="pdf-page-break"></div>
        @endif

        <div class="pdf-card">
            <table class="pdf-meta">
                <tr>
                    <td>
                        <span class="pdf-label">Financiera</span>
                        <span class="pdf-value">{{ $financiera->nombre }}</span>
                    </td>
                    <td>
                        <span class="pdf-label">Clientes con crédito vigente</span>
                        <span class="pdf-value">{{ $porCliente->count() }}</span>
                    </td>
                    <td>
                        <span class="pdf-label">Total a cobrar esta quincena</span>
                        <span class="pdf-value">${{ number_format($totalImporteFinanciera, 2) }}</span>
                    </td>
                </tr>
            </table>
        </div>

        @forelse ($porCliente as $valesCliente)
            @php $cliente = $valesCliente->first()->cliente; @endphp
            <div class="pdf-client-card {{ $loop->index % 2 === 1 ? 'pdf-client-card-r' : '' }}">
                <div class="pdf-client-header">{{ $cliente->nombre_completo }}</div>
                <table>
                    <thead>
                        <tr>
                            <th>Folio</th>
                            <th>Fecha</th>
                            <th>Núm. pago</th>
                            <th class="pdf-num">Saldo ant.</th>
                            <th class="pdf-num">Importe</th>
                            <th class="pdf-num">Nuevo saldo</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($valesCliente as $vale)
                            @php
                                $saldoAnterior = (float) $vale->saldo_pendiente;
                                $importe = $vale->montoProximoPago();
                                $nuevoSaldo = max(0, round($saldoAnterior - $importe, 2));
                            @endphp
                            <tr>
                                <td>{{ $vale->folio_vale }}</td>
                                <td style="white-space: nowrap;">{{ $vale->fecha_disposicion?->format('d/m/y') ?? '—' }}</td>
                                <td style="white-space: nowrap;">{{ $vale->numeroPagoTexto() }}</td>
                                <td class="pdf-num">${{ number_format($saldoAnterior, 2) }}</td>
                                <td class="pdf-num">${{ number_format($importe, 2) }}</td>
                                <td class="pdf-num">${{ number_format($nuevoSaldo, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="3">TOTALES</td>
                            <td class="pdf-num">${{ number_format($valesCliente->sum(fn ($v) => (float) $v->saldo_pendiente), 2) }}</td>
                            <td class="pdf-num">${{ number_format($valesCliente->sum(fn ($v) => $v->montoProximoPago()), 2) }}</td>
                            <td class="pdf-num">${{ number_format($valesCliente->sum(fn ($v) => max(0, round((float) $v->saldo_pendiente - $v->montoProximoPago(), 2))), 2) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @empty
            <div class="pdf-card"><span class="pdf-muted">Sin créditos vigentes con esta financiera.</span></div>
        @endforelse
        <div class="pdf-clear"></div>

        <div class="pdf-footer-note">
            Ganancia quincenal ({{ $financiera->nombre }}):
            @if ($ganancia !== null)
                {{ number_format((float) $financiera->ganancia_quincenal_porcentaje, 2) }}% de ${{ number_format($totalImporteFinanciera, 2) }} = ${{ number_format($ganancia, 2) }}
            @else
                porcentaje pendiente de definir
            @endif
            &middot; Recargo por pago fuera de tiempo: {{ number_format((float) $financiera->recargo_porcentaje, 2) }}%
        </div>
    @empty
        <div class="pdf-card"><span class="pdf-muted">No hay financieras registradas.</span></div>
    @endforelse
</body>
</html>
