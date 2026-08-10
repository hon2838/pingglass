<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TargetState extends Model
{
    protected $fillable = [
        'target_id', 'overall_status', 'icmp_status', 'icmp_latency_ms',
        'icmp_loss_percent', 'tcp_status', 'tcp_latency_ms',
        'tcp_loss_percent', 'last_measured_at', 'last_status_change_at',
        'consecutive_failures', 'consecutive_recoveries', 'first_failure_at',
    ];

    protected function casts(): array
    {
        return [
            'icmp_latency_ms' => 'float',
            'icmp_loss_percent' => 'float',
            'tcp_latency_ms' => 'float',
            'tcp_loss_percent' => 'float',
            'last_measured_at' => 'datetime',
            'last_status_change_at' => 'datetime',
            'first_failure_at' => 'datetime',
            'consecutive_failures' => 'integer',
            'consecutive_recoveries' => 'integer',
        ];
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(Target::class);
    }
}
