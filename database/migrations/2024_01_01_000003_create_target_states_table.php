<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('target_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('target_id')->constrained()->cascadeOnDelete();
            $table->string('overall_status', 20)->default('unknown');
            $table->string('icmp_status', 20)->default('unknown');
            $table->float('icmp_latency_ms', 10, 2)->nullable();
            $table->float('icmp_loss_percent', 5, 2)->nullable();
            $table->string('tcp_status', 20)->default('unknown');
            $table->float('tcp_latency_ms', 10, 2)->nullable();
            $table->float('tcp_loss_percent', 5, 2)->nullable();
            $table->timestamp('last_measured_at')->nullable();
            $table->timestamp('last_status_change_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('target_states');
    }
};
