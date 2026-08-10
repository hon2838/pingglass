<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MonitorSetting extends Model
{
    protected $fillable = ['key', 'value', 'type'];

    public static function getValue(string $key, mixed $default = null): mixed
    {
        $setting = static::where('key', $key)->first();
        if (!$setting) return $default;

        return match ($setting->type) {
            'int' => (int) $setting->value,
            'float' => (float) $setting->value,
            'bool' => (bool) $setting->value,
            'json' => json_decode($setting->value, true),
            default => $setting->value,
        };
    }

    public static function setValue(string $key, mixed $value, string $type = 'string'): void
    {
        $stored = match ($type) {
            'json' => is_string($value) ? $value : json_encode($value),
            default => (string) $value,
        };

        static::updateOrCreate(
            ['key' => $key],
            ['value' => $stored, 'type' => $type]
        );
    }
}
