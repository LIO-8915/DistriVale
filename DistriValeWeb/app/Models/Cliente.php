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

    public function notas(): HasMany
    {
        return $this->hasMany(NotaCliente::class, 'id_cliente', 'id_cliente')->latest();
    }

    public function saldoTotal(): float
    {
        // Uses the loaded `vales` collection (avoids an N+1 query when the
        // relation was already eager-loaded, e.g. in listing tables).
        return (float) $this->vales
            ->where('estado', '!=', 'LIQUIDADO')
            ->sum(fn ($v) => (float) $v->saldo_pendiente);
    }

    public function ultimoPago(): ?DetalleReciboVale
    {
        return DetalleReciboVale::whereHas('vale', fn ($q) => $q->where('id_cliente', $this->id_cliente))
            ->latest('created_at')
            ->first();
    }

    public function financierasNombres(): string
    {
        $nombres = $this->vales->pluck('financiera.nombre')->unique()->values();

        return $nombres->count() > 1 ? 'Varias' : ($nombres->first() ?? '—');
    }
}
