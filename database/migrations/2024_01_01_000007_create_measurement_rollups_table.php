<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('measurement_rollups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('target_id')->constrained()->cascadeOnDelete();
            $table->string('protocol', 10);
            $table->string('granularity', 5);
            $table->timestamp('period_start');
            $table->unsignedInteger('sent')->default(0);
            $table->unsignedInteger('received')->default(0);
            $table->float('loss_percent', 5, 2)->default(0);
            $table->float('min_ms', 10, 2)->nullable();
            $table->float('max_ms', 10, 2)->nullable();
            $table->float('avg_ms', 10, 2)->nullable();
            $table->float('median_ms', 10, 2)->nullable();
            $table->float('p10_ms', 10, 2)->nullable();
            $table->float('p25_ms', 10, 2)->nullable();
            $table->float('p75_ms', 10, 2)->nullable();
            $table->float('p90_ms', 10, 2)->nullable();
            $table->float('p95_ms', 10, 2)->nullable();
            $table->float('stddev_ms', 10, 2)->nullable();
            $table->timestamps();

            $table->unique(['target_id', 'protocol', 'granularity', 'period_start']);
            $table->index(['target_id', 'protocol', 'granularity', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('measurement_rollups');
    }
};
