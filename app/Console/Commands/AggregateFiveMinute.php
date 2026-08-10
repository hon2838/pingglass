<?php

namespace App\Console\Commands;

use App\Services\Monitoring\RollupService;
use Illuminate\Console\Command;

class AggregateFiveMinute extends Command
{
    protected $signature = 'pingglass:aggregate-five-minute';
    protected $description = 'Aggregate raw measurements into 5-minute rollups';

    public function handle(RollupService $rollup): int
    {
        $count = $rollup->aggregateFiveMinute();
        $this->info("5-minute aggregation complete: {$count} windows processed.");
        return self::SUCCESS;
    }
}
