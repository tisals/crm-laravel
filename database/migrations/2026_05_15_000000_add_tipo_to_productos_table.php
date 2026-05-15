<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('productos', 'tipo')) {
            Schema::table('productos', function (Blueprint $table) {
                $table->string('tipo', 50)->default('producto')->after('nombre');
            });
        }

        if (!Schema::hasColumn('productos', 'precio')) {
            Schema::table('productos', function (Blueprint $table) {
                $table->decimal('precio', 10, 2)->nullable()->after('iva');
            });
        }

        if (!Schema::hasColumn('productos', 'descripcion')) {
            Schema::table('productos', function (Blueprint $table) {
                $table->text('descripcion')->nullable()->after('precio');
            });
        }

        if (!Schema::hasColumn('productos', 'caracteristicas')) {
            Schema::table('productos', function (Blueprint $table) {
                $table->json('caracteristicas')->nullable()->after('descripcion');
            });
        }
    }

    public function down(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->dropColumn(['tipo', 'precio', 'descripcion', 'caracteristicas']);
        });
    }
};
