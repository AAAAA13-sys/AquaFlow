<?php

namespace App\Models;

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