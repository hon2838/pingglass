<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('probe_cycles', function (Blueprint $table) {
            $table->id();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('target_count')->default(0);
            $table->unsignedInteger('expected_probe_count')->default(0);
            $table->unsignedInteger('successful_probe_count')->default(0);
            $table->unsignedInteger('failed_probe_count')->default(0);
            $table->string('status', 20)->default('running');
            $table->timestamps();

            $table->index('status');
            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('probe_cycles');
    }
};
