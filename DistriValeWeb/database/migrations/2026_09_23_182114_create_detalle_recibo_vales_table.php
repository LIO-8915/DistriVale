<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('detalle_recibo_vales', function (Blueprint $table) {
            $table->id('id_detalle');
            $table->foreignId('id_recibo')->constrained('recibos_consolidados', 'id_recibo')->cascadeOnDelete();
            $table->foreignId('id_vale')->constrained('vales', 'id_vale')->cascadeOnDelete();
            $table->decimal('monto_pago', 10, 2);
            $table->string('numero_pago_texto', 20); // ej. "2 de 12"
            $table->decimal('nuevo_saldo', 10, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('detalle_recibo_vales');
    }
};
