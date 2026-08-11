<?php

return [
    'fping_path' => env('PINGGLASS_FPING_PATH', '/usr/bin/fping'),

    'probe_interval' => env('PINGGLASS_PROBE_INTERVAL', 60),

    // Number of targets handled by one queue job. This bounds DNS, socket,
    // process, and database work while still keeping queue overhead low.
    'probe_chunk_size' => env('PINGGLASS_PROBE_CHUNK_SIZE', 200),

    // Minimum interval between fping packets sent to any target.
    'fping_interval_ms' => env('PINGGLASS_FPING_INTERVAL_MS', 1),

    // Native PHP hostname resolution is blocking, so successful lookups are
    // shared by every worker and retained long enough to keep DNS out of the
    // one-minute probe hot path. Deterministic jitter spreads refreshes across
    // a window instead of expiring thousands of entries simultaneously.
    'dns' => [
        'success_cache_ttl' => env('PINGGLASS_DNS_CACHE_TTL', 3600),
        'success_cache_jitter' => env('PINGGLASS_DNS_CACHE_JITTER', 3600),
        'failure_cache_ttl' => env('PINGGLASS_DNS_FAILURE_CACHE_TTL', 300),
        'failure_cache_jitter' => env('PINGGLASS_DNS_FAILURE_CACHE_JITTER', 300),
    ],

    'icmp' => [
        'samples' => env('PINGGLASS_ICMP_SAMPLES', 10),
        'timeout' => env('PINGGLASS_ICMP_TIMEOUT', 2000),
    ],

    'tcp' => [
        'samples' => env('PINGGLASS_TCP_SAMPLES', 10),
        'timeout' => env('PINGGLASS_TCP_TIMEOUT', 2000),
    ],

    'retention' => [
        'raw_days' => env('PINGGLASS_RAW_RETENTION_DAYS', 30),
        'rollup5m_days' => env('PINGGLASS_ROLLUP5M_RETENTION_DAYS', 180),
    ],

    'status' => [
        'down_confirmation_cycles' => env('PINGGLASS_DOWN_CONFIRMATION_CYCLES', 3),
        'recovery_confirmation_cycles' => env('PINGGLASS_RECOVERY_CONFIRMATION_CYCLES', 2),
    ],

    'degraded' => [
        'loss_threshold_percent' => env('PINGGLASS_LOSS_THRESHOLD_PERCENT', 10),
        'latency_threshold_ms' => env('PINGGLASS_LATENCY_THRESHOLD_MS', 200),
    ],

    // Set to true to allow monitoring private/internal IPs (127.x, 10.x, 192.168.x, etc.)
    // Only enable this if you trust all admin users.
    'allow_private_targets' => env('PINGGLASS_ALLOW_PRIVATE_TARGETS', false),
];
