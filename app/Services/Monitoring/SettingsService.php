<?php

namespace App\Services\Monitoring;

use App\Models\MonitorSetting;
use Illuminate\Support\Facades\Cache;

class SettingsService
{
    private const CACHE_KEY = 'pingglass:settings';
    private const CACHE_TTL = 300; // 5 minutes

    /**
     * No in-memory cache — always read from shared Cache (Redis).
     * This ensures long-running queue workers pick up setting changes.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $settings = $this->all();
        return $settings[$key] ?? $default;
    }

    public function all(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            $dbSettings = MonitorSetting::all()->pluck('value', 'key')->toArray();

            return [
                'probe_interval' => $this->cast($dbSettings['probe_interval'] ?? null, 'int', config('pingglass.probe_interval')),
                'icmp_samples' => $this->cast($dbSettings['icmp_samples'] ?? null, 'int', config('pingglass.icmp.samples')),
                'tcp_samples' => $this->cast($dbSettings['tcp_samples'] ?? null, 'int', config('pingglass.tcp.samples')),
                'icmp_timeout' => $this->cast($dbSettings['icmp_timeout'] ?? null, 'int', config('pingglass.icmp.timeout')),
                'tcp_timeout' => $this->cast($dbSettings['tcp_timeout'] ?? null, 'int', config('pingglass.tcp.timeout')),
                'loss_threshold_percent' => $this->cast($dbSettings['loss_threshold_percent'] ?? null, 'float', config('pingglass.degraded.loss_threshold_percent')),
                'latency_threshold_ms' => $this->cast($dbSettings['latency_threshold_ms'] ?? null, 'float', config('pingglass.degraded.latency_threshold_ms')),
                'raw_retention_days' => $this->cast($dbSettings['raw_retention_days'] ?? null, 'int', config('pingglass.retention.raw_days')),
                'rollup5m_retention_days' => $this->cast($dbSettings['rollup5m_retention_days'] ?? null, 'int', config('pingglass.retention.rollup5m_days')),
                'down_confirmation_cycles' => $this->cast($dbSettings['down_confirmation_cycles'] ?? null, 'int', config('pingglass.status.down_confirmation_cycles')),
                'recovery_confirmation_cycles' => $this->cast($dbSettings['recovery_confirmation_cycles'] ?? null, 'int', config('pingglass.status.recovery_confirmation_cycles')),
            ];
        });
    }

    public function refresh(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public function probeInterval(): int { return $this->get('probe_interval', 60); }

    public function icmpSamples(): int { return $this->get('icmp_samples', 10); }
    public function tcpSamples(): int { return $this->get('tcp_samples', 10); }
    public function icmpTimeout(): int { return $this->get('icmp_timeout', 2000); }
    public function tcpTimeout(): int { return $this->get('tcp_timeout', 2000); }
    public function lossThresholdPercent(): float { return $this->get('loss_threshold_percent', 10.0); }
    public function latencyThresholdMs(): float { return $this->get('latency_threshold_ms', 200.0); }
    public function rawRetentionDays(): int { return $this->get('raw_retention_days', 30); }
    public function rollup5mRetentionDays(): int { return $this->get('rollup5m_retention_days', 180); }
    public function downConfirmationCycles(): int { return $this->get('down_confirmation_cycles', 3); }
    public function recoveryConfirmationCycles(): int { return $this->get('recovery_confirmation_cycles', 2); }

    private function cast(?string $value, string $type, mixed $default): mixed
    {
        if ($value === null) return $default;
        return match ($type) {
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => (bool) $value,
            default => $value,
        };
    }
}
