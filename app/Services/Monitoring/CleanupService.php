<?php

namespace App\Services\Monitoring;

use App\Models\Measurement;
use App\Models\MeasurementRollup;
use App\Models\ScopeMeasurementRollup;

class CleanupService
{
    public function __construct(
        private SettingsService $settings,
    ) {}

    public function cleanupRawMeasurements(): int
    {
        $days = $this->settings->rawRetentionDays();
        return Measurement::where('measured_at', '<', now()->subDays($days))->delete();
    }

    public function cleanupFiveMinuteRollups(): int
    {
        $days = $this->settings->rollup5mRetentionDays();
        $targetRollups = MeasurementRollup::where('granularity', '5m')
            ->where('period_start', '<', now()->subDays($days))
            ->delete();

        $scopeRollups = ScopeMeasurementRollup::where('granularity', '5m')
            ->where('period_start', '<', now()->subDays($days))
            ->delete();

        return $targetRollups + $scopeRollups;
    }
}
