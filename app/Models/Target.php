<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Target extends Model
{
    use HasFactory;

    protected $fillable = [
        'category_id', 'name', 'slug', 'description', 'host',
        'show_host_publicly', 'is_public', 'is_enabled',
        'icmp_enabled', 'tcp_enabled', 'tcp_port',
        'loss_threshold_percent', 'latency_threshold_ms',
        'probe_interval_seconds', 'next_probe_at', 'active_probe_cycle_id',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'category_id' => 'integer',
            'show_host_publicly' => 'boolean',
            'is_public' => 'boolean',
            'is_enabled' => 'boolean',
            'icmp_enabled' => 'boolean',
            'tcp_enabled' => 'boolean',
            'tcp_port' => 'integer',
            'loss_threshold_percent' => 'float',
            'latency_threshold_ms' => 'float',
            'probe_interval_seconds' => 'integer',
            'next_probe_at' => 'datetime',
            'active_probe_cycle_id' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function state(): HasOne
    {
        return $this->hasOne(TargetState::class);
    }

    public function measurements(): HasMany
    {
        return $this->hasMany(Measurement::class);
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(Incident::class);
    }

    public function rollups(): HasMany
    {
        return $this->hasMany(MeasurementRollup::class);
    }

    public function scopeEnabled($query)
    {
        return $query->where('is_enabled', true);
    }

    public function scopePublic($query)
    {
        return $query->where('is_public', true)->where('is_enabled', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    public function getOverallStatusAttribute(): string
    {
        return $this->state?->overall_status ?? 'unknown';
    }

    public function toArrayPublic(): array
    {
        $data = [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'show_host_publicly' => $this->show_host_publicly,
            'is_public' => $this->is_public,
            'icmp_enabled' => $this->icmp_enabled,
            'tcp_enabled' => $this->tcp_enabled,
            'tcp_port' => $this->tcp_port,
            'sort_order' => $this->sort_order,
            'overall_status' => $this->overall_status,
        ];

        if ($this->show_host_publicly) {
            $data['host'] = $this->host;
        }

        return $data;
    }
}
