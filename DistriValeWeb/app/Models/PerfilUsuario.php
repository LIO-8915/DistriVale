<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PerfilUsuario extends Model
{
    use HasFactory;

    protected $table = 'perfil_usuario';

    protected $fillable = [
        'nombre',
        'cargo',
        'color',
    ];

    public const COLORES = ['blue', 'purple', 'orange', 'green'];

    /**
     * Esta app es de un solo puesto (sin login por usuario): siempre hay
     * una única fila de perfil, creada sola con los valores por defecto la
     * primera vez que se pide.
     */
    public static function actual(): self
    {
        return static::firstOrCreate(['id' => 1]);
    }

    public function iniciales(): string
    {
        $palabras = preg_split('/\s+/', trim($this->nombre)) ?: [];
        $palabras = array_filter($palabras);

        if (count($palabras) === 0) {
            return '—';
        }

        $primera = mb_substr(reset($palabras), 0, 1);
        $ultima = count($palabras) > 1 ? mb_substr(end($palabras), 0, 1) : '';

        return mb_strtoupper($primera.$ultima);
    }
}
