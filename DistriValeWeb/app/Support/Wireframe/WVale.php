<?php

namespace App\Support\Wireframe;

/**
 * Mismos métodos de negocio que App\Models\Vale — ver ese archivo en
 * cualquier otra rama. registrarPago() persiste el cambio en la sesión vía
 * Store (nunca toca disco/DB) y refresca esta misma instancia en memoria.
 */
class WVale extends Entidad
{
    public function numeroPagoTexto(): string
    {
        return $this->quincena_actual.' de '.$this->total_quincenas;
    }

    public function esUltimoPago(): bool
    {
        return $this->estado === 'ACTIVO'
            && $this->quincena_actual >= $this->total_quincenas
            && (float) $this->saldo_pendiente <= (float) $this->cuota_quincenal + 0.01;
    }

    public function registrarPago(float $monto): void
    {
        $actualizado = Store::registrarPagoVale($this->id_vale, $monto);

        foreach (get_object_vars($actualizado) as $clave => $valor) {
            $this->$clave = $valor;
        }
    }
}
