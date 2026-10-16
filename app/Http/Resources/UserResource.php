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
            'access' => match ($this->role) {
                User::ROLE_SUPER_ADMIN => 'Owner + all workspaces',
                User::ROLE_ADMIN => 'Station management + Analytics',
                User::ROLE_REFILLER => 'Production',
                User::ROLE_RIDER => 'Delivery',
                default => 'POS only',
            },
            'home_url' => $this->homeUrl(),
            'can_manage' => $request->user()?->canManageUser($this->resource) ?? false,
            'active' => (bool) $this->is_active,
            'must_change_password' => (bool) $this->must_change_password,
        ];
    }
}
