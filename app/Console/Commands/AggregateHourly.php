<?php

namespace App\Console\Commands;

use App\Services\Monitoring\RollupService;
use Illuminate\Console\Command;

class AggregateHourly extends Command
{
    protected $signature = 'pingglass:aggregate-hourly';
    protected $description = 'Aggregate raw measurements into hourly rollups';

    public function handle(RollupService $rollup): int
    {
        $count = $rollup->aggregateHourly();
        $this->info("Hourly aggregation complete: {$count} windows processed.");
        return self::SUCCESS;
    }
}
