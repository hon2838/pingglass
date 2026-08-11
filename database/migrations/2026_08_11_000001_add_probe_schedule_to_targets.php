<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('targets', function (Blueprint $table) {
            $table->unsignedInteger('probe_interval_seconds')->nullable()->after('latency_threshold_ms');
            $table->timestamp('next_probe_at')->nullable()->after('probe_interval_seconds');
            $table->unsignedBigInteger('active_probe_cycle_id')->nullable()->after('next_probe_at');
            $table->index(
                ['is_enabled', 'active_probe_cycle_id', 'next_probe_at'],
                'targets_due_probe_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('targets', function (Blueprint $table) {
            $table->dropIndex('targets_due_probe_index');
            $table->dropColumn(['probe_interval_seconds', 'next_probe_at', 'active_probe_cycle_id']);
        });
    }
};
