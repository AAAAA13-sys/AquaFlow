<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

/**
 * Production line queue entry.
 */
class ProductionQueueItem extends Model
{
    protected $table = 'production_queue';

    protected $hidden = ['delivery_code'];

    public const STAGES = ['Unload', 'Wash', 'Fill', 'Seal'];

    public const FINAL_STAGE = 4;

    protected $fillable = [
        'transaction_id',
        'receipt_number',
        'customer_name',
        'stage',
        'elapsed_minutes',
        'items_description',
        'order_type',
        'is_completed',
    ];

    protected function casts(): array
    {
        return [
            'delivered_at' => 'datetime',
            'handed_over_at' => 'datetime',
            'delivery_code' => 'encrypted',
            'version' => 'integer',
            'stage' => 'integer',
            'stage_history' => 'array',
            'elapsed_minutes' => 'integer',
            'is_completed' => 'boolean',
        ];
    }

    /** Today's unfinished work is what the terminal shows. */
    public function scopeActiveToday(Builder $query): Builder
    {
        return $query->where('is_completed', false);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function deliveryProof(): HasOne
    {
        return $this->hasOne(DeliveryProof::class, 'production_queue_id');
    }

    public function advance(): self
    {
        DB::transaction(function (): void {
            $locked = self::whereKey($this->id)->lockForUpdate()->firstOrFail();
            abort_if($locked->transaction_id || $locked->order_type === 'Delivery', 403, 'Use the employee fulfillment workflow.');
            if ($locked->is_completed) {
                return;
            }
            $locked->stage = min(self::FINAL_STAGE, $locked->stage + 1);
            $locked->is_completed = $locked->stage >= self::FINAL_STAGE;
            $history = $locked->stage_history ?? [];
            $history[] = ['stage' => $locked->stage, 'at' => now()->toIso8601String(), 'user_id' => auth()->id()];
            $locked->stage_history = $history;
            $locked->save();
        });

        return $this->refresh();
    }
}
