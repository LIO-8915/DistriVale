<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotaCliente extends Model
{
    use HasFactory;

    protected $table = 'notas_cliente';
    protected $primaryKey = 'id_nota';

    protected $fillable = ['id_cliente', 'contenido'];

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'id_cliente', 'id_cliente');
    }
}
