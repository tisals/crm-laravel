<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Create pipelines table
        Schema::create('pipelines', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 100);
            $table->string('codigo', 50)->unique();
            $table->boolean('habilitado')->default(true);
            $table->timestamps();
        });

        // 2. Create pipeline_etapas table
        Schema::create('pipeline_etapas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pipeline_id')->constrained('pipelines')->onDelete('cascade');
            $table->string('nombre', 100);
            $table->integer('orden')->default(0);
            $table->boolean('habilitado')->default(true);
            $table->timestamps();
        });

        // 3. Update oportunidad table
        Schema::table('oportunidad', function (Blueprint $table) {
            $table->foreignId('pipeline_id')->nullable()->after('contacto_id')->constrained('pipelines')->onDelete('set null');
            $table->foreignId('pipeline_etapa_id')->nullable()->after('pipeline_id')->constrained('pipeline_etapas')->onDelete('set null');
            $table->foreignId('parent_id')->nullable()->after('pipeline_etapa_id')->constrained('oportunidad')->onDelete('set null');
            $table->integer('version')->default(1)->after('parent_id');
            $table->boolean('is_latest')->default(true)->after('version');

            // Alter estado default to 'Activa' (already varchar(50) from previous migration)
            $table->string('estado', 50)->default('Activa')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('oportunidad', function (Blueprint $table) {
            $table->dropForeign(['pipeline_id']);
            $table->dropForeign(['pipeline_etapa_id']);
            $table->dropForeign(['parent_id']);

            $table->dropColumn(['pipeline_id', 'pipeline_etapa_id', 'parent_id', 'version', 'is_latest']);

            $table->string('estado', 50)->default('Borrador')->change();
        });

        Schema::dropIfExists('pipeline_etapas');
        Schema::dropIfExists('pipelines');
    }
};
