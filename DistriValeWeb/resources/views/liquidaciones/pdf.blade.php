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

    {{-- Seccionado por financiera (no por cliente): un bloque por financiera
         con todos sus clientes y su subtotal, aunque un cliente se repita en
         varios bloques. La matriz completa por columnas se salía de los
         márgenes de la hoja con varias financieras; así cada bloque solo
         tiene 4 columnas y cabe. --}}
    @foreach ($financieras as $f)
        @php
            $filasFinanciera = collect($matriz)->filter(fn ($fila) => $fila['financieras'][$f->id_financiera] !== null);
            $subtotal = $totales['por_financiera'][$f->id_financiera];
        @endphp
        @if ($filasFinanciera->isNotEmpty())
            <div class="pdf-table-card">
                <table class="pdf-table">
                    <thead>
                        <tr>
                            <th colspan="5">{{ $f->nombre }}</th>
                        </tr>
                        <tr>
                            <th>Cliente</th>
                            <th class="pdf-num">Cuota</th>
                            <th class="pdf-num">Pago</th>
                            <th class="pdf-num">Saldo</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($filasFinanciera as $fila)
                            @php $d = $fila['financieras'][$f->id_financiera]; @endphp
                            <tr>
                                <td>{{ $fila['cliente']->nombre_completo }}</td>
                                <td class="pdf-num">${{ number_format($d['cuota'], 0) }}</td>
                                <td class="pdf-num">${{ number_format($d['pago'], 0) }}</td>
                                <td class="pdf-num">${{ number_format($d['saldo'], 0) }}</td>
                                <td>
                                    <span class="pdf-badge {{ $fila['oportuno'] ? 'pdf-badge-ACTIVO' : 'pdf-badge-EN_MORA' }}">
                                        {{ $fila['oportuno'] ? 'Oportuno' : 'Extemporáneo' }}
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="pdf-total-row">
                            <td>SUBTOTAL &middot; {{ $filasFinanciera->count() }} clientes</td>
                            <td class="pdf-num">${{ number_format($subtotal['cuota'], 0) }}</td>
                            <td class="pdf-num">${{ number_format($subtotal['pago'], 0) }}</td>
                            <td class="pdf-num">${{ number_format($subtotal['saldo'], 0) }}</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endif
    @endforeach

    {{-- El total general y el resumen de cortes van en su propia página,
         separados de los bloques de clientes por financiera. --}}
    <div class="pdf-page-break">
    <div class="pdf-table-card">
        <table class="pdf-table">
            <tbody>
                <tr class="pdf-total-row">
                    <td>TOTAL GENERAL &middot; {{ count($matriz) }} clientes</td>
                    <td class="pdf-num">${{ number_format($totales['cuota'], 0) }}</td>
                    <td class="pdf-num">${{ number_format($totales['pago'], 0) }}</td>
                    <td class="pdf-num">${{ number_format($totales['saldo'], 0) }}</td>
                    <td></td>
                </tr>
            </tbody>
        </table>
    </div>

    @if ($liquidaciones->isNotEmpty())
        <div class="pdf-table-card">
            <table class="pdf-table">
                <thead>
                    <tr>
                        <th>Financiera</th>
                        <th>Corte</th>
                        <th>Límite de pago</th>
                        <th>Depositar a más tardar</th>
                        <th class="pdf-num">Cobrar</th>
                        <th class="pdf-num">Depositar</th>
                        <th class="pdf-num">Ganancias</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($liquidaciones as $l)
                        <tr>
                            <td>{{ $l->financiera->nombre }}</td>
                            <td>{{ $l->fecha_corte?->format('d/m/Y') ?? '—' }}</td>
                            <td>{{ $l->fecha_limite_pago?->format('d/m/Y') ?? '—' }}</td>
                            <td>{{ $l->fecha_deposito?->format('d/m/Y') ?? '—' }}</td>
                            <td class="pdf-num">${{ number_format($l->monto_cobrar, 2) }}</td>
                            <td class="pdf-num">${{ number_format($l->monto_depositar, 2) }}</td>
                            <td class="pdf-num">${{ number_format($l->monto_ganancias, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="pdf-total-row">
                        <td colspan="4">TOTAL &middot; {{ $liquidaciones->count() }} financieras</td>
                        <td class="pdf-num">${{ number_format($liquidaciones->sum('monto_cobrar'), 2) }}</td>
                        <td class="pdf-num">${{ number_format($liquidaciones->sum('monto_depositar'), 2) }}</td>
                        <td class="pdf-num">${{ number_format($liquidaciones->sum('monto_ganancias'), 2) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    @endif
    </div>

    <div class="pdf-footer-note">
        DistriVale &middot; Reporte de liquidación quincenal
    </div>
</body>
</html>
