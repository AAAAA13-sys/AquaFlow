<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

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
            'stage' => 'integer',
            'elapsed_minutes' => 'integer',
            'is_completed' => 'boolean',
        ];
    }

    /** Today's unfinished work is what the terminal shows. */
    public function scopeActiveToday(Builder $query): Builder
    {
        return $query->where('is_completed', false)
            ->whereDate('created_at', today());
    }

    public function advance(): self
    {
        $this->stage = min(self::FINAL_STAGE, $this->stage + 1);
        $this->is_completed = $this->stage >= self::FINAL_STAGE;
        $this->save();

        return $this;
    }
}
