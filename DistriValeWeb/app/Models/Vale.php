<?php

namespace App\Models;

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
        'estado',
    ];

    protected $casts = [
        'monto_original' => 'decimal:2',
        'cuota_quincenal' => 'decimal:2',
        'saldo_pendiente' => 'decimal:2',
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
     * Vale que en este corte paga su última cuota: sigue ACTIVO (esa cuota
     * todavía se cobra) y pasa a LIQUIDADO al confirmarse el pago.
     */
    public function esUltimoPago(): bool
    {
        return $this->estado === 'ACTIVO'
            && $this->quincena_actual >= $this->total_quincenas
            && (float) $this->saldo_pendiente <= (float) $this->cuota_quincenal + 0.01;
    }

    public function registrarPago(float $monto): void
    {
        $this->saldo_pendiente = max(0, (float) $this->saldo_pendiente - $monto);
        $this->quincena_actual = min($this->total_quincenas, $this->quincena_actual + 1);
        $this->estado = $this->saldo_pendiente <= 0 ? 'LIQUIDADO' : $this->estado;
        $this->fecha_ultimo_pago = now();
        $this->save();
    }
}
