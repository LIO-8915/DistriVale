<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Links a Recibo to the same free-text "periodo_quincena" label used by
     * Liquidaciones_Quincena, so the liquidación matrix report can group
     * client payments (detalle_recibo_vales) by quincena.
     */
    public function up(): void
    {
        Schema::table('recibos_consolidados', function (Blueprint $table) {
            $table->string('periodo_quincena', 50)->nullable()->after('id_cliente');
        });
    }

    public function down(): void
    {
        Schema::table('recibos_consolidados', function (Blueprint $table) {
            $table->dropColumn('periodo_quincena');
        });
    }
};
