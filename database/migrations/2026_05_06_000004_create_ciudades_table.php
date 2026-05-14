<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ciudades', function (Blueprint $table) {
            $table->string('cod_municipio', 10)->primary();
            $table->string('nombre', 150);
            $table->string('departamento', 100);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            // No softDeletes — read-only reference data
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ciudades');
    }
};
