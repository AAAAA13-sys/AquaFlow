<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string,mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'u' => $this->username,
            'name' => $this->name,
            'role' => $this->role,
            'email' => $this->email,
            'employee_id' => $this->employee_id,
            'job_title' => $this->job_title,
            'access' => $this->isAdmin() ? 'Full + Analytics' : 'POS only',
            'active' => (bool) $this->is_active,
            'must_change_password' => (bool) $this->must_change_password,
        ];
    }
}
