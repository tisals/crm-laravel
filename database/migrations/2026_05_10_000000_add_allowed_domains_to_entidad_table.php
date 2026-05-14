<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entidad', function (Blueprint $table) {
            $table->text('allowed_domains')
                ->nullable()
                ->comment('Dominios permitidos para API key, separados por coma (ej: sailus.com,api.sailus.com)');
        });
    }

    public function down(): void
    {
        Schema::table('entidad', function (Blueprint $table) {
            $table->dropColumn('allowed_domains');
        });
    }
};