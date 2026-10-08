<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeAttendance extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['attendance_date' => 'date:Y-m-d', 'time_in' => 'datetime', 'time_out' => 'datetime', 'version' => 'integer'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function recorderIn(): BelongsTo
    {
        return $this->belongsTo(User::class, 'time_in_by');
    }

    public function recorderOut(): BelongsTo
    {
        return $this->belongsTo(User::class, 'time_out_by');
    }

    public function status(): string
    {
        if ($this->type !== 'present') {
            return $this->type;
        }
        if ($this->time_in?->format('H:i') > '08:00') {
            return 'late';
        }

        return $this->progress();
    }

    public function progress(): string
    {
        if ($this->type !== 'present') {
            return $this->type;
        }
        if ($this->time_out) {
            return 'completed';
        }

        return $this->attendance_date->toDateString() < now()->toDateString() ? 'needs_review' : 'checked_in';
    }
}
