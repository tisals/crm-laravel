<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Apps catalog. Each row is a product/app that can be contracted by
     * an entity. Examples: crm, sailus, marketing, wp-plugin, la-llave, brp.
     */
    public function up(): void
    {
        Schema::create('apps', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 50)->unique();
            $table->string('nombre', 100);
            $table->enum('tipo', ['internal', 'external', 'customer'])->default('internal');
            $table->enum('auth_type', ['sanctum', 'api_key'])->default('sanctum');
            $table->boolean('activo')->default(true);
            $table->text('descripcion')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('apps');
    }
};
