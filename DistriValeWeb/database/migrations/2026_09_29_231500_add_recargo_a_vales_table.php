<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vales', function (Blueprint $table) {
            // Cuánto se le agregó de más al próximo pago esperado por
            // cuotas incompletas o completamente no pagadas (cuota +
            // recargo_acumulado = lo que en realidad toca cubrir la
            // siguiente vez, no solo cuota_quincenal). Se resetea a 0
            // cuando el cliente se pone al día.
            $table->decimal('recargo_acumulado', 10, 2)->default(0)->after('saldo_pendiente');

            // Cuántas quincenas llevaba vencidas la última vez que
            // Mora::actualizar() revisó este vale — para no volver a
            // cobrarle recargo por una quincena que ya se le cobró.
            $table->unsignedInteger('quincenas_vencidas')->default(0)->after('recargo_acumulado');
        });
    }

    public function down(): void
    {
        Schema::table('vales', function (Blueprint $table) {
            $table->dropColumn(['recargo_acumulado', 'quincenas_vencidas']);
        });
    }
};
