<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Expense extends Model
{
    /** Category key => display label, in display order. */
    public const CATEGORIES = [
        'utilities' => 'Utilities',
        'rent' => 'Rent',
        'salaries' => 'Salaries & wages',
        'supplies' => 'Supplies',
        'maintenance' => 'Maintenance',
        'transport' => 'Transport & fuel',
        'permits' => 'Permits & fees',
        'other' => 'Other',
    ];

    protected $fillable = [
        'spent_on', 'category', 'amount', 'payee', 'reference', 'notes', 'recorded_by',
        'submission_key', 'request_hash', 'voided_at', 'voided_by', 'void_reason',
    ];

    protected function casts(): array
    {
        return [
            'spent_on' => 'date:Y-m-d',
            'amount' => 'decimal:2',
            'voided_at' => 'datetime',
        ];
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }
}
