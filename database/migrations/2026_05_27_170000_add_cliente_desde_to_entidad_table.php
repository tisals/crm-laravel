<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entidad', function (Blueprint $table) {
            $table->timestamp('cliente_desde')->nullable()->after('estado');
        });
    }

    public function down(): void
    {
        Schema::table('entidad', function (Blueprint $table) {
            $table->dropColumn('cliente_desde');
        });
    }
};
