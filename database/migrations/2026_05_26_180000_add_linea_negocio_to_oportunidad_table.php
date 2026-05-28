<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oportunidad', function (Blueprint $table) {
            if (!Schema::hasColumn('oportunidad', 'linea_negocio')) {
                $table->string('linea_negocio', 100)->nullable()->after('garantia');
            }
        });
    }

    public function down(): void
    {
        Schema::table('oportunidad', function (Blueprint $table) {
            $table->dropColumn(['linea_negocio']);
        });
    }
};
