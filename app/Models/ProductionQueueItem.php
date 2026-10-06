<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Production line queue entry.
 */
class ProductionQueueItem extends Model
{
    protected $table = 'production_queue';

    public const STAGES = ['Unload', 'Wash', 'Fill', 'Seal'];

    public const FINAL_STAGE = 4;

    protected $fillable = [
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

    public function advance(): self
    {
        DB::transaction(function (): void {
            $locked = self::whereKey($this->id)->lockForUpdate()->firstOrFail();
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
