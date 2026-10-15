<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeliveryProof extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['photo_path', 'photo_hash'];

    protected function casts(): array
    {
        return ['reported_cash' => 'float', 'received_quantity' => 'integer', 'created_at' => 'datetime'];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(ProductionQueueItem::class, 'production_queue_id');
    }

    public function remittances(): HasMany
    {
        return $this->hasMany(DeliveryRemittance::class);
    }

    public function unremittedCash(bool $lock = false): float
    {
        $payments = $lock
            ? $this->remittances()->orderBy('id')->lockForUpdate()->get()
            : $this->remittances;

        return $this->reported_cash - (float) $payments->sum('amount');
    }

    public static function pendingForCustomer(int $customerId, bool $lock = true): float
    {
        return round(self::where('customer_id', $customerId)->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate())->get()
            ->sum(fn ($proof) => $proof->unremittedCash($lock)), 2);
    }
}
