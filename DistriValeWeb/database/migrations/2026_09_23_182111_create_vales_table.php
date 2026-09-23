<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vales', function (Blueprint $table) {
            $table->id('id_vale');
            $table->foreignId('id_cliente')->constrained('clientes', 'id_cliente')->cascadeOnDelete();
            $table->foreignId('id_financiera')->constrained('cat_financieras', 'id_financiera')->cascadeOnDelete();
            $table->string('folio_vale', 50);
            $table->decimal('monto_original', 10, 2);
            $table->decimal('cuota_quincenal', 10, 2);
            $table->unsignedInteger('total_quincenas');
            $table->unsignedInteger('quincena_actual')->default(1);
            $table->decimal('saldo_pendiente', 10, 2);
            $table->string('estado', 20)->default('ACTIVO'); // ACTIVO, LIQUIDADO, EN_MORA
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vales');
    }
};
