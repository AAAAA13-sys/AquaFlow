<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Key/value station configuration.
 */
class SystemSetting extends Model
{
    protected $table = 'system_settings';
    protected $primaryKey = 'setting_key';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    public const KEY_STATION_NAME = 'station_name';
    public const KEY_RESTOCK_LEAD_DAYS = 'restock_lead_days';
    public const KEY_SUS_TARGET = 'sus_target';

    protected $fillable = [
        'setting_key',
        'setting_value',
        'description',
    ];

    /** @return array<string,string> */
    public static function allValues(): array
    {
        return static::query()->pluck('setting_value', 'setting_key')
            ->map(static fn ($value): string => (string) $value)
            ->all();
    }

    public static function value(string $key, ?string $default = null): ?string
    {
        $row = static::query()->find($key);

        return $row?->setting_value ?? $default;
    }

    public static function put(string $key, string $value, ?string $description = null): void
    {
        static::query()->updateOrCreate(
            ['setting_key' => $key],
            array_filter([
                'setting_value' => $value,
                'description' => $description,
            ], static fn ($v): bool => $v !== null)
        );
    }
}
