<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Preferencia "Tema contrastado" del perfil (modal "Mi perfil"). Apagada por
 * defecto: la app se ve con el diseño de cristal original y quien lo necesite
 * enciende los colores/opacidades de public/css/dv-contraste.css. Como el
 * perfil es único por instalación (sin login por usuario), la preferencia
 * vale igual en la PC, el iPad y el celular.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('perfil_usuario', function (Blueprint $table) {
            $table->boolean('alto_contraste')->default(false)->after('color');
        });
    }

    public function down(): void
    {
        Schema::table('perfil_usuario', function (Blueprint $table) {
            $table->dropColumn('alto_contraste');
        });
    }
};
