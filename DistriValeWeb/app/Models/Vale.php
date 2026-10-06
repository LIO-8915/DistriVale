<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vale extends Model
{
    use HasFactory;

    protected $table = 'vales';
    protected $primaryKey = 'id_vale';

    protected $fillable = [
        'id_cliente',
        'id_financiera',
        'folio_vale',
        'fecha_disposicion',
        'monto_original',
        'cuota_quincenal',
        'total_quincenas',
        'quincena_actual',
        'saldo_pendiente',
        'recargo_acumulado',
        'quincenas_vencidas',
        'estado',
        'fecha_ultimo_pago',
    ];

    protected $casts = [
        'monto_original' => 'decimal:2',
        'cuota_quincenal' => 'decimal:2',
        'saldo_pendiente' => 'decimal:2',
        'recargo_acumulado' => 'decimal:2',
        'fecha_disposicion' => 'date',
        'fecha_ultimo_pago' => 'datetime',
    ];

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'id_cliente', 'id_cliente');
    }

    public function financiera(): BelongsTo
    {
        return $this->belongsTo(Financiera::class, 'id_financiera', 'id_financiera');
    }

    public function detallesRecibo(): HasMany
    {
        return $this->hasMany(DetalleReciboVale::class, 'id_vale', 'id_vale');
    }

    public function numeroPagoTexto(): string
    {
        return $this->quincena_actual.' de '.$this->total_quincenas;
    }

    /**
     * El valor guardado en `estado` sigue siendo EN_MORA (cambiarlo
     * rompería filtros/consultas ya escritos contra ese valor) — esto es
     * solo la etiqueta que se le muestra a quien usa la app, más fácil de
     * leer que el nombre interno de la columna.
     */
    public static function estadoTexto(string $estado): string
    {
        return match ($estado) {
            'ACTIVO' => 'Activo',
            'EN_MORA' => 'Mora',
            'LIQUIDADO' => 'Liquidado',
            default => $estado,
        };
    }

    public function estadoLegible(): string
    {
        return static::estadoTexto($this->estado);
    }

    /**
     * Lo que en realidad toca cubrir en el siguiente pago: la cuota normal
     * más cualquier recargo acumulado por cuotas incompletas o no pagadas
     * de quincenas anteriores (ver registrarPago() y App\Support\Mora).
     */
    public function montoProximoPago(): float
    {
        return round((float) $this->cuota_quincenal + (float) $this->recargo_acumulado, 2);
    }

    /**
     * Vale que en este corte paga su última cuota: sigue ACTIVO (esa cuota
     * todavía se cobra) y pasa a LIQUIDADO al confirmarse el pago. Ya no
     * depende de quincena_actual vs total_quincenas — un crédito con
     * cuotas atrasadas puede tardar más quincenas de las originalmente
     * planeadas en liquidarse, así que lo único que de verdad determina si
     * es el último pago es si ese monto alcanza para saldar lo que queda.
     */
    public function esUltimoPago(): bool
    {
        return $this->estado !== 'LIQUIDADO'
            && (float) $this->saldo_pendiente <= $this->montoProximoPago() + 0.01;
    }

    /**
     * Registra un abono. Si no alcanza a cubrir cuota + recargo_acumulado
     * (pago incompleto), lo que faltó genera un recargo nuevo (el
     * porcentaje de la financiera sobre ese faltante) que se suma al saldo
     * total y queda pendiente para exigirse en el siguiente pago — y si
     * vuelve a faltar, ese recargo entra otra vez a la base sobre la que se
     * calcula el próximo, por eso se va acumulando. Si el pago sí alcanza,
     * el cliente queda al día y el recargo se limpia.
     *
     * $fecha: fecha real en que se recibió el pago (capturable en cobranza,
     * ver ReciboController::confirmarPago()) — null usa el momento actual.
     */
    public function registrarPago(float $monto, ?Carbon $fecha = null): void
    {
        $montoEsperado = $this->montoProximoPago();
        $this->saldo_pendiente = max(0, round((float) $this->saldo_pendiente - $monto, 2));

        if ($monto + 0.01 < $montoEsperado) {
            $faltante = round($montoEsperado - $monto, 2);
            $recargoPct = (float) ($this->financiera?->recargo_porcentaje ?? 0);
            $recargo = round($faltante * $recargoPct / 100, 2);

            $this->recargo_acumulado = round($faltante + $recargo, 2);
            $this->saldo_pendiente = round((float) $this->saldo_pendiente + $recargo, 2);
        } else {
            $this->recargo_acumulado = 0;
            $this->quincenas_vencidas = 0;
            if ($this->estado === 'EN_MORA') {
                $this->estado = 'ACTIVO';
            }
        }

        $this->quincena_actual = $this->quincena_actual + 1;
        $this->fecha_ultimo_pago = $fecha ?? now();

        if ($this->saldo_pendiente <= 0) {
            $this->estado = 'LIQUIDADO';
            $this->recargo_acumulado = 0;
        }

        $this->save();
    }
}
