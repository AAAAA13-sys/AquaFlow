<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:120'], 'job_title' => ['required', 'string', 'max:120'], 'contact_number' => ['nullable', 'string', 'max:50'], 'is_active' => ['required', 'boolean']];
    }
}
