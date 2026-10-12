<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('power_sources', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('type')->default('push');
            $table->boolean('enabled')->default(true);
            $table->string('token', 64)->nullable()->unique();
            $table->text('url')->nullable();
            $table->string('json_path')->nullable();
            $table->text('auth_header')->nullable();
            $table->double('multiplier')->default(1);
            $table->double('last_watts')->nullable();
            $table->timestamp('last_sample_at')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('power_sources');
    }
};
