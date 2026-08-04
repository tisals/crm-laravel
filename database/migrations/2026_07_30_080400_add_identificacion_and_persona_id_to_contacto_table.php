<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Idempotency guards
        if (Schema::hasColumn('contacto', 'persona_id')) {
            return;
        }

        Schema::table('contacto', function (Blueprint $table) {
            $table->string('identificacion_tipo', 10)->nullable()->after('id');
            $table->string('identificacion_numero', 20)->nullable()->after('identificacion_tipo');
            $table->index('identificacion_numero');
        });

        Schema::table('contacto', function (Blueprint $table) {
            $table->unsignedBigInteger('persona_id')->nullable()->after('id');
            $table->foreign('persona_id')->references('id')->on('personas')->nullOnDelete();
            $table->index('persona_id');
        });
    }

    public function down(): void
    {
        Schema::table('contacto', function (Blueprint $table) {
            $table->dropForeign(['persona_id']);
            $table->dropColumn(['persona_id', 'identificacion_tipo', 'identificacion_numero']);
        });
    }
};
