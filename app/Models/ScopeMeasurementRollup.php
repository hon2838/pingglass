<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScopeMeasurementRollup extends Model
{
    protected $fillable = [
        'scope_key', 'category_id', 'protocol', 'granularity', 'period_start',
        'target_count', 'sent', 'received', 'loss_percent', 'min_ms', 'max_ms',
        'avg_ms', 'median_ms', 'p10_ms', 'p25_ms', 'p75_ms', 'p90_ms',
        'p95_ms', 'stddev_ms',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'datetime',
            'category_id' => 'integer',
            'target_count' => 'integer',
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
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
