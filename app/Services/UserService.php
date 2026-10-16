<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\ProductionQueueItem;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserService
{
    public function create(array $data, User $actor): User
    {
        return DB::transaction(function () use ($data, $actor): User {
            $actor = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->is_active && $actor->isAdmin(), 403);
            abort_unless(in_array($data['role'], $actor->isSuperAdmin() ? User::ROLES : User::STAFF_ROLES, true), 403);
            $employeeId = $this->employeeFor($data['role'], $data['employee_id'] ?? null, $data['name']);

            return User::query()->create([
                'email' => $data['email'] ?? null,
                'employee_id' => $employeeId,
                'username' => $data['username'],
                'password' => $data['password'] ?? '12345678',
                'must_change_password' => in_array($data['role'], User::STAFF_ROLES, true) || ($data['password'] ?? '12345678') === '12345678',
                'role' => $data['role'],
                'name' => $data['name'],
                'is_active' => $data['is_active'] ?? true,
            ]);
        });
    }

    public function update(User $user, array $data, User $actor): User
    {
        return DB::transaction(function () use ($user, $data, $actor): User {
            User::where('role', User::ROLE_SUPER_ADMIN)->orderBy('id')->lockForUpdate()->get();
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $actor = User::findOrFail($actor->id);
            abort_unless($actor->is_active && $actor->canManageUser($user), 403);
            $role = $data['role'] ?? $user->role;
            abort_unless(in_array($role, $actor->isSuperAdmin() ? User::ROLES : User::STAFF_ROLES, true), 403);
            if ($user->isSuperAdmin() && ($role !== User::ROLE_SUPER_ADMIN || ! (bool) ($data['is_active'] ?? true))) {
                $this->ensureAnotherOwner($user);
            }
            $employeeId = $data['employee_id'] ?? $user->employee_id;
            $employeeId = $this->employeeFor($role, $employeeId, $data['name'] ?? $user->name);
            $user->employee_id = $employeeId;
            if (array_key_exists('email', $data) && $data['email'] !== $user->email) {
                $user->email = $data['email'];
            }
            if (array_key_exists('name', $data)) {
                $user->name = $data['name'];
            }

            if (array_key_exists('role', $data)) {
                if ($user->id === $actor->id && $data['role'] !== $user->role) {
                    throw ValidationException::withMessages([
                        'role' => 'You cannot change your own role.',
                    ]);
                }
                $user->role = $data['role'];
            }

            if (! empty($data['password'] ?? null)) {
                $user->password = $data['password'];
                $user->must_change_password = true;
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

            if ($user->employee_id !== null && array_key_exists('name', $data)) {
                $user->employee()->update(['name' => $user->name]);
            }
            return $user->refresh();
        });
    }

    private function employeeFor(string $role, ?int $employeeId, string $name): ?int
    {
        if ($employeeId !== null && ! Employee::whereKey($employeeId)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['employee_id' => 'Select an active employee for this account.']);
        }
        if ($employeeId === null && in_array($role, [User::ROLE_REFILLER, User::ROLE_RIDER], true)) {
            return Employee::create(['name' => $name, 'job_title' => $role === User::ROLE_RIDER ? 'Delivery Rider' : 'Refiller', 'is_active' => true])->id;
        }

        return $employeeId;
    }

    private function ensureAnotherOwner(User $user): void
    {
        if (! User::where('role', User::ROLE_SUPER_ADMIN)->where('is_active', true)
            ->where('is_bootstrap', false)->where('id', '!=', $user->id)->exists()) {
            throw ValidationException::withMessages(['user' => 'At least one active Super Admin account must remain.']);
        }
    }

    public function delete(User $user, User $actor): void
    {
        DB::transaction(function () use ($user, $actor): void {
            User::where('role', User::ROLE_SUPER_ADMIN)->orderBy('id')->lockForUpdate()->get();
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $actor = User::findOrFail($actor->id);
            abort_unless($actor->is_active && $actor->canManageUser($user), 403);
            if ($user->id === $actor->id) {
                throw ValidationException::withMessages([
                    'user' => 'You cannot delete your own account.',
                ]);
            }

            if ($user->isSuperAdmin()) {
                $this->ensureAnotherOwner($user);
            }

            if ($user->transactions()->exists()
                || ProductionQueueItem::where('rider_id', $user->id)->orWhere('handed_over_by', $user->id)->exists()
                || DB::table('fulfillment_events')->where('user_id', $user->id)->exists()
                || StockMovement::where('user_id', $user->id)->exists()) {
                throw ValidationException::withMessages(['user' => 'This account has transaction or fulfillment history. Deactivate it instead.']);
            }
            $user->delete();
        });
    }
}
