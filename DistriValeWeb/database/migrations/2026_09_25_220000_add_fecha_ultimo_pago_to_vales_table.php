<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vales', function (Blueprint $table) {
            // Cuándo se aplicó el último abono. La mora automática lo compara
            // contra la fecha de corte de la financiera para saber si el vale
            // se pagó en el corte vigente.
            $table->timestamp('fecha_ultimo_pago')->nullable()->after('estado');
        });
    }

    public function down(): void
    {
        Schema::table('vales', fn (Blueprint $table) => $table->dropColumn('fecha_ultimo_pago'));
    }
};
