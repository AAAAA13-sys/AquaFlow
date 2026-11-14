<?php

namespace App\Http\Requests;

use App\Models\Expense;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreExpenseRequest extends FormRequest
{
    public const MAX_DAYS_BACK = 366;

    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * @return array<string,mixed>
     */
    public function rules(): array
    {
        return [
            'submission_key' => ['required', 'uuid'],
            'spent_on' => ['required', 'date_format:Y-m-d'],
            'category' => ['required', Rule::in(array_keys(Expense::CATEGORIES))],
            // expenses.amount is decimal(12,2).
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999999.99'],
            'payee' => ['nullable', 'string', 'max:100'],
            'reference' => ['nullable', 'string', 'max:60'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Validation\Validator $v): void {
            if ($v->errors()->has('spent_on')) {
                return;
            }
            $tz = config('app.timezone');
            $today = CarbonImmutable::now($tz)->startOfDay();
            $date = CarbonImmutable::createFromFormat('Y-m-d', (string) $this->input('spent_on'), $tz)->startOfDay();
            if ($date->greaterThan($today)) {
                $v->errors()->add('spent_on', 'The expense date cannot be in the future.');
            } elseif ($date->lessThan($today->subDays(self::MAX_DAYS_BACK))) {
                $v->errors()->add('spent_on', 'The expense date cannot be more than '.self::MAX_DAYS_BACK.' days ago.');
            }
        });
    }

    public function toDto(): \App\Data\ExpenseData
    {
        return \App\Data\ExpenseData::fromArray($this->validated());
    }

    /**
     * @return array<string,string>
     */
    public function messages(): array
    {
        return [
            'amount.gt' => 'The amount must be greater than zero.',
            'amount.decimal' => 'The amount can have at most 2 decimal places.',
            'amount.max' => 'The amount is too large.',
            'category.in' => 'Choose one of the listed categories.',
        ];
    }
}
