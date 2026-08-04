<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personas', function (Blueprint $table) {
            $table->id();
            $table->string('identificacion_tipo', 10)->nullable();
            $table->string('identificacion_numero', 20)->nullable();
            $table->string('nombres', 100);
            $table->string('apellidos', 100);
            $table->string('email_principal', 150)->nullable();
            $table->string('telefono_principal', 30)->nullable();
            $table->timestamps();

            $table->index('email_principal');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personas');
    }
};
