<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Rohmesswerte (kurz aufbewahrt, für die Live-Verläufe)
        Schema::create('power_samples', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('power_source_id');
            $table->double('watts');
            $table->timestamp('recorded_at');

            $table->foreign('power_source_id')->references('id')->on('power_sources')->cascadeOnDelete();
            $table->index(['power_source_id', 'recorded_at']);
            $table->index('recorded_at');
        });

        // Stunden-Aggregate (lange aufbewahrt, für Monat/Jahr/kWh)
        Schema::create('power_hourly_stats', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('power_source_id');
            $table->timestamp('hour');
            $table->unsignedInteger('samples')->default(0);
            $table->double('sum_watts')->default(0);
            $table->double('min_watts')->default(0);
            $table->double('max_watts')->default(0);
            $table->double('energy_wh')->default(0);

            $table->foreign('power_source_id')->references('id')->on('power_sources')->cascadeOnDelete();
            $table->unique(['power_source_id', 'hour']);
            $table->index('hour');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('power_hourly_stats');
        Schema::dropIfExists('power_samples');
    }
};
