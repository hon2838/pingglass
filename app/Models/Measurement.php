<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Measurement extends Model
{
    protected $fillable = [
        'probe_cycle_id', 'target_id', 'protocol', 'measured_at',
        'sent', 'received', 'loss_percent', 'min_ms', 'max_ms',
        'avg_ms', 'median_ms', 'p10_ms', 'p25_ms', 'p75_ms',
        'p90_ms', 'p95_ms', 'stddev_ms', 'samples', 'status', 'error',
    ];

    protected function casts(): array
    {
        return [
            'measured_at' => 'datetime',
            'sent' => 'integer',
            'received' => 'integer',
            'loss_percent' => 'float',
            'min_ms' => 'float',
            'max_ms' => 'float',
            'avg_ms' => 'float',
            'median_ms' => 'float',
            'p10_ms' => 'float',
            'p25_ms' => 'float',
            'p75_ms' => 'float',
            'p90_ms' => 'float',
            'p95_ms' => 'float',
            'stddev_ms' => 'float',
            'samples' => 'array',
        ];
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(Target::class);
    }

    public function probeCycle(): BelongsTo
    {
        return $this->belongsTo(ProbeCycle::class);
    }
}
