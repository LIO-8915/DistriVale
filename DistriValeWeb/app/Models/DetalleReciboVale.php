<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DetalleReciboVale extends Model
{
    use HasFactory;

    protected $table = 'detalle_recibo_vales';
    protected $primaryKey = 'id_detalle';

    protected $fillable = [
        'id_recibo',
        'id_vale',
        'monto_pago',
        'numero_pago_texto',
        'nuevo_saldo',
    ];

    protected $casts = [
        'monto_pago' => 'decimal:2',
        'nuevo_saldo' => 'decimal:2',
    ];

    public function recibo(): BelongsTo
    {
        return $this->belongsTo(ReciboConsolidado::class, 'id_recibo', 'id_recibo');
    }

    public function vale(): BelongsTo
    {
        return $this->belongsTo(Vale::class, 'id_vale', 'id_vale');
    }
}
