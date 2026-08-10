<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('targets', function (Blueprint $table) {
            $table->float('loss_threshold_percent', 5, 2)->nullable()->after('tcp_port');
            $table->float('latency_threshold_ms', 10, 2)->nullable()->after('loss_threshold_percent');
        });
    }

    public function down(): void
    {
        Schema::table('targets', function (Blueprint $table) {
            $table->dropColumn(['loss_threshold_percent', 'latency_threshold_ms']);
        });
    }
};
