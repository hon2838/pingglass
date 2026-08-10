<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Incident extends Model
{
    protected $fillable = [
        'target_id', 'type', 'protocol', 'started_at', 'ended_at',
        'status', 'initial_reason', 'last_reason', 'duration_seconds',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'duration_seconds' => 'integer',
        ];
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(Target::class);
    }

    public function scopeOpen($query)
    {
        return $query->where('status', 'open');
    }

    public function scopeRecent($query, int $days = 30)
    {
        return $query->where('started_at', '>=', now()->subDays($days));
    }

    public function close(?string $reason = null): void
    {
        $this->update([
            'status' => 'closed',
            'ended_at' => now(),
            'last_reason' => $reason ?? $this->last_reason,
            'duration_seconds' => $this->started_at->diffInSeconds(now()),
        ]);
    }

    public function getDurationHumanAttribute(): string
    {
        $seconds = $this->duration_seconds ?? $this->started_at->diffInSeconds(now());
        if ($seconds < 60) return $seconds . 's';
        if ($seconds < 3600) return round($seconds / 60) . 'm';
        return round($seconds / 3600, 1) . 'h';
    }
}
