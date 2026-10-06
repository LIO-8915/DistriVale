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
        'recargo_personal_porcentaje',
        'ganancia_quincenal_porcentaje',
        'activo',
    ];

    protected $casts = [
        'comision_porcentaje' => 'decimal:2',
        'recargo_porcentaje' => 'decimal:2',
        'recargo_personal_porcentaje' => 'decimal:2',
        'ganancia_quincenal_porcentaje' => 'decimal:2',
        'activo' => 'boolean',
    ];

    /**
     * PENDIENTE DE DEFINIR (anotación, 2026-10-05): `recargo_personal_porcentaje`
     * es el recargo/beneficio que le corresponde al usuario del programa (el
     * distribuidor que presta el servicio a la financiera), no al cliente
     * final — distinto de `recargo_porcentaje`, que sigue siendo el recargo
     * de la financiera sobre el cliente y es el único que usan hoy
     * App\Support\Mora y Vale::registrarPago(). El cliente tiene beneficios
     * por prestar el servicio (uno de ellos es este recargo/"préstamo
     * personal"), pero la mecánica exacta todavía no está definida — el
     * usuario la detallará después. No conectar este campo a ningún cálculo
     * de mora/saldo hasta entonces; por ahora solo se captura el dato.
     */

    public function vales(): HasMany
    {
        return $this->hasMany(Vale::class, 'id_financiera', 'id_financiera');
    }

    public function liquidaciones(): HasMany
    {
        return $this->hasMany(LiquidacionQuincena::class, 'id_financiera', 'id_financiera');
    }
}
