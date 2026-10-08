<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;

class TransactionSubmissionService
{
    public function execute(User $cashier, string $key, string $operation, array $input, Closure $write): array
    {
        $hash = hash('sha256', json_encode([$operation, $input], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($cashier, $key, $hash, $write): array {
            // Serialize financial submissions by this operator, including concurrent retries.
            User::whereKey($cashier->id)->lockForUpdate()->firstOrFail();
            $existing = Transaction::where('cashier_id', $cashier->id)->where('submission_key', $key)->first();
            if ($existing) {
                abort_unless(hash_equals($existing->submission_hash, $hash), 409, 'This submission was already used with different details. Recover the original receipt first.');
                $existing->load(['customer', 'cashier', 'items']);

                return $existing->submission_result + ['transaction' => $existing, 'customer' => $existing->customer, 'replayed' => true];
            }
            $result = $write();
            $snapshot = array_intersect_key($result, array_flip(['totals', 'applied']));
            $result['transaction']->forceFill(['submission_key' => $key, 'submission_hash' => $hash, 'submission_result' => $snapshot])->save();

            return $result + ['replayed' => false];
        });
    }
}
