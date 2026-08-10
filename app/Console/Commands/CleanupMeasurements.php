<?php

namespace App\Console\Commands;

use App\Services\Monitoring\CleanupService;
use Illuminate\Console\Command;

class CleanupMeasurements extends Command
{
    protected $signature = 'pingglass:cleanup';
    protected $description = 'Delete old raw measurements and rollups based on retention policy';

    public function handle(CleanupService $cleanup): int
    {
        $deleted = $cleanup->cleanupRawMeasurements();
        $this->info("Deleted {$deleted} raw measurements.");

        $deleted = $cleanup->cleanupFiveMinuteRollups();
        $this->info("Deleted {$deleted} 5-minute rollups.");

        return self::SUCCESS;
    }
}
