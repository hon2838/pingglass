<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Older installations did not enforce the has-one relationship at the
        // database layer. Keep the newest row before adding the unique index.
        DB::table('target_states')
            ->select('target_id')
            ->groupBy('target_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('target_id')
            ->each(function ($targetId) {
                $keepId = DB::table('target_states')->where('target_id', $targetId)->max('id');
                DB::table('target_states')
                    ->where('target_id', $targetId)
                    ->where('id', '!=', $keepId)
                    ->delete();
            });

        Schema::table('target_states', function (Blueprint $table) {
            $table->unique('target_id', 'target_states_target_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('target_states', function (Blueprint $table) {
            $table->dropUnique('target_states_target_id_unique');
        });
    }
};
