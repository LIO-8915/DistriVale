<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cat_financieras', function (Blueprint $table) {
            // `recargo_porcentaje` (ya existente) pasa a leerse como "recargo
            // de financiera". Este es su contraparte "personal": el beneficio
            // que recibe el usuario del programa (el distribuidor) por
            // prestar el servicio a esa financiera. Mecánica aún sin definir
            // — ver nota en App\Models\Financiera — por eso no se usa todavía
            // en ningún cálculo de mora/recargo, solo se captura el dato.
            $table->decimal('recargo_personal_porcentaje', 5, 2)->nullable()->after('recargo_porcentaje');

            // % que se queda el distribuidor del total cobrado en la
            // quincena a esta financiera (ver pdf de "Ganancias" en
            // financieras). Null = todavía no definido con esa financiera.
            $table->decimal('ganancia_quincenal_porcentaje', 5, 2)->nullable()->after('recargo_personal_porcentaje');
        });

        Schema::table('recibos_consolidados', function (Blueprint $table) {
            // Fecha real en que el cliente pagó, capturable al confirmar el
            // recibo — distinta de fecha_emision (cuándo se generó el
            // recibo) y de fecha_corte (la quincena a la que pertenece).
            $table->dateTime('fecha_pago')->nullable()->after('fecha_emision');
        });
    }

    public function down(): void
    {
        Schema::table('cat_financieras', function (Blueprint $table) {
            $table->dropColumn(['recargo_personal_porcentaje', 'ganancia_quincenal_porcentaje']);
        });

        Schema::table('recibos_consolidados', function (Blueprint $table) {
            $table->dropColumn('fecha_pago');
        });
    }
};
