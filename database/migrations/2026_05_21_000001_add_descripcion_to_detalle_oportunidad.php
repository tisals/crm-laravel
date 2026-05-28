<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('detalle_oportunidad', 'descripcion')) {
            Schema::table('detalle_oportunidad', function (Blueprint $table) {
                $table->text('descripcion')->nullable()->after('concepto');
            });
        }
    }

    public function down(): void
    {
        Schema::table('detalle_oportunidad', function (Blueprint $table) {
            $table->dropColumn('descripcion');
        });
    }
};
