<?php

namespace App\Http\Requests;

use App\Models\CashShift;

class RemittanceRequest extends SettleDebtRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canHandleCash() ?? false;
    }

    public function rules(): array
    {
        return parent::rules() + [
            'note' => ['nullable', 'string', 'min:5', 'max:500'],
            // Cash handed over by a rider has to land in an open drawer, otherwise
            // the end-of-day reconciliation cannot explain where the money went.
            // Derived state, not input: the client never sends this field, it only
            // names the reason when the check fails.
            'cash_shift' => [function (string $attribute, mixed $value, \Closure $fail): void {
                $open = CashShift::query()
                    ->where('user_id', $this->user()->id)
                    ->whereNull('closed_at')
                    ->exists();

                if (! $open) {
                    $fail('Open the cash drawer before accepting a delivery collection.');
                }
            }],
        ];
    }
}
