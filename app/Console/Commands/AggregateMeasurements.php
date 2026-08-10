<?php

namespace App\Console\Commands;

use App\Services\Monitoring\RollupService;
use Illuminate\Console\Command;

class AggregateMeasurements extends Command
{
    protected $signature = 'pingglass:aggregate';
    protected $description = 'Aggregate measurements into both 5-minute and hourly rollups';

    public function handle(RollupService $rollup): int
    {
        $this->info('Aggregating 5-minute rollups...');
        $count = $rollup->aggregateFiveMinute();
        $this->info("  Created/updated {$count} five-minute windows.");

        $this->info('Aggregating hourly rollups...');
        $count = $rollup->aggregateHourly();
        $this->info("  Created/updated {$count} hourly windows.");

        $this->info('Aggregation complete.');
        return self::SUCCESS;
    }
}
