<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('power_sources', function (Blueprint $table) {
            $table->unsignedInteger('node_id')->nullable()->after('multiplier');
            $table->double('idle_watts')->nullable()->after('node_id');
            $table->double('max_watts')->nullable()->after('idle_watts');
            $table->boolean('estimate_fallback')->default(true)->after('max_watts');
            $table->boolean('last_estimated')->default(false)->after('last_sample_at');
            $table->timestamp('last_measured_at')->nullable()->after('last_estimated');
        });

        Schema::table('power_samples', function (Blueprint $table) {
            $table->boolean('estimated')->default(false);
        });

        Schema::table('power_hourly_stats', function (Blueprint $table) {
            $table->unsignedInteger('estimated_samples')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('power_hourly_stats', function (Blueprint $table) {
            $table->dropColumn('estimated_samples');
        });

        Schema::table('power_samples', function (Blueprint $table) {
            $table->dropColumn('estimated');
        });

        Schema::table('power_sources', function (Blueprint $table) {
            $table->dropColumn(['node_id', 'idle_watts', 'max_watts', 'estimate_fallback', 'last_estimated', 'last_measured_at']);
        });
    }
};
