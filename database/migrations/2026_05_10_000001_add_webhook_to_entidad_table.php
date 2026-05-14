<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entidad', function (Blueprint $table) {
            $table->string('webhook_url', 500)
                ->nullable()
                ->comment('URL donde se enviarán los webhooks cuando ocurran eventos');
            $table->string('webhook_secret', 255)
                ->nullable()
                ->comment('Secret para firmar payloads HMAC-SHA256');
            $table->boolean('webhook_enabled')
                ->default(false)
                ->comment('Habilitar envío de webhooks');
        });
    }

    public function down(): void
    {
        Schema::table('entidad', function (Blueprint $table) {
            $table->dropColumn(['webhook_url', 'webhook_secret', 'webhook_enabled']);
        });
    }
};