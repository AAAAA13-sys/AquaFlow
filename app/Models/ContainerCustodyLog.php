<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Container custody audit entry: what left and what came back per shape.
 */
class ContainerCustodyLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'transaction_id',
        'customer_id',
        'slim_out',
        'slim_in',
        'round_out',
        'round_in',
        'deficit_slim',
        'deficit_round',
        'damaged_reported',
        'logged_at',
    ];

    protected function casts(): array
    {
        return [
            'slim_out' => 'integer',
            'slim_in' => 'integer',
            'round_out' => 'integer',
            'round_in' => 'integer',
            'deficit_slim' => 'integer',
            'deficit_round' => 'integer',
            'damaged_reported' => 'boolean',
            'logged_at' => 'datetime',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
