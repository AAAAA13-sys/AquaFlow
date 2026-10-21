<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One till session for one cashier on one day.
 *
 * opened_at / closed_at bracket the session; counted_cash and variance are
 * filled in when the drawer is counted.
 */
class CashShift extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'business_date',
        'opening_float',
        'expected_cash',
        'counted_cash',
        'variance',
        'total_sales',
        'cash_sales_count',
        'opened_at',
        'closed_at',
        'note',
        'opening_note',
    ];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'opening_float' => 'float',
            'expected_cash' => 'float',
            'counted_cash' => 'float',
            'variance' => 'float',
            'total_sales' => 'float',
            'cash_sales_count' => 'integer',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /** @return array{start: string, end: string, grace_minutes: int, tz: string} */
    public static function shiftPolicy(): array
    {
        return SystemSetting::shiftPolicy();
    }

    public function policyTz(): string
    {
        return self::shiftPolicy()['tz'];
    }

    public function openingPunctuality(): string
    {
        if (! $this->opened_at) {
            return 'unopened';
        }

        $tz = $this->policyTz();
        $actual = $this->opened_at->copy()->setTimezone($tz);
        $policy = self::shiftPolicy();

        $dateStr = $this->business_date->format('Y-m-d');
        $targetOpen = Carbon::parse($dateStr.' '.$policy['start'], $tz);
        $graceOpen = $targetOpen->copy()->addMinutes($policy['grace_minutes']);

        if ($actual->lt($targetOpen)) {
            return 'early';
        }
        if ($actual->lte($graceOpen)) {
            return 'on_time';
        }

        return 'late';
    }

    public function closingPunctuality(): string
    {
        if (! $this->closed_at) {
            return $this->opened_at ? 'open' : 'unopened';
        }

        $tz = $this->policyTz();
        $actual = $this->closed_at->copy()->setTimezone($tz);
        $policy = self::shiftPolicy();

        $dateStr = $this->business_date->format('Y-m-d');
        $targetClose = Carbon::parse($dateStr.' '.$policy['end'], $tz);
        $graceClose = $targetClose->copy()->addMinutes($policy['grace_minutes']);

        if ($actual->lt($targetClose)) {
            return 'early';
        }
        if ($actual->lte($graceClose)) {
            return 'on_time';
        }

        return 'delayed';
    }

    public function openingLateMinutes(): int
    {
        if ($this->openingPunctuality() !== 'late') {
            return 0;
        }

        $tz = $this->policyTz();
        $actual = $this->opened_at->copy()->setTimezone($tz);
        $policy = self::shiftPolicy();

        $dateStr = $this->business_date->format('Y-m-d');
        $targetOpen = Carbon::parse($dateStr.' '.$policy['start'], $tz);
        $graceOpen = $targetOpen->copy()->addMinutes($policy['grace_minutes']);

        $secondsPastGrace = $actual->getTimestamp() - $graceOpen->getTimestamp();

        return (int) ceil(max(0, $secondsPastGrace) / 60);
    }

    public function closingLateMinutes(): int
    {
        if ($this->closingPunctuality() !== 'delayed') {
            return 0;
        }

        $tz = $this->policyTz();
        $actual = $this->closed_at->copy()->setTimezone($tz);
        $policy = self::shiftPolicy();

        $dateStr = $this->business_date->format('Y-m-d');
        $targetClose = Carbon::parse($dateStr.' '.$policy['end'], $tz);
        $graceClose = $targetClose->copy()->addMinutes($policy['grace_minutes']);

        $secondsPastGrace = $actual->getTimestamp() - $graceClose->getTimestamp();

        return (int) ceil(max(0, $secondsPastGrace) / 60);
    }

    /** Shortfall or overage in pesos. Negative means the drawer is short. */
    public function isShort(): bool
    {
        return $this->variance !== null && $this->variance < -0.005;
    }

    public function isOver(): bool
    {
        return $this->variance !== null && $this->variance > 0.005;
    }

    public function isBalanced(): bool
    {
        return $this->variance !== null && abs($this->variance) <= 0.005;
    }

    public function isOpen(): bool
    {
        return $this->closed_at === null;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}