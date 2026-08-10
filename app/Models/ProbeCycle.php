<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProbeCycle extends Model
{
    protected $fillable = [
        'started_at', 'completed_at', 'target_count', 'expected_probe_count',
        'successful_probe_count', 'failed_probe_count', 'status',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'target_count' => 'integer',
            'expected_probe_count' => 'integer',
            'successful_probe_count' => 'integer',
            'failed_probe_count' => 'integer',
        ];
    }

    public function measurements(): HasMany
    {
        return $this->hasMany(Measurement::class);
    }

    public function markCompleted(): void
    {
        $this->update([
            'completed_at' => now(),
            'status' => 'completed',
            'successful_probe_count' => $this->measurements()->where('status', 'success')->count(),
            'failed_probe_count' => $this->measurements()->where('status', '!=', 'success')->count(),
        ]);
    }

    public function getDurationSecondsAttribute(): ?float
    {
        if (!$this->completed_at) return null;
        return $this->started_at->diffInSeconds($this->completed_at);
    }
}
