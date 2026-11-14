<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

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

    // ---- Domain Methods (OOP Invariants & Formatting) ----------------------

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    public function isActive(): bool
    {
        return $this->voided_at === null;
    }

    public function canBeVoidedBy(User $user): bool
    {
        return $user->isSuperAdmin() && ! $this->isVoided();
    }

    public function void(User $user, string $reason): self
    {
        if ($this->isVoided()) {
            throw ValidationException::withMessages(['status' => 'This expense has already been voided.']);
        }

        abort_unless($user->isSuperAdmin(), 403, 'Only the owner can void an expense.');

        $this->forceFill([
            'voided_at' => now(),
            'voided_by' => $user->id,
            'void_reason' => $reason,
        ]);

        return $this;
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? (string) $this->category;
    }

    public function amountInCents(): int
    {
        return (int) round(((float) $this->amount) * 100);
    }

    /**
     * Snapshot representation for immutable audit logging.
     *
     * @return array<string, mixed>
     */
    public function toAuditSnapshot(): array
    {
        return [
            'spent_on' => $this->spent_on?->format('Y-m-d'),
            'category' => $this->category,
            'amount' => (float) $this->amount,
            'payee' => $this->payee,
            'reference' => $this->reference,
            'notes' => $this->notes,
            'voided' => $this->isVoided(),
        ];
    }

    // ---- Query Scopes -----------------------------------------------------

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('voided_at');
    }

    public function scopeVoided(Builder $query): Builder
    {
        return $query->whereNotNull('voided_at');
    }

    public function scopeForPeriod(Builder $query, CarbonImmutable|string $from, CarbonImmutable|string $to): Builder
    {
        $fromDate = $from instanceof CarbonImmutable ? $from->toDateString() : (string) $from;
        $toDate = $to instanceof CarbonImmutable ? $to->toDateString() : (string) $to;

        return $query->whereBetween('spent_on', [$fromDate, $toDate]);
    }

    public function scopeForCategory(Builder $query, ?string $category): Builder
    {
        return $query->when($category, fn ($q) => $q->where('category', $category));
    }
}
