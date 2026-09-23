<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recibos_consolidados', function (Blueprint $table) {
            $table->id('id_recibo');
            $table->foreignId('id_cliente')->constrained('clientes', 'id_cliente')->cascadeOnDelete();
            $table->string('nombre_distribuidora', 150);
            $table->date('fecha_corte');
            $table->decimal('total_oportuno', 10, 2);
            $table->decimal('total_extemporaneo', 10, 2);
            $table->timestamp('fecha_emision')->useCurrent();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recibos_consolidados');
    }
};
