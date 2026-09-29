<?php

namespace App\Support\Wireframe;

/**
 * Mismos tres métodos de negocio que App\Models\Cliente (ver ese archivo en
 * cualquier otra rama) — reimplementados sobre las relaciones que Store ya
 * dejó hidratadas en $this->vales, en vez de una consulta a Eloquent.
 */
class WCliente extends Entidad
{
    public function saldoTotal(): float
    {
        return (float) $this->vales
            ->where('estado', '!=', 'LIQUIDADO')
            ->sum(fn ($v) => (float) $v->saldo_pendiente);
    }

    public function ultimoPago(): ?Entidad
    {
        return $this->vales
            ->flatMap(fn ($v) => $v->detallesRecibo)
            ->sortByDesc('created_at')
            ->first();
    }

    public function financierasNombres(): string
    {
        $nombres = $this->vales->pluck('financiera.nombre')->unique()->values();

        return $nombres->count() > 1 ? 'Varias' : ($nombres->first() ?? '—');
    }
}
