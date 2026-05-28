<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('detalle_oportunidad', function (Blueprint $table) {
            $table->text('concepto')->nullable()->change();
            $table->string('medida', 20)->default('Und')->change();
        });
    }

    public function down(): void
    {
        Schema::table('detalle_oportunidad', function (Blueprint $table) {
            $table->string('concepto', 255)->nullable()->change();
            $table->string('medida', 10)->default('Und')->change();
        });
    }
};
