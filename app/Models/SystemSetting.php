<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

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
    public const KEY_SHIFT_START_TIME = 'shift_start_time';
    public const KEY_SHIFT_END_TIME = 'shift_end_time';
    public const KEY_SHIFT_GRACE_MINUTES = 'shift_grace_minutes';
    public const KEY_STATION_TIMEZONE = 'station_timezone';

    public const DEFAULT_SHIFT_START = '08:00:00';
    public const DEFAULT_SHIFT_END = '17:00:00';
    public const DEFAULT_GRACE_MINUTES = 30;
    public const DEFAULT_TIMEZONE = 'Asia/Manila';

    public const CACHE_KEY_SHIFT_POLICY = 'shift_policy';

    protected $fillable = [
        'setting_key',
        'setting_value',
        'description',
    ];

    /** @return array{start: string, end: string, grace_minutes: int, tz: string} */
    public static function shiftPolicy(): array
    {
        return Cache::rememberForever(self::CACHE_KEY_SHIFT_POLICY, function (): array {
            $start = static::value(self::KEY_SHIFT_START_TIME);
            $end = static::value(self::KEY_SHIFT_END_TIME);
            $grace = static::value(self::KEY_SHIFT_GRACE_MINUTES);
            $tz = static::value(self::KEY_STATION_TIMEZONE);

            return [
                'start' => ($start !== null && $start !== '') ? $start : self::DEFAULT_SHIFT_START,
                'end' => ($end !== null && $end !== '') ? $end : self::DEFAULT_SHIFT_END,
                'grace_minutes' => ($grace !== null && $grace !== '') ? (int) $grace : self::DEFAULT_GRACE_MINUTES,
                'tz' => ($tz !== null && $tz !== '') ? $tz : (config('app.timezone', self::DEFAULT_TIMEZONE) ?: self::DEFAULT_TIMEZONE),
            ];
        });
    }

    public static function bustShiftPolicyCache(): void
    {
        Cache::forget(self::CACHE_KEY_SHIFT_POLICY);
    }

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

        static::bustShiftPolicyCache();
    }
}
