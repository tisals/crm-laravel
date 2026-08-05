<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dashboard KPI snapshot table (CQRS-Lite read model).
     *
     * Pre-aggregates dashboard metrics at (scope, year) granularity so the
     * dashboard endpoint can serve from a single row instead of running 70+
     * aggregation queries. Refreshed nightly via the
     * `crm:refresh-dashboard-snapshot` artisan command (scheduled in
     * routes/console.php).
     *
     * Scope values:
     * - 'global'  : unfiltered (super-admin default view)
     * - 'comercial:{user_id}' : filtered to a single comercial's entities
     */
    public function up(): void
    {
        Schema::create('dashboard_kpi_snapshot', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 50);
            $table->unsignedSmallInteger('year');
            $table->json('kpis');
            $table->timestamp('computed_at');
            $table->timestamps();

            $table->unique(['scope', 'year'], 'idx_snapshot_scope_year_unique');
            $table->index('computed_at', 'idx_snapshot_computed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_kpi_snapshot');
    }
};
