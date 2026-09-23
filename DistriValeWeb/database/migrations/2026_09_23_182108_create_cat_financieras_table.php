<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cat_financieras', function (Blueprint $table) {
            $table->id('id_financiera');
            $table->string('nombre', 50);
            $table->decimal('comision_porcentaje', 5, 2)->default(0);
            $table->decimal('recargo_porcentaje', 5, 2)->default(0);
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cat_financieras');
    }
};
