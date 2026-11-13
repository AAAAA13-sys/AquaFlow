<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

class CustomerStatementRequest extends FormRequest
{
    public const MAX_DAYS = 366;
    public const DEFAULT_DAYS = 30;

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
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Validation\Validator $v): void {
            if ($v->errors()->hasAny(['from', 'to'])) {
                return;
            }
            [$from, $to] = $this->range();
            if ($from->greaterThan($to)) {
                $v->errors()->add('from', 'The start date must be on or before the end date.');
            } elseif ($from->diffInDays($to) >= self::MAX_DAYS) {
                $v->errors()->add('from', 'Choose a range of at most '.self::MAX_DAYS.' days.');
            }
        });
    }

    /** @return array{0:CarbonImmutable,1:CarbonImmutable} inclusive range, defaulting to the last 30 days */
    public function range(): array
    {
        $tz = config('app.timezone');
        $today = CarbonImmutable::now($tz)->startOfDay();
        $to = $this->input('to') ? CarbonImmutable::createFromFormat('Y-m-d', $this->input('to'), $tz)->startOfDay() : $today;
        $from = $this->input('from')
            ? CarbonImmutable::createFromFormat('Y-m-d', $this->input('from'), $tz)->startOfDay()
            : $to->subDays(self::DEFAULT_DAYS - 1);

        return [$from, $to];
    }
}
