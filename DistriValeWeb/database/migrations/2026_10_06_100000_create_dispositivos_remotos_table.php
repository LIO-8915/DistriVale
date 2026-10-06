<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Un registro por cada dispositivo que pide acceso remoto. Nace como
        // `pendiente` (con un código de 6 dígitos que solo se muestra en la
        // PC) y pasa a `autorizado` cuando el dispositivo escribe ese código
        // bien, o a `rechazado`/`revocado` si se le niega o se le quita.
        Schema::create('dispositivos_remotos', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 80);
            $table->string('user_agent', 255)->nullable();
            $table->string('ip', 45);
            $table->string('estado', 12)->default('pendiente'); // pendiente|autorizado|rechazado|revocado

            // Código vigente (solo mientras está pendiente) y su control de
            // intentos. La PC lo necesita en claro para mostrarlo en el modal.
            $table->string('codigo', 6)->nullable();
            $table->unsignedTinyInteger('intentos')->default(0);
            $table->dateTime('codigo_expira_en')->nullable();

            // El secreto del dispositivo ya autorizado viaja en su cookie; en
            // la base solo queda el hash. `boot_id` ata la autorización a
            // UNA ejecución de la app: al cerrarla o reiniciarla deja de valer.
            $table->string('token_hash', 64)->nullable()->index();
            $table->string('boot_id', 40)->nullable();
            $table->dateTime('autorizado_en')->nullable();
            $table->dateTime('ultimo_uso_en')->nullable();

            $table->timestamps();
            $table->index('estado');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispositivos_remotos');
    }
};
