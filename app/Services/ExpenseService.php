<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Operating expenses. Rows are never edited or deleted: a mistake is voided
 * (owner only) and stays visible, struck through, with its reason.
 */
class ExpenseService
{
    public function __construct(private readonly AuditLogService $audit) {}

    /**
     * Records an expense; an identical retry returns the stored row.
     *
     * @param  array<string,mixed>  $data  validated payload including submission_key
     */
    public function record(User $user, array $data): Expense
    {
        $hash = $this->hash($data);

        try {
            return DB::transaction(function () use ($user, $data, $hash): Expense {
                $existing = $this->findByKey($user, $data['submission_key']);
                if ($existing) {
                    return $this->replay($existing, $hash);
                }

                $expense = Expense::create([
                    'spent_on' => $data['spent_on'],
                    'category' => $data['category'],
                    'amount' => $data['amount'],
                    'payee' => $this->clean($data['payee'] ?? null),
                    'reference' => $this->clean($data['reference'] ?? null),
                    'notes' => $this->clean($data['notes'] ?? null),
                    'recorded_by' => $user->id,
                    'submission_key' => $data['submission_key'],
                    'request_hash' => $hash,
                ]);
                $this->audit->record($user, 'expense_create', $expense, null, $this->snapshot($expense));

                return $this->fresh($expense);
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent request with the same key inserted first.
            $existing = $this->findByKey($user, $data['submission_key']);
            if (! $existing) {
                throw ValidationException::withMessages(['submission_key' => 'This expense could not be saved. Please try again.']);
            }

            return $this->replay($existing, $hash);
        }
    }

    public function void(User $user, Expense $expense, string $reason): Expense
    {
        abort_unless($user->isSuperAdmin(), 403, 'Only the owner can void an expense.');

        return DB::transaction(function () use ($user, $expense, $reason): Expense {
            $expense = Expense::whereKey($expense->id)->lockForUpdate()->firstOrFail();
            if ($expense->voided_at !== null) {
                throw ValidationException::withMessages(['status' => 'This expense has already been voided.']);
            }
            $before = $this->snapshot($expense);

            $expense->forceFill([
                'voided_at' => now(), 'voided_by' => $user->id, 'void_reason' => $reason,
            ])->save();

            $this->audit->record($user, 'expense_void', $expense, $before, $this->snapshot($expense) + ['void_reason' => $reason]);

            return $this->fresh($expense);
        });
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int,Expense>
     */
    public function list(CarbonImmutable $from, CarbonImmutable $to, ?string $category, int $limit = 500)
    {
        return $this->scope($from, $to, $category)->with(['recorder', 'voider'])
            ->orderByDesc('spent_on')->orderByDesc('id')->limit($limit)->get();
    }

    /**
     * Non-voided totals per category over an inclusive date range.
     *
     * @return array{by_category: array<string,string>, total: string}
     */
    public function totalsBetween(CarbonImmutable $from, CarbonImmutable $to, ?string $category = null): array
    {
        $sums = $this->scope($from, $to, $category)->whereNull('voided_at')
            ->groupBy('category')->selectRaw('category, SUM(amount) as total')->pluck('total', 'category');

        $byCategory = [];
        $cents = 0;
        foreach (array_keys(Expense::CATEGORIES) as $key) {
            $value = (int) round(((float) ($sums[$key] ?? 0)) * 100);
            $cents += $value;
            $byCategory[$key] = $this->fmt($value);
        }

        return ['by_category' => $byCategory, 'total' => $this->fmt($cents)];
    }

    private function scope(CarbonImmutable $from, CarbonImmutable $to, ?string $category)
    {
        return Expense::query()
            ->whereBetween('spent_on', [$from->toDateString(), $to->toDateString()])
            ->when($category, fn ($q) => $q->where('category', $category));
    }

    private function fmt(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    private function findByKey(User $user, string $key): ?Expense
    {
        return Expense::where('recorded_by', $user->id)->where('submission_key', $key)->lockForUpdate()->first();
    }

    private function replay(Expense $existing, string $hash): Expense
    {
        if (! hash_equals($existing->request_hash, $hash)) {
            throw ValidationException::withMessages(['submission_key' => 'This submission key was already used for a different expense.']);
        }

        return $this->fresh($existing);
    }

    /** @param array<string,mixed> $data */
    private function hash(array $data): string
    {
        return hash('sha256', json_encode([
            (string) $data['spent_on'], (string) $data['category'], number_format((float) $data['amount'], 2, '.', ''),
            $this->clean($data['payee'] ?? null), $this->clean($data['reference'] ?? null), $this->clean($data['notes'] ?? null),
        ], JSON_THROW_ON_ERROR));
    }

    private function clean(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value === '' ? null : $value;
    }

    /** @return array<string,mixed> */
    private function snapshot(Expense $expense): array
    {
        return [
            'spent_on' => $expense->spent_on?->format('Y-m-d'),
            'category' => $expense->category,
            'amount' => (float) $expense->amount,
            'payee' => $expense->payee,
            'reference' => $expense->reference,
            'notes' => $expense->notes,
            'voided' => $expense->voided_at !== null,
        ];
    }

    private function fresh(Expense $expense): Expense
    {
        return $expense->refresh()->load(['recorder', 'voider']);
    }
}
