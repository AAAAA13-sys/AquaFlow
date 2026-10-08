<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

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
        'payment_status',
        'balance_after',
        'delivery_address',
        'transaction_date',
        'transaction_time',
    ];

    protected function casts(): array
    {
        return [
            'submission_result' => 'array',
            'subtotal_amount' => 'float',
            'vat_amount' => 'float',
            'auto_discount' => 'float',
            'manual_discount' => 'float',
            'total_amount' => 'float',
            'cash_tendered' => 'float',
            'cash_change' => 'float',
            'balance_after' => 'float',
            'transaction_date' => 'date:Y-m-d',
        ];
    }

    /** Next receipt suffix, including receipts already in the production queue. */
    public static function nextReceiptSequence(): int
    {
        return DB::transaction(function (): int {
            DB::table('system_settings')->insertOrIgnore([
                'setting_key' => 'receipt_sequence', 'setting_value' => '1010', 'description' => 'Last allocated receipt number',
                'updated_at' => now(),
            ]);
            $counter = DB::table('system_settings')->where('setting_key', 'receipt_sequence')->lockForUpdate()->first();
            $highest = max(1010, (int) $counter->setting_value);
            foreach ([self::query(), ProductionQueueItem::query()] as $query) {
                $suffix = $query->where('receipt_number', 'regexp', '^OR-[0-9]+$')
                    ->max(DB::raw('CAST(SUBSTRING(receipt_number, 4) AS UNSIGNED)'));
                $highest = max($highest, (int) $suffix);
            }
            DB::table('system_settings')->where('setting_key', 'receipt_sequence')->update([
                'setting_value' => (string) ($highest + 1), 'updated_at' => now(),
            ]);

            return $highest + 1;
        });
    }

    public function scopeNewestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('transaction_date')->orderByDesc('transaction_time')->orderByDesc('id');
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
