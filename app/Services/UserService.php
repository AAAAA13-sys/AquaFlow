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
    public function create(array $data): User
    {
        $this->validateEmployee($data['role'], $data['employee_id'] ?? null);
        $user = User::query()->create([
            'email' => $data['email'] ?? null,
            'employee_id' => $data['employee_id'] ?? null,
            'username' => $data['username'],
            'password' => $data['password'],
            'role' => $data['role'],
            'name' => $data['name'],
            'job_title' => $data['job_title'] ?? null,
            'is_active' => $data['is_active'] ?? true,
        ]);

        return $user;
    }

    public function update(User $user, array $data, User $actor): User
    {
        $role = $data['role'] ?? $user->role;
        $employeeId = $data['employee_id'] ?? $user->employee_id;
        $this->validateEmployee($role, $employeeId);
        $user->employee_id = $employeeId;
        if (array_key_exists('job_title', $data)) {
            $user->job_title = $data['job_title'];
        }

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

    private function validateEmployee(string $role, ?int $employeeId): void
    {
        if ($employeeId !== null && ! Employee::whereKey($employeeId)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['employee_id' => 'Select an active employee for this account.']);
        }
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

        if ($user->transactions()->exists()
            || ProductionQueueItem::where('rider_id', $user->id)->orWhere('handed_over_by', $user->id)->exists()
            || DB::table('fulfillment_events')->where('user_id', $user->id)->exists()
            || StockMovement::where('user_id', $user->id)->exists()) {
            throw ValidationException::withMessages(['user' => 'This account has transaction or fulfillment history. Deactivate it instead.']);
        }
        $user->delete();

    }
}
