<?php

namespace App\Console\Commands;

use App\Services\Monitoring\StalenessEvaluator;
use Illuminate\Console\Command;

class EvaluateStaleness extends Command
{
    protected $signature = 'pingglass:evaluate-staleness';
    protected $description = 'Check for stale targets and mark them as unknown';

    public function handle(StalenessEvaluator $evaluator): int
    {
        $staleCount = $evaluator->evaluate();

        if ($staleCount > 0) {
            $this->warn("Marked {$staleCount} targets as stale/unknown.");
        } else {
            $this->info('No stale targets found.');
        }

        return self::SUCCESS;
    }
}
