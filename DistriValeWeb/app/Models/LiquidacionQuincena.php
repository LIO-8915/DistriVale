<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LiquidacionQuincena extends Model
{
    use HasFactory;

    protected $table = 'liquidaciones_quincena';
    protected $primaryKey = 'id_liquidacion';

    protected $fillable = [
        'periodo_quincena',
        'id_financiera',
        'monto_cobrar',
        'monto_poner',
        'monto_depositar',
        'monto_ganancias',
    ];

    protected $casts = [
        'monto_cobrar' => 'decimal:2',
        'monto_poner' => 'decimal:2',
        'monto_depositar' => 'decimal:2',
        'monto_ganancias' => 'decimal:2',
    ];

    public function financiera(): BelongsTo
    {
        return $this->belongsTo(Financiera::class, 'id_financiera', 'id_financiera');
    }
}
