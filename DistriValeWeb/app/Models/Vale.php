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

    public function registrarPago(float $monto): void
    {
        $this->saldo_pendiente = max(0, (float) $this->saldo_pendiente - $monto);
        $this->quincena_actual = min($this->total_quincenas, $this->quincena_actual + 1);
        $this->estado = $this->saldo_pendiente <= 0 ? 'LIQUIDADO' : $this->estado;
        $this->save();
    }
}
