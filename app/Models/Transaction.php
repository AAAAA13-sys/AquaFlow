<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * POS transaction (receipt) header.
 */
class Transaction extends Model
{
    protected $fillable = [
        'receipt_number',
        'customer_id',
        'cashier_id',
        'order_type',
        'gallons_volume',
        'subtotal_amount',
        'vat_amount',
        'auto_discount',
        'manual_discount',
        'total_amount',
        'payment_method',
        'cash_tendered',
        'cash_change',
        'transaction_date',
        'transaction_time',
    ];

    protected function casts(): array
    {
        return [
            'subtotal_amount' => 'float',
            'vat_amount' => 'float',
            'auto_discount' => 'float',
            'manual_discount' => 'float',
            'total_amount' => 'float',
            'cash_tendered' => 'float',
            'cash_change' => 'float',
            'transaction_date' => 'date:Y-m-d',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(TransactionItem::class);
    }

    public function custodyLog(): HasOne
    {
        return $this->hasOne(ContainerCustodyLog::class);
    }
}
