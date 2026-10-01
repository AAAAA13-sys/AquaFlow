<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LogReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string,mixed>
     */
    public function rules(): array
    {
        return [
            'kind' => ['required', 'in:slim,round'],
        ];
    }
}
