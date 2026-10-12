<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Jede Quelle ist jetzt ein Host mit Mess-Kette: 1. direkte Messung → 2. Steckdose → 3. Schätzung.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('power_sources', function (Blueprint $table) {
            $table->string('direct_method')->nullable();
            $table->text('direct_url')->nullable();
            $table->string('direct_username')->nullable();
            $table->text('direct_password')->nullable();
            $table->double('direct_offset_watts')->default(0);
            $table->string('plug_type')->nullable();
            $table->text('plug_url')->nullable();
            $table->string('plug_json_path')->nullable();
            $table->text('plug_auth')->nullable();
            $table->string('last_method')->nullable();
            $table->timestamp('last_direct_at')->nullable();
        });

        // Bestehende Quellen in die neue Struktur übernehmen
        foreach (DB::table('power_sources')->get() as $source) {
            $update = ['last_method' => $source->last_estimated ? 'estimate' : null];

            if ($source->type === 'push') {
                $update['direct_method'] = 'push';
            } elseif ($source->type === 'http_json') {
                $update['plug_type'] = 'custom';
                $update['plug_url'] = $source->url;
                $update['plug_json_path'] = $source->json_path;
                $update['plug_auth'] = $source->auth_header; // gleicher App-Schlüssel, bleibt verschlüsselt
            } elseif ($source->type === 'estimate') {
                $update['estimate_fallback'] = true;
            }

            DB::table('power_sources')->where('id', $source->id)->update($update);
        }
    }

    public function down(): void
    {
        Schema::table('power_sources', function (Blueprint $table) {
            $table->dropColumn([
                'direct_method', 'direct_url', 'direct_username', 'direct_password', 'direct_offset_watts',
                'plug_type', 'plug_url', 'plug_json_path', 'plug_auth', 'last_method', 'last_direct_at',
            ]);
        });
    }
};
