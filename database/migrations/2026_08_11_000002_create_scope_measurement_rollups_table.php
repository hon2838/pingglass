<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scope_measurement_rollups', function (Blueprint $table) {
            $table->id();
            $table->string('scope_key', 80);
            $table->foreignId('category_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('protocol', 10);
            $table->string('granularity', 5);
            $table->timestamp('period_start');
            $table->unsignedInteger('target_count')->default(0);
            $table->unsignedBigInteger('sent')->default(0);
            $table->unsignedBigInteger('received')->default(0);
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

            $table->unique(
                ['scope_key', 'protocol', 'granularity', 'period_start'],
                'scope_rollups_unique',
            );
            $table->index(['category_id', 'granularity', 'period_start'], 'scope_rollups_category_time_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scope_measurement_rollups');
    }
};
