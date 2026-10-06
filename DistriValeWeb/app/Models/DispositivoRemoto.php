<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class DispositivoRemoto extends Model
{
    protected $table = 'dispositivos_remotos';

    protected $fillable = [
        'nombre', 'user_agent', 'ip', 'estado', 'codigo', 'intentos', 'codigo_expira_en',
        'token_hash', 'boot_id', 'autorizado_en', 'ultimo_uso_en',
    ];

    protected $casts = [
        'codigo_expira_en' => 'datetime',
        'autorizado_en' => 'datetime',
        'ultimo_uso_en' => 'datetime',
    ];

    // El código y el hash del token nunca salen en un toArray()/JSON por accidente.
    protected $hidden = ['codigo', 'token_hash'];

    /** Solicitudes cuyo código todavía sirve para verificarse. */
    public function scopePendientesVigentes(Builder $q): Builder
    {
        return $q->where('estado', 'pendiente')
            ->whereNotNull('codigo')
            ->where('codigo_expira_en', '>', now());
    }

    public function scopeAutorizadosEnEstaSesion(Builder $q, string $bootId): Builder
    {
        return $q->where('estado', 'autorizado')->where('boot_id', $bootId);
    }

    public function codigoVencido(): bool
    {
        return ! $this->codigo_expira_en || $this->codigo_expira_en->isPast();
    }
}
