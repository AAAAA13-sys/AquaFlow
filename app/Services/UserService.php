<?php

namespace App\Services;

use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class UserService
{
    public function create(array $data): User
    {

        if ($data['role'] === User::ROLE_ADMIN && empty($data['pin'])) {
            throw ValidationException::withMessages([
                'pin' => 'An Owner PIN is required for admin accounts.',
            ]);
        }

        $user = User::query()->create([
            'username' => $data['username'],
            'password' => $data['password'],
            'role' => $data['role'],
            'name' => $data['name'],
            'pin' => ! empty($data['pin']) ? Hash::make($data['pin']) : null,
            'is_active' => $data['is_active'] ?? true,
        ]);

        return $user;
    }

    public function update(User $user, array $data, User $actor): User
    {

        if (array_key_exists('name', $data)) {
            $user->name = $data['name'];
        }

        if (array_key_exists('role', $data)) {
            if ($user->id === $actor->id && $data['role'] !== $user->role) {
                throw ValidationException::withMessages([
                    'role' => 'You cannot change your own role.',
                ]);
            }
            if ($data['role'] === User::ROLE_ADMIN && empty($data['pin'] ?? null) && empty($user->pin)) {
                throw ValidationException::withMessages([
                    'pin' => 'An Owner PIN is required for admin accounts.',
                ]);
            }
            $user->role = $data['role'];
            if ($user->role !== User::ROLE_ADMIN) {
                $user->pin = null;
            }
        }

        if (! empty($data['password'] ?? null)) {
            $user->password = $data['password'];
        }

        if ($user->isAdmin() && array_key_exists('pin', $data) && empty($data['pin'])) {
            throw ValidationException::withMessages(['pin' => 'An Owner PIN is required for admin accounts.']);
        }

        if (array_key_exists('pin', $data)) {
            $user->pin = ! empty($data['pin']) ? Hash::make($data['pin']) : null;
        }

        if (array_key_exists('is_active', $data)) {
            if ($user->id === $actor->id && ! $data['is_active']) {
                throw ValidationException::withMessages([
                    'is_active' => 'You cannot deactivate your own account.',
                ]);
            }
            $user->is_active = (bool) $data['is_active'];
        }

        $user->save();

        return $user->refresh();
    }

    public function delete(User $user, ?User $actor): void
    {

        if ($actor !== null && $user->id === $actor->id) {
            throw ValidationException::withMessages([
                'user' => 'You cannot delete your own account.',
            ]);
        }

        if ($user->isAdmin()) {
            $otherAdmins = User::query()
                ->where('role', User::ROLE_ADMIN)
                ->where('is_active', true)
                ->where('id', '!=', $user->id)
                ->count();

            if ($otherAdmins === 0) {
                throw ValidationException::withMessages([
                    'user' => 'At least one active owner account must remain.',
                ]);
            }
        }

        if (StockMovement::where('user_id', $user->id)->exists()) {
            throw ValidationException::withMessages(['user' => 'This account has stock audit history. Deactivate it instead.']);
        }
        $user->delete();

    }
}
