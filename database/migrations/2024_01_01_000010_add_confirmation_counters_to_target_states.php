<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('target_states', function (Blueprint $table) {
            $table->unsignedInteger('consecutive_failures')->default(0)->after('last_status_change_at');
            $table->unsignedInteger('consecutive_recoveries')->default(0)->after('consecutive_failures');
            $table->timestamp('first_failure_at')->nullable()->after('consecutive_recoveries');
            $table->unique('target_id');
        });
    }

    public function down(): void
    {
        Schema::table('target_states', function (Blueprint $table) {
            $table->dropUnique(['target_id']);
            $table->dropColumn(['consecutive_failures', 'consecutive_recoveries', 'first_failure_at']);
        });
    }
};
