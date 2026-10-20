<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ResolveDefectReportRequest extends FormRequest
{
    /**
     * Resolving a report writes stock off, so it stays a management action.
     * The route already carries `owner:management`; this mirrors it so the
     * request can be resolved and validated without the route context.
     */
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return [
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
