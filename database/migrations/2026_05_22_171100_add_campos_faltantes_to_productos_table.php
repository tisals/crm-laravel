<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            if (!Schema::hasColumn('productos', 'referencia')) {
                $table->string('referencia', 100)->nullable()->after('nombre');
            }
            if (!Schema::hasColumn('productos', 'medida')) {
                $table->string('medida', 20)->nullable()->after('iva');
            }
            if (!Schema::hasColumn('productos', 'vr_unitario')) {
                $table->decimal('vr_unitario', 15, 2)->nullable()->after('medida');
            }
        });

        Schema::table('entidad', function (Blueprint $table) {
            if (!Schema::hasColumn('entidad', 'linea_negocio')) {
                $table->string('linea_negocio', 100)->nullable()->after('nombre_comercial');
            }
        });
    }

    public function down(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->dropColumn(['referencia', 'medida', 'vr_unitario']);
        });

        Schema::table('entidad', function (Blueprint $table) {
            $table->dropColumn(['linea_negocio']);
        });
    }
};
