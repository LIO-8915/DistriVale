<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReciboConsolidado extends Model
{
    use HasFactory;

    protected $table = 'recibos_consolidados';
    protected $primaryKey = 'id_recibo';

    protected $fillable = [
        'id_cliente',
        'nombre_distribuidora',
        'periodo_quincena',
        'fecha_corte',
        'total_oportuno',
        'total_extemporaneo',
        'fecha_emision',
    ];

    protected $casts = [
        'fecha_corte' => 'date',
        'fecha_emision' => 'datetime',
        'total_oportuno' => 'decimal:2',
        'total_extemporaneo' => 'decimal:2',
    ];

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'id_cliente', 'id_cliente');
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(DetalleReciboVale::class, 'id_recibo', 'id_recibo');
    }
}
