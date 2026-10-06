<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

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
        'fecha_pago',
    ];

    protected $casts = [
        'fecha_corte' => 'date',
        'fecha_emision' => 'datetime',
        'fecha_pago' => 'datetime',
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

    /** Ya se confirmó el pago (la fecha de pago solo se guarda al confirmarlo). */
    public function estaPagado(): bool
    {
        return $this->fecha_pago !== null;
    }

    /** Código del cliente tal como se ve en su ficha: C-004. */
    public function codigoCliente(): string
    {
        return 'C-'.str_pad((string) $this->id_cliente, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Renglones del recibo agrupados como el recibo impreso de la financiera:
     * por financiera y, dentro de ella, por folio. Requiere detalles.vale.financiera.
     */
    public function detallesOrdenados(): Collection
    {
        return $this->detalles
            ->sortBy(fn (DetalleReciboVale $d) => [$d->vale->financiera->nombre, $d->vale->folio_vale])
            ->values();
    }

    /**
     * Subtotal por financiera (más el total general lo da total_oportuno /
     * total_extemporaneo). `tardio` es lo que saldría si ese grupo se paga
     * después de la fecha de corte — el mismo cálculo con que se generó el
     * recibo (cuota × (1 + recargo de la financiera)). Solo se calcula mientras
     * el recibo no se ha pagado: al confirmar, `monto_pago` pasa a ser lo que
     * realmente se cobró y esa cifra ya no tendría sentido (null).
     *
     * @return array<int, array{id:int, nombre:string, vales:int, pct:float, monto:float, tardio:?float}>
     */
    public function resumenPorFinanciera(): array
    {
        $pagado = $this->estaPagado();

        return $this->detallesOrdenados()
            ->groupBy(fn (DetalleReciboVale $d) => $d->vale->id_financiera)
            ->map(function (Collection $grupo) use ($pagado) {
                $financiera = $grupo->first()->vale->financiera;
                $pct = (float) ($financiera->recargo_porcentaje ?? 0);

                return [
                    'id' => (int) $financiera->id_financiera,
                    'nombre' => $financiera->nombre,
                    'vales' => $grupo->count(),
                    'pct' => $pct,
                    'monto' => round($grupo->sum(fn (DetalleReciboVale $d) => (float) $d->monto_pago), 2),
                    'tardio' => $pagado
                        ? null
                        : round($grupo->sum(fn (DetalleReciboVale $d) => round((float) $d->monto_pago * (1 + $pct / 100), 2)), 2),
                ];
            })
            ->values()
            ->all();
    }

    /** Lo que de verdad se cobró: la suma de los montos de cada vale (editables al confirmar). */
    public function totalCapturado(): float
    {
        return round($this->detalles->sum(fn (DetalleReciboVale $d) => (float) $d->monto_pago), 2);
    }
}
