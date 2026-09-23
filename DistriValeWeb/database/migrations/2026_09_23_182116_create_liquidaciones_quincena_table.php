<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('liquidaciones_quincena', function (Blueprint $table) {
            $table->id('id_liquidacion');
            $table->string('periodo_quincena', 50); // ej. "15 DE SEPTIEMBRE 2026"
            $table->foreignId('id_financiera')->constrained('cat_financieras', 'id_financiera')->cascadeOnDelete();
            $table->decimal('monto_cobrar', 10, 2)->default(0);
            $table->decimal('monto_poner', 10, 2)->default(0);
            $table->decimal('monto_depositar', 10, 2)->default(0);
            $table->decimal('monto_ganancias', 10, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('liquidaciones_quincena');
    }
};
