<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Financiera extends Model
{
    use HasFactory;

    protected $table = 'cat_financieras';
    protected $primaryKey = 'id_financiera';

    protected $fillable = [
        'nombre',
        'comision_porcentaje',
        'recargo_porcentaje',
        'activo',
    ];

    protected $casts = [
        'comision_porcentaje' => 'decimal:2',
        'recargo_porcentaje' => 'decimal:2',
        'activo' => 'boolean',
    ];

    public function vales(): HasMany
    {
        return $this->hasMany(Vale::class, 'id_financiera', 'id_financiera');
    }

    public function liquidaciones(): HasMany
    {
        return $this->hasMany(LiquidacionQuincena::class, 'id_financiera', 'id_financiera');
    }
}
