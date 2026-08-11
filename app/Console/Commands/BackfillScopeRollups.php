<?php

namespace App\Console\Commands;

use App\Services\Monitoring\RollupService;
use Illuminate\Console\Command;

class BackfillScopeRollups extends Command
{
    protected $signature = 'pingglass:backfill-scope-rollups {--hours=24 : Hours of existing rollups to aggregate}';
    protected $description = 'Build category and global public latency rollups from existing target rollups';

    public function handle(RollupService $rollupService): int
    {
        $hours = min(8760, max(1, (int) $this->option('hours')));
        $count = $rollupService->backfillScopeRollups($hours);
        $this->info("Scope rollup backfill complete: {$count} periods processed.");
        return self::SUCCESS;
    }
}
