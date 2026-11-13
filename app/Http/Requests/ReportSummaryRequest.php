<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Period for the reports summary: inclusive station-time dates, at most
 * MAX_DAYS long. Missing dates default to this month so far.
 */
class ReportSummaryRequest extends FormRequest
{
    public const MAX_DAYS = 366;

    public function authorize(): bool
    {
        // The route already sits behind owner:management; mirror it here.
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

    /**
     * @return array<string,string>
     */
    public function messages(): array
    {
        return [
            'from.date_format' => 'Enter the start date as YYYY-MM-DD.',
            'to.date_format' => 'Enter the end date as YYYY-MM-DD.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($v->errors()->hasAny(['from', 'to'])) {
                return;
            }
            [$from, $to] = $this->range();
            if ($from->greaterThan($to)) {
                $v->errors()->add('from', 'The start date must be on or before the end date.');
            } elseif ($from->diffInDays($to) >= self::MAX_DAYS) {
                $v->errors()->add('from', 'Choose a period of at most '.self::MAX_DAYS.' days.');
            }
        });
    }

    /** @return array{0:CarbonImmutable,1:CarbonImmutable} inclusive start-of-day dates in station time */
    public function range(): array
    {
        $tz = config('app.timezone');
        $today = CarbonImmutable::now($tz)->startOfDay();
        $from = $this->input('from') ? CarbonImmutable::createFromFormat('!Y-m-d', $this->input('from'), $tz) : $today->startOfMonth();
        $to = $this->input('to') ? CarbonImmutable::createFromFormat('!Y-m-d', $this->input('to'), $tz) : $today;

        return [$from, $to];
    }
}
