<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * An approver's decision on a pending request.
 *
 * `submission_key` makes a retried approval idempotent: the same key returns the
 * original outcome rather than deciding twice, which matters because a network
 * failure after a successful decision looks identical to a failed one.
 */
class DecideApprovalRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Any authenticated account may attempt a decision; the service decides,
        // against the live role, whether this person is entitled to one.
        return $this->user() !== null;
    }

    /**
     * @return array<string,mixed>
     */
    public function rules(): array
    {
        return [
            'decision' => ['required', 'in:approve,reject'],
            'submission_key' => ['required', 'uuid'],
            'note' => ['nullable', 'string', 'max:500', 'required_if:decision,reject'],
        ];
    }
}
