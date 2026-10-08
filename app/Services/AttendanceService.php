<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AttendanceService
{
    public function listing(array $filters): LengthAwarePaginator
    {
        $date = $filters['date'] ?? now()->toDateString();
        $status = $filters['status'] ?? null;
        $search = $filters['search'] ?? '';
        $direction = ($filters['order'] ?? 'newest') === 'oldest' ? 'asc' : 'desc';
        if (! empty($filters['history'])) {
            $q = EmployeeAttendance::with(['employee', 'recorderIn', 'recorderOut']);
            if ($search !== '') {
                $q->whereHas('employee', fn ($e) => $e->where('name', 'like', '%'.$search.'%'));
            }
            $this->filterStatus($q, $status);

            return $q->orderBy('attendance_date', $direction)->orderBy('id', $direction)->paginate(25)->through(fn ($a) => $this->row($a->employee, $a, $a->attendance_date->toDateString()));
        }
        $q = Employee::withTrashed()->where(function ($e) use ($date): void {
            $e->where(fn ($active) => $active->where('is_active', true)->whereNull('deleted_at')->whereDate('created_at', '<=', $date))
                ->orWhereHas('attendances', fn ($a) => $a->whereDate('attendance_date', $date));
        })->with(['attendances' => fn ($a) => $a->whereDate('attendance_date', $date)->with(['recorderIn', 'recorderOut'])]);
        if ($search !== '') {
            $q->where('name', 'like', '%'.$search.'%');
        }
        $closed = $date < now()->toDateString() || ($date === now()->toDateString() && now()->format('H:i') >= '17:00');
        if ($status === 'absent' && $closed) {
            $q->where(fn ($e) => $e->whereDoesntHave('attendances', fn ($a) => $a->whereDate('attendance_date', $date))->orWhereHas('attendances', fn ($a) => $a->whereDate('attendance_date', $date)->where('type', 'absent')));
        } elseif ($status === 'not_recorded') {
            if ($closed) {
                $q->whereRaw('1 = 0');
            }
            $q->whereDoesntHave('attendances', fn ($a) => $a->whereDate('attendance_date', $date));
        } elseif ($status) {
            $q->whereHas('attendances', function ($a) use ($date, $status): void {
                $a->whereDate('attendance_date', $date);
                $this->filterStatus($a, $status);
            });
        }

        return $q->orderBy('id', $direction)->paginate(25)->through(fn ($e) => $this->row($e, $e->attendances->first(), $date));
    }

    private function filterStatus($query, ?string $status): void
    {
        if (! $status) {
            return;
        }
        if (in_array($status, ['checked_in', 'completed', 'needs_review'])) {
            $query->whereTime('time_in', '<', '08:01:00');
        }
        match ($status) {
            'late' => $query->where('type', 'present')->whereTime('time_in', '>=', '08:01:00'),
            'absent','leave' => $query->where('type', $status),
            'completed' => $query->where('type', 'present')->whereNotNull('time_out'),
            'checked_in' => $query->where('type', 'present')->whereNull('time_out')->whereDate('attendance_date', now()->toDateString()),
            'needs_review' => $query->where('type', 'present')->whereNull('time_out')->whereDate('attendance_date', '<', now()->toDateString()),
            default => $query->whereRaw('1 = 0'),
        };
    }

    public function row(Employee $employee, ?EmployeeAttendance $attendance, string $date): array
    {
        return ['employee_id' => $employee->id, 'employee_name' => $employee->name, 'job_title' => $employee->job_title,
            'active' => (bool) $employee->is_active && ! $employee->trashed(), 'attendance_date' => $date, 'id' => $attendance?->id,
            'type' => $attendance?->type, 'status' => $attendance?->status() ?? (($date < now()->toDateString() || ($date === now()->toDateString() && now()->format('H:i') >= '17:00')) ? 'absent' : 'not_recorded'),
            'progress' => $attendance?->progress(),
            'expected_time_out' => $attendance?->time_in?->copy()->addHours(9)->format('Y-m-d\TH:i'),
            'time_in' => $attendance?->time_in?->format('Y-m-d\TH:i'), 'time_out' => $attendance?->time_out?->format('Y-m-d\TH:i'),
            'recorded_in_by' => $attendance?->recorderIn?->name, 'recorded_out_by' => $attendance?->recorderOut?->name, 'version' => $attendance?->version ?? 0];
    }

    public function punch(int $employeeId, string $action, User $user, ?string $reason = null): array
    {
        return DB::transaction(function () use ($employeeId, $action, $user, $reason): array {
            $employee = Employee::withTrashed()->lockForUpdate()->findOrFail($employeeId);
            $now = now();
            $date = $now->toDateString();
            $reason = trim($reason ?? '');
            $a = EmployeeAttendance::where('employee_id', $employeeId)->whereDate('attendance_date', $date)->lockForUpdate()->first();
            $before = $a?->getAttributes();
            if ($action === 'time-in') {
                if ($a) {
                    if ($a->type !== 'present') {
                        throw ValidationException::withMessages(['attendance' => 'This attendance record is already finalized as absent or on leave.']);
                    }

                    return $this->row($employee, $a, $date);
                }
                if (! $employee->is_active || $employee->trashed()) {
                    throw ValidationException::withMessages(['employee' => 'Inactive employees cannot time in.']);
                }
                if ($now->format('H:i') > '08:00' && $reason === '') {
                    throw ValidationException::withMessages(['reason' => 'Explain the late arrival (store opening is 8 AM).']);
                }
                $a = EmployeeAttendance::create(['employee_id' => $employeeId, 'attendance_date' => $date, 'type' => 'present', 'time_in' => $now, 'time_in_by' => $user->id, 'version' => 1]);
            } else {
                if (! $a || $a->type !== 'present' || ! $a->time_in) {
                    throw ValidationException::withMessages(['attendance' => 'Record today’s time in first. Older missing departures require admin review.']);
                }
                if ($a->time_out) {
                    return $this->row($employee, $a, $date);
                }
                if ($now->lt($a->time_in)) {
                    throw ValidationException::withMessages(['attendance' => 'Time out cannot precede time in. Ask an admin to review the record.']);
                }
                if ($now->lt($a->time_in->copy()->addHours(9)) && $reason === '') {
                    throw ValidationException::withMessages(['reason' => 'Explain the early departure: nine hours from time in are required, including breaks.']);
                }
                $a->update(['time_out' => $now, 'time_out_by' => $user->id, 'version' => $a->version + 1]);
            }
            $this->audit($a, $user, $action, $reason !== '' ? $reason : null, $before);

            return $this->row($employee, $a->fresh(['recorderIn', 'recorderOut']), $date);
        });
    }

    private function audit(EmployeeAttendance $a, User $user, string $action, ?string $reason, ?array $before): void
    {
        DB::table('employee_attendance_changes')->insert(['employee_attendance_id' => $a->id, 'user_id' => $user->id, 'action' => $action, 'reason' => $reason, 'before' => $before ? json_encode($before, JSON_THROW_ON_ERROR) : null, 'after' => json_encode($a->getAttributes(), JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }

    public function changes(int $employeeId): LengthAwarePaginator
    {
        Employee::withTrashed()->findOrFail($employeeId);

        return DB::table('employee_attendance_changes as c')->join('employee_attendances as a', 'a.id', '=', 'c.employee_attendance_id')->join('users as u', 'u.id', '=', 'c.user_id')->where('a.employee_id', $employeeId)->orderByDesc('c.id')->select(['c.id', 'c.action', 'c.reason', 'c.before', 'c.after', 'c.created_at', 'a.attendance_date', 'u.name as operator'])->paginate(20)->through(function ($row) {
            $row->before = json_decode($row->before ?? 'null', true);
            $row->after = json_decode($row->after, true);

            return $row;
        });
    }
}
