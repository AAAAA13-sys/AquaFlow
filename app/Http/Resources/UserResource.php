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
            'access' => $this->isAdmin() ? 'Full + Analytics' : 'POS only',
            'active' => (bool) $this->is_active,
        ];
    }
}
