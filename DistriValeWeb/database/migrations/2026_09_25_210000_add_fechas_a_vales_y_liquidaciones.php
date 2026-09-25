<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vales', function (Blueprint $table) {
            // Fecha de disposición / compra / canje que imprime cada financiera.
            $table->date('fecha_disposicion')->nullable()->after('folio_vale');
        });

        Schema::table('liquidaciones_quincena', function (Blueprint $table) {
            // Cada financiera corta en un día distinto dentro de la misma
            // quincena y cobra en otro (a veces ya en la quincena siguiente),
            // así que el periodo solo no alcanza para saber cuándo se pagó.
            $table->date('fecha_corte')->nullable()->after('id_financiera');
            $table->date('fecha_limite_pago')->nullable()->after('fecha_corte');
            // Último día en que vale el monto_depositar (las tablas de
            // bonificación cambian el monto según el día en que se paga).
            $table->date('fecha_deposito')->nullable()->after('fecha_limite_pago');
        });
    }

    public function down(): void
    {
        Schema::table('vales', fn (Blueprint $table) => $table->dropColumn('fecha_disposicion'));
        Schema::table('liquidaciones_quincena', fn (Blueprint $table) => $table->dropColumn(['fecha_corte', 'fecha_limite_pago', 'fecha_deposito']));
    }
};
