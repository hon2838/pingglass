<?php

namespace App\Services\Monitoring;

use App\Models\Measurement;
use App\Models\MeasurementRollup;

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
        return MeasurementRollup::where('granularity', '5m')
            ->where('period_start', '<', now()->subDays($days))
            ->delete();
    }
}
