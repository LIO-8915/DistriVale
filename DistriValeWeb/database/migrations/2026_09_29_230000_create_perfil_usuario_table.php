<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Una sola fila con los datos de quien usa esta instalación (nombre, cargo,
 * color del avatar) — se muestran en la barra superior y se editan desde el
 * modal que abre el avatar. La app es de un solo puesto, sin login por
 * usuario, así que no hace falta una tabla de varias cuentas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('perfil_usuario', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 100)->default('Elia Véliz');
            $table->string('cargo', 100)->nullable()->default('Administradora');
            $table->string('color', 20)->default('blue');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('perfil_usuario');
    }
};
