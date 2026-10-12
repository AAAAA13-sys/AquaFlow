<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AttendancePunchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && ! $this->user()->isAdmin();
    }

    public function rules(): array
    {
        return ['reason' => ['nullable', 'string', 'min:5', 'max:500'], 'time_in' => ['prohibited'], 'time_out' => ['prohibited'], 'attendance_date' => ['prohibited'], 'user_id' => ['prohibited'], 'type' => ['prohibited']];
    }
}
