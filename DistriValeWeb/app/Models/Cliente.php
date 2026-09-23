<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cliente extends Model
{
    use HasFactory;

    protected $table = 'clientes';
    protected $primaryKey = 'id_cliente';

    protected $fillable = [
        'nombre_completo',
        'telefono',
        'direccion',
        'activo',
    ];

    protected $casts = [
        'activo' => 'boolean',
    ];

    public function vales(): HasMany
    {
        return $this->hasMany(Vale::class, 'id_cliente', 'id_cliente');
    }

    public function recibos(): HasMany
    {
        return $this->hasMany(ReciboConsolidado::class, 'id_cliente', 'id_cliente');
    }

    public function saldoTotal(): float
    {
        return (float) $this->vales()->where('estado', '!=', 'LIQUIDADO')->sum('saldo_pendiente');
    }
}
