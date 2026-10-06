<?php

namespace App\Http\Controllers;

use App\Models\PerfilUsuario;
use Illuminate\Http\Request;

class PerfilController extends Controller
{
    public function update(Request $request)
    {
        $data = $request->validate([
            'nombre' => 'required|string|max:100',
            'cargo' => 'nullable|string|max:100',
            'color' => 'required|in:'.implode(',', PerfilUsuario::COLORES),
            'alto_contraste' => 'nullable|boolean',
        ]);
        // El interruptor manda "0" (campo oculto) o "1": un checkbox sin marcar no envía nada.
        $data['alto_contraste'] = $request->boolean('alto_contraste');

        PerfilUsuario::actual()->update($data);

        return back()->with('success', 'Perfil actualizado.');
    }
}
