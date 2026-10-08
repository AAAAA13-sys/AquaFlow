<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AttendanceIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() || (! $this->boolean('history') && (! $this->filled('date') || $this->input('date') === now()->toDateString()));
    }

    public function rules(): array
    {
        return ['date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'], 'history' => ['sometimes', 'boolean'], 'search' => ['nullable', 'string', 'max:120'], 'status' => ['nullable', 'in:late,not_recorded,checked_in,completed,needs_review,absent,leave'], 'order' => ['sometimes', 'in:newest,oldest'], 'page' => ['sometimes', 'integer', 'min:1']];
    }
}
