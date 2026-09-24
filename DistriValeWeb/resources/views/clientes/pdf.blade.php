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
        <table class="pdf-table">
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
                        <td><span class="pdf-badge pdf-badge-{{ $vale->estado }}">{{ $vale->estado }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="pdf-muted">Sin créditos activos o en mora.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="pdf-footer-note">
        DistriVale &middot; Reporte de créditos / vales
    </div>
</body>
</html>
