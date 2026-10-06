<?php

namespace App\Models;

use App\Services\CustomerLedgerService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Customer and receivables ledger.
 */
class Customer extends Model
{
    protected $fillable = [
        'name',
        'address',
        'contact',
        'issued_slim',
        'returned_slim',
        'issued_round',
        'returned_round',
        'debt_balance',
        'total_transactions',
        'last_visit',
    ];

    protected function casts(): array
    {
        return [
            'debt_balance' => 'float',
            'issued_slim' => 'integer',
            'returned_slim' => 'integer',
            'issued_round' => 'integer',
            'returned_round' => 'integer',
            'total_transactions' => 'integer',
            'last_visit' => 'date:Y-m-d',
        ];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function custodyLogs(): HasMany
    {
        return $this->hasMany(ContainerCustodyLog::class);
    }

    /** Pending (unreturned) bottles per shape. */
    public function pendingSlim(): int
    {
        return $this->issued_slim - $this->returned_slim;
    }

    public function pendingRound(): int
    {
        return $this->issued_round - $this->returned_round;
    }

    public function pendingBottles(): int
    {
        return $this->pendingSlim() + $this->pendingRound();
    }

    public function settleBalance(float $amount, User $cashier): float
    {
        return app(CustomerLedgerService::class)->settle($this, $amount, $cashier)['applied'];
    }

    public function recordReturn(string $kind): void
    {
        DB::transaction(function () use ($kind): void {
            /** @var Customer $locked */
            $locked = Customer::query()->lockForUpdate()->find($this->id);

            $pending = $kind === 'slim' ? $locked->pendingSlim() : $locked->pendingRound();

            if ($pending <= 0) {
                throw ValidationException::withMessages([
                    'kind' => "No pending {$kind} bottles to return.",
                ]);
            }

            if ($kind === 'slim') {
                $locked->returned_slim += 1;
            } else {
                $locked->returned_round += 1;
            }

            $locked->save();
        });

    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if ($term === null || $term === '') {
            return $query;
        }

        return $query->where(function (Builder $inner) use ($term): void {
            $inner->where('name', 'like', '%'.$term.'%')
                ->orWhere('contact', 'like', '%'.$term.'%');
        });
    }

    public function scopeWithBottles(Builder $query): Builder
    {
        return $query->whereRaw('((issued_slim - returned_slim) + (issued_round - returned_round)) > 0');
    }

    public function scopeWithDebt(Builder $query): Builder
    {
        return $query->where('debt_balance', '>', 0);
    }
}
