<?php

return [
    'fping_path' => env('PINGGLASS_FPING_PATH', '/usr/bin/fping'),

    'probe_interval' => env('PINGGLASS_PROBE_INTERVAL', 60),

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
        'loss_threshold_percent' => 10,
        'latency_threshold_ms' => 200,
    ],

    // Set to true to allow monitoring private/internal IPs (127.x, 10.x, 192.168.x, etc.)
    // Only enable this if you trust all admin users.
    'allow_private_targets' => env('PINGGLASS_ALLOW_PRIVATE_TARGETS', false),
];
